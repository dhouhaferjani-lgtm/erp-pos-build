<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain\Enums;

/**
 * Physical bay (lift / ramp / flat-ground slot) classification. Drives the
 * "free slots for bay_type X" queries and surfaces as a coloured badge in
 * the day-view header.
 */
enum BayType: string
{
    case General = 'general';
    case QuickService = 'quick_service';
    case Alignment = 'alignment';
    case Heavy = 'heavy';
    case Specialist = 'specialist';
    case Flat = 'flat';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
