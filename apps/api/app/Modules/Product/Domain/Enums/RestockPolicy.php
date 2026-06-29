<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Enums;

enum RestockPolicy: string
{
    case Never = 'never';
    case IfSealed = 'if_sealed';
    case DefaultAllow = 'default_allow';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
