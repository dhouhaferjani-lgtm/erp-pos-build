<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

enum ComponentType: string
{
    case Product = 'product';
    case CompositeItem = 'composite_item';
}
