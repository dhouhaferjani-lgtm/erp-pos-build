<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Commands;

/**
 * Command to create a product variant with its attribute-value assignments.
 *
 * Monetary fields (priceOverride, costOverride) are decimal strings to avoid
 * float precision issues — see the project monetary-precision rule.
 */
final readonly class CreateVariantCommand
{
    /**
     * @param  array<int, array{attributeId: string, attributeValueId: string}>  $attributeValues
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $productId,
        public readonly string $variantCode,
        public readonly string $sku,
        public readonly string $nameSuffix,
        public readonly bool $isDefault = false,
        public readonly ?string $barcode = null,
        public readonly ?string $priceOverride = null,
        public readonly ?string $costOverride = null,
        public readonly ?string $imageUrl = null,
        public readonly array $attributeValues = [],
    ) {}
}
