<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

enum TaxType: string
{
    case Percentage = 'PERCENTAGE';
    case FixedAmount = 'FIXED_AMOUNT';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage',
            self::FixedAmount => 'Fixed Amount',
        };
    }
}
