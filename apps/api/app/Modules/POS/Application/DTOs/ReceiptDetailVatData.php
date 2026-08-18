<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ReceiptDetailVatData extends Data
{
    public function __construct(
        public readonly string $tax_rate,
        public readonly string $net_amount,
        public readonly string $vat_amount,
        public readonly string $gross_amount,
    ) {}
}
