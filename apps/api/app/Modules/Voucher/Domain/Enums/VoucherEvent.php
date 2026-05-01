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
    /**
     * Administrative metadata-only event — written by VoucherController::extendExpiry()
     * to make the expiry mutation reconstructible from the ledger. Carries no GL impact
     * (amount = '0.00000') and is symmetrical with the Transferred event. See Codex
     * review m2 (2026-04-30).
     */
    case ExpiryExtended = 'expiry_extended';
}
