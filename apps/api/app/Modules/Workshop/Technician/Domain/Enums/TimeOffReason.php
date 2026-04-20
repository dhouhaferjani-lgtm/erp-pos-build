<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Domain\Enums;

enum TimeOffReason: string
{
    case Vacation = 'vacation';
    case Sick = 'sick';
    case Training = 'training';
    case Personal = 'personal';
    case Unpaid = 'unpaid';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
