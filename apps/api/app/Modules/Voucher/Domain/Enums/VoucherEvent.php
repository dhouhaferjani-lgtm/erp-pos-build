<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Enums;

enum VoucherEvent: string
{
    case Issued = 'issued';
    case Redeemed = 'redeemed';
    case PartiallyRedeemed = 'partially_redeemed';
    case Expired = 'expired';
    case Voided = 'voided';
    case Reversed = 'reversed';
    case Transferred = 'transferred';
    case RoundingAdjustment = 'rounding_adjustment';
}
