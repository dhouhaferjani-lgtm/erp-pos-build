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
 *  (a) THE LOCATION HAS AT LEAST ONE REAL TILL, IN ANY STATE. Read from
 *      `pos_terminals.location_id` with NO filter on `deleted_at`,
 *      `is_active` or `hardware_identifier`. A terminal that was ever
 *      provisioned there is proof somebody decided that location sells; an
 *      archived or deactivated terminal is proof of the same past decision,
 *      and re-activating it is exactly the flow the refusal would otherwise
 *      block. This branch is the honest one and carries most of the repair.
 *
 *      IT DOES FILTER ON `type`, and must (gate r1 / P1-1). `pos_terminals`
 *      also holds SERVER-authored rows: `VirtualAdminTerminalResolver::resolve()`
 *      mints a single `virtual_admin` terminal per company at whatever location
 *      is OLDEST — no `type` filter, no `pos_enabled` filter — triggered by
 *      ordinary back-office actions (`RecordCustomerDepositService`,
 *      `CustomerAccountStatusService`). In the canonical warehouse-first shape
 *      of this codebase (`DemoPharmacySeeder` creates `WH-01` before its four
 *      shops) that row lands on a WAREHOUSE, and an unfiltered branch (a) would
 *      flip it on — contradicting the "never enable warehouses" contract below
 *      by the back door. Worse, it would be PERMANENT: the update is scoped
 *      `where pos_enabled = false` and never writes false, so a corrected
 *      re-run cannot undo an over-enable; the repair would be manual, per
 *      location, per tenant, and invisible until someone audited. Hence
 *      {@see self::TILL_TERMINAL_TYPES}. Pinned by
 *      `test_a_virtual_admin_terminal_is_not_evidence_that_a_location_sells`
 *      and its `web`-still-counts contrast case.
 *
 *  (b) THE COMPANY'S SHOP-LIKE PRIMARY LOCATION. `type = 'shop'` AND
 *      (`is_default = true` OR it is the company's only location). This is the
 *      auto-provisioned "Main Location" — the three writers create it with
 *      `type = 'shop'`, `is_default = true`, `code = 'MAIN'`, and the same
 *      ruling's parent-delegated provisioning sub-ruling flips those writers to
 *      `pos_enabled = true` going forward.
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
 *    `updated_at`. Nothing here ever sets `pos_enabled` back to false, so a
 *    location an operator switches OFF between deploys is not re-enabled by a
 *    re-run. Stated precisely, because the weaker claim is the true one: on the
 *    FIRST run a matching location IS enabled even if somebody had unticked the
 *    box before this deploy. That is deliberate — until this deploy the flag
 *    was decorative, read by no backend decision, so a pre-deploy `false`
 *    records no reliable intent and cannot be honoured. From this deploy
 *    forward the tick means something and the migration never overrides it.
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

    /**
     * The `pos_terminals.type` values that count as a TILL for branch (a).
     *
     * `App\Modules\POS\Domain\Enums\TerminalType` has three cases — `web`,
     * `physical` and `virtual_admin`. Only the first two are tills. The third
     * is server-authored: `VirtualAdminTerminalResolver::resolve()` mints one
     * per company, at whatever location is OLDEST, on ordinary back-office
     * actions — see the `(a)` note in the class docblock for why counting it
     * would be wrong and unrepairable.
     *
     * Spelled as literal strings, not `TerminalType::Web->value`, on purpose: a
     * migration is a historical record and must keep meaning what it meant on
     * the day it ran, even if the enum is later renamed or re-cased. The column
     * is `string(20)` (`2026_02_19_000002_add_type_to_pos_terminals.php:16`),
     * `virtual_admin` was added by `2026_05_22_101000`.
     *
     * A FOURTH ENUM CASE, added later, is therefore NOT evidence until someone
     * adds it here — and that is the deliberate, fail-safe direction, not an
     * oversight. Under-enabling leaves a location POS-disabled that perhaps
     * should not be, which an operator repairs with one tick in Settings.
     * Over-enabling is PERMANENT: this migration is scoped
     * `where pos_enabled = false` and never writes false, so a wrong `true`
     * survives every re-run and can only be undone by hand, per location, per
     * tenant. When in doubt, leave a new type out.
     *
     * Legacy rows cannot slip through the filter either: the column was added
     * as `->default('physical')` NOT NULL, so every pre-2026-02-19 terminal was
     * backfilled to `physical` and no row carries NULL or ''.
     *
     * @var list<string>
     */
    private const TILL_TERMINAL_TYPES = ['physical', 'web'];

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

                        // (a) the location has a REAL TILL, in any state.
                        if ($hasTerminals) {
                            $query->orWhereIn('id', function (Builder $withTerminal): void {
                                $withTerminal->from('pos_terminals')
                                    ->select('location_id')
                                    ->whereNotNull('location_id')
                                    ->whereIn('type', self::TILL_TERMINAL_TYPES);
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
