<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\FiscalPayloadArrayGuards;

/**
 * One row in `fiscal_events.payload.original_line_references[]` — v4
 * REFUND-only, spec `2026-07-31-v3-refund-chain-integration.md` §3.3.
 *
 * Strict parallel array to `line_items[]`: one entry per `line_items[i]`,
 * `product_id`/`quantity` equal at each index `i` (validated by
 * `FiscalPayloadConstraintValidator::validateOriginalLineReferences()`
 * BEFORE a payload is ever signed-and-stored — this DTO does not
 * re-validate the cross-reference, matching every other Canonical DTO's
 * "verified row" contract).
 *
 * `disposition` reuses the exact, verbatim string values of the existing
 * `App\Modules\POS\Domain\Enums\ReturnLineDisposition` enum (no new enum,
 * no cross-module import — the payload boundary is a plain string per
 * every other enum field in this DTO family, e.g. `SaleReceiptPayload::$invoiceTypeCode`).
 */
final readonly class OriginalLineReferenceDTO
{
    public function __construct(
        public string $disposition,
        public int $originalLineIndex,
        public string $productId,
        public string $quantity,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            disposition: FiscalPayloadArrayGuards::requireString($data, 'disposition'),
            originalLineIndex: FiscalPayloadArrayGuards::requireInt($data, 'original_line_index'),
            productId: FiscalPayloadArrayGuards::requireString($data, 'product_id'),
            quantity: FiscalPayloadArrayGuards::requireString($data, 'quantity'),
        );
    }
}
