<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

enum TableStatus: string
{
    case Available = 'available';
    case Occupied = 'occupied';
    case Reserved = 'reserved';
    case Cleaning = 'cleaning';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Occupied => 'Occupied',
            self::Reserved => 'Reserved',
            self::Cleaning => 'Cleaning',
        };
    }
}
