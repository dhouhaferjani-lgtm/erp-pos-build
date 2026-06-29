<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

enum ReturnLineDisposition: string
{
    case Restock = 'restock';
    case Scrap = 'scrap';
    case NotReceived = 'not_received';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
