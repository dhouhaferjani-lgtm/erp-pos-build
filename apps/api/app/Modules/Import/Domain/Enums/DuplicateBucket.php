<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Enums;

enum DuplicateBucket: string
{
    case New = 'new';
    case ExistingSku = 'existing_sku';
    case ExistingBarcode = 'existing_barcode';
    case ExistingName = 'existing_name';
    case InFile = 'in_file';
    case Refused = 'refused';
}
