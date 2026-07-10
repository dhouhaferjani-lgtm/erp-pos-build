<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Domain\Enums;

enum ReplenishmentChannel: string
{
    case Pos = 'pos';
    case Web = 'web';
}
