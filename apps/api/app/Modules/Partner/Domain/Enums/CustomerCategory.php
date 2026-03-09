<?php

declare(strict_types=1);

namespace App\Modules\Partner\Domain\Enums;

enum CustomerCategory: string
{
    case Individual = 'individual';
    case Business = 'business';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
