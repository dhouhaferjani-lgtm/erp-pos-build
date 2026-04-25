<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * TolerancePaymentBreakdownDTO
 *
 * Per-cashier breakdown of tolerance writeoffs within a shift. Emitted by
 * PaymentToleranceQueryService::breakdownForShift() so the Z-report can
 * attribute rounding deltas to individual operators.
 *
 * Shape is frozen as of Payment Tolerance contract v1.1.
 */
#[TypeScript]
final class TolerancePaymentBreakdownDTO extends Data
{
    public function __construct(
        public string $userId,
        public string $userName,
        // Decimal string at currency scale 3 (TND-grade precision); never a float.
        public string $totalAmount,
        public string $currencyCode,
        public int $writeoffCount,
    ) {}
}
