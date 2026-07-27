<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class DiscountAnalysisData extends Data
{
    /**
     * @param  array<int, array{reason: string, total_amount: string, count: int}>  $by_reason
     * @param  array<int, array{product_id: string|null, product_name: string, discount_amount: string, quantity: string, quantity_decimals: int}>  $top_discounted_products
     */
    public function __construct(
        public string $total_discount_amount,
        public int $discount_count,
        public array $by_reason,
        public array $top_discounted_products,
    ) {}
}
