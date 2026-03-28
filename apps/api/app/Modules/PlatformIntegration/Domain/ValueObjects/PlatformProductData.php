<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Domain\ValueObjects;

/**
 * Immutable representation of a product from the platform's universal lookup endpoint.
 * Maps the LookupProductResource response shape.
 *
 * @phpstan-type Classification array<string, mixed>
 * @phpstan-type Ingredient array{name: string, position: int}
 * @phpstan-type Image array{url: ?string, thumbnail: ?string, type: ?string}
 */
final readonly class PlatformProductData
{
    /**
     * @param  Classification  $classification
     * @param  list<Ingredient>  $ingredients
     * @param  list<Image>  $images
     */
    public function __construct(
        public string $id,
        public string $barcode,
        public string $name,
        public ?string $brand,
        public ?string $description,
        public array $classification,
        public array $ingredients,
        public array $images,
        public int $confidenceScore,
        public ?string $enrichmentTier,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromApiResponse(array $data): self
    {
        return new self(
            id: $data['id'],
            barcode: $data['barcode'],
            name: $data['name'],
            brand: $data['brand'] ?? null,
            description: $data['description'] ?? null,
            classification: $data['classification'] ?? [],
            ingredients: $data['ingredients'] ?? [],
            images: $data['images'] ?? [],
            confidenceScore: (int) ($data['confidence_score'] ?? 0),
            enrichmentTier: $data['enrichment_tier'] ?? null,
        );
    }
}
