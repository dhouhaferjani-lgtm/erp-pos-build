<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Enums;

enum RestockPolicySource: string
{
    case Product = 'product';
    case Category = 'category';
    case Company = 'company';
    case DefaultFallback = 'default';
}
