<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use App\Shared\Domain\CurrencyScale;
use RuntimeException;

/**
 * Thrown when a discount magnitude falls at-or-below the applicable
 * payment-tolerance margin. The discount/tolerance boundary closes a
 * sub-tolerance arbitrage where a cashier or collector could otherwise
 * launder a tolerance write-off as a discount and illegitimately reduce
 * the VAT base. See spec §7 (anti-abuse rule).
 *
 * Carries the original discount, the resolved tolerance margin, and the
 * subtotal — all as scale-4 decimal strings (matches the tolerance pipeline)
 * so callers (form validators, conversion auto-strip) can render specific
 * user-facing messages without recomputing the comparison. The displayed
 * message renders amounts at the currency's native scale (EUR/USD → 2,
 * TND/LYD → 3) so users do not see noise like "0.5000".
 */
final class DiscountBelowToleranceException extends RuntimeException
{
    public function __construct(
        public readonly string $discountAmount,
        public readonly string $toleranceMargin,
        public readonly string $subtotal,
        public readonly ?string $currencyCode = null,
    ) {
        // Derive display scale from the currency. CurrencyScale::for()
        // accepts the empty string and returns the system default (2),
        // which is the right fallback when the caller has no currency
        // context. The on-disk fields stay at their full scale-4 form —
        // we only re-format the user-visible message.
        $displayScale = CurrencyScale::for($currencyCode ?? '');

        parent::__construct(sprintf(
            'Discount of %s does not exceed the tolerance margin of %s for subtotal %s. '
            .'Discounts this small must be handled as payment tolerance.',
            CurrencyScale::bcformat($discountAmount, $displayScale),
            CurrencyScale::bcformat($toleranceMargin, $displayScale),
            CurrencyScale::bcformat($subtotal, $displayScale),
        ));
    }
}
