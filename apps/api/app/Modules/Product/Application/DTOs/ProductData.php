<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ProductData extends Data
{
    /**
     * @param  array<int, string>|null  $oem_numbers
     * @param  array<int, array{brand: string, reference: string}>|null  $cross_references
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $sku,
        public ?ProductType $type,
        public ?string $description,
        public ?string $sale_price,
        public ?string $purchase_price,
        public ?string $cost_price,
        public ?string $tax_rate,
        public ?string $default_tax_configuration_id,
        public ?string $unit,
        public ?string $barcode,
        public bool $is_active,
        public bool $is_physical,
        public ?array $oem_numbers,
        public ?array $cross_references,
        public ?string $target_margin_override,
        public ?string $minimum_margin_override,
        public string $created_at,
        public ?string $updated_at,
        public ?ParapharmacyProductMetadataData $parapharmacy_metadata = null,
        public ?AutomotiveProductMetadataData $automotive_metadata = null,
    ) {}

    public static function fromModel(Product $product): self
    {
        return new self(
            id: $product->id,
            name: $product->name,
            sku: $product->sku,
            type: $product->type,
            description: $product->description,
            sale_price: $product->sale_price !== null ? (string) $product->sale_price : null,
            purchase_price: $product->purchase_price !== null ? (string) $product->purchase_price : null,
            cost_price: (string) $product->cost_price,
            tax_rate: $product->tax_rate !== null ? (string) $product->tax_rate : null,
            default_tax_configuration_id: $product->default_tax_configuration_id,
            unit: $product->unit,
            barcode: $product->barcode,
            is_active: $product->is_active,
            is_physical: $product->is_physical,
            oem_numbers: $product->oem_numbers,
            cross_references: $product->cross_references,
            target_margin_override: $product->target_margin_override !== null ? (string) $product->target_margin_override : null,
            minimum_margin_override: $product->minimum_margin_override !== null ? (string) $product->minimum_margin_override : null,
            created_at: $product->created_at?->toIso8601String() ?? '',
            updated_at: $product->updated_at?->toIso8601String(),
            parapharmacy_metadata: $product->relationLoaded('parapharmacyMetadata') && $product->parapharmacyMetadata !== null
                ? ParapharmacyProductMetadataData::fromModel($product->parapharmacyMetadata)
                : null,
            automotive_metadata: $product->relationLoaded('automotiveMetadata') && $product->automotiveMetadata !== null
                ? AutomotiveProductMetadataData::fromModel($product->automotiveMetadata)
                : null,
        );
    }
}
