<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * TolerancePaymentReceiptDTO
 *
 * Receipt-level drill-down for a single sale that incurred a tolerance
 * writeoff. Emitted by PaymentToleranceQueryService::receiptsWithToleranceForShift()
 * so an auditor can trace any shift-level rounding total back to the
 * specific receipts that produced it.
 *
 * Shape is frozen as of Payment Tolerance contract v1.1.
 */
#[TypeScript]
final class TolerancePaymentReceiptDTO extends Data
{
    public function __construct(
        public string $receiptNumber,
        public string $userId,
        public string $userName,
        // Decimal string at currency scale 3 (TND-grade precision); never a float.
        public string $writeoffAmount,
        public string $currencyCode,
        // ISO 8601 UTC timestamp (e.g. "2026-04-25T14:32:11Z").
        public string $occurredAt,
    ) {}
}
