<?php

declare(strict_types=1);

namespace App\Modules\Cart\Domain\Enums;

enum CartItemSource: string
{
    case Catalog = 'catalog';
    case Marketplace = 'marketplace';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Catalog => 'Catalog',
            self::Marketplace => 'Marketplace',
            self::Manual => 'Manual',
        };
    }
}
