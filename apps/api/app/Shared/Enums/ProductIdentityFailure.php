<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum ProductIdentityFailure: string
{
    case BarcodeAmbiguous = 'barcode_ambiguous';
    case SkuHeldByDeletedProduct = 'sku_held_by_deleted_product';
}
