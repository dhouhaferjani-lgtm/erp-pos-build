<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use DomainException;

/**
 * Thrown by `RefundCompensationService::compensate()` (§5.2/§5.3 — review
 * round-2 CRITICAL 3) when the target fiscal event does not satisfy the
 * write-off action's preconditions. Extends `\DomainException` so the
 * existing generic 422 `BUSINESS_ERROR` handler in `bootstrap/app.php`
 * maps it without a dedicated per-exception render() closure — matching
 * `RepositoryAdjustmentController`'s own established stance for this
 * exact class of precondition failure.
 *
 * Reasons (all fatal, none retryable by a mere repeat POST with the same
 * input — the caller must fix the underlying condition):
 *   - `not_a_refund` — the event is not a `SALE_RECEIPT` with
 *     `invoice_type_code=REFUND`. The write-off action only exists for
 *     rejected REFUND events.
 *   - `not_rejected` — the event is neither dead-lettered
 *     (`fiscal_event_projections.projection_status=DeadLettered`) nor
 *     ingress-quarantined (`canonical_parse_failure`) — i.e. it already
 *     applied successfully through the normal projection path. Refusing
 *     here is what prevents the double-cash-out case: booking a
 *     write-off AND having the original refund's own successful
 *     projection both move cash out of the drawer for the same event.
 *   - `missing_account_purpose` — the company's chart of accounts is
 *     missing `RefundWriteOff` or `SalesReturn` (§5.3 precheck).
 *   - `missing_cash_repository` — no active, GL-linked cash repository
 *     exists for the company.
 */
final class RefundCompensationRefusedException extends DomainException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
