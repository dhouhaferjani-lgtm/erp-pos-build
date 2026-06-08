<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a new product attribute (variant axis or informational) is created.
 */
final class ProductAttributeCreated extends DomainEvent
{
    public function __construct(
        public readonly string $attributeId,
        public readonly string $tenantId,
        public readonly string $code,
        public readonly string $name,
        public readonly string $dataType,
        public readonly bool $isVariantAxis,
        public readonly string $createdAt,
    ) {
        parent::__construct($attributeId);
    }

    public function getEventName(): string
    {
        return 'catalog.attribute.created';
    }
}
