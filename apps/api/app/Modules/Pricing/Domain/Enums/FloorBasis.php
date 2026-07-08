<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain\Enums;

enum FloorBasis: string
{
    case None = 'None';
    case Cost = 'Cost';
    case MinimumMargin = 'MinimumMargin';
    case DiscountCap = 'DiscountCap';
}
