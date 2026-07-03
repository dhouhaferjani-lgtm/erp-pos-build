<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class EnrichmentResultData extends Data
{
    /**
     * @param  array<string, bool>|null  $accepted_fields  Fields accepted during review
     */
    public function __construct(
        public string $id,
        public string $product_id,
        public string $product_name,
        public ?string $product_barcode,
        public ?string $product_sku,
        public ?string $tracking_id,
        public string $status,
        public EnrichedProductData $enriched_data,
        public string $enrichment_quality,
        public ?string $assigned_barcode,
        public ?string $reviewed_at,
        public ?string $reviewed_by,
        public ?array $accepted_fields,
        public ?string $rejection_reason,
        public string $created_at,
    ) {}
}
