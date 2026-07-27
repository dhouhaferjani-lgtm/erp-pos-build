<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class TopSkuData extends Data
{
    public function __construct(
        public readonly ?string $product_id,
        public readonly string $product_name,
        public readonly ?string $sku,
        public readonly string $revenue,
        public readonly string $quantity,
        public readonly int $quantity_decimals,
    ) {}
}
