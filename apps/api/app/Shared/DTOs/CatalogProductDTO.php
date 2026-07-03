<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

final readonly class CatalogProductDTO
{
    /**
     * @param  array<string, mixed>  $classification
     * @param  list<array{name: string, position: int}>  $ingredients
     * @param  list<array{url: ?string, thumbnail: ?string, type: ?string}>  $images
     */
    public function __construct(
        public string $platformProductId,
        public string $barcode,
        public string $name,
        public ?string $brand,
        public ?string $description,
        public array $classification,
        public array $ingredients,
        public array $images,
        public int $confidenceScore,
        public ?string $enrichmentTier,
        // Cross-ERP brand mapping passthrough from the platform payload (audit).
        public ?string $canonicalBrandId = null,
        public ?string $canonicalBrandSlug = null,
        public ?string $externalBrandId = null,
        // Local ERP brand row resolved by the lookup's BrandResolutionService;
        // null for legacy payloads without mapping fields.
        public ?string $localBrandId = null,
    ) {}
}
