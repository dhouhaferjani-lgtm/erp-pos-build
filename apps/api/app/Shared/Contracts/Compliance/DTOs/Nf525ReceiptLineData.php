<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Compliance\DTOs;

/**
 * Snapshot of a receipt line for NF525 export.
 *
 * Immutable, primitive-typed view of POS\Domain\ReceiptLine. Compliance
 * never imports the Eloquent model; the POS-side provider hydrates this DTO.
 *
 * Numeric values are passed as strings to preserve precision (no float).
 */
final readonly class Nf525ReceiptLineData
{
    public function __construct(
        public int $lineNumber,
        public ?string $productCode,
        public string $productName,
        /** Numeric string (e.g., "2.000"). */
        public string $quantity,
        /** Numeric string. */
        public string $unitPrice,
        /** Numeric string. */
        public string $lineTotal,
        /** Numeric string (e.g., "20.00"). */
        public string $taxRate,
        /** Numeric string. */
        public string $taxAmount,
        /** Numeric string; nullable in source schema, "0.00" fallback applied here. */
        public string $discountAmount,
    ) {}
}
