<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Compliance\DTOs;

/**
 * Snapshot of a receipt-print row (reprint / duplicata) for NF525 export.
 *
 * Immutable view of POS\Domain\ReceiptPrint. `$printType` and `$printMethod`
 * are emitted as their backed-enum string values to keep the contract
 * primitive-only (no enum types leak across the boundary).
 */
final readonly class Nf525ReceiptPrintData
{
    public function __construct(
        public string $id,
        public string $receiptId,
        public string $terminalId,
        public string $userId,
        /** Enum value, e.g. "ORIGINAL", "DUPLICATE". */
        public string $printType,
        public int $copyNumber,
        /** Enum value, e.g. "THERMAL", "PDF". */
        public string $printMethod,
        public string $printedAtIso8601,
    ) {}
}
