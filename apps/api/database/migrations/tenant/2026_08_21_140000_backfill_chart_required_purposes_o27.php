<?php

declare(strict_types=1);

use App\Console\Commands\BackfillChartPurposesCommand;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * O-27 / F-4 — re-run the chart purpose backfill for the fourteen purposes it
 * gained this lane, unattended, on every tenant database.
 *
 * WHY A SECOND MIGRATION. `2026_08_10_090000_backfill_chart_purposes.php`
 * already wraps {@see BackfillChartPurposesCommand}, but a migration runs ONCE
 * per tenant database and every existing tenant has that row in `migrations`
 * already. The command's definition table has since grown from six purposes to
 * twenty (O-27), so the repair has to be re-invoked under a new name. This file
 * is a deliberate near-copy of that one — same guard ladder, same savepoint,
 * same log-never-throw contract — because the shape is the reviewed one, not
 * because the duplication is accidental.
 *
 * WHY IT MUST RUN AT ALL. Pushing to `origin/dev` auto-deploys and runs
 * `tenants:migrate` unattended. In the SAME deploy,
 * {@see SystemAccountPurpose::requiredPurposes()} starts deriving from
 * `ProvisioningRequiredPurposesV1`, so live-tenant validation goes from
 * checking fourteen purposes to checking twenty-eight. Without this migration,
 * every brownfield tenant would begin reporting unhealthy on the fourteen new
 * ones with no repair having been offered. The ruling is explicit: backfill
 * FIRST, then widen.
 *
 * ORDERING — why the widening cannot land before the fill. The widened set has
 * exactly one production consumer chain:
 * `AccountPurposeController::validate()` -> `ChartOfAccountsService::validateCompanyAccounts()`,
 * an authenticated HTTP endpoint. NOTHING validates at boot, in a migration, in
 * a seeder or in provisioning — `ChartOfAccountsService::seedForCompany()` does
 * not call it, and no migration in `database/migrations/tenant/` references it.
 * So the widening cannot fire during the migration run itself, and the only
 * window in which a tenant could observe the widened set unfilled is between
 * the deploy releasing the new code and `tenants:migrate` reaching that
 * tenant's database. In that window the endpoint reports the purposes as
 * missing — which is TRUE and is precisely the condition this migration then
 * repairs. It is a read-only report, never a refusal: no posting path consults
 * `requiredPurposes()`.
 *
 * SELF-GUARDING AND IDEMPOTENT, as `tenants:migrate` demands:
 *  - CHARTLESS TENANT: the `companies` / `accounts` table check returns early
 *    and emits `status=skipped` on the gate token, so a run before the
 *    accounting tables exist is a no-op that is still ACCOUNTED FOR — see the
 *    comment at the guard for why silence there would be indistinguishable
 *    from a crashed tenant. A company that exists but has no chart rows at all
 *    is a different case: it reaches the command, finds no parent for
 *    anything, and reports — it never invents a chart.
 *  - ALREADY COMPLETE: the command is PURPOSE-FIRST, so a chart that already
 *    resolves a purpose on ANY code is counted `satisfied` and left untouched.
 *    Re-running is a no-op; the earlier migration having filled the original
 *    six changes nothing here.
 *  - UNMAPPABLE PURPOSE: reported per (company, purpose) on the command's
 *    `UNMAPPED REQUIRED` token and never guessed. It does not throw, does not
 *    fail the migration, and deliberately leaves live-tenant validation
 *    reporting that tenant unhealthy — the visible-failure outcome the ruling
 *    asks for, rather than a silently invented account code.
 *  - NEVER THROWS, and the failure is CONTAINED IN A SAVEPOINT so the enclosing
 *    migration transaction survives it. On PostgreSQL a caught QueryException
 *    would otherwise leave the whole transaction aborted (SQLSTATE 25P02) and
 *    the migration repository's own bookkeeping INSERT would fail, killing the
 *    tenant's entire run. `DB::transaction()` opens a SAVEPOINT when a
 *    transaction is already active and rolls back to it alone, which is what
 *    makes the catch below an honest guarantee. Proven FOR THIS MIGRATION by
 *    `BackfillChartRequiredPurposesO27MigrationTest::test_a_failing_backfill_does_not_poison_the_enclosing_migration_transaction`
 *    (`tests/Feature/Accounting/BackfillChartRequiredPurposesO27MigrationTest.php:279`),
 *    which runs green on a real PostgreSQL database and is skipped on sqlite —
 *    only PostgreSQL aborts the enclosing transaction after a failed statement.
 *    The identically-shaped case on the 2026-08-10 sibling proves the sibling,
 *    not this file; each migration carries its own proof.
 *
 * RESIDUAL, DOCUMENTED. For a French-plan chart the command now maps eighteen
 * of the twenty-eight REQUIRED purposes; the ten it does not are exactly the
 * ones `requiredPurposes()` ALREADY checked before this lane
 * (`bank`, `cash`, `customer_receivable`, `supplier_payable`, `vat_collected`,
 * `vat_deductible`, `product_revenue`, `service_revenue`,
 * `opening_balance_equity`, `inventory_shrinkage_expense`). A tenant missing
 * one of those was already failing validation loudly BEFORE the widening, so
 * the widening introduces no new unrepaired failure — it only adds purposes
 * that this migration fills in the same run.
 */
