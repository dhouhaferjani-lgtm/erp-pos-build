<?php

declare(strict_types=1);

namespace App\Modules\Partner\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when an existing partner is updated.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create PartnerUpdatedV2.
 */
final class PartnerUpdated extends DomainEvent
{
    /**
     * @param  array<string, mixed>  $changes
     */
    public function __construct(
        public readonly string $partnerId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly array $changes,
        public readonly string $updatedAt,
    ) {
        parent::__construct($partnerId);
    }

    public function getEventName(): string
    {
        return 'partner.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function getAuditPayload(): array
    {
        return [
            'partner_id' => $this->partnerId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'changes' => $this->changes,
            'updated_at' => $this->updatedAt,
        ];
    }
}
