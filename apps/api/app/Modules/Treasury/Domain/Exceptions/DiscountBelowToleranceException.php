<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a discount magnitude falls at-or-below the applicable
 * payment-tolerance margin. The discount/tolerance boundary closes a
 * sub-tolerance arbitrage where a cashier or collector could otherwise
 * launder a tolerance write-off as a discount and illegitimately reduce
 * the VAT base. See spec §7 (anti-abuse rule).
 *
 * Carries the original discount, the resolved tolerance margin, and the
 * subtotal — all as decimal strings — so callers (form validators,
 * conversion auto-strip) can render specific user-facing messages without
 * recomputing the comparison.
 */
final class DiscountBelowToleranceException extends RuntimeException
{
    public function __construct(
        public readonly string $discountAmount,
        public readonly string $toleranceMargin,
        public readonly string $subtotal,
    ) {
        parent::__construct(sprintf(
            'Discount of %s does not exceed the tolerance margin of %s for subtotal %s. '
            .'Discounts this small must be handled as payment tolerance.',
            $discountAmount,
            $toleranceMargin,
            $subtotal,
        ));
    }
}
