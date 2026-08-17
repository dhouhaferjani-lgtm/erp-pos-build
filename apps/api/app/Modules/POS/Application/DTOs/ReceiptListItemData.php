<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ReceiptListItemData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $receipt_number,
        public readonly string $posted_at,
        public readonly string $invoice_type_code,
        public readonly string $receipt_type,
        public readonly bool $training_flag,
        public readonly string $fiscal_status,
        public readonly string $location_id,
        public readonly ?string $location_name,
        public readonly string $terminal_id,
        public readonly string $terminal_code,
        public readonly string $cashier_id,
        public readonly string $cashier_name,
        public readonly string $total,
        public readonly string $currency,
        public readonly ?string $original_receipt_id,
    ) {}
}
