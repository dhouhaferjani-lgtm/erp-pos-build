<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

enum FiscalEventType: string
{
    case SALE_RECEIPT = 'SALE_RECEIPT';
    case CHAIN_BREAK_DETECTED = 'CHAIN_BREAK_DETECTED';
    case CHAIN_RESTART = 'CHAIN_RESTART';
    case TERMINAL_REGISTRY_SNAPSHOT = 'TERMINAL_REGISTRY_SNAPSHOT';
    case COMPANY_DAY_CLOSURE_MANIFEST = 'COMPANY_DAY_CLOSURE_MANIFEST';
    case ACCOUNT_PAYMENT = 'ACCOUNT_PAYMENT';
    case ACCOUNT_CHARGE = 'ACCOUNT_CHARGE';
    case ACCOUNT_STATUS_CHANGED = 'ACCOUNT_STATUS_CHANGED';
    case OPERATOR_APPROVAL_GRANTED = 'OPERATOR_APPROVAL_GRANTED';
    case OVERRIDE_CREDIT_LIMIT = 'OVERRIDE_CREDIT_LIMIT';
    case OVERRIDE_ACCOUNT_STATUS = 'OVERRIDE_ACCOUNT_STATUS';
    case OVERRIDE_DISCOUNT_LIMIT = 'OVERRIDE_DISCOUNT_LIMIT';
    case OVERRIDE_TENDER_TOLERANCE = 'OVERRIDE_TENDER_TOLERANCE';
    case OVERRIDE_VOID_OR_RETURN = 'OVERRIDE_VOID_OR_RETURN';
    case ACCOUNT_REFUND = 'ACCOUNT_REFUND';
    case ACCOUNT_PAYMENT_RECONCILED = 'ACCOUNT_PAYMENT_RECONCILED';
    case ACCOUNT_CREDIT_ISSUE = 'ACCOUNT_CREDIT_ISSUE';
    case ACCOUNT_CREDIT_USAGE = 'ACCOUNT_CREDIT_USAGE';
    case DEPOSIT_RECEIPT = 'DEPOSIT_RECEIPT';
    case IDENTITY_ALIAS_RECONCILED = 'IDENTITY_ALIAS_RECONCILED';
    case SALE_VOID = 'SALE_VOID';
    case SALE_CORRECTION = 'SALE_CORRECTION';
    case REFUND_RECEIPT = 'REFUND_RECEIPT';
    case PARTIAL_REFUND = 'PARTIAL_REFUND';
    case RETURN_WITHOUT_RECEIPT = 'RETURN_WITHOUT_RECEIPT';
    case OPENING_FLOAT = 'OPENING_FLOAT';
    case CASH_IN = 'CASH_IN';
    case CASH_OUT = 'CASH_OUT';
    case SAFE_DROP = 'SAFE_DROP';
    case CASH_CORRECTION = 'CASH_CORRECTION';
    case SESSION_OPEN = 'SESSION_OPEN';
    case SESSION_CLOSE = 'SESSION_CLOSE';
    case X_REPORT = 'X_REPORT';
    case Z_REPORT = 'Z_REPORT';
    case REPRINT_COPY = 'REPRINT_COPY';

    public function isImplemented(): bool
    {
        return in_array($this, [
            self::SALE_RECEIPT,
            self::CHAIN_BREAK_DETECTED,
            self::CHAIN_RESTART,
            self::TERMINAL_REGISTRY_SNAPSHOT,
            self::ACCOUNT_PAYMENT,
            self::ACCOUNT_CHARGE,
            self::ACCOUNT_STATUS_CHANGED,
            self::OPERATOR_APPROVAL_GRANTED,
            self::OVERRIDE_CREDIT_LIMIT,
            self::OVERRIDE_ACCOUNT_STATUS,
            self::OVERRIDE_DISCOUNT_LIMIT,
            self::OVERRIDE_TENDER_TOLERANCE,
            self::OVERRIDE_VOID_OR_RETURN,
            self::OPENING_FLOAT,
            self::CASH_IN,
            self::CASH_OUT,
            self::SAFE_DROP,
            self::CASH_CORRECTION,
            self::SESSION_OPEN,
            self::SESSION_CLOSE,
            self::X_REPORT,
            self::Z_REPORT,
        ], true);
    }

    public function isServerOnly(): bool
    {
        return in_array($this, [
            self::TERMINAL_REGISTRY_SNAPSHOT,
            self::COMPANY_DAY_CLOSURE_MANIFEST,
            self::ACCOUNT_STATUS_CHANGED,
        ], true);
    }

    public function isImplementedInPhase1(): bool
    {
        return $this->isImplemented();
    }

    public static function checkConstraintList(): string
    {
        return implode(', ', array_map(
            static fn (self $case): string => "'".$case->value."'",
            self::cases(),
        ));
    }
}
