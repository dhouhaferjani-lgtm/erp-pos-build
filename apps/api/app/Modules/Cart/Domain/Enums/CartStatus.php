<?php

declare(strict_types=1);

namespace App\Modules\Cart\Domain\Enums;

enum CartStatus: string
{
    case Active = 'active';
    case Converted = 'converted';
    case Partial = 'partial';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Converted => 'Converted',
            self::Partial => 'Partial',
            self::Archived => 'Archived',
        };
    }
}
