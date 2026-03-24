<?php

declare(strict_types=1);

namespace App\Modules\Partner\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a partner is soft-deleted.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create PartnerDeletedV2.
 */
final class PartnerDeleted extends DomainEvent
{
    public function __construct(
        public readonly string $partnerId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $deletedAt,
    ) {
        parent::__construct($partnerId);
    }

    public function getEventName(): string
    {
        return 'partner.deleted';
    }

    /**
     * @return array<string, string>
     */
    public function getAuditPayload(): array
    {
        return [
            'partner_id' => $this->partnerId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'deleted_at' => $this->deletedAt,
        ];
    }
}
