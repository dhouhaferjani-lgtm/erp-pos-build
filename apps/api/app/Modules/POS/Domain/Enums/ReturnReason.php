<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

enum ReturnReason: string
{
    case Defective = 'defective';
    case WrongItem = 'wrong_item';
    case CustomerChangedMind = 'customer_changed_mind';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Defective => 'Defective Product',
            self::WrongItem => 'Wrong Item',
            self::CustomerChangedMind => 'Customer Changed Mind',
            self::Other => 'Other',
        };
    }
}
