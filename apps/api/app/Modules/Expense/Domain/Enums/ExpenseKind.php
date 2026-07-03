<?php

declare(strict_types=1);

namespace App\Modules\Expense\Domain\Enums;

enum ExpenseKind: string
{
    case Generic = 'generic';
    case LinkedCost = 'linked_cost';
}
