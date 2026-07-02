<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

enum LandedCostSplitMethod: string
{
    case ByValue = 'by_value';
    case ByQuantity = 'by_quantity';
}
