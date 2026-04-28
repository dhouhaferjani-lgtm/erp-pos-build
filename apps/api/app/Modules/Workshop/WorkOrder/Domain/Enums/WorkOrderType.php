<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Enums;

/**
 * High-level categorization of the nature of the work being performed.
 */
enum WorkOrderType: string
{
    case Repair = 'repair';
    case Maintenance = 'maintenance';
    case Inspection = 'inspection';
    case Bodywork = 'bodywork';
    case TireService = 'tire_service';
    case Electrical = 'electrical';
    case Diagnostic = 'diagnostic';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
