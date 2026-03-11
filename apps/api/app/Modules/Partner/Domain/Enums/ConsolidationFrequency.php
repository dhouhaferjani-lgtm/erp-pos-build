<?php

declare(strict_types=1);

namespace App\Modules\Partner\Domain\Enums;

enum ConsolidationFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
