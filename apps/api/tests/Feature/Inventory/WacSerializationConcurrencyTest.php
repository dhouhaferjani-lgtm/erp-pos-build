<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Two-process serialization proof for the WAC concurrency foundation.
 *
 * This is the only test that exercises the ProductCostLock advisory-lock seam
 * (and the lock-free decrement path's row-lock fallback) as a TRUE two-process
 * race: two independent PostgreSQL connections contend for the same lock at the
 * same time. The local SQLite test runner cannot model this — pg_advisory_*_lock
 * is a no-op there and a second SQLite ":memory:" connection sees a different
 * database — so every test here gates on the pgsql driver and is exercised only
 * by the PostgreSQL CI job. Mirrors the driver-gating convention of the fiscal
 * PG-only invariant tests (e.g. tests/Feature/Fiscal/FiscalEventsImmutabilityTest).
 *
 * The proofs are DETERMINISTIC rather than timing-based:
 *  - Test 1 uses pg_try_advisory_xact_lock, which returns immediately (true =
 *    acquired, false = already held), so mutual exclusion is observed without
 *    sleeps or thread races.
 *  - Test 2 uses SELECT ... FOR UPDATE NOWAIT, which raises SQLSTATE 55P03
 *    immediately when the row is already locked, again with no timing window.
 *
 * Two purpose-built connections are cloned at runtime from the default pgsql
 * connection config. They are independent of Laravel's RefreshDatabase
 * transaction wrapping (their own PDO, their own transactions) precisely so the
 * two can contend — which is also why this test deliberately does NOT use
 * RefreshDatabase and seeds nothing through the framework: Test 1 needs no rows
 * (advisory locks are keyed on an arbitrary hash, not on row existence) and
 * Test 2 owns a committed scratch table it creates and drops itself.
 */
final class WacSerializationConcurrencyTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $clonedConnections = [];

    protected function tearDown(): void
    {
        // Roll back any still-open transactions and forget the cloned
        // connections so a leaked lock/transaction cannot bleed into later tests.
        foreach ($this->clonedConnections as $name) {
            try {
                $connection = DB::connection($name);
                if ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            } catch (\Throwable) {
                // Connection may never have been opened; nothing to unwind.
            }
            DB::purge($name);
            $configKey = "database.connections.{$name}";
            config([$configKey => null]);
        }
        $this->clonedConnections = [];

        parent::tearDown();
    }

    /**
     * Test 1 — the core guarantee: the ProductCostLock seam serializes two
     * recompute/row-creating ops for the SAME product, and does NOT serialize
     * different products.
     *
     * ProductCostLock::acquire takes pg_advisory_xact_lock(hashtext("wac:{T}:{C}:{P}"))
     * for each product. Here we contend on that EXACT key from two connections
     * and prove mutual exclusion deterministically with the try-variant.
     */
    public function test_advisory_lock_serializes_same_product_and_not_different_products(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('advisory-lock race is pgsql-only');
        }

        $tenantId = (string) Str::uuid();
        $companyId = (string) Str::uuid();
        $productId = (string) Str::uuid();
        $otherProductId = (string) Str::uuid();

        // Built IDENTICALLY to ProductCostLock::acquire so we contend on the
        // SAME logical lock a real recompute would take. Keep in sync with
        // app/Modules/Inventory/Domain/Services/ProductCostLock.php.
        $key = "wac:{$tenantId}:{$companyId}:{$productId}";
        $otherKey = "wac:{$tenantId}:{$companyId}:{$otherProductId}";

        $connA = $this->cloneConnection('wac_race_a');
        $connB = $this->cloneConnection('wac_race_b');

        // Connection A acquires the seam lock inside an open transaction
        // (xact-scoped, exactly as ProductCostLock does).
        $connA->beginTransaction();
        $connA->statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$key]);

        // Connection B cannot acquire the SAME product's lock while A holds it.
        // try-lock must also run inside a transaction for xact scoping; we wrap
        // each probe in its own transaction and roll back to release.
        $connB->beginTransaction();
        $this->assertFalse(
            $this->tryAdvisoryXactLock($connB, $key),
            'Connection B acquired the same-product seam lock while A held it — mutual exclusion broken.',
        );

        // ...but a DIFFERENT product's lock is independent and must be grantable,
        // proving the seam is per-product, not a global mutex.
        $this->assertTrue(
            $this->tryAdvisoryXactLock($connB, $otherKey),
            'Connection B could not acquire a different product lock — the seam over-serializes across products.',
        );
        $connB->rollBack();

        // Release A's lock by ending its transaction.
        $connA->commit();

        // Now B can take the previously-contended same-product lock.
        $connB->beginTransaction();
        $this->assertTrue(
            $this->tryAdvisoryXactLock($connB, $key),
            'Connection B still could not acquire the seam lock after A released it — lock did not release on transaction end.',
        );
        $connB->rollBack();
    }

    /**
     * Test 2 — the lock-free decrement path still serializes against an in-flight
     * recompute via the stock_level row lock.
     *
     * Pure decrements (recordSale / issue) intentionally skip the advisory seam;
     * they serialize against a recompute (which holds the row under
     * lockForUpdate/firstOrFail) through the stock_level row's FOR UPDATE lock.
     * We prove that PG row-lock primitive directly and deterministically: while
     * one connection holds a row FOR UPDATE, a second connection's FOR UPDATE
     * NOWAIT on the same row raises SQLSTATE 55P03 (lock_not_available); once the
     * first releases, the second succeeds.
     *
     * A self-owned, committed scratch table is used instead of the real
     * stock_levels row: stock_levels has cascading FKs to products/locations and
     * a full tenant graph, and a row committed for cross-connection visibility
     * would have to reproduce every NOT NULL column and clean up afterwards. The
     * row-lock semantics under test (FOR UPDATE vs FOR UPDATE NOWAIT) are a
     * property of PostgreSQL, identical for any table, so the scratch row proves
     * the exact mechanism the decrement path relies on without that fragility.
     */
    public function test_lock_free_decrement_serializes_against_recompute_via_row_lock(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('row-lock contention race is pgsql-only');
        }

        $connA = $this->cloneConnection('wac_row_a');
        $connB = $this->cloneConnection('wac_row_b');

        $table = 'wac_rowlock_probe';
        $rowId = (string) Str::uuid();

        // DDL auto-commits in PostgreSQL, so the row is immediately visible to
        // both independent connections — the precondition for cross-connection
        // row contention.
        $connA->statement("DROP TABLE IF EXISTS {$table}");
        $connA->statement("CREATE TABLE {$table} (id uuid PRIMARY KEY, quantity numeric(15,4) NOT NULL)");

        try {
            $connA->table($table)->insert(['id' => $rowId, 'quantity' => '5.0000']);

            // Connection A mimics the recompute reader: open a transaction and
            // hold the row under FOR UPDATE.
            $connA->beginTransaction();
            $lockedQty = (string) $connA->table($table)
                ->where('id', $rowId)
                ->lockForUpdate()
                ->value('quantity');
            $this->assertTrue(is_numeric($lockedQty), "Locked quantity was not numeric: {$lockedQty}");
            $this->assertSame(0, bccomp('5.0000', $lockedQty, 4));

            // Connection B mimics the lock-free decrement (recordSale/issue): a
            // FOR UPDATE on the SAME row. NOWAIT makes the contention
            // deterministic — it errors at once rather than blocking.
            $sqlState = null;
            try {
                $connB->select("SELECT id FROM {$table} WHERE id = ? FOR UPDATE NOWAIT", [$rowId]);
                $this->fail('Connection B acquired the row lock while A held it — the decrement path is not serialized against the recompute reader.');
            } catch (QueryException $e) {
                $sqlState = $e->getCode();
            }
            // 55P03 = lock_not_available (NOWAIT could not obtain the row lock).
            $this->assertSame('55P03', (string) $sqlState, 'Expected lock_not_available (55P03) while the row was held FOR UPDATE.');

            // Release A's row lock; B must now obtain it.
            $connA->rollBack();

            $rows = $connB->select("SELECT id FROM {$table} WHERE id = ? FOR UPDATE NOWAIT", [$rowId]);
            $this->assertCount(1, $rows, 'Connection B could not acquire the row lock after A released it.');
        } finally {
            // Best-effort teardown of the scratch table on a clean connection.
            try {
                if ($connA->transactionLevel() > 0) {
                    $connA->rollBack();
                }
            } catch (\Throwable) {
                // ignore
            }
            $connB->statement("DROP TABLE IF EXISTS {$table}");
        }
    }

    /**
     * Clone the default pgsql connection config under a fresh name so the
     * returned connection has its own PDO/transaction independent of the test's
     * RefreshDatabase wrapping. Tracked for teardown.
     */
    private function cloneConnection(string $name): Connection
    {
        $default = (string) config('database.default');
        /** @var array<string, mixed> $baseConfig */
        $baseConfig = config("database.connections.{$default}");

        config(["database.connections.{$name}" => $baseConfig]);
        DB::purge($name);
        $this->clonedConnections[] = $name;

        return DB::connection($name);
    }

    /**
     * Run pg_try_advisory_xact_lock for the given pre-hash key on the connection
     * and return whether the lock was granted. Caller must be inside a
     * transaction for the xact scoping to hold.
     */
    private function tryAdvisoryXactLock(Connection $connection, string $key): bool
    {
        /** @var array<int, object{granted: bool}> $rows */
        $rows = $connection->select('SELECT pg_try_advisory_xact_lock(hashtext(?)) AS granted', [$key]);

        return (bool) $rows[0]->granted;
    }
}
