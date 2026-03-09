<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Domain\ValueObjects;

final readonly class PlatformArticle
{
    /**
     * @param array<int, array{type: string, number: string, manufacturer_name: string|null}> $crossReferences
     * @param array<int, array{vehicle_type: string, vehicle_id: string, display: string, year_from: int|null, year_to: int|null}> $vehicleLinkages
     * @param array<int, array{key: string, label: string, value: string, unit: string|null}> $criteria
     */
    public function __construct(
        public string $id,
        public string $articleNumber,
        public string $articleName,
        public string $supplierBrand,
        public ?string $productGroupName,
        public ?string $barcode,
        public ?string $brandQualityTier,
        public ?string $weightKg,
        public ?array $dimensions,
        public array $crossReferences,
        public array $vehicleLinkages,
        public array $criteria,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromApiResponse(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            articleNumber: (string) $data['article_number'],
            articleName: (string) $data['name'],
            supplierBrand: (string) $data['supplier_brand'],
            productGroupName: isset($data['product_group_name']) ? (string) $data['product_group_name'] : null,
            barcode: isset($data['barcode']) ? (string) $data['barcode'] : null,
            brandQualityTier: isset($data['brand_quality_tier']) ? (string) $data['brand_quality_tier'] : null,
            weightKg: isset($data['weight_kg']) ? (string) $data['weight_kg'] : null,
            dimensions: $data['dimensions'] ?? null,
            crossReferences: $data['cross_references'] ?? [],
            vehicleLinkages: $data['vehicle_linkages'] ?? [],
            criteria: $data['criteria'] ?? [],
        );
    }
}
