<?php

declare(strict_types=1);

namespace Tests\Traits;

use Closure;
use Illuminate\Database\Migrations\Migration;
use Throwable;

/**
 * Reusable forward/rollback proof harness for tenant migrations.
 *
 * Migration-bearing lanes keep re-inventing "require the file, call up()/
 * down(), assert the schema" by hand (see `T2MigrationRollbackTest`,
 * `BankStatementAggregateSchemaTest`'s old `--step 6` trap). This trait
 * packages that pattern once so a lane just supplies the migration
 * filename(s) and two schema-assertion closures. It manipulates PHYSICAL
 * schema by requiring migration files directly and calling `up()`/`down()`
 * on the returned instances — it never touches `artisan migrate` or the
 * `migrations` bookkeeping table, so it is immune to what has (or hasn't)
 * landed in `database/migrations/tenant/` after the target migration(s).
 * Feeds `docs/superpowers/plans/2026-08-07-remediation-round-2-plan.md`
 * items T-1 and R2-A2.
 *
 * Requires the consuming test to already have a fully migrated tenant
 * schema (`RefreshDatabase`, or an equivalent baseline) before calling
 * either entry point below. None of the target migration(s) may declare
 * `public $withinTransaction = false;` / run `CREATE INDEX CONCURRENTLY`
 * under `RefreshDatabase` (Postgres refuses CONCURRENTLY inside a
 * transaction) — such migrations need the non-transactional setup
 * `T2MigrationRollbackTest` uses instead. {@see requireTenantMigrations()}
 * enforces this at runtime and fails fast with that pointer.
 *
 * Two entry points, chosen by what the migration's own `down()` promises:
 *
 *  - {@see assertTenantMigrationRoundTrips()} — `down()` TRULY reverses
 *    `up()`. Use for ordinary schema migrations (create table, add column,
 *    add index, …) and FK-dependent batches of them.
 *  - {@see assertTenantMigrationIsIrreversibleNoOp()} — `down()` is a
 *    DECLARED no-op (typically a data backfill/correction that cannot be
 *    safely undone once real data may depend on it). Use this instead of
 *    fabricating a reverse assertion that doesn't reflect what the
 *    migration actually does; it proves `down()` changes NOTHING rather
 *    than silently skipping rollback coverage altogether.
 */
trait ProvesTenantMigrationRoundTrip
{
    /**
     * @param  string|list<string>  $filenames  Tenant migration filenames
     *                                          (basename only, as found
     *                                          under `database/migrations/
     *                                          tenant/`), in forward
     *                                          dependency order.
     * @return list<Migration&ReversibleTenantMigration>
     */
    protected function requireTenantMigrations(string|array $filenames): array
    {
        $dir = database_path('migrations/tenant');

        $migrations = [];
        foreach (is_array($filenames) ? $filenames : [$filenames] as $filename) {
            $path = $dir.'/'.$filename;
            $this->assertFileExists($path, "Tenant migration file missing: {$filename}");

            /** @var Migration&ReversibleTenantMigration $migration */
            $migration = require $path;

            // A `CREATE INDEX CONCURRENTLY` migration declares this false
            // because PostgreSQL refuses CONCURRENTLY inside a transaction —
            // exactly the transaction RefreshDatabase wraps every test in.
            // Fail fast with the documented alternative instead of letting
            // the caller hit an opaque "cannot run inside a transaction
            // block" error deep inside up()/down().
            if ($migration->withinTransaction === false) {
                $this->fail(
                    "Tenant migration '{$filename}' declares \$withinTransaction = false ".
                    '(a CREATE INDEX CONCURRENTLY migration, most likely) — this harness runs '.
                    "up()/down() directly under RefreshDatabase's transaction, which PostgreSQL ".
                    'refuses for CONCURRENTLY statements. Use the non-transactional require-and-'.
                    'call pattern from T2MigrationRollbackTest instead.',
                );
            }

            $migrations[] = $migration;
        }

        return $migrations;
    }

