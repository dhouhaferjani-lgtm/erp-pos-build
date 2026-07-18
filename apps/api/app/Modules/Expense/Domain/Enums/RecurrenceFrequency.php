<?php

declare(strict_types=1);

namespace App\Modules\Expense\Domain\Enums;

enum RecurrenceFrequency: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';
}
