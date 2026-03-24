<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a product is deleted.
 *
 * This is an operational event capturing the deletion (soft delete)
 * of a product within a company.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create ProductDeletedV2.
 */
final class ProductDeleted extends DomainEvent
{
    public function __construct(
        public readonly string $productId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly ?string $deletedAt = null,
    ) {
        parent::__construct($productId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'product.deleted';
    }

    /**
     * Get payload for audit logging.
     *
     * @return array<string, string|null>
     */
    public function getAuditPayload(): array
    {
        return [
            'product_id' => $this->productId,
            'deleted_at' => $this->deletedAt ?? now()->toIso8601String(),
        ];
    }
}
