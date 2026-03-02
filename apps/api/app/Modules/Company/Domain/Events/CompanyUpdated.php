<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * CompanyUpdated Event
 *
 * Fired when company settings are updated.
 * Critical for audit trail especially for tax_status changes.
 *
 * IMMUTABLE: Never rename, modify payload, or delete this event.
 * Create CompanyUpdatedV2 if requirements change.
 */
class CompanyUpdated extends DomainEvent
{
    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes  Changes made (before/after per field)
     * @param  array<string, mixed>  $attributes  Full current state snapshot
     */
    public function __construct(
        public readonly string $companyId,
        public readonly string $tenantId,
        public readonly string $userId,
        public readonly array $changes,
        public readonly array $attributes,
        public readonly string $updatedAt,
    ) {
        parent::__construct();
    }

    public function getAggregateId(): string
    {
        return $this->companyId;
    }

    public function getEventName(): string
    {
        return 'company.updated';
    }
}
