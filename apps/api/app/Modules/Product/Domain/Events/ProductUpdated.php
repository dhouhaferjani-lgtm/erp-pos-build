<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a product is updated.
 *
 * This is an operational event capturing changes made to a product.
 * The changes array records which fields were modified.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create ProductUpdatedV2.
 */
final class ProductUpdated extends DomainEvent
{
    /**
     * @param  array<string, mixed>  $changes
     */
    public function __construct(
        public readonly string $productId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly array $changes,
        public readonly ?string $updatedAt = null,
    ) {
        parent::__construct($productId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'product.updated';
    }

    /**
     * Get payload for audit logging.
     *
     * @return array<string, mixed>
     */
    public function getAuditPayload(): array
    {
        return [
            'product_id' => $this->productId,
            'changes' => $this->changes,
            'updated_at' => $this->updatedAt ?? now()->toIso8601String(),
        ];
    }
}