    /**
     * Proves a reversible tenant migration (or an ordered, FK-dependent
     * batch of them) round-trips cleanly. Assumes the CALLING test's
     * baseline (e.g. `RefreshDatabase`) has already run every tenant
     * migration once, including the target(s) — true of every test in this
     * suite — so the sequence starts from "already applied", not from an
     * empty schema:
     *
     *   (pre-condition: already applied) -> assertApplied
     *   down() reverse order             -> assertReverted
     *   up() forward order               -> assertApplied   (forward-apply proof)
     *   down() reverse order, again      -> assertReverted
     *   up() forward order, again        -> assertApplied   (idempotency: the
     *       round trip is REPEATABLE, not a lucky one-off — this is the
     *       correct idempotency proof for schema DDL such as CREATE TABLE
     *       or ADD COLUMN, which legitimately errors on a direct
     *       back-to-back double `up()` with no `down()` between; Laravel's
     *       real idempotency guarantee for those lives in the `migrations`
     *       bookkeeping table, not in the migration file itself)
     *
     * @param  string|list<string>  $filenames  Forward dependency order;
     *                                          rolled back in the exact reverse order.
     * @param  Closure(string): void  $assertApplied  Asserts the
     *                                                post-migration schema shape. Receives a human-readable
     *                                                context string (for failure messages) describing which pass
     *                                                is being checked.
     * @param  Closure(string): void  $assertReverted  Asserts the
     *                                                 post-rollback schema shape — the migrated artifacts absent.
     */
    protected function assertTenantMigrationRoundTrips(
        string|array $filenames,
        Closure $assertApplied,
        Closure $assertReverted,
    ): void {
        $migrations = $this->requireTenantMigrations($filenames);

        $assertApplied('as a pre-condition (the baseline migrate already applied it)');

        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }
        $assertReverted('after the first rollback');

        foreach ($migrations as $migration) {
            $migration->up();
        }
        $assertApplied('after the forward-apply proof');

        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }
        $assertReverted('after the second rollback (proving the round trip repeats)');

        foreach ($migrations as $migration) {
            $migration->up();
        }
        $assertApplied('after the second re-apply (proving the round trip repeats)');
    }

    /**
     * Proves a tenant migration whose `down()` is a DECLARED irreversible
     * no-op (a data backfill/correction — see the migration's own `down()`
     * docblock for why): forward apply, idempotent re-apply, then `down()`
     * must change NOTHING (the shape `assertApplied` checks must still
     * hold), then `up()` must remain safe once more. This is the harness's
     * explicit way to handle and report a no-op `down()`: it neither skips
     * rollback coverage nor wrongly asserts the artifacts vanish — a future
     * `down()` that turns destructive fails this the same way a genuine
     * regression would.
     *
     * PRECONDITION: `up()` must be idempotent — this entry point calls it a
     * second time on an already-applied migration, which must not throw.
     * True by design for a compliant data backfill (self-guarding: "already
     * mapped, skip"). NOT true for ordinary schema DDL (`CREATE TABLE`,
     * `ADD COLUMN`, …) — those belong in {@see assertTenantMigrationRoundTrips()}
     * instead, whose idempotency proof is the round trip repeating, not a
     * raw double `up()`.
     *
     * @param  Closure(string): void  $assertApplied
     */
    protected function assertTenantMigrationIsIrreversibleNoOp(
        string $filename,
        Closure $assertApplied,
    ): void {
        [$migration] = $this->requireTenantMigrations($filename);

        // MINOR-5 (gate re-verify): under the harness's documented baseline
        // (schema already fully migrated by RefreshDatabase) THIS first call
        // is already a re-run — it needs the same idempotency guard as the
        // explicit second call below, or the default path surfaces a raw
        // SQLSTATE instead of the named precondition failure.
        try {
            $migration->up();
        } catch (Throwable $e) {
            $this->fail(
                'assertTenantMigrationIsIrreversibleNoOp() requires an idempotent up() — '.
                "calling up() on '{$filename}' against the already-migrated baseline threw ".
                $e::class.': '.$e->getMessage().
                '. This entry point is for data backfills whose up() self-guards against being '.
                're-run; ordinary schema DDL belongs in assertTenantMigrationRoundTrips() instead.',
            );
        }
        $assertApplied('after the initial forward apply');

        try {
            $migration->up();
        } catch (Throwable $e) {
            $this->fail(
                'assertTenantMigrationIsIrreversibleNoOp() requires an idempotent up() — '.
                "calling up() a second time on '{$filename}' threw ".$e::class.': '.$e->getMessage().
                '. This entry point is for data backfills whose up() self-guards against being '.
                're-run; ordinary schema DDL (CREATE TABLE / ADD COLUMN, …) belongs in '.
                'assertTenantMigrationRoundTrips() instead.',
            );
        }
        $assertApplied('after an idempotent re-apply');

        $migration->down();
        $assertApplied('after down() — declared irreversible no-op, schema/data must be unchanged');

        $migration->up();
        $assertApplied('after re-applying following the no-op down()');
    }
}
