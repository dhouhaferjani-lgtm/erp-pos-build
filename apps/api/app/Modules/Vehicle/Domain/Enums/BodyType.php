<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Domain\Enums;

enum BodyType: string
{
    case Sedan = 'sedan';
    case Hatchback = 'hatchback';
    case Suv = 'suv';
    case Pickup = 'pickup';
    case Van = 'van';
    case Coupe = 'coupe';
    case Convertible = 'convertible';
    case Wagon = 'wagon';
    case Truck = 'truck';
    case Motorcycle = 'motorcycle';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
