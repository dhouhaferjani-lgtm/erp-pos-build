<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Domain\Enums;

enum DiscountType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
    case FreeItem = 'free_item';
}
