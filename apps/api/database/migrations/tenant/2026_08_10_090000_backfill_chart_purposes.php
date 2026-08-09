<?php

declare(strict_types=1);

use App\Console\Commands\BackfillChartPurposesCommand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
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
 *  - it NEVER throws here. The command returns FAILURE (and its gate token)
 *    when a chart could not be placed, e.g. a chart missing the parent class
 *    header; that is an operator follow-up, not a reason to abort a tenant's
 *    whole migration run. The full command output, token included, is written
 *    to the log so the same evidence is available after an unattended deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies') || ! Schema::hasTable('accounts')) {
            return;
        }

        try {
            $exitCode = Artisan::call(BackfillChartPurposesCommand::class);
            $output = trim(Artisan::output());

            Log::info(sprintf(
                "Migration backfill_chart_purposes: command exited %d.\n%s",
                $exitCode,
                $output,
            ));

            if ($exitCode !== 0) {
                Log::warning(
                    'Migration backfill_chart_purposes: at least one chart could not be placed '
                    .'(see the CHART-PURPOSE BACKFILL FAILURES token above). Assign the purpose '
                    .'manually in Settings -> Chart of Accounts, or add the missing parent account.',
                );
            }
        } catch (Throwable $e) {
            // A tenant whose chart cannot be repaired must not brick the whole
            // unattended migration run for every other tenant.
            Log::error('Migration backfill_chart_purposes failed: '.$e->getMessage());
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
