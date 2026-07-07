<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

/**
 * Fixed v1 node-type set for the location placement hierarchy (D3). Types are
 * labels + icons only — no ordering constraints between parent/child types.
 */
enum LocationNodeType: string
{
    case Zone = 'zone';
    case Aisle = 'aisle';
    case Rack = 'rack';
    case Shelf = 'shelf';
    case Bin = 'bin';
    case Section = 'section';

    public function label(): string
    {
        return match ($this) {
            self::Zone => 'Zone',
            self::Aisle => 'Aisle',
            self::Rack => 'Rack',
            self::Shelf => 'Shelf',
            self::Bin => 'Bin',
            self::Section => 'Section',
        };
    }
}
