<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Exceptions\CannotChangeTaxStatusException;
use App\Modules\Taxation\Domain\Enums\CompanyTaxStatus;

/**
 * Service responsible for validating company tax status changes.
 *
 * Tax status changes affect VAT recoverability and fiscal compliance.
 * Once fiscal documents are posted, tax status becomes immutable to
 * prevent invalidating historical tax calculations and fiscal chains.
 */
final class CompanyTaxStatusValidationService
{
    /**
     * Validate that tax status change is allowed.
     *
     * Rules:
     * - No validation needed if status isn't changing
     * - Cannot change if posted fiscal documents (invoices, credit notes) exist
     * - Drafts and non-fiscal documents don't block changes
     *
     * @throws CannotChangeTaxStatusException if validation fails
     */
    public function validateTaxStatusChange(
        Company $company,
        CompanyTaxStatus $newStatus
    ): void {
        // No change = no validation needed
        if ($company->tax_status === $newStatus) {
            return;
        }

        // Cannot change if posted fiscal documents exist
        if (! $company->canChangeTaxStatus()) {
            throw CannotChangeTaxStatusException::hasPostedFiscalDocuments(
                $company->name
            );
        }
    }
}
