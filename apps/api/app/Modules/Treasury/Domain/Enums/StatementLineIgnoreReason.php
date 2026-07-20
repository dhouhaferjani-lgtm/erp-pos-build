<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum StatementLineIgnoreReason: string
{
    case Duplicate = 'duplicate';
    case Informational = 'informational';
    case BankError = 'bank_error';
    case OutOfScope = 'out_of_scope';
    case Other = 'other';
}
