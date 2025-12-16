<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Enums;

enum SubscriptionStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case PastDue = 'past_due';
    case Unpaid = 'unpaid';
    case Paused = 'paused';
    case Cancelling = 'cancelling';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * Check if subscription is active (has access).
     */
    public function hasAccess(): bool
    {
        return in_array($this, [
            self::Trial,
            self::Active,
            self::PastDue,
            self::Cancelling,
        ], true);
    }

    /**
     * Check if subscription can be renewed.
     */
    public function canRenew(): bool
    {
        return in_array($this, [
            self::Active,
            self::PastDue,
            self::Unpaid,
            self::Paused,
            self::Expired,
        ], true);
    }

    /**
     * Check if this is a terminal status.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Cancelled,
            self::Expired,
        ], true);
    }

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Trial',
            self::Active => 'Active',
            self::PastDue => 'Past Due',
            self::Unpaid => 'Unpaid',
            self::Paused => 'Paused',
            self::Cancelling => 'Cancelling',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }
}
