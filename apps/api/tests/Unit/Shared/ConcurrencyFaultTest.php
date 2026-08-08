<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Modules\Inventory\Domain\Exceptions\InsufficientStockException;
use App\Shared\Domain\ConcurrencyFault;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Gate C2 (fiscal). A projector "may never reject an already-signed event", but a
 * DEADLOCK / SERIALIZATION FAILURE is not the projector rejecting anything — it
 * is a retryable infrastructure fault, and swallowing it is catastrophic:
 *
 * Laravel's `ManagesTransactions::transaction()` does NOT issue
 * `ROLLBACK TO SAVEPOINT` for a nested concurrency error (it decrements the
 * counter and throws `DeadlockException`), so on PostgreSQL the ENCLOSING
 * transaction is left in aborted state (25P02). If the caller then swallows the
 * exception and returns normally, Laravel issues `COMMIT`, PostgreSQL silently
 * converts it to `ROLLBACK` and reports success — the whole receipt projection
 * is lost while the projection row is marked applied and the event is never
 * retried.
 *
 * This helper is the classifier the two scrap catch-blocks use to re-throw
 * instead of swallowing. It is unit-tested here because the fault itself is not
 * reproducible in the suite (SQLite + the `RefreshDatabase` wrapping
 * transaction) — see the report.
 */
final class ConcurrencyFaultTest extends TestCase
{
    public function test_deadlock_exception_is_retryable(): void
    {
        self::assertTrue(ConcurrencyFault::isRetryable(
            new DeadlockException('deadlock detected'),
        ));
    }

    /**
     * @return list<array{string}>
     */
    public static function retryableSqlStates(): array
    {
        return [
            ['40001'], // serialization_failure
            ['40P01'], // deadlock_detected
            ['25P02'], // in_failed_sql_transaction — the aborted-transaction state
            ['25006'], // read_only_sql_transaction (failover mid-write)
        ];
    }

    #[DataProvider('retryableSqlStates')]
    public function test_query_exception_with_a_retryable_sqlstate_is_retryable(string $sqlState): void
    {
        self::assertTrue(
            ConcurrencyFault::isRetryable($this->queryException($sqlState)),
            "SQLSTATE {$sqlState} must be treated as retryable",
        );
    }

    public function test_an_aborted_transaction_nested_inside_another_exception_is_still_detected(): void
    {
        $wrapped = new \RuntimeException('wrapper', 0, $this->queryException('25P02'));

        self::assertTrue(ConcurrencyFault::isRetryable($wrapped));
    }

    public function test_a_domain_failure_is_not_retryable(): void
    {
        self::assertFalse(ConcurrencyFault::isRetryable(
            new InsufficientStockException(
                productId: '11111111-1111-4111-8111-111111111111',
                locationId: '22222222-2222-4222-8222-222222222222',
                requested: '2.0000',
                available: '1.0000',
            ),
        ));
    }

    public function test_an_unrelated_query_exception_is_not_retryable(): void
    {
        self::assertFalse(ConcurrencyFault::isRetryable($this->queryException('23505')));
    }

    public function test_a_plain_throwable_is_not_retryable(): void
    {
        self::assertFalse(ConcurrencyFault::isRetryable(new \RuntimeException('boom')));
    }

    private function queryException(string $sqlState): QueryException
    {
        $pdo = new PDOException('SQLSTATE['.$sqlState.']: something went wrong');
        $pdo->errorInfo = [$sqlState, 0, 'something went wrong'];

        return new QueryException('pgsql', 'select 1', [], $pdo);
    }
}
