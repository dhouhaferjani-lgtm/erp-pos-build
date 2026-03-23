<?php

declare(strict_types=1);

namespace App\Modules\Company\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Events\FirstTransactionPosted;
use App\Modules\Company\Domain\Events\FiscalYearValidated;
use App\Modules\Document\Domain\Document;
use App\Modules\Identity\Domain\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing fiscal year validation and locking.
 *
 * Business Rules:
 * - Fiscal year must be validated before posting transactions
 * - Fiscal year can be changed ONLY before first transaction
 * - First transaction permanently locks fiscal year (irreversible)
 * - Changing fiscal year start month recreates all fiscal years and periods
 */
final class FiscalYearValidationService
{
    public function __construct(
        private readonly FiscalYearCreationService $fiscalYearCreationService
    ) {}

    /**
     * Validate the fiscal year for a company.
     *
     * @throws DomainException If fiscal year is already validated or locked
     */
    public function validateFiscalYear(Company $company, int $startMonth, User $user): void
    {
        // Guard: Cannot validate if already locked
        if ($company->hasFiscalYearLocked()) {
            throw new DomainException('Fiscal year is locked and cannot be validated');
        }

        // Guard: Cannot validate if already validated
        if ($company->isFiscalYearValidated()) {
            throw new DomainException('Fiscal year already validated');
        }

        DB::transaction(function () use ($company, $startMonth, $user): void {
            // If start month changed, recreate fiscal years
            if ($company->fiscal_year_start_month !== $startMonth) {
                $this->recreateFiscalYears($company, $startMonth);
            }

            // Mark as validated
            $company->fiscal_year_validated_at = now();
            $company->fiscal_year_validated_by = $user->id;
            $company->fiscal_year_start_month = $startMonth;
            $company->save();

            // Dispatch domain event
            $companyId = $company->id;
            $tenantId = $company->tenant_id;
            $userId = $user->id;
            DB::afterCommit(function () use ($companyId, $tenantId, $startMonth, $userId): void {
                event(new FiscalYearValidated(
                    companyId: $companyId,
                    tenantId: $tenantId,
                    fiscalYearStartMonth: $startMonth,
                    validatedBy: $userId,
                    validatedAt: now()->toIso8601String()
                ));
            });
        });
    }

    /**
     * Record the first transaction for a company (locks fiscal year forever).
     *
     * @throws DomainException If fiscal year not validated or already locked
     */
    public function recordFirstTransaction(Company $company, Document $document): void
    {
        // Guard: Must be validated first
        if (! $company->isFiscalYearValidated()) {
            throw new DomainException('Fiscal year must be validated before posting transactions');
        }

        // Guard: Cannot lock if already locked
        if ($company->hasFiscalYearLocked()) {
            throw new DomainException('Fiscal year already locked');
        }

        DB::transaction(function () use ($company, $document): void {
            // Permanently lock fiscal year
            $company->first_transaction_posted_at = now();
            $company->first_transaction_document_id = $document->id;
            $company->save();

            // Dispatch domain event
            $companyId = $company->id;
            $tenantId = $company->tenant_id;
            $documentId = $document->id;
            $documentNumber = $document->document_number;
            $documentType = $document->type->value;
            DB::afterCommit(function () use ($companyId, $tenantId, $documentId, $documentNumber, $documentType): void {
                event(new FirstTransactionPosted(
                    companyId: $companyId,
                    tenantId: $tenantId,
                    documentId: $documentId,
                    documentNumber: $documentNumber,
                    documentType: $documentType,
                    postedAt: now()->toIso8601String()
                ));
            });
        });
    }

    /**
     * Recreate all fiscal years and periods when start month changes.
     */
    private function recreateFiscalYears(Company $company, int $newStartMonth): void
    {
        // Delete existing fiscal years and their periods (cascade)
        $company->fiscalYears()->delete();

        // Update company fiscal year start month
        $company->fiscal_year_start_month = $newStartMonth;
        $company->save();

        // Refresh company instance and create new fiscal years with the new start month
        $company->refresh();
        $this->fiscalYearCreationService->createFiscalYearsForCompany($company);
    }
}
