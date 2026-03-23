<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

enum VatPeriodStatus: string
{
    case Open = 'OPEN';
    case Closed = 'CLOSED';
    case Filed = 'FILED';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closed => 'Closed',
            self::Filed => 'Filed',
        };
    }
}
