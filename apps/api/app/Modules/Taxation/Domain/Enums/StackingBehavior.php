<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

enum StackingBehavior: string
{
    case SUBTOTAL = 'SUBTOTAL';
    case TOTAL_INCLUDING_PREVIOUS = 'TOTAL_INCLUDING_PREVIOUS';

    public function label(): string
    {
        return match ($this) {
            self::SUBTOTAL => 'Calculate on subtotal only',
            self::TOTAL_INCLUDING_PREVIOUS => 'Compound (calculate on subtotal + all previous taxes)',
        };
    }
}
