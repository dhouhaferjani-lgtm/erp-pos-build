<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class EarningConditionsData extends Data
{
    /**
     * @param  array<int, string>|null  $product_ids
     * @param  array<int, string>|null  $category_ids
     * @param  array<int, string>|null  $tier_ids
     * @param  array<int, string>|null  $company_ids
     * @param  array<int, string>|null  $day_of_week
     * @param  array<string, mixed>|null  $custom
     */
    public function __construct(
        public ?string $min_purchase_amount = null,
        public ?string $max_purchase_amount = null,
        public ?int $min_quantity = null,
        public ?int $max_quantity = null,
        public ?array $product_ids = null,
        public ?array $category_ids = null,
        public ?array $tier_ids = null,
        public ?array $company_ids = null,
        public ?string $time_start = null,
        public ?string $time_end = null,
        public ?array $day_of_week = null,
        public ?bool $first_purchase = null,
        public ?bool $new_customer = null,
        public ?array $custom = null,
    ) {}
}
