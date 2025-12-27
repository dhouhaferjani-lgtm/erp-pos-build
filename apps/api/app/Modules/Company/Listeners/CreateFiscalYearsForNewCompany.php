<?php

declare(strict_types=1);

namespace App\Modules\Company\Listeners;

use App\Modules\Company\Application\Services\FiscalYearCreationService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Events\CompanyCreated;
use Illuminate\Support\Facades\Log;

/**
 * Creates fiscal years when a company is created.
 *
 * Runs synchronously (NOT queued) to ensure fiscal years exist
 * immediately for subsequent operations.
 */
final class CreateFiscalYearsForNewCompany
{
    public function __construct(
        private readonly FiscalYearCreationService $fiscalYearCreationService
    ) {}

    public function handle(CompanyCreated $event): void
    {
        try {
            $company = Company::findOrFail($event->companyId);

            $this->fiscalYearCreationService->createFiscalYearsForCompany($company);

            Log::info('Fiscal years created automatically', [
                'company_id' => $event->companyId,
                'country' => $event->countryCode,
            ]);
        } catch (\Throwable $e) {
            // Log error but don't fail company creation
            Log::error('Failed to create fiscal years', [
                'company_id' => $event->companyId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
