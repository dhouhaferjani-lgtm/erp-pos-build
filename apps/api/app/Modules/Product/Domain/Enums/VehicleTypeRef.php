<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Enums;

enum VehicleTypeRef: string
{
    case Pc = 'pc';
    case Cv = 'cv';
    case Mtb = 'mtb';
    case Eng = 'eng';
    case Axl = 'axl';
    case Universal = 'universal';

    public function label(): string
    {
        return match ($this) {
            self::Pc => 'Passenger Car',
            self::Cv => 'Commercial Vehicle',
            self::Mtb => 'Motorbike',
            self::Eng => 'Engine',
            self::Axl => 'Axle',
            self::Universal => 'Universal',
        };
    }
}
