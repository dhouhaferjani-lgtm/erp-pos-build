<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a company is successfully created.
 *
 * Triggers initialization of company-specific resources:
 * - Fiscal years and periods
 * - Payment methods (future)
 * - Default chart of accounts (future)
 */
final class CompanyCreated extends DomainEvent
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $tenantId,
        public readonly string $name,
        public readonly string $countryCode,
        public readonly string $currency,
        public readonly ?int $fiscalYearStartMonth,
        public readonly string $createdBy,
        public readonly string $createdAt,
    ) {
        parent::__construct($companyId);
    }

    public function getEventName(): string
    {
        return 'company.created';
    }
}
