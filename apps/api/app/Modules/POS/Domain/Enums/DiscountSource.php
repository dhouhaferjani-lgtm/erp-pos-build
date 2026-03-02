<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

enum DiscountSource: string
{
    case Manual = 'manual';
    case Promotion = 'promotion';
    case Coupon = 'coupon';
    case Loyalty = 'loyalty';
}
