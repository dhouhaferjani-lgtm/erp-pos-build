<?php

declare(strict_types=1);

namespace App\Modules\Coupon\Domain\Enums;

enum CouponStatus: string
{
    case Active = 'active';
    case Exhausted = 'exhausted';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
