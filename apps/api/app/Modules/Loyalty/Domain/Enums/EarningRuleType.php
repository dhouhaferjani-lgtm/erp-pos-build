<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Enums;

enum EarningRuleType: string
{
    case Spend = 'spend';
    case Item = 'item';
    case Category = 'category';
    case Quantity = 'quantity';
    case Visit = 'visit';
    case Threshold = 'threshold';
    case Time = 'time';
}
