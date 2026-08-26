<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

use RuntimeException;

/**
 * A customer account collection landed on this terminal inside the shift's
 * window, but its `payload_snapshot` does not say WHICH shift it belongs to, so
 * its cash cannot be attributed and the shift's expected cash cannot be derived.
 *
 * Cash collected against a customer credit account physically enters the drawer,
 * and the device folds it into its own expected cash
 * (`apps/pos/src/lib/offline/zReportService.ts:272-275`). The current device
 * requires `shift_id` on every ACCOUNT_PAYMENT it authors
 * (`accountPaymentService.ts`: `shift_id: requireText(input.shiftId, 'shift_id')`),
 * so a row without one is grandfathered or hand-written — and guessing that it
 * belongs to this shift, or silently leaving it out, both put a figure into
 * `pos_shifts.expected_cash` that the NF525 JET then exports as
 * `EspecesAttendues` with `Ecart = 0`.
 *
 * Fails closed for the same reason {@see UnsignableCashMovementException} does:
 * silently-short money in a certified export is worse than a command that stops
 * and asks for a human.
 */
final class UnattributableAccountCollectionException extends RuntimeException
{
    public static function forShift(string $shiftId, string $accountPaymentReceiptId): self
    {
        return new self(sprintf(
            'Account collection %s landed on this terminal inside shift %s\'s window but its payload carries no '
            .'shift_id, so its cash cannot be attributed. Refusing to derive expected cash rather than leave it '
            .'out — a collection taken in cash is in the drawer, and omitting it exports a short figure as a '
            .'balanced count. Attribute the collection (or confirm it belongs to another shift) before closing.',
            $accountPaymentReceiptId,
            $shiftId,
        ));
    }
}
