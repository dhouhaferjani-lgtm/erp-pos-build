<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum StatementDirectionConvention: string
{
    case SignedAmount = 'signed_amount';
    case DebitCreditColumns = 'debit_credit_columns';
}
