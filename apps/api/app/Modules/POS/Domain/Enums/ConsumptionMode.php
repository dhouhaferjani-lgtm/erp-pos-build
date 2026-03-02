<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

enum ConsumptionMode: string
{
    case SurPlace = 'SUR_PLACE';
    case AEmporter = 'A_EMPORTER';
}
