<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum ReconciliationStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
