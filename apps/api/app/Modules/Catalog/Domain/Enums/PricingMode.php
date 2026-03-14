<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

enum PricingMode: string
{
    case Standard = 'standard';
    case FixedBundle = 'fixed_bundle';
}
