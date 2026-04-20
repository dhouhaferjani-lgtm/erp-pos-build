<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Domain\Enums;

enum FuelType: string
{
    case Gasoline = 'gasoline';
    case Diesel = 'diesel';
    case Electric = 'electric';
    case Hybrid = 'hybrid';
    case PluginHybrid = 'plugin_hybrid';
    case Lpg = 'lpg';
    case Cng = 'cng';
    case Hydrogen = 'hydrogen';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
