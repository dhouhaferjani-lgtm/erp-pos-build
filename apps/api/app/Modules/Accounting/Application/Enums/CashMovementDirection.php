<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Enums;

enum CashMovementDirection: string
{
    case In = 'in';
    case Out = 'out';
}
