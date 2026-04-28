<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Domain\Enums;

/**
 * Provenance of a time entry — event-driven (WorkOrder lifecycle),
 * manager-entered, or bulk-imported.
 */
enum TimeEntrySource: string
{
    case Event = 'event';
    case Manual = 'manual';
    case Import = 'import';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
