<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\FiscalPayloadArrayGuards;

/**
 * Reference to the original SALE_RECEIPT for refund / void events.
 *
 * Sourced from `fiscal_events.payload.original_receipt_reference`.
 * NULL on plain SALE events; populated when `invoice_type_code` is
 * `REFUND` or `VOID` (per synthesis v5 §3 + §6).
 *
 * Refunds are modeled via `invoice_type_code='REFUND'` +
 * non-null `original_receipt_reference` — NOT via negative payload
 * amounts (every money field in the payload is NON-NEGATIVE per §6).
 *
 * 4 required keys when present.
 */
final readonly class OriginalReceiptReferenceDTO
{
    public function __construct(
        public string $fiscalEventId,
        public string $originalBusinessDate,
        public string $originalReceiptUuid,
        public string $refundReason,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            fiscalEventId: FiscalPayloadArrayGuards::requireString($data, 'fiscal_event_id'),
            originalBusinessDate: FiscalPayloadArrayGuards::requireString($data, 'original_business_date'),
            originalReceiptUuid: FiscalPayloadArrayGuards::requireString($data, 'original_receipt_uuid'),
            refundReason: FiscalPayloadArrayGuards::requireString($data, 'refund_reason'),
        );
    }
}
