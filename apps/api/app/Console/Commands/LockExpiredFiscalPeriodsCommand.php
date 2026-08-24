<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Application\Services\FiscalPeriodAutoLockService;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Tenant;

/**
 * Automatically lock expired fiscal periods and close ended fiscal years.
 *
 * THREE-STEP AUTO-LOCK PROCESS (unchanged — see FiscalPeriodAutoLockService):
 * - STEP 1: Close periods that ended more than the COMPANY's own country threshold ago
 *   (Open → Closed), skipping any period a human reopened
 * - STEP 2: Mark fiscal years as closed when their end_date has passed, unless one of
 *   their periods is open for correction
 * - STEP 3: Lock all periods in closed fiscal years (Open/Closed → Locked)
 *
 * SCHEDULING:
 * - Runs: Daily at 1:00 AM (routes/console.php), in-process, withoutOverlapping(),
 *   with an ->onFailure() Log::error hook.
 * - `batch-expiry:daily-check` runs at 01:30 — keep that gap.
 *
 * Tenant-isolation: cat-(a-per-tenant-iter). `fiscal_periods` and `fiscal_years`
 * are TENANT tables, and until 2026-08-05 this command called
 * `FiscalPeriodAutoLockService::lockExpiredPeriods()` ONCE, on whatever
 * connection the scheduler happened to hold — which under database-per-tenant
 * (flipped 2026-05-28) is CENTRAL, where neither table exists. Two things then
 * hid the breakage from every operator signal:
 *
 *   1. `handle()` wrapped the call in `catch (\Exception)` and converted the
 *      42P01 into a FAILURE exit with a console message nobody reads;
 *   2. the schedule entry used `runInBackground()` with NO `->onFailure()`, so
 *      that exit code was never observed.
 *
 * Result: the nightly auto-lock had been silently dead for months. Periods that
 * should have been Closed/Locked stayed Open — which in a compliance-oriented
 * ERP means posting into a period the law considers shut.
 *
 * The swallow is GONE. A per-tenant throw now propagates into
 * {@see TenantScopedCommand::forEachTenant()}, which logs it with the tenant id
 * and aggregates a non-zero exit while still processing the remaining tenants —
 * so one broken tenant no longer costs the fleet its nightly lock, and the
 * failure reaches the scheduler's onFailure() hook.
 *
 * **The 2026-08-05 tenant conversion left the locking business logic untouched.**
 * The service carries no `tenant_id` predicate and none was added: `fiscal_periods`
 * / `fiscal_years` have no such column (they are anchored by `company_id`), and
 * every step is idempotent, so the redundant re-run under legacy row-level mode
 * (`tenancy_resolver.db_per_tenant=false`, where forEachTenant does not switch
 * databases) is a no-op after the first pass. Changing the predicates would be
 * a fiscal-behaviour change, which that conversion explicitly was not.
 *
 * Idempotency survived the Session B lane Q-10 rewrite (2026-08-24), which replaced
 * the three set-based bulk `->update(['status' => …])` statements this docblock used
 * to describe with a per-company, per-row `chunkById` walk that stamps the transition
 * audit columns. The bulk updates are GONE; the per-row path excludes an
 * already-transitioned row by the same status predicates, so a second run in the same
 * night still changes nothing (`FiscalPeriodAutoLockServiceTest
 * ::test_it_does_not_modify_already_locked_periods` asserts `updated_at` is not even
 * bumped). The same rewrite made the country window per-company and made the scheduler
 * step around a human reopen — see FiscalPeriodAutoLockService's docblock.
 *
 * TROUBLESHOOTING:
 * - Manual execution: php artisan fiscal:lock-expired-periods
 * - Preview: php artisan fiscal:lock-expired-periods --dry-run
 * - Per-tenant failures: application error log, `TenantScopedCommand::forEachTenant`
 *   entry (tenant_id + exception).
 *
 * @see FiscalPeriodAutoLockService For implementation details
 */
final class LockExpiredFiscalPeriodsCommand extends TenantScopedCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fiscal:lock-expired-periods
                            {--dry-run : Display what would be locked without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Lock expired fiscal periods and close ended fiscal years, per tenant';

    public function __construct(
        CompanyContext $companyContext,
        private readonly FiscalPeriodAutoLockService $autoLockService,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $this->info('Starting fiscal period auto-lock process...');

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN MODE: No changes will be made');

            return self::SUCCESS;
        }

        $exit = $this->forEachTenant(function (Tenant $tenant): int {
            // Deliberately NOT wrapped in a try/catch: forEachTenant() logs the
            // throw with the tenant id, records that tenant as a FAILURE and
            // continues with the rest of the fleet. Catching here is what turned
            // a fleet-wide 42P01 into a silent nightly no-op.
            $this->autoLockService->lockExpiredPeriods();

            return self::SUCCESS;
        });

        if ($exit === self::SUCCESS) {
            $this->info('✓ Successfully locked expired periods and closed ended fiscal years');
        } else {
            $this->error('✗ One or more tenants failed the fiscal period auto-lock — see the application error log (TenantScopedCommand::forEachTenant entries) for the tenant ids.');
        }

        return $exit;
    }
}
