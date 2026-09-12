<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum TransferReceiptStatus: string
{
    case Posted = 'posted';
}
