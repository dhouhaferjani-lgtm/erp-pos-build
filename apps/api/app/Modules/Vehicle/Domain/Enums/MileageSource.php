<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Domain\Enums;

enum MileageSource: string
{
    case Service = 'service';
    case Manual = 'manual';
    case OdometerPhoto = 'odometer_photo';
    case ExternalApi = 'external_api';
    case WorkOrderCompletion = 'work_order_completion';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
