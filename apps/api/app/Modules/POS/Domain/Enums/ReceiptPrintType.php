<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

enum ReceiptPrintType: string
{
    case Original = 'original';
    case Duplicate = 'duplicate';
    case Reprint = 'reprint';
}
