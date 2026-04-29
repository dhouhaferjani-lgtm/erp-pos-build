<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Enums;

enum RedemptionMode: string
{
    case Bearer = 'bearer';
    case CustomerBound = 'customer_bound';
}
