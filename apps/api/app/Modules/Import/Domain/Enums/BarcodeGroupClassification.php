<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Enums;

enum BarcodeGroupClassification: string
{
    case MultiLocation = 'multi_location';
    case BarcodeIdentityConflict = 'barcode_identity_conflict';
}
