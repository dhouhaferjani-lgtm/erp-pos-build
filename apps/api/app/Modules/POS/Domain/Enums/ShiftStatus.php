<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

enum ShiftStatus: string
{
    case Open = 'OPEN';
    case Closed = 'CLOSED';
}
