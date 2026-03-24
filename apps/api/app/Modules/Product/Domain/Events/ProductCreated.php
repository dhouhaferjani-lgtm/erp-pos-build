<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a new product is created.
 *
 * This is an operational event capturing the creation of a product
 * within a company. It records the initial product configuration.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create ProductCreatedV2.
 */
final class ProductCreated extends DomainEvent
{
    public function __construct(
        public readonly string $productId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $name,
        public readonly string $sku,
        public readonly string $type,
        public readonly string $salePrice,
        public readonly ?string $createdAt = null,
    ) {
        parent::__construct($productId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'product.created';
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
            'name' => $this->name,
            'sku' => $this->sku,
            'type' => $this->type,
            'sale_price' => $this->salePrice,
            'created_at' => $this->createdAt ?? now()->toIso8601String(),
        ];
    }
}
