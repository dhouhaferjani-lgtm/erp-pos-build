<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * TolerancePaymentTotalsDTO
 *
 * Shift-level summary of tolerance writeoffs (rounding adjustments) used
 * by the cash-counting Z-report. Aggregates every receipt within a shift
 * regardless of cashier or payment method.
 *
 * Shape is frozen as of Payment Tolerance contract v1.1.
 */
#[TypeScript]
final class TolerancePaymentTotalsDTO extends Data
{
    public function __construct(
        // Decimal string at currency scale 3 (TND-grade precision); never a float.
        public string $totalAmount,
        public string $currencyCode,
        public int $writeoffCount,
    ) {}
}
