<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\DTOs;

/**
 * Canonical "ready-to-print" label payload for a single variant.
 *
 * effective_price / barcode_value are decimal/identifier strings — never floats.
 */
final readonly class VariantLabelData
{
    public function __construct(
        public string $variant_id,
        public string $product_name,
        public string $name_suffix,
        public string $effective_price,
        public string $barcode_value,
        public string $symbology,
        public int $quantity,
        public string $sku,
    ) {}
}
