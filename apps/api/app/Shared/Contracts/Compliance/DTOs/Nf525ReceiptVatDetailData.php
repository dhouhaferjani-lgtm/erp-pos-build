<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Compliance\DTOs;

/**
 * Snapshot of a receipt VAT breakdown row for NF525 export.
 *
 * Immutable view of POS\Domain\ReceiptVatDetail.
 *
 * **Pass 2A.PHP.2 — canonical-only `taxCategoryCode` (synthesis v5 §8.B).**
 * Sourced from `fiscal_events.payload.vat_breakdown[]`; not projected to
 * columns. For legacy receipts (`fiscal_event_id IS NULL`) defaults to null.
 */
final readonly class Nf525ReceiptVatDetailData
{
    public function __construct(
        public string $taxRate,
        public string $netAmount,
        public string $vatAmount,
        public string $grossAmount,
        // Pass 2A.PHP.2 — canonical-only.
        /** Unified KSA BT-151 / IT Natura tax category code. */
        public ?string $taxCategoryCode = null,
    ) {}
}
