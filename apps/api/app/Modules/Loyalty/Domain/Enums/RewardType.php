<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Enums;

enum RewardType: string
{
    case FreeItem = 'free_item';
    case DiscountAmount = 'discount_amount';
    case DiscountPercent = 'discount_percent';
    case Choice = 'choice';
    case Credit = 'credit';
    case External = 'external';
}
