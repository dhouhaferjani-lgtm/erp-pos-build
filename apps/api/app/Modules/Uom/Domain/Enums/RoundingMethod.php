<?php

declare(strict_types=1);

namespace App\Modules\Uom\Domain\Enums;

enum RoundingMethod: string
{
    case HalfUp = 'half_up';
    case Floor = 'floor';
    case Ceil = 'ceil';
}
