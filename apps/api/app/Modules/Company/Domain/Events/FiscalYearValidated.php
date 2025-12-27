<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a company's fiscal year is validated by the user.
 *
 * This event is triggered when:
 * - User confirms fiscal year start month during onboarding
 * - User validates fiscal year in settings (before first transaction)
 *
 * Once validated, the company can post transactions.
 * Fiscal year cannot be changed after first transaction is posted.
 */
final class FiscalYearValidated extends DomainEvent
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $tenantId,
        public readonly int $fiscalYearStartMonth,
        public readonly string $validatedBy,
        public readonly string $validatedAt,
    ) {
        parent::__construct($companyId);
    }

    public function getEventName(): string
    {
        return 'company.fiscal_year.validated';
    }
}
