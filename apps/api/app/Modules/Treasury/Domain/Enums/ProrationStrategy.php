<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum ProrationStrategy: string
{
    /**
     * Prorate refund across original payments by their proportional share.
     * Residual cent allocated to the last payment in deterministic order
     * (amount DESC, id ASC).
     */
    case Proportional = 'proportional';

    /**
     * Drain from the largest payment first, then the next largest, until
     * the total refund is consumed. Tiebreaker: payments.id ASC.
     */
    case LargestFirst = 'largest_first';

    /**
     * Caller supplies explicit per-payment allocations.
     * Requires pos.refund_destination_override permission — enforced by caller.
     */
    case CashierChoice = 'cashier_choice';

    public function label(): string
    {
        return match ($this) {
            self::Proportional => 'Proportional',
            self::LargestFirst => 'Largest First',
            self::CashierChoice => 'Cashier Choice',
        };
    }
}
