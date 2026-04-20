<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Domain\Enums;

enum TransmissionType: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';
    case SemiAutomatic = 'semi_automatic';
    case Cvt = 'cvt';
    case DualClutch = 'dual_clutch';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
