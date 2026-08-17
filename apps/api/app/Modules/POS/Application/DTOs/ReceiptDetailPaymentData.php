<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ReceiptDetailPaymentData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $payment_method,
        public readonly string $amount,
        public readonly ?string $card_last_four,
        public readonly ?string $instrument_serial,
        public readonly ?string $transaction_reference,
        public readonly ?string $authorization_code,
    ) {}
}
