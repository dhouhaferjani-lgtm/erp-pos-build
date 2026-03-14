<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

enum TableShape: string
{
    case Rectangle = 'rectangle';
    case Circle = 'circle';
    case Square = 'square';

    public function label(): string
    {
        return match ($this) {
            self::Rectangle => 'Rectangle',
            self::Circle => 'Circle',
            self::Square => 'Square',
        };
    }
}
