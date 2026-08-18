<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ReceiptDetailLineData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly int $line_number,
        public readonly ?string $product_id,
        public readonly string $product_name,
        public readonly string $product_code,
        public readonly string $quantity,
        public readonly int $quantity_decimals,
        public readonly string $unit_price,
        public readonly string $discount_amount,
        public readonly string $vat_rate,
        public readonly string $vat_amount,
        public readonly string $line_total,
        public readonly string $returned_quantity,
    ) {}
}
