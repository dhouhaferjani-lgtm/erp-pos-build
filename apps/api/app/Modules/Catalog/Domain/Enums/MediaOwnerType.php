<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

enum MediaOwnerType: string
{
    case Product = 'PRODUCT';
    case ProductVariant = 'PRODUCT_VARIANT';
    case Category = 'CATEGORY';
}
