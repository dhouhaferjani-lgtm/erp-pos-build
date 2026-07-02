<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

enum CostApplicationPath: string
{
    case LandedCost = 'landed_cost';
    case WacAdjustment = 'wac_adjustment';
    case ProfitOnly = 'profit_only';
}
