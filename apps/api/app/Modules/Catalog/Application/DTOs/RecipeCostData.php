<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class RecipeCostData extends Data
{
    /**
     * @param  array<int, array{component_name: string, quantity: string, quantity_decimals: int, unit_cost: string, line_cost: string, percent_of_total: string}>  $lines
     */
    public function __construct(
        public string $total_cost,
        public array $lines,
    ) {}
}
