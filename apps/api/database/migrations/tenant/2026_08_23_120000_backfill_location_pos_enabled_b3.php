<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Owner ruling B-3, 2026-08-23 — make `locations.pos_enabled` true for the
 * locations that are DEMONSTRABLY POS locations, before the flag starts being
 * enforced.
 *
 * WHY IT MUST RUN, AND IN THIS DEPLOY. `pos_enabled` shipped in the original
 * `create_locations_table` migration (2025_11_30_105000) with `default(false)`
 * and, until this lane, no backend decision ever read it — the three
 * auto-provisioning writers set it to false and `LocationResource` echoed it
 * back to a checkbox nobody's answer changed anything about. In the SAME deploy
 * as this migration, `TerminalController` starts REFUSING claim / request /
 * create / web-terminal at a location whose `pos_enabled` is false, and
 * `available()` stops offering those terminals at all. Every brownfield tenant
 * whose locations still carry the born-false default would therefore find its
 * existing tills unusable the moment the code lands. This migration repairs
 * that BEFORE the refusal can bite, using evidence already in the tenant's own
 * data rather than a blanket `UPDATE locations SET pos_enabled = true`.
 *
 * THE PREDICATE — two branches, both evidence-based, both deliberately narrow:
 *
 *  (a) THE LOCATION HAS AT LEAST ONE POS TERMINAL, ANY STATE. Read from
 *      `pos_terminals.location_id` with NO filter on `deleted_at`,
 *      `is_active`, `type` or `hardware_identifier`. A terminal that was ever
 *      provisioned there is proof somebody decided that location sells; an
 *      archived or deactivated terminal is proof of the same past decision,
 *      and re-activating it is exactly the flow the refusal would otherwise
 *      block. This branch is the honest one and carries most of the repair.
 *
 *  (b) THE COMPANY'S SHOP-LIKE PRIMARY LOCATION. `type = 'shop'` AND
 *      (`is_default = true` OR it is the company's only location). This is the
 *      auto-provisioned "Main Location" — the three writers create it with
 *      `type = 'shop'`, `is_default = true`, `code = 'MAIN'`, and the same
 *      ruling (A2) flips those writers to `pos_enabled = true` going forward.
 *      Branch (b) simply gives the tenants already provisioned the default
 *      they would get if they registered today. It is matched on `type` and
 *      `is_default`, NOT on `code = 'MAIN'`: a tenant that renamed or recoded
 *      its head location is the same case.
 *
 * WHAT IS DELIBERATELY NOT ENABLED. `LocationType` has exactly four cases —
 * `shop`, `warehouse`, `office`, `mobile`
 * (app/Modules/Company/Domain/Enums/LocationType.php). Only `shop` is treated
 * as shop-like in branch (b). A warehouse is never enabled by (b) — the ruling
 * is explicit about that, and `DemoPharmacySeederTest` pins that a warehouse
 * must stay POS-disabled. `office` is not a sales floor. `mobile` is genuinely
 * ambiguous (a delivery van may or may not take payment) and so is left to
 * branch (a): a mobile location that actually has a terminal IS enabled, one
 * that does not is left for a human to switch on. A secondary shop in a
 * multi-location company with no terminal is likewise left alone — there is no
 * evidence it sells, and enabling it would be inventing a business decision.
 *
 * SELF-GUARDING AND IDEMPOTENT, as `tenants:migrate` demands (pushing to
 * origin/dev auto-deploys and runs it unattended on every tenant database):
 *  - `locations` ABSENT: reports `status=skipped` and returns. Like the O-27
 *    precedent, it REPORTS rather than returning silently, because absence of
 *    the token line is how a deploy gate detects a tenant that died mid-run —
 *    a silent skip is indistinguishable from a crash at the log.
 *  - `pos_terminals` ABSENT: branch (a) is dropped and branch (b) still runs,
 *    reported as `terminals-table-absent`. A tenant database without the POS
 *    tables has no terminals to protect, but its Main Location should still
 *    match a tenant registered today.
 *  - IDEMPOTENT: the update is scoped `where('pos_enabled', false)`, so a
 *    second run matches zero rows and writes nothing — it does not even touch
 *    `updated_at`. It is also NON-DESTRUCTIVE in the other direction: nothing
 *    here ever sets `pos_enabled` back to false, so a location an operator
 *    deliberately switched OFF between deploys is not re-enabled by a re-run.
 *  - NEVER THROWS, and the failure is CONTAINED IN A SAVEPOINT. On PostgreSQL
 *    a caught QueryException inside the migrator's own transaction would leave
 *    that transaction aborted (SQLSTATE 25P02) and the migration repository's
 *    bookkeeping INSERT would then fail, killing the tenant's entire run.
 *    `DB::transaction()` opens a SAVEPOINT when a transaction is already
 *    active and rolls back to that alone, which is what makes the catch below
 *    an honest guarantee. Pinned for THIS migration by
 *    `BackfillLocationPosEnabledB3MigrationTest::test_a_failing_backfill_does_not_poison_the_enclosing_migration_transaction`.
 *
 * PORTABILITY. Written with the query builder, not raw SQL, because the
 * default (SQLite) test harness runs every tenant migration under
 * `RefreshDatabase`. `having count(*) = 1` and the self-referencing subquery
 * are both valid on PostgreSQL and SQLite.
 */
return new class extends Migration
{
    /**
     * Deploy-gate token for the AUTOMATIC (`tenants:migrate`) path. One line
     * per tenant, exactly once, at WARNING level — production runs
     * LOG_LEVEL=warning and drops info entirely, so an info-level gate line
     * would let a checklist's grep pass against an empty log.
     */
    private const GATE_TOKEN = 'LOCATION POS-ENABLED B3 BACKFILL MIGRATION:';

    public function up(): void
    {
        // Under `tenants:migrate` all tenants share one laravel.log, so an
        // unattributed line cannot be acted on.
        $tenantKey = (string) (tenant()?->getTenantKey() ?? 'unknown');

        if (! Schema::hasTable('locations')) {
            Log::warning(sprintf(
                '%s tenant=%s status=skipped reason=locations-table-absent.',
                self::GATE_TOKEN,
                $tenantKey,
            ));

            return;
        }

        $hasTerminals = Schema::hasTable('pos_terminals');

        try {
            $enabled = 0;

            DB::connection($this->getConnection())->transaction(function () use (&$enabled, $hasTerminals): void {
                $enabled = DB::connection($this->getConnection())
                    ->table('locations')
                    ->where('pos_enabled', false)
                    ->where(function (Builder $query) use ($hasTerminals): void {
                        // (b) the company's shop-like primary location.
                        $query->where(function (Builder $shop): void {
                            $shop->where('type', 'shop')
                                ->where(function (Builder $primary): void {
                                    $primary->where('is_default', true)
                                        ->orWhereIn('company_id', function (Builder $sole): void {
                                            $sole->from('locations')
                                                ->select('company_id')
                                                ->groupBy('company_id')
                                                ->havingRaw('count(*) = 1');
                                        });
                                });
                        });

                        // (a) the location has a terminal, in any state.
                        if ($hasTerminals) {
                            $query->orWhereIn('id', function (Builder $withTerminal): void {
                                $withTerminal->from('pos_terminals')
                                    ->select('location_id')
                                    ->whereNotNull('location_id');
                            });
                        }
                    })
                    ->update([
                        'pos_enabled' => true,
                        'updated_at' => now(),
                    ]);
            });

            Log::warning(sprintf(
                '%s tenant=%s status=ok enabled=%d%s',
                self::GATE_TOKEN,
                $tenantKey,
                $enabled,
                $hasTerminals ? '.' : ' reason=terminals-table-absent (evidence branch skipped).',
            ));
        } catch (Throwable $e) {
            // One tenant's failure must not brick the unattended run for every
            // other tenant. Same gate token so a `status=FAILED` grep catches
            // this path too. A tenant left unrepaired reports honestly: its
            // operators see LOCATION_POS_DISABLED and can switch the location
            // on in Settings, which is a visible failure rather than a silent
            // blanket enable.
            Log::error(sprintf(
                '%s tenant=%s status=FAILED reason=exception. %s',
                self::GATE_TOKEN,
                $tenantKey,
                $e->getMessage(),
            ));
        }
    }

    public function down(): void
    {
        // Not reversed. This is a data correction, and the rows it touched are
        // indistinguishable afterwards from rows an operator enabled by hand in
        // Settings — a down() would switch those off too and take working tills
        // offline. Re-disabling a location is a one-click operation in the UI.
    }
};
