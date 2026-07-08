<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain\Enums;

enum PriceBasis: string
{
    case Ht = 'Ht';
    case Ttc = 'Ttc';
}
