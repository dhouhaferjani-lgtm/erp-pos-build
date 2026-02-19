<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Enums;

enum ProgramType: string
{
    case Points = 'points';
    case Stamps = 'stamps';
    case Visits = 'visits';
    case Cashback = 'cashback';
    case Hybrid = 'hybrid';
}
