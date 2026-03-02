<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Domain\Enums;

enum PromotionType: string
{
    case HappyHour = 'happy_hour';
    case BuyXGetY = 'buy_x_get_y';
    case VolumeDiscount = 'volume_discount';
    case CategoryDiscount = 'category_discount';
    case ComboDiscount = 'combo_discount';
}
