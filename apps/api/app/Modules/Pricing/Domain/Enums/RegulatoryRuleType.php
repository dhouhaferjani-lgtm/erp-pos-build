<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain\Enums;

enum RegulatoryRuleType: string
{
    case BelowCostFloor = 'BelowCostFloor';
    case PharmaMarginSchedule = 'PharmaMarginSchedule';
}
