<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown by `PosCoreReceiptProjection` (§3.3/§12/review round-2 CRITICAL 1)
 * when a v4 REFUND's `original_line_references[]` row does not resolve to a
 * REAL line on the resolved original receipt.
 *
 * **The trust hole this closes.** `FiscalPayloadConstraintValidator::validateOriginalLineReferences()`
 * only checks the refund's OWN payload for internal parallel-array
 * consistency (each `original_line_references[i]` matches
 * `line_items[i]`'s `product_id`/`quantity`) — it has no access to the
 * ORIGINAL receipt's actual projected lines, so it cannot catch a reference
 * row whose `original_line_index` points at a line_number that doesn't
 * exist on the original, or whose `product_id` doesn't match what the
 * original's line at that position actually is. Both conditions are only
 * detectable server-side, at projection time, against the real
 * `pos_receipt_lines` rows.
 *
 * Two distinct failure shapes, both fatal:
 *   - **out-of-range index** — no `pos_receipt_lines` row exists at
 *     `original_line_index + 1` for the resolved original receipt.
 *   - **product mismatch** — a row exists at that line_number, but its
 *     resolved `product_id` does not equal the reference row's own
 *     `product_id` — i.e. the reference is pointing at the RIGHT position
 *     but the WRONG product, which would otherwise silently attach the
 *     refund's quantity-cap accounting and `original_line_id` linkage to
 *     an unrelated line.
 *
 * **NonRetryableProjectionException.** Neither shape resolves itself on a
 * later Horizon attempt — the signed event's own reference array is fixed
 * at signing time. Dead-letters immediately via
 * `ApplyFiscalEventProjectionJob`'s `catch (NonRetryableProjectionException $e)`
 * branch (§4.2) instead of burning all 5 retry attempts.
 */
final class OriginalLineUnresolvableException extends RuntimeException implements NonRetryableProjectionException
{
    public function __construct(
        public readonly string $fiscalEventId,
        public readonly string $originalReceiptId,
        public readonly int $originalLineIndex,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf(
            'OriginalLineUnresolvable: fiscal_event=%s original_receipt=%s original_line_index=%d reason=%s',
            $fiscalEventId,
            $originalReceiptId,
            $originalLineIndex,
            $reason,
        ));
    }
}
