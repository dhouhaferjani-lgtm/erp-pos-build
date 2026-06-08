<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

/**
 * Immutable summary of a product variant for cross-module use.
 *
 * Monetary overrides (price_override, cost_override) are nullable decimal
 * strings — never cast to float (monetary precision rule).
 */
final readonly class ProductVariantSummary
{
    public function __construct(
        public string $id,
        public string $productId,
        public string $tenantId,
        public string $companyId,
        public string $sku,
        public string $variantCode,
        public ?string $barcode,
        public string $nameSuffix,
        public bool $isDefault,
        public bool $isActive,
        public ?string $priceOverride,
        public ?string $costOverride,
        public ?string $imageUrl,
    ) {}
}
