<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum CountingScopeType: string
{
    case ProductLocation = 'product_location';
    case Product = 'product';
    case Location = 'location';
    case Category = 'category';
    case FullInventory = 'full_inventory';
    case Zone = 'zone';

    public function allowsUnexpectedItems(): bool
    {
        return $this === self::FullInventory;
    }

    public function label(): string
    {
        return match ($this) {
            self::ProductLocation => 'Specific Product at Location',
            self::Product => 'Product (All Locations)',
            self::Location => 'Location',
            self::Category => 'Category',
            self::FullInventory => 'Full Inventory',
            self::Zone => 'Zone / Shelf',
        };
    }
}
