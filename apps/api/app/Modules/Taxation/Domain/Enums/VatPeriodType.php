<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

enum VatPeriodType: string
{
    case Monthly = 'MONTHLY';
    case Quarterly = 'QUARTERLY';
    case Annual = 'ANNUAL';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::Annual => 'Annual',
        };
    }
}
