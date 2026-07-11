<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum InstrumentOrigin: string
{
    case Web = 'web';
    case Pos = 'pos';
}
