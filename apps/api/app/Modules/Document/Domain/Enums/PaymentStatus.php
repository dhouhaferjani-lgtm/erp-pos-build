<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

/**
 * Payment status for invoices.
 *
 * This is a COMPUTED status based on outstanding amount, not stored in the database.
 * The outstanding amount is calculated from: Total - Payments - Credit Notes
 */
enum PaymentStatus: string
{
    /**
     * No payments received yet
     */
    case Unpaid = 'unpaid';

    /**
     * Some payments received, but not full amount
     */
    case PartiallyPaid = 'partially_paid';

    /**
     * Payment registered but pending bank reconciliation
     */
    case InPayment = 'in_payment';

    /**
     * Fully paid (outstanding = 0)
     */
    case Paid = 'paid';

    /**
     * Customer paid more than invoice total (has credit balance)
     */
    case Overpaid = 'overpaid';

    /**
     * Get color for UI badge display
     */
    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'red',
            self::PartiallyPaid => 'yellow',
            self::InPayment => 'blue',
            self::Paid => 'green',
            self::Overpaid => 'purple',
        };
    }

    /**
     * Get icon name for UI display
     */
    public function icon(): string
    {
        return match ($this) {
            self::Unpaid => 'circle-x',
            self::PartiallyPaid => 'clock',
            self::InPayment => 'clock',
            self::Paid => 'check-circle',
            self::Overpaid => 'alert-circle',
        };
    }

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::PartiallyPaid => 'Partially Paid',
            self::InPayment => 'In Payment',
            self::Paid => 'Paid',
            self::Overpaid => 'Overpaid',
        };
    }
}
