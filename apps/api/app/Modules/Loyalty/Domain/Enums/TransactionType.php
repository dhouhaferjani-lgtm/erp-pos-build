<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Enums;

enum TransactionType: string
{
    case Earn = 'earn';
    case Redeem = 'redeem';
    case Adjust = 'adjust';
    case Expire = 'expire';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case Bonus = 'bonus';
    case Refund = 'refund';
}
