<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum TransferCloseDisposition: string
{
    case WriteOff = 'write_off';
    case ReturnToSource = 'return_to_source';
}
