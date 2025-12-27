<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Company\Application\Services\FiscalYearCreationService;
use App\Modules\Company\Domain\Company;
use Illuminate\Console\Command;

/**
 * Backfill fiscal years for companies that don't have them.
 *
 * Usage:
 *   php artisan fiscal-years:backfill
 *   php artisan fiscal-years:backfill --company=uuid
 */
class BackfillFiscalYears extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fiscal-years:backfill {--company= : Specific company UUID to backfill}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create fiscal years for companies missing them';

    /**
     * Execute the console command.
     */
    public function handle(FiscalYearCreationService $service): int
    {
        if ($companyId = $this->option('company')) {
            return $this->backfillSingleCompany($companyId, $service);
        }

        return $this->backfillAllCompanies($service);
    }

    /**
     * Backfill fiscal years for a single company.
     */
    private function backfillSingleCompany(string $companyId, FiscalYearCreationService $service): int
    {
        $company = Company::find($companyId);

        if ($company === null) {
            $this->error("Company not found: {$companyId}");

            return self::FAILURE;
        }

        if ($company->fiscalYears()->exists()) {
            $this->warn("Company '{$company->name}' already has fiscal years.");

            return self::SUCCESS;
        }

        try {
            $service->createFiscalYearsForCompany($company);
            $this->info("✓ Created fiscal years for company: {$company->name}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed to create fiscal years: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Backfill fiscal years for all companies without them.
     */
    private function backfillAllCompanies(FiscalYearCreationService $service): int
    {
        $companies = Company::doesntHave('fiscalYears')->get();

        if ($companies->isEmpty()) {
            $this->info('No companies need fiscal years.');

            return self::SUCCESS;
        }

        $this->info("Backfilling {$companies->count()} companies...");
        $bar = $this->output->createProgressBar($companies->count());
        $bar->start();

        $successCount = 0;
        $failureCount = 0;

        foreach ($companies as $company) {
            try {
                $service->createFiscalYearsForCompany($company);
                $successCount++;
                $bar->advance();
            } catch (\Throwable $e) {
                $failureCount++;
                $this->newLine();
                $this->error("Failed for {$company->name} ({$company->id}): {$e->getMessage()}");
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Backfill complete!');
        $this->info("  ✓ Success: {$successCount}");

        if ($failureCount > 0) {
            $this->warn("  ✗ Failures: {$failureCount}");
        }

        return self::SUCCESS;
    }
}
