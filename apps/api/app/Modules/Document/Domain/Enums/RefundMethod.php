<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

/**
 * Method of refund for returned products.
 *
 * Determines how the customer will be compensated for the return.
 */
enum RefundMethod: string
{
    case OriginalPayment = 'original_payment';
    case StoreCredit = 'store_credit';
    case Exchange = 'exchange';
    case None = 'none';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::OriginalPayment => 'Refund to Original Payment Method',
            self::StoreCredit => 'Store Credit / Gift Card',
            self::Exchange => 'Exchange for Other Product',
            self::None => 'No Refund (Inspection/Repair)',
        };
    }

    /**
     * Check if this method requires creating a credit note
     */
    public function requiresCreditNote(): bool
    {
        return match ($this) {
            self::OriginalPayment, self::StoreCredit => true,
            self::Exchange, self::None => false,
        };
    }
}
