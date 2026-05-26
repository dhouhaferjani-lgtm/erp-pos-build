<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class PaymentMethodBreakdownData extends Data
{
    public function __construct(
        public readonly string $payment_type,
        public readonly string $payment_method_name,
        public readonly string $amount,
        public readonly string $percentage,
        public readonly int $transaction_count,
    ) {}
}
