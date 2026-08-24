<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

/**
 * Raised when a held order could not be recalled because another actor already
 * consumed it. Mapped to HTTP 409 by `HeldOrderController::recall()`.
 *
 * TWO raise sites, both in `HeldOrderService::recallOrder()`:
 *
 *   1. the guard after the locking read observes `status = 'recalled'`. This
 *      is the PRODUCTION site on PostgreSQL: under READ COMMITTED the loser's
 *      `SELECT ... FOR UPDATE` blocks on the winner's row lock and then
 *      re-reads the new, committed row version (EvalPlanQual), so it sees the
 *      winner's `recalled` before it ever reaches the UPDATE.
 *   2. the conditional `UPDATE ... WHERE status = 'held'` affects zero rows —
 *      the second backstop, and the only defence on SQLite, where
 *      `FOR UPDATE` is a no-op.
 *
 * Both sites raise THIS exception so the 409 contract is true on every driver.
 * A basket that merely lapsed on its own TTL is NOT a conflict: it is refused
 * with a plain `\RuntimeException` (422 `RECALL_FAILED`), because the client's
 * remedy there is not "refresh the list and try again".
 */
final class HeldOrderRecallConflictException extends \RuntimeException
{
    public static function forOrder(string $heldOrderId): self
    {
        return new self(sprintf(
            'This held order (%s) was recalled or released by another terminal. Refresh the held-orders list and try again.',
            $heldOrderId,
        ));
    }
}
