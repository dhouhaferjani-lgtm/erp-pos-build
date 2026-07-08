<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Catalog\Application\DTOs\MediaAttachmentData;
use App\Modules\Catalog\Application\DTOs\ProductMediaData;
use App\Modules\Product\Domain\Enums\PricingMode;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Shared\Domain\CurrencyScale;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ProductData extends Data
{
    /**
     * @param  array<int, string>|null  $oem_numbers
     * @param  array<int, array{brand: string, reference: string}>|null  $cross_references
     * @param  array<int, MediaAttachmentData>  $media
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
        public ?string $unit_id,
        public ?int $units_per_pack,
        public ?string $shelf_location,
        public ?string $reorder_point,
        public ?string $reorder_quantity,
        public int $quantity_decimals,
        public ?string $barcode,
        public bool $is_active,
        public bool $is_active_for_ecommerce,
        public bool $is_physical,
        public bool $requires_batch_tracking,
        public ?array $oem_numbers,
        public ?array $cross_references,
        public ?string $target_margin_override,
        public ?string $minimum_margin_override,
        public ?string $max_discount_percent,
        public ?string $platform_product_id,
        public string $created_at,
        public ?string $updated_at,
        public bool $has_variants = false,
        public ?string $primary_image_url = null,
        public array $media = [],
        public ?BrandData $brand = null,
        public ?string $brand_source = null,
        public ?CategoryData $category = null,
        public ?ParapharmacyProductMetadataData $parapharmacy_metadata = null,
        public ?AutomotiveProductMetadataData $automotive_metadata = null,
        public ?OpeningStateData $opening = null,
        public ?string $enrichment_status = null,
        /** @var array{id: string, status: string}|null */
        public ?array $latest_enrichment_result = null,
        public ?string $stock_quantity = null,
        public PricingMode $pricing_mode = PricingMode::Manual,
        public ?EffectiveMargins $effective_margins = null,
    ) {}

    public static function fromModel(Product $product, ?ProductMediaData $media = null, ?OpeningStateData $opening = null, ?EffectiveMargins $effective = null): self
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
            unit_id: $product->unit_id,
            units_per_pack: $product->units_per_pack,
            shelf_location: $product->shelf_location,
            reorder_point: $product->reorder_point !== null ? (string) $product->reorder_point : null,
            reorder_quantity: $product->reorder_quantity !== null ? (string) $product->reorder_quantity : null,
            quantity_decimals: self::quantityDecimals($product),
            barcode: $product->barcode,
            is_active: $product->is_active,
            is_active_for_ecommerce: (bool) $product->is_active_for_ecommerce,
            is_physical: $product->is_physical,
            requires_batch_tracking: $product->requires_batch_tracking,
            oem_numbers: $product->oem_numbers,
            cross_references: $product->cross_references,
            target_margin_override: $product->target_margin_override !== null ? (string) $product->target_margin_override : null,
            minimum_margin_override: $product->minimum_margin_override !== null ? (string) $product->minimum_margin_override : null,
            max_discount_percent: $product->max_discount_percent !== null ? (string) $product->max_discount_percent : null,
            platform_product_id: $product->platform_product_id,
            created_at: $product->created_at?->toIso8601String() ?? '',
            updated_at: $product->updated_at?->toIso8601String(),
            has_variants: $product->has_variants,
            primary_image_url: $media !== null ? $media->primary_image_url : null,
            media: $media !== null ? $media->media : [],
            brand: $product->relationLoaded('brand') && $product->brand !== null
                ? BrandData::fromModel($product->brand)
                : null,
            brand_source: $product->brand_source?->value,
            category: $product->relationLoaded('category') && $product->category !== null
                ? CategoryData::fromModel($product->category)
                : null,
            parapharmacy_metadata: $product->relationLoaded('parapharmacyMetadata') && $product->parapharmacyMetadata !== null
                ? ParapharmacyProductMetadataData::fromModel($product->parapharmacyMetadata)
                : null,
            automotive_metadata: $product->relationLoaded('automotiveMetadata') && $product->automotiveMetadata !== null
                ? AutomotiveProductMetadataData::fromModel($product->automotiveMetadata)
                : null,
            opening: $opening,
            enrichment_status: $product->enrichment_status?->value,
            latest_enrichment_result: $product->relationLoaded('latestEnrichmentResult') && $product->latestEnrichmentResult !== null
                ? [
                    'id' => $product->latestEnrichmentResult->id,
                    'status' => $product->latestEnrichmentResult->status->value,
                ]
                : null,
            stock_quantity: self::stockQuantity($product),
            pricing_mode: $product->pricing_mode ?? PricingMode::Manual,
            effective_margins: $effective,
        );
    }

    private static function quantityDecimals(Product $product): int
    {
        if ($product->relationLoaded('unitOfMeasure') && $product->unitOfMeasure !== null) {
            return $product->unitOfMeasure->decimal_places;
        }

        return 4;
    }

    private static function stockQuantity(Product $product): ?string
    {
        $value = $product->getAttribute('stock_quantity');
        if (is_scalar($value) && is_numeric((string) $value)) {
            return CurrencyScale::bcformatStrict((string) $value, 4);
        }

        if ($product->relationLoaded('stockLevels')) {
            /** @var numeric-string $sum */
            $sum = '0.0000';
            foreach ($product->stockLevels as $stockLevel) {
                $sum = bcadd($sum, (string) $stockLevel->quantity, 4);
            }

            return $sum;
        }

        return null;
    }
}
