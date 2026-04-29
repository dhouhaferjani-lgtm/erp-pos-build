<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Enums;

enum VoucherStatus: string
{
    case Issued = 'issued';
    case PartiallyRedeemed = 'partially_redeemed';
    case FullyRedeemed = 'fully_redeemed';
    case Expired = 'expired';
    case Voided = 'voided';
}
