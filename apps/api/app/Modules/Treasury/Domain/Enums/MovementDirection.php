<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum MovementDirection: string
{
    case In = 'in';
    case Out = 'out';
}
