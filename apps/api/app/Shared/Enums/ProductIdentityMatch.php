<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum ProductIdentityMatch: string
{
    case Sku = 'sku';
    case Barcode = 'barcode';
    case Name = 'name';
}
