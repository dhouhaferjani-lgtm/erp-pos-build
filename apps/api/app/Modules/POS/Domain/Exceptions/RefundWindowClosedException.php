<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

/**
 * Thrown when the return window is expired, the tenant policy is 'refuse', and
 * the cashier does not hold the pos.refund_above_threshold permission.
 *
 * This is distinct from RefundDestinationNotAllowedException (which handles
 * destination policy violations); RefundWindowClosedException is thrown before
 * destination resolution when the entire return is blocked.
 *
 * Note: RefundDestinationResolver already throws RefundDestinationNotAllowedException
 * for the 'refuse' case.  This exception provides a named, more specific type that
 * callers can catch to return HTTP 403 with code REFUND_WINDOW_CLOSED.
 */
final class RefundWindowClosedException extends \DomainException
{
    public function __construct(int $expiryDays, string $originalPostedAt)
    {
        parent::__construct(
            "The return window of {$expiryDays} days has closed for the receipt posted at {$originalPostedAt}. ".
            "The tenant policy refuses out-of-window refunds and you do not hold the 'pos.refund_above_threshold' permission."
        );
    }
}
