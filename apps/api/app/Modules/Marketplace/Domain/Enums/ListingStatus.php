<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Domain\Enums;

enum ListingStatus: string
{
    case Active = 'active';
    case OutOfStock = 'out_of_stock';
    case Suspended = 'suspended';
    case Delisted = 'delisted';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::OutOfStock => 'Out of Stock',
            self::Suspended => 'Suspended',
            self::Delisted => 'Delisted',
        };
    }
}
