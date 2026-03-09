<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Domain\AutomotiveProductMetadata;
use App\Modules\Product\Domain\Enums\AutomotiveArticleStatus;
use App\Modules\Product\Domain\Enums\BrandQualityTier;
use App\Modules\Product\Domain\Enums\PlatformLinkStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class AutomotiveProductMetadataData extends Data
{
    /**
     * @param  array<string, mixed>|null  $dimensions
     * @param  list<AutomotiveCrossReferenceData>|null  $cross_references
     * @param  list<AutomotiveVehicleData>|null  $vehicles
     * @param  list<AutomotiveCriterionData>|null  $criteria
     */
    public function __construct(
        public string $id,
        public string $product_id,
        public ?string $platform_article_id,
        public PlatformLinkStatus $platform_link_status,
        public ?string $article_number,
        public ?string $supplier_brand,
        public ?string $product_group_name,
        public ?BrandQualityTier $brand_quality_tier,
        public AutomotiveArticleStatus $article_status,
        public int $confidence_score,
        public string $data_source,
        public ?string $weight_kg,
        public ?array $dimensions,
        public ?string $superseded_by_product_id,
        public bool $is_universal_fit,
        public ?string $notes,
        public ?string $platform_synced_at,
        public ?int $tire_width,
        public ?int $tire_aspect_ratio,
        public ?int $tire_rim_diameter,
        public ?string $tire_speed_rating,
        public ?int $tire_load_index,
        public ?string $tire_season,
        public ?string $glass_type,
        public ?string $glass_tinting,
        public ?array $cross_references,
        public ?array $vehicles,
        public ?array $criteria,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(AutomotiveProductMetadata $metadata): self
    {
        if (! $metadata->relationLoaded('crossReferences')) {
            $metadata->load('crossReferences');
        }
        if (! $metadata->relationLoaded('vehicles')) {
            $metadata->load('vehicles');
        }
        if (! $metadata->relationLoaded('criteria')) {
            $metadata->load('criteria');
        }

        return new self(
            id: $metadata->id,
            product_id: $metadata->product_id,
            platform_article_id: $metadata->platform_article_id,
            platform_link_status: $metadata->platform_link_status,
            article_number: $metadata->article_number,
            supplier_brand: $metadata->supplier_brand,
            product_group_name: $metadata->product_group_name,
            brand_quality_tier: $metadata->brand_quality_tier,
            article_status: $metadata->article_status,
            confidence_score: $metadata->confidence_score,
            data_source: $metadata->data_source,
            weight_kg: $metadata->weight_kg !== null ? (string) $metadata->weight_kg : null,
            dimensions: $metadata->dimensions,
            superseded_by_product_id: $metadata->superseded_by_product_id,
            is_universal_fit: $metadata->is_universal_fit,
            notes: $metadata->notes,
            platform_synced_at: $metadata->platform_synced_at?->toIso8601String(),
            tire_width: $metadata->tire_width,
            tire_aspect_ratio: $metadata->tire_aspect_ratio,
            tire_rim_diameter: $metadata->tire_rim_diameter,
            tire_speed_rating: $metadata->tire_speed_rating,
            tire_load_index: $metadata->tire_load_index,
            tire_season: $metadata->tire_season,
            glass_type: $metadata->glass_type,
            glass_tinting: $metadata->glass_tinting,
            cross_references: $metadata->crossReferences->isNotEmpty()
                ? array_values($metadata->crossReferences->map(fn ($cr) => AutomotiveCrossReferenceData::fromModel($cr))->all())
                : null,
            vehicles: $metadata->vehicles->isNotEmpty()
                ? array_values($metadata->vehicles->map(fn ($v) => AutomotiveVehicleData::fromModel($v))->all())
                : null,
            criteria: $metadata->criteria->isNotEmpty()
                ? array_values($metadata->criteria->map(fn ($c) => AutomotiveCriterionData::fromModel($c))->all())
                : null,
            created_at: $metadata->created_at?->toIso8601String() ?? '',
            updated_at: $metadata->updated_at?->toIso8601String(),
        );
    }
}
