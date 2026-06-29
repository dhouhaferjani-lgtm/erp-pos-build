<?php

declare(strict_types=1);

namespace App\Shared\Domain\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum SkinType: string
{
    case Normal = 'normal';
    case Oily = 'oily';
    case Dry = 'dry';
    case Combination = 'combination';
    case Sensitive = 'sensitive';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Peau normale',
            self::Oily => 'Peau grasse',
            self::Dry => 'Peau sèche',
            self::Combination => 'Peau mixte',
            self::Sensitive => 'Peau sensible',
        };
    }
}
