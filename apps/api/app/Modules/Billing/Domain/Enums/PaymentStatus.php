<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case RequiresAction = 'requires_action';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    /**
     * Check if this is a final/terminal status.
     */
    public function isFinal(): bool
    {
        return in_array($this, [
            self::Succeeded,
            self::Failed,
            self::Cancelled,
            self::Refunded,
        ], true);
    }

    /**
     * Check if this status indicates success.
     */
    public function isSuccessful(): bool
    {
        return $this === self::Succeeded;
    }

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::RequiresAction => 'Requires Action',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
            self::PartiallyRefunded => 'Partially Refunded',
        };
    }
}
