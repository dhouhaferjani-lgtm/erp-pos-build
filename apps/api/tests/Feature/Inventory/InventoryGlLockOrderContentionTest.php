<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\DB;
use PgSql\Connection;
use PgSql\Result;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * T11c — deterministic two-connection proof of I-1's terminal-advisory order.
 *
 * The scratch row stands for the one stock row shared by each production pair;
 * the advisory is the exact company key used by GeneralLedgerService. Keeping
 * one shared stock row (rather than two reversed stock rows) isolates the cycle
 * this wave owns: inventory -> company GL versus company GL -> inventory.
 *
 * Pairs 1-6 encode the post-T5b / D-28 target order and must stay green. The
 * production-driven red arms for pairs 7-10 live beside their real writers in
 * PosReturnScrapWriteOffTest and PosCoreReceiptProjectionRefundDispositionStockTest.
 * This file retains their two-sided PostgreSQL sensitivity control: reversing
 * the observed production order must still be capable of producing 40P01.
 */
final class InventoryGlLockOrderContentionTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function greenPairs(): iterable
    {
        yield 'pair 1 — DN confirm x DN confirm' => ['dn-confirm_x_dn-confirm'];
        yield 'pair 2 — DN confirm x RN confirm' => ['dn-confirm_x_rn-confirm'];
        yield 'pair 3 — DN confirm x POS projection' => ['dn-confirm_x_pos-projection'];
        yield 'pair 4 — counting listener x GR (PO is Received before GL)' => ['counting_x_goods-receipt-received'];
        yield 'pair 5 — POS projection x GR (PO is Received before GL)' => ['pos-projection_x_goods-receipt-received'];
        yield 'pair 6 — multi-DN composite x invoice post' => ['multi-dn-composite_x_invoice-post'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function redPairs(): iterable
    {
        yield 'pair 7 — POS refund scrap x DN confirm' => ['pos-refund-scrap_x_dn-confirm', 'T16d'];
        yield 'pair 8 — POS refund scrap x POS sale' => ['pos-refund-scrap_x_pos-sale', 'T16d'];
        yield 'pair 9 — voucher POS sale x DN confirm' => ['voucher-pos-sale_x_dn-confirm', 'T16e'];
        yield 'pair 10 — voucher POS sale x POS sale' => ['voucher-pos-sale_x_pos-sale', 'T16e'];
    }

    #[DataProvider('greenPairs')]
    public function test_pairs_one_to_six_have_no_deadlock_with_terminal_company_advisory(string $pair): void
    {
        $this->requirePostgresRuntime();

        $states = $this->runTerminalOrder($pair);

        self::assertNotContains(
            '40P01',
            $states,
            sprintf('%s violated I-1: the company GL advisory was not terminal.', $pair),
        );
        self::assertSame(['00000', '00000'], $states);
    }

    #[DataProvider('redPairs')]
    public function test_pairs_seven_to_ten_sensitivity_control_reproduces_40p01(
        string $pair,
        string $missingTask,
    ): void {
        $this->requirePostgresRuntime();

        $states = $this->runAdvisoryFirstOrder($pair);

        self::assertContains(
            '40P01',
            $states,
            sprintf(
                '%s sensitivity control did not reproduce I-1 AB-BA for %s: one root frame held '
                .'inventory then requested company GL while the other held company GL then requested inventory.',
                $pair,
                $missingTask,
            ).' SQLSTATEs='.implode(',', $states),
        );
    }

    public function test_advisory_acquired_inside_an_aborted_subtransaction_is_released(): void
    {
        $this->requirePostgresRuntime();

        $a = $this->connect();
        $b = $this->connect();
        $key = 't11c:aborted-savepoint:'.bin2hex(random_bytes(8));

        try {
            pg_query($a, 'BEGIN');
            pg_query($a, 'SAVEPOINT t11c_inner');
            pg_query_params($a, 'SELECT pg_advisory_xact_lock(hashtextextended($1, 0))', [$key]);

            pg_query($b, 'BEGIN');
            $heldBeforeAbort = pg_query_params(
                $b,
                'SELECT pg_try_advisory_xact_lock(hashtextextended($1, 0)) AS granted',
                [$key],
            );
            self::assertInstanceOf(Result::class, $heldBeforeAbort);
            self::assertSame('f', pg_fetch_result($heldBeforeAbort, 0, 'granted'));

            pg_query($a, 'ROLLBACK TO SAVEPOINT t11c_inner');

            $releasedAfterAbort = pg_query_params(
                $b,
                'SELECT pg_try_advisory_xact_lock(hashtextextended($1, 0)) AS granted',
                [$key],
            );
            self::assertInstanceOf(Result::class, $releasedAfterAbort);
            self::assertSame(
                't',
                pg_fetch_result($releasedAfterAbort, 0, 'granted'),
                'PostgreSQL retained a transaction advisory acquired after a savepoint was rolled back.',
            );
        } finally {
            @pg_query($a, 'ROLLBACK');
            @pg_query($b, 'ROLLBACK');
        }
    }

    /** @return list<string> */
    private function runTerminalOrder(string $pair): array
    {
        return $this->withProbeTable($pair, function (Connection $a, Connection $b, string $table, string $key): array {
            pg_query($a, 'BEGIN');
            pg_query($b, 'BEGIN');

            $first = pg_query($a, "SELECT id FROM {$table} WHERE id = 1 FOR UPDATE");
            self::assertInstanceOf(Result::class, $first);

            // B waits on the same inventory row. A therefore reaches the GL
            // advisory only after its last inventory lock, then commits; B can
            // proceed and take the same terminal order. There is no AB-BA edge.
            self::assertTrue(pg_send_query($b, "SELECT id FROM {$table} WHERE id = 1 FOR UPDATE"));
            $gl = pg_query_params($a, 'SELECT pg_advisory_xact_lock(hashtextextended($1, 0))', [$key]);
            self::assertInstanceOf(Result::class, $gl);
            pg_query($a, 'COMMIT');

            $rowResult = pg_get_result($b);
            self::assertInstanceOf(Result::class, $rowResult);
            $stateBRow = $this->sqlState($rowResult);
            $glB = pg_query_params($b, 'SELECT pg_advisory_xact_lock(hashtextextended($1, 0))', [$key]);
            self::assertInstanceOf(Result::class, $glB);
            $stateBGl = $this->sqlState($glB);
            pg_query($b, 'COMMIT');

            return [$stateBRow, $stateBGl];
        });
    }

    /** @return list<string> */
    private function runAdvisoryFirstOrder(string $pair): array
    {
        return $this->withProbeTable($pair, function (Connection $a, Connection $b, string $table, string $key): array {
            pg_query($a, 'BEGIN');
            pg_query($b, 'BEGIN');

            $inventory = pg_query($a, "SELECT id FROM {$table} WHERE id = 1 FOR UPDATE");
            self::assertInstanceOf(Result::class, $inventory);
            $gl = pg_query_params($b, 'SELECT pg_advisory_xact_lock(hashtextextended($1, 0))', [$key]);
            self::assertInstanceOf(Result::class, $gl);

            // A: inventory -> company GL. B: company GL -> inventory. Sending
            // both blocking requests before collecting either result forces PG
            // to resolve the exact cycle and expose SQLSTATE 40P01.
            self::assertTrue(pg_send_query_params(
                $a,
                'SELECT pg_advisory_xact_lock(hashtextextended($1, 0))',
                [$key],
            ));
            self::assertTrue(pg_send_query($b, "SELECT id FROM {$table} WHERE id = 1 FOR UPDATE"));

            $resultA = pg_get_result($a);
            $resultB = pg_get_result($b);
            self::assertInstanceOf(Result::class, $resultA);
            self::assertInstanceOf(Result::class, $resultB);

            return [$this->sqlState($resultA), $this->sqlState($resultB)];
        });
    }

    /**
     * @template T
     *
     * @param  callable(Connection, Connection, string, string): T  $run
     * @return T
     */
    private function withProbeTable(string $pair, callable $run): mixed
    {
        $control = $this->connect();
        $a = $this->connect();
        $b = $this->connect();
        $table = 'w3_t11c_'.bin2hex(random_bytes(6));
        $key = 't11c:'.$pair.':'.bin2hex(random_bytes(8));

        $created = pg_query($control, "CREATE TABLE {$table} (id integer PRIMARY KEY)");
        self::assertInstanceOf(Result::class, $created);
        $inserted = pg_query($control, "INSERT INTO {$table} VALUES (1)");
        self::assertInstanceOf(Result::class, $inserted);

        foreach ([$a, $b] as $connection) {
            pg_query($connection, "SET deadlock_timeout = '100ms'");
            pg_query($connection, "SET lock_timeout = '5s'");
        }

        try {
            return $run($a, $b, $table, $key);
        } finally {
            @pg_query($a, 'ROLLBACK');
            @pg_query($b, 'ROLLBACK');
            @pg_query($control, "DROP TABLE IF EXISTS {$table}");
        }
    }

    private function connect(): Connection
    {
        /** @var array{host?: mixed, port?: mixed, database?: mixed, username?: mixed, password?: mixed} $config */
        $config = config('database.connections.'.config('database.default'));
        $connection = pg_connect(sprintf(
            'host=%s port=%s dbname=%s user=%s password=%s',
            (string) ($config['host'] ?? '127.0.0.1'),
            (string) ($config['port'] ?? '5432'),
            (string) ($config['database'] ?? ''),
            (string) ($config['username'] ?? ''),
            (string) ($config['password'] ?? ''),
        ), PGSQL_CONNECT_FORCE_NEW);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function sqlState(Result $result): string
    {
        return pg_result_error_field($result, PGSQL_DIAG_SQLSTATE) ?: '00000';
    }

    private function requirePostgresRuntime(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('T11c requires two live PostgreSQL connections.');
        }
        if (! function_exists('pg_connect')) {
            self::markTestSkipped('T11c requires ext-pgsql for asynchronous contention.');
        }
    }
}
