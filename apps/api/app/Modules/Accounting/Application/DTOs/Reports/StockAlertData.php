<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class StockAlertData extends Data
{
    public function __construct(
        public readonly string $product_id,
        public readonly string $product_name,
        public readonly string $location_id,
        public readonly string $location_name,
        public readonly string $quantity,
        public readonly string $min_quantity,
        public readonly int $threshold_pct,
        public readonly string $severity,
    ) {}
}
