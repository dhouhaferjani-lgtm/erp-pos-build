<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Application\Services\FiscalYearCreationService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Tenant;

/**
 * Backfill fiscal years for companies that don't have them.
 *
 * Usage:
 *   php artisan fiscal-years:backfill --tenant=<uuid>
 *   php artisan fiscal-years:backfill --tenant=<uuid> --company=<uuid>
 *   php artisan fiscal-years:backfill --all-tenants
 *
 * Tenant-isolation: cat-(a-per-tenant-iter) behind an EXPLICIT scope,
 * converted 2026-08-05 (cat-(b) wave 2).
 *
 * The command used to run `Company::doesntHave('fiscalYears')->get()` on the
 * console's CENTRAL connection. `companies` and `fiscal_years` are TENANT
 * tables, so after the 2026-05-28 database-per-tenant flip it raised 42P01 and
 * backfilled nothing.
 *
 * A company left without fiscal years cannot post — so a backfill that
 * silently skips a tenant is exactly the failure this wave exists to stop.
 * The scope must be named: `--tenant=<uuid>` or `--all-tenants`.
 */
final class BackfillFiscalYears extends TenantScopedCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fiscal-years:backfill
                            {--tenant= : Tenant UUID to backfill (required unless --all-tenants)}
                            {--all-tenants : Deliberate fleet-wide run over every reachable tenant}
                            {--company= : Specific company UUID to backfill}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create fiscal years for companies missing them (per tenant)';

    public function __construct(
        CompanyContext $companyContext,
        private readonly FiscalYearCreationService $service,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $companyFilter = $this->stringOption('company');

        $companiesTouched = 0;
        $successCount = 0;
        $failureCount = 0;

        $exit = $this->forEachExplicitlySelectedTenant(
            $this->stringOption('tenant'),
            $this->option('all-tenants') === true,
            function (Tenant $tenant) use ($companyFilter, &$companiesTouched, &$successCount, &$failureCount): int {
                // The explicit tenant_id predicate is redundant under
                // database-per-tenant and load-bearing in single-schema
                // compatibility mode.
                $companies = Company::query()
                    ->where('tenant_id', $tenant->id)
                    ->when(
                        $companyFilter !== null,
                        fn ($query) => $query->where('id', $companyFilter),
                        fn ($query) => $query->doesntHave('fiscalYears'),
                    )
                    ->get();

                if ($companies->isEmpty()) {
                    $this->info(sprintf(
                        'TENANT %s (%s): no companies need fiscal years.',
                        $tenant->id,
                        $tenant->slug,
                    ));

                    return self::SUCCESS;
                }

                $this->info(sprintf(
                    'TENANT %s (%s): backfilling %d company/companies...',
                    $tenant->id,
                    $tenant->slug,
                    $companies->count(),
                ));

                $tenantExit = self::SUCCESS;

                foreach ($companies as $company) {
                    $companiesTouched++;

                    if ($company->fiscalYears()->exists()) {
                        $this->warn("  Company '{$company->name}' already has fiscal years.");

                        continue;
                    }

                    try {
                        $this->service->createFiscalYearsForCompany($company);
                        $successCount++;
                        $this->info("  ✓ Created fiscal years for company: {$company->name}");
                    } catch (\Throwable $e) {
                        $failureCount++;
                        // A company left without fiscal years cannot post, so a
                        // per-company failure must degrade the exit code —
                        // before the conversion the fleet path swallowed it and
                        // still returned SUCCESS.
                        $tenantExit = self::FAILURE;
                        $this->error("  ✗ Failed for {$company->name} ({$company->id}): {$e->getMessage()}");
                    }
                }

                return $tenantExit;
            },
        );

        if ($exit !== self::SUCCESS) {
            return $exit;
        }

        if ($companiesTouched === 0) {
            if ($companyFilter !== null) {
                // Historic message and exit code for an explicit target that
                // does not exist — preserved, but it can no longer be produced
                // by "the console cannot see the companies table".
                $this->error("Company not found: {$companyFilter}");

                return self::FAILURE;
            }

            $this->info('No companies need fiscal years.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Backfill complete!');
        $this->info("  ✓ Success: {$successCount}");

        if ($failureCount > 0) {
            $this->warn("  ✗ Failures: {$failureCount}");
        }

        return self::SUCCESS;
    }
}
