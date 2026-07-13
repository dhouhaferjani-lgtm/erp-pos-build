<?php

declare(strict_types=1);

namespace App\Modules\Expense\Domain\Enums;

enum RecurrenceStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';
}
