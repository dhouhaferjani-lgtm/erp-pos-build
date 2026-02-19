<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

enum VerticalType: string
{
    case Fnb = 'fnb';
    case Manufacturing = 'manufacturing';
    case Sewing = 'sewing';
    case Bakery = 'bakery';
    case Generic = 'generic';
}
