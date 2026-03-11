<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

enum OrderStatus: string
{
    case Open = 'open';
    case SentToKitchen = 'sent_to_kitchen';
    case Ready = 'ready';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * Get the human-readable label for this status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::SentToKitchen => 'Sent to Kitchen',
            self::Ready => 'Ready',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Determine if this status represents an active order.
     */
    public function isActive(): bool
    {
        return match ($this) {
            self::Open, self::SentToKitchen, self::Ready => true,
            self::Closed, self::Cancelled => false,
        };
    }
}
