<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum CancellationShape: string
{
    case B2b = 'b2b';
    case PosRevenue = 'pos_revenue';
}
