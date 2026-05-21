<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

enum ProjectionStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Applied = 'applied';
    case DeadLettered = 'dead_lettered';
}
