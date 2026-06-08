<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\DTOs;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * API representation of a product variant.
 *
 * Monetary fields (price_override, cost_override) are decimal strings to avoid
 * float precision loss. cost_override is ADVISORY ONLY — inventory WAC remains
 * product-grain (spec §6.7); it is exposed here for display, never as a costing
 * input.
 */
#[TypeScript]
class ProductVariantData extends Data
{
    public function __construct(
        public string $id,
        public string $tenant_id,
        public string $company_id,
        public string $product_id,
        public string $variant_code,
        public string $sku,
        public ?string $barcode,
        public string $name_suffix,
        public bool $is_default,
        public bool $is_active,
        public int $display_order,
        public ?string $price_override,
        public ?string $cost_override,
        public ?string $image_url,
    ) {}

    public static function fromModel(ProductVariant $variant): self
    {
        return new self(
            id: $variant->id,
            tenant_id: $variant->tenant_id,
            company_id: $variant->company_id,
            product_id: $variant->product_id,
            variant_code: $variant->variant_code,
            sku: $variant->sku,
            barcode: $variant->barcode,
            name_suffix: $variant->name_suffix,
            is_default: $variant->is_default,
            is_active: $variant->is_active,
            display_order: $variant->display_order,
            price_override: $variant->price_override,
            cost_override: $variant->cost_override,
            image_url: $variant->image_url,
        );
    }
}
