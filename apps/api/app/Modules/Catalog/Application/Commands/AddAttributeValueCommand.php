<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Commands;

/**
 * Command to add a value to an existing product attribute.
 */
final readonly class AddAttributeValueCommand
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $attributeId,
        public readonly string $code,
        public readonly string $label,
        public readonly ?string $hexColor = null,
        public readonly ?string $imageUrl = null,
        public readonly int $displayOrder = 0,
    ) {}
}
