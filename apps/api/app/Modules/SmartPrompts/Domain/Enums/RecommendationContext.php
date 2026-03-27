<?php

declare(strict_types=1);

namespace App\Modules\SmartPrompts\Domain\Enums;

enum RecommendationContext: string
{
    case Cart = 'cart';
    case Checkout = 'checkout';
    case Reorder = 'reorder';
}
