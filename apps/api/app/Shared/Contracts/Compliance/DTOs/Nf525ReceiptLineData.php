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
 *
 * **Pass 2A.PHP.2 — canonical-only fields (synthesis v5 §3 / §8.B).**
 * Fields below the trailing `gtin` / `taxCategoryCode` / `nonCollectedSubtype`
 * are sourced from `fiscal_events.payload.line_items[]` (the 27-key canonical
 * SALE_RECEIPT payload). They are not projected to `pos_receipt_lines`
 * columns — readable via `CanonicalPayloadReader::forSaleReceipt()`. For
 * legacy receipts (`fiscal_event_id IS NULL`) they default to null.
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
        // Pass 2A.PHP.2 — canonical-only fields. Synthesis v5 §3 / §8.B.
        /** GTIN/EAN/UPC barcode (DSFinV-K future). Canonical-only. */
        public ?string $gtin = null,
        /** KSA BT-151 / IT Natura unified tax category code. Canonical-only. */
        public ?string $taxCategoryCode = null,
        /** IT non_collected_subtype (servizi|beni|omaggio|successiva). Canonical-only. */
        public ?string $nonCollectedSubtype = null,
    ) {}
}
