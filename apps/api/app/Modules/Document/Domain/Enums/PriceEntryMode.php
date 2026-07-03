<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

enum PriceEntryMode: string
{
    case Unit = 'unit';
    case Total = 'total';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }
}
