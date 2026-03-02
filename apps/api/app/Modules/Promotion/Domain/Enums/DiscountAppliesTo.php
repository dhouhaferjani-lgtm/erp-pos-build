<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Domain\Enums;

enum DiscountAppliesTo: string
{
    case Transaction = 'transaction';
    case QualifyingItems = 'qualifying_items';
    case SpecificItem = 'specific_item';
    case CheapestItem = 'cheapest_item';
}
