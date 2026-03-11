<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

enum HeldOrderStatus: string
{
    case Held = 'held';
    case Recalled = 'recalled';
    case Expired = 'expired';

    /**
     * Get human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Held => 'Held',
            self::Recalled => 'Recalled',
            self::Expired => 'Expired',
        };
    }
}
