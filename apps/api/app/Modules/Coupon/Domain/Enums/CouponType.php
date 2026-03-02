<?php

declare(strict_types=1);

namespace App\Modules\Coupon\Domain\Enums;

enum CouponType: string
{
    case Standard = 'standard';
    case SingleUse = 'single_use';
    case CustomerSpecific = 'customer_specific';
}
