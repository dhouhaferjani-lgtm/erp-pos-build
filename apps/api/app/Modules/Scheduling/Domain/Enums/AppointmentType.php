<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain\Enums;

/**
 * Intake classification for an Appointment. Drives default duration &
 * default bay-type suggestion when the operator creates a new appointment.
 */
enum AppointmentType: string
{
    case QuickService = 'quick_service';
    case Inspection = 'inspection';
    case Diagnostic = 'diagnostic';
    case StandardRepair = 'standard_repair';
    case MajorRepair = 'major_repair';
    case Maintenance = 'maintenance';
    case TireService = 'tire_service';
    case Bodywork = 'bodywork';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
