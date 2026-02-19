<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Enums;

enum QualificationType: string
{
    case Spend = 'spend';
    case PointsEarned = 'points_earned';
    case Visits = 'visits';
    case Manual = 'manual';
}
