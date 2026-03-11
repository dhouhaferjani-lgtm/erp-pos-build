<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

enum OrderLineStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Served = 'served';
    case Cancelled = 'cancelled';

    /**
     * Get the human-readable label for this status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Sent => 'Sent',
            self::Preparing => 'Preparing',
            self::Ready => 'Ready',
            self::Served => 'Served',
            self::Cancelled => 'Cancelled',
        };
    }
}
