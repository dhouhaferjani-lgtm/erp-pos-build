<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Domain\Enums;

/**
 * Classifies a time entry for payroll + reporting. `work_order` entries feed
 * billing computation; others are informational.
 */
enum TimeEntryType: string
{
    case WorkOrder = 'work_order';
    case Break = 'break';
    case NonBillable = 'non_billable';
    case ManualAdjust = 'manual_adjust';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
