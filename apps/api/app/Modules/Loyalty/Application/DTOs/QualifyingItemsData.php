<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class QualifyingItemsData extends Data
{
    /**
     * @param  array<int, string>|null  $product_ids
     * @param  array<int, string>|null  $category_ids
     * @param  array<int, string>|null  $excluded_product_ids
     * @param  array<int, string>|null  $excluded_category_ids
     */
    public function __construct(
        public ?array $product_ids = null,
        public ?array $category_ids = null,
        public ?array $excluded_product_ids = null,
        public ?array $excluded_category_ids = null,
        public ?string $min_price = null,
        public ?string $max_price = null,
        public ?bool $all_products = null,
    ) {}
}
