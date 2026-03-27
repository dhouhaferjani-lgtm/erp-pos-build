<?php

declare(strict_types=1);

namespace App\Modules\SmartPrompts\Domain\Enums;

enum SkinType: string
{
    case Normal = 'normal';
    case Oily = 'oily';
    case Dry = 'dry';
    case Combination = 'combination';
    case Sensitive = 'sensitive';
}
