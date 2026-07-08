<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain\Enums;

enum DiscountFloorMode: string
{
    case Advisory = 'Advisory';
    case WarnRequiresPermission = 'WarnRequiresPermission';
    case Block = 'Block';
}
