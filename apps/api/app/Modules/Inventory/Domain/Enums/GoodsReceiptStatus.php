<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum GoodsReceiptStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';
}
