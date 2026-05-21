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