return new class extends Migration
{
    /**
     * Deploy-gate token for the AUTOMATIC (`tenants:migrate`) path.
     *
     * Distinct from both the command's own `CHART-PURPOSE BACKFILL FAILURES:`
     * token (the manual `tenants:run` channel) and the 2026-08-10 migration's
     * token, so no gate can be satisfied by another channel's or another
     * deploy's output. One line per tenant, exactly once.
     */
    private const GATE_TOKEN = 'CHART-PURPOSE O27 BACKFILL MIGRATION:';

    public function up(): void
    {
        // Under `tenants:migrate` all tenants share one laravel.log, so an
        // unattributed line cannot be acted on. Resolved BEFORE the table guard
        // because the guard now reports too.
        $tenantKey = (string) (tenant()?->getTenantKey() ?? 'unknown');

        if (! Schema::hasTable('companies') || ! Schema::hasTable('accounts')) {
            // REPORTS, does not return silently. The deploy gate's second half
            // asserts one token line PER TENANT, precisely because ABSENCE of
            // the token means the migration died before finishing. A silent
            // early return is indistinguishable from that failure at the log,
            // so a database legitimately without the accounting tables would
            // read as a crashed tenant. `status=skipped` is a third value
            // alongside `ok` and `FAILED`, so it satisfies the per-tenant count
            // without ever matching a `status=FAILED` failure grep.
            Log::warning(sprintf(
                '%s tenant=%s status=skipped reason=accounting-tables-absent.',
                self::GATE_TOKEN,
                $tenantKey,
            ));

            return;
        }

        try {
            $exitCode = 1;
            $output = '';

            DB::connection($this->getConnection())->transaction(function () use (&$exitCode, &$output): void {
                $exitCode = Artisan::call(BackfillChartPurposesCommand::class);
                $output = trim(Artisan::output());
            });

            // Per-company detail (which chart, which reason, which purposes had
            // no mapping) — info level, so it survives only where LOG_LEVEL
            // admits it. Production does not; the remedy there is a manual
            // `tenants:run`, which prints all of it.
            Log::info(sprintf(
                "Migration backfill_chart_required_purposes_o27 [tenant %s]: command exited %d.\n%s",
                $tenantKey,
                $exitCode,
                $output,
            ));

            // DEPLOY GATE LINE — WARNING, not info, because production runs
            // LOG_LEVEL=warning and drops info entirely; an info-level gate line
            // makes a checklist's automatic grep pass on an EMPTY log.
            Log::warning(sprintf(
                '%s tenant=%s status=%s exit=%d.%s',
                self::GATE_TOKEN,
                $tenantKey,
                $exitCode === 0 ? 'ok' : 'FAILED',
                $exitCode,
                $exitCode === 0
                    ? ''
                    : ' At least one chart could not be placed. Re-run'
                        .' `php artisan tenants:run accounting:backfill-chart-purposes` for the per-company'
                        .' reasons, then assign the purpose in Settings -> Chart of Accounts or add the'
                        .' missing parent account.',
            ));
        } catch (Throwable $e) {
            // A tenant whose chart cannot be repaired must not brick the whole
            // unattended migration run for every other tenant. Same gate token
            // at error level, so a failure grep catches this path too.
            Log::error(sprintf(
                '%s tenant=%s status=FAILED exit=exception. %s',
                self::GATE_TOKEN,
                $tenantKey,
                $e->getMessage(),
            ));
        }
    }

    public function down(): void
    {
        // Data correction (creates missing system accounts and maps purposes
        // onto existing ones). Not reversed: the accounts may already carry
        // posted journal lines, and unmapping the purposes would return tenants
        // to the pre-O-27 state where a chart that cannot receive goods reports
        // itself healthy.
    }
};
