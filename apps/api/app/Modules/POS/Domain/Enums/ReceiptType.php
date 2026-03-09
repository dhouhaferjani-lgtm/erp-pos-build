<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

enum ReceiptType: string
{
    case Sale = 'sale';
    case Return = 'return';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Sale',
            self::Return => 'Return',
        };
    }
}
