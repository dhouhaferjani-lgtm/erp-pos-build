<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ReceiptReturnLineageData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $receipt_number,
        public readonly string $posted_at,
        public readonly string $invoice_type_code,
        public readonly string $total,
        public readonly string $currency,
        public readonly ?string $refund_reason,
        public readonly string $refund_reason_source,
        public readonly ?string $refund_destination,
    ) {}
}
