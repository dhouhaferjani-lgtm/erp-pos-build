<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum MovementSourceType: string
{
    case Payment = 'payment';
    case Expense = 'expense';
    case Income = 'income';
    case Refund = 'refund';
    case FiscalEvent = 'fiscal_event';
    case Transfer = 'transfer';
    case Adjustment = 'adjustment';
    case OpeningBalance = 'opening_balance';
    case Instrument = 'instrument';
}
