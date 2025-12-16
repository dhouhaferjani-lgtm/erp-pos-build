<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Sent = 'sent';
    case Paid = 'paid';
    case PartiallyPaid = 'partially_paid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    /**
     * Check if invoice is payable.
     */
    public function isPayable(): bool
    {
        return in_array($this, [
            self::Pending,
            self::Sent,
            self::PartiallyPaid,
            self::Overdue,
        ], true);
    }

    /**
     * Check if this is a final status.
     */
    public function isFinal(): bool
    {
        return in_array($this, [
            self::Paid,
            self::Cancelled,
            self::Refunded,
        ], true);
    }

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Pending => 'Pending',
            self::Sent => 'Sent',
            self::Paid => 'Paid',
            self::PartiallyPaid => 'Partially Paid',
            self::Overdue => 'Overdue',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
        };
    }
}
