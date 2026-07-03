<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class EnrichedProductData extends Data
{
    /**
     * @param  array<string, string>  $classification  Category/classification hierarchy from enrichment
     * @param  array<int, string>  $ingredients  List of product ingredients
     * @param  array<int, array{url: string, type?: string}>  $images  Enriched product images
     * @param  array<string, int>|null  $field_confidence  Per-field confidence scores (0-100)
     * @param  array<int, string>|null  $enrichment_sources  Sources used for enrichment
     */
    public function __construct(
        public string $name,
        public ?string $brand,
        public ?string $description,
        public array $classification,
        public array $ingredients,
        public array $images,
        public int $confidence_score,
        public ?string $enrichment_tier,
        public ?array $field_confidence,
        public ?array $enrichment_sources,
        public ?string $assigned_barcode,
        public ?string $assigned_barcode_type,
        // Persisted inside the enriched_data JSONB. If H3 introduces
        // locale-based routing/analytics or filtering, promote this to a
        // dedicated enrichment_results.locale column (index-friendly).
        public ?string $locale = null,
        // Cross-ERP brand mapping. Read-time, partner-scoped on the platform
        // lookup endpoint; defensively parsed here so accept can honor them if
        // lookup-status enriched_data starts carrying them.
        public ?string $canonical_brand_id = null,
        public ?string $canonical_brand_slug = null,
        public ?string $external_brand_id = null,
    ) {}
}
