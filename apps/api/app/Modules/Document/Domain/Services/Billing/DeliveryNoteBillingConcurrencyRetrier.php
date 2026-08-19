<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services\Billing;

use App\Modules\Document\Domain\Exceptions\DeliveryNoteAlreadyClaimedException;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use PDOException;
use Throwable;

/**
 * Finite transaction retry boundary for delivery-note billing claims.
 *
 * Only PostgreSQL deadlock and serialization failures are retryable. Every
 * attempt starts a fresh transaction with bounded server-side waits. A third
 * retryable failure becomes the established attributed billing refusal, while
 * every unrelated database exception propagates unchanged.
 */
final class DeliveryNoteBillingConcurrencyRetrier
{
    private const MAX_RETRIES = 2;

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(string $deliveryNoteId, Closure $operation): mixed
    {
        $retry = 0;

        while (true) {
            try {
                return $this->db->transaction(function () use ($operation): mixed {
                    if ($this->db instanceof Connection && $this->db->getDriverName() === 'pgsql') {
                        $this->db->statement("SET LOCAL lock_timeout = '5s'");
                        $this->db->statement("SET LOCAL statement_timeout = '30s'");
                    }

                    return $operation();
                });
            } catch (Throwable $exception) {
                if (! $this->isRetryable($exception)) {
                    throw $exception;
                }

                if ($retry >= self::MAX_RETRIES) {
                    throw new DeliveryNoteAlreadyClaimedException($deliveryNoteId, $exception);
                }

                $this->rollBackFailedCommit();
                $retry++;
                usleep(random_int(1_000, 5_000) * $retry);
            }
        }
    }

    private function isRetryable(Throwable $exception): bool
    {
        for ($cursor = $exception; $cursor !== null; $cursor = $cursor->getPrevious()) {
            if (! $cursor instanceof PDOException) {
                continue;
            }

            $sqlState = $cursor->errorInfo[0] ?? $cursor->getCode();
            if (in_array((string) $sqlState, ['40P01', '40001'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A deferred PostgreSQL constraint can fail inside PDO::commit(). Laravel
     * has already decremented its transaction counter when it rethrows, while
     * PDO still owns the aborted transaction. Clear that driver transaction so
     * the next retry can really start a new one.
     */
    private function rollBackFailedCommit(): void
    {
        if (! $this->db instanceof Connection) {
            return;
        }

        $pdo = $this->db->getPdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
