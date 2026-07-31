<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown by `PosCoreReceiptProjection::apply()` (§12) when a v4 REFUND's
 * cumulative refunded quantity for an original line — INCLUDING the
 * refund currently being applied — would exceed the original sale's
 * quantity for that line.
 *
 * **NonRetryableProjectionException.** Unlike a missing-dependency
 * condition (retryable — the dependency lands on a later attempt), an
 * over-quantity refund is intrinsically invalid: no amount of retrying
 * makes the same signed event's requested quantity fit under the cap. The
 * already-signed event is NEVER silently dropped or auto-corrected
 * (Model 1, §4.1) — it dead-letters immediately via
 * `ApplyFiscalEventProjectionJob`'s dedicated
 * `catch (NonRetryableProjectionException $e)` branch (§4.2) so an
 * operator can review and route it through §5's compensation flow
 * (`invalid_refund` class) rather than exhausting all 5 Horizon retry
 * attempts on a condition that will never resolve itself.
 */
final class RefundQuantityExceededException extends RuntimeException implements NonRetryableProjectionException
{
    public function __construct(
        public readonly string $fiscalEventId,
        public readonly string $originalLineId,
        public readonly string $originalQuantity,
        public readonly string $alreadyRefundedQuantity,
        public readonly string $requestedQuantity,
    ) {
        parent::__construct(sprintf(
            'RefundQuantityExceeded: fiscal_event=%s original_line=%s original_quantity=%s '.
            'already_refunded=%s requested=%s — cumulative refund would exceed the original '.
            'sale quantity for this line.',
            $fiscalEventId,
            $originalLineId,
            $originalQuantity,
            $alreadyRefundedQuantity,
            $requestedQuantity,
        ));
    }
}
