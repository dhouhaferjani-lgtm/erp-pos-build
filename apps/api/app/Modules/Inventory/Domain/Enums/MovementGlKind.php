<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum MovementGlKind: string
{
    case Exit = 'exit';
    case Entry = 'entry';
    case CountCorrection = 'count_correction';
    case BatchWriteOff = 'batch_write_off';
}
