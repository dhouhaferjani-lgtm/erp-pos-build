<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a value is added to a product attribute.
 */
final class ProductAttributeValueAdded extends DomainEvent
{
    public function __construct(
        public readonly string $attributeValueId,
        public readonly string $attributeId,
        public readonly string $tenantId,
        public readonly string $code,
        public readonly string $label,
        public readonly string $createdAt,
    ) {
        parent::__construct($attributeValueId);
    }

    public function getEventName(): string
    {
        return 'catalog.attribute_value.added';
    }
}
