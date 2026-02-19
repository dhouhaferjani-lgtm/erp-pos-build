<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

enum ProductionType: string
{
    case MadeToOrder = 'made_to_order';
    case Batch = 'batch';
    case Stock = 'stock';
}
