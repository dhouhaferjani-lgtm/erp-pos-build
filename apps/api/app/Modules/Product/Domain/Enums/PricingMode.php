<?php
declare(strict_types=1);
namespace App\Modules\Product\Domain\Enums;

enum PricingMode: string
{
    case Auto = 'auto';
    case Manual = 'manual';
}
