<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum DishonorRouting: string
{
    case RePresent = 're_present';
    case Receivable = 'receivable';
    case Doubtful = 'doubtful';
}
