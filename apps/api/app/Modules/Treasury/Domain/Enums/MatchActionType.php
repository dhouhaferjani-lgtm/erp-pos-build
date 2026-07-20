<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum MatchActionType: string
{
    case OutboundClear = 'outbound_clear';
    case InboundClear = 'inbound_clear';
    case ExpenseSettle = 'expense_settle';
    case AcquirerFee = 'acquirer_fee';
    case CreateExpense = 'create_expense';
    case CreateIncome = 'create_income';
}
