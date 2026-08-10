<?php

declare(strict_types=1);

use App\Console\Commands\BackfillChartPurposesCommand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Run the country-chart purpose backfill unattended, on every tenant database.
 *
 * SEEDS gate finding I-1. The lane widened
 * `SystemAccountPurpose::requiredPurposes()` with `CostOfGoodsSold` and
 * `GeneralExpense` and seeded their French homes (`603` / `628`), but a chart is
 * written ONCE at provisioning and the seeders never rewrite an existing row —
 * so every already-provisioned French company keeps booking zero COGS
 * (`PostCOGSOnInvoice` swallows the resolution failure) and keeps throwing on a
 * discounted POS `ACCOUNT_CHARGE` (`GeneralLedgerService::createPOSChargeEntry`
 * resolves `SalesDiscount` via `findByPurposeOrFail`) until the backfill runs.
 * The widening ships automatically on push; a console command documented only in
 * a task report does not. Register H-5's own sketch says "backfill BEFORE
 * widening requiredPurposes()", so the repair must be as unattended as the
 * widening — the shape the three sibling backfills already use
 * (`2026_08_05_120000_backfill_sales_rounding_difference_accounts.php`,
 * `2026_08_07_100000_backfill_purchase_stamp_duty_account.php`,
 * `2026_06_30_120000_backfill_sales_stamp_duty_account.php`).
 *
 * DELEGATES rather than duplicates: the logic lives in
 * {@see BackfillChartPurposesCommand}, which carries the house contract
 * (PURPOSE FIRST then CODE, type/active validation, never invents a parent,
 * refuses to repurpose) and is directly tested. Duplicating ~200 lines here
 * would guarantee drift between the automatic and the manual repair path.
 * Precedent for invoking a command from a tenant migration:
 * `2026_07_16_100100_register_manage_location_access_permission.php:15`.
 *
 * SELF-GUARDING AND IDEMPOTENT — `tenants:migrate` runs on push, on every
 * tenant, unattended:
 *  - the tables it needs are checked first, so a run outside a tenant context
 *    (or before the accounting tables exist) is a quiet no-op;
 *  - the command is idempotent: a chart that already resolves a purpose — on
 *    ANY code — is reported and left untouched;
 *  - it NEVER throws here, and the failure is CONTAINED IN A SAVEPOINT so the
 *    enclosing migration transaction survives it (see up(); on PostgreSQL a
 *    caught QueryException would otherwise leave the whole transaction aborted).
 *    The command returns FAILURE (and its gate token) when a chart could not be
 *    placed, e.g. a chart missing the parent class header; that is an operator
 *    follow-up, not a reason to abort a tenant's whole migration run. The full
 *    command output, token included, is written to the log so the same evidence
 *    is available after an unattended deploy.
 */
return new class extends Migration
{
    /**
     * Deploy-gate token for the AUTOMATIC (`tenants:migrate`) path.
     *
     * Deliberately NOT the command's own `CHART-PURPOSE BACKFILL FAILURES:`
     * token: that one belongs to the manual `tenants:run` channel, and reusing
     * it would let either gate be satisfied by the other channel's output. One
     * line per tenant, exactly once (a migration runs once per tenant database),
     * carrying `status=ok` or `status=FAILED` so the checklist never has to
     * parse a count out of a log line.
     */
    private const GATE_TOKEN = 'CHART-PURPOSE BACKFILL MIGRATION:';

    public function up(): void
    {
        if (! Schema::hasTable('companies') || ! Schema::hasTable('accounts')) {
            return;
        }

        // Under `tenants:migrate` all tenants share one laravel.log, so an
        // unattributed line cannot be acted on (gate finding N-4).
        $tenantKey = (string) (tenant()?->getTenantKey() ?? 'unknown');

        try {
            $exitCode = 1;
            $output = '';

            // SAVEPOINT, not a bare call. `migrate` wraps each migration in a
            // transaction (Migrator::runMigration + PostgresGrammar::$transactions),
            // and on PostgreSQL a failed statement aborts that WHOLE transaction
            // (SQLSTATE 25P02) — catching the exception below would leave the
            // connection unusable, so the migration repository's own bookkeeping
            // INSERT would fail and the tenant's run would die anyway.
            // DB::transaction() opens a SAVEPOINT when a transaction is already
            // active and rolls back to it alone, which is what makes the catch
            // below an honest guarantee. Proven by
            // BackfillChartPurposesMigrationTest::test_a_failing_backfill_does_not_poison_the_enclosing_migration_transaction.
            // Bound to the MIGRATION's own connection to mirror how the framework
            // itself resolves it (Migrator::runMigration). getConnection() is null
            // here, and even under `migrate --database=X` the migrator SETS the
            // default connection to X before running (MigrateCommand/Migrator), so
            // this is byte-identical to a bare DB::transaction() today — a
            // defensive alignment with the enclosing transaction's connection,
            // not a divergence guard.
            DB::connection($this->getConnection())->transaction(function () use (&$exitCode, &$output): void {
                $exitCode = Artisan::call(BackfillChartPurposesCommand::class);
                $output = trim(Artisan::output());
            });

            // Per-company detail (which chart, which reason) — info level, so it
            // survives only where LOG_LEVEL admits it. Production does not; the
            // remedy there is a manual `tenants:run` re-run, which prints all of it.
            Log::info(sprintf(
                "Migration backfill_chart_purposes [tenant %s]: command exited %d.\n%s",
                $tenantKey,
                $exitCode,
                $output,
            ));

            // DEPLOY GATE LINE — WARNING, not info, because production runs
            // LOG_LEVEL=warning (.env.production.example:33) and drops info
            // entirely; an info-level gate line makes the checklist's automatic
            // grep pass on an EMPTY log (gate finding N-2).
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
            // unattended migration run for every other tenant. Same gate token at
            // error level, so the checklist's failure grep catches this path too.
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
        // Data correction (creates missing system accounts and maps purposes onto
        // existing ones). Not reversed: the accounts may already carry posted
        // journal lines, and unmapping the purposes would return French tenants to
        // booking zero COGS.
    }
};
