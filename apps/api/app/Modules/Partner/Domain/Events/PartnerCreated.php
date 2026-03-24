<?php

declare(strict_types=1);

namespace App\Modules\Partner\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a new partner is created.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create PartnerCreatedV2.
 */
final class PartnerCreated extends DomainEvent
{
    public function __construct(
        public readonly string $partnerId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $name,
        public readonly string $type,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly string $createdAt,
    ) {
        parent::__construct($partnerId);
    }

    public function getEventName(): string
    {
        return 'partner.created';
    }

    /**
     * @return array<string, string|null>
     */
    public function getAuditPayload(): array
    {
        return [
            'partner_id' => $this->partnerId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'name' => $this->name,
            'type' => $this->type,
            'email' => $this->email,
            'phone' => $this->phone,
            'created_at' => $this->createdAt,
        ];
    }
}
