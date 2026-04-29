<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

/**
 * Thrown when a cashier's daily refund cap would be exceeded by the current
 * return, and the cashier does not hold pos.refund_extend_daily_cap permission
 * or the tenant has dailyRefundCapOverrideAllowed = false.
 */
final class DailyRefundCapExceededException extends \DomainException
{
    /**
     * @param  numeric-string  $cap
     * @param  numeric-string  $projectedTotal
     */
    public function __construct(
        public readonly string $cap,
        public readonly string $projectedTotal,
        string $cashierId,
    ) {
        parent::__construct(
            "Daily refund cap {$cap} exceeded. ".
            "Projected total for cashier {$cashierId} today would be {$projectedTotal}. ".
            'Manager override (pos.refund_extend_daily_cap) required.'
        );
    }
}
