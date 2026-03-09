<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class PriceComparisonData extends Data
{
    public function __construct(
        public string $current_price,
        public string $best_marketplace_price,
        public string $savings_amount,
        public string $savings_percent,
        public int $available_listings_count,
        public string $cheapest_listing_id,
    ) {}
}
