<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

enum ApprovalScope: string
{
    case CloseShiftVariance = 'close_shift_variance';
    case CreditLimitOverride = 'credit_limit_override';
    case AccountStatusOverride = 'account_status_override';
    case DiscountLimitOverride = 'discount_limit_override';
    case TenderToleranceOverride = 'tender_tolerance_override';
    case VoidOrReturnOverride = 'void_or_return_override';
    case CashDrawerControl = 'cash_drawer_control';

    public function permissionName(): string
    {
        return match ($this) {
            self::CloseShiftVariance => 'pos.close_shift_with_variance',
            self::CreditLimitOverride => 'pos.approve_credit_limit_override',
            self::AccountStatusOverride => 'pos.approve_account_status_override',
            self::DiscountLimitOverride => 'pos.approve_discount_limit_override',
            self::TenderToleranceOverride => 'pos.approve_tender_tolerance_override',
            self::VoidOrReturnOverride => 'pos.approve_void_or_return_override',
            self::CashDrawerControl => 'pos.approve_cash_drawer_control',
        };
    }
}
