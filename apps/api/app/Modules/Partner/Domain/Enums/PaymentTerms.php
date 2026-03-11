<?php

declare(strict_types=1);

namespace App\Modules\Partner\Domain\Enums;

enum PaymentTerms: string
{
    case Immediate = 'immediate';
    case Net15 = 'net_15';
    case Net30 = 'net_30';
    case Net60 = 'net_60';
    case Net90 = 'net_90';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Immediate => 'Immediate',
            self::Net15 => 'Net 15',
            self::Net30 => 'Net 30',
            self::Net60 => 'Net 60',
            self::Net90 => 'Net 90',
            self::Custom => 'Custom',
        };
    }

    /**
     * Returns the number of days for standard payment terms.
     * Returns null for Custom (user-defined days).
     */
    public function days(): ?int
    {
        return match ($this) {
            self::Immediate => 0,
            self::Net15 => 15,
            self::Net30 => 30,
            self::Net60 => 60,
            self::Net90 => 90,
            self::Custom => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
