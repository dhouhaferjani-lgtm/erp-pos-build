<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

enum PriceAdjustmentType: string
{
    case Absolute = 'absolute';
    case Percentage = 'percentage';
    case Override = 'override';
}
