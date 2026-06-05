<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a product variant is created and persisted.
 */
final class ProductVariantCreated extends DomainEvent
{
    public function __construct(
        public readonly string $variantId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $productId,
        public readonly string $variantCode,
        public readonly string $sku,
        public readonly bool $isDefault,
        public readonly string $createdAt,
    ) {
        parent::__construct($variantId);
    }

    public function getEventName(): string
    {
        return 'catalog.variant.created';
    }
}
