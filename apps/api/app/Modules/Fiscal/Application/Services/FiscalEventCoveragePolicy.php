<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Domain\Enums\FiscalEventType;

/**
 * Explicit coverage matrix for the fiscal-event vocabulary.
 *
 * This is intentionally separate from the payload registry: the registry says
 * what is implemented today, while this policy states why each enum case is
 * projected, audit-only, or still reserved.
 */
final class FiscalEventCoveragePolicy
{
    public const PROJECTED = 'projected';

    public const AUDIT_ONLY = 'audit-only';

    public const RESERVED_UNREACHABLE = 'reserved-unreachable';

    /**
     * @var array<value-of<FiscalEventType>, self::PROJECTED|self::AUDIT_ONLY|self::RESERVED_UNREACHABLE>
     */
    private const POLICIES = [
        FiscalEventType::SALE_RECEIPT->value => self::PROJECTED,
        FiscalEventType::CHAIN_BREAK_DETECTED->value => self::AUDIT_ONLY,
        FiscalEventType::CHAIN_RESTART->value => self::AUDIT_ONLY,
        FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT->value => self::AUDIT_ONLY,
        FiscalEventType::COMPANY_DAY_CLOSURE_MANIFEST->value => self::RESERVED_UNREACHABLE,
        FiscalEventType::ACCOUNT_PAYMENT->value => self::PROJECTED,
        FiscalEventType::ACCOUNT_CHARGE->value => self::PROJECTED,
        FiscalEventType::ACCOUNT_STATUS_CHANGED->value => self::AUDIT_ONLY,
        FiscalEventType::OPERATOR_APPROVAL_GRANTED->value => self::AUDIT_ONLY,
        FiscalEventType::OVERRIDE_CREDIT_LIMIT->value => self::AUDIT_ONLY,
        FiscalEventType::OVERRIDE_ACCOUNT_STATUS->value => self::AUDIT_ONLY,
        FiscalEventType::OVERRIDE_DISCOUNT_LIMIT->value => self::AUDIT_ONLY,
        FiscalEventType::OVERRIDE_TENDER_TOLERANCE->value => self::AUDIT_ONLY,
        FiscalEventType::OVERRIDE_VOID_OR_RETURN->value => self::AUDIT_ONLY,
        FiscalEventType::ACCOUNT_REFUND->value => self::RESERVED_UNREACHABLE,
        FiscalEventType::ACCOUNT_PAYMENT_RECONCILED->value => self::RESERVED_UNREACHABLE,
        FiscalEventType::ACCOUNT_CREDIT_ISSUE->value => self::RESERVED_UNREACHABLE,
        FiscalEventType::ACCOUNT_CREDIT_USAGE->value => self::RESERVED_UNREACHABLE,
        FiscalEventType::DEPOSIT_RECEIPT->value => self::PROJECTED,
        FiscalEventType::IDENTITY_ALIAS_RECONCILED->value => self::RESERVED_UNREACHABLE,
        FiscalEventType::SALE_VOID->value => self::RESERVED_UNREACHABLE,
        FiscalEventType::SALE_CORRECTION->value => self::RESERVED_UNREACHABLE,
        FiscalEventType::REFUND_RECEIPT->value => self::RESERVED_UNREACHABLE,
        FiscalEventType::PARTIAL_REFUND->value => self::RESERVED_UNREACHABLE,
        FiscalEventType::RETURN_WITHOUT_RECEIPT->value => self::RESERVED_UNREACHABLE,
        FiscalEventType::OPENING_FLOAT->value => self::PROJECTED,
        FiscalEventType::CASH_IN->value => self::PROJECTED,
        FiscalEventType::CASH_OUT->value => self::PROJECTED,
        FiscalEventType::SAFE_DROP->value => self::PROJECTED,
        FiscalEventType::CASH_CORRECTION->value => self::PROJECTED,
        FiscalEventType::SESSION_OPEN->value => self::PROJECTED,
        FiscalEventType::SESSION_CLOSE->value => self::PROJECTED,
        FiscalEventType::X_REPORT->value => self::PROJECTED,
        FiscalEventType::Z_REPORT->value => self::PROJECTED,
        FiscalEventType::REPRINT_COPY->value => self::RESERVED_UNREACHABLE,
    ];

    /**
     * @var array<value-of<FiscalEventType>, non-empty-string>
     */
    private const CANONICAL_READER_METHODS = [
        FiscalEventType::SALE_RECEIPT->value => 'forSaleReceipt',
        FiscalEventType::ACCOUNT_PAYMENT->value => 'forAccountPayment',
        FiscalEventType::ACCOUNT_CHARGE->value => 'forAccountCharge',
        FiscalEventType::DEPOSIT_RECEIPT->value => 'forDepositReceipt',
    ];

    /**
     * @return array<value-of<FiscalEventType>, self::PROJECTED|self::AUDIT_ONLY|self::RESERVED_UNREACHABLE>
     */
    public function all(): array
    {
        return self::POLICIES;
    }

    /**
     * @return self::PROJECTED|self::AUDIT_ONLY|self::RESERVED_UNREACHABLE
     */
    public function policyFor(FiscalEventType $type): string
    {
        return self::POLICIES[$type->value];
    }

    public function isProjected(FiscalEventType $type): bool
    {
        return $this->policyFor($type) === self::PROJECTED;
    }

    public function isReservedUnreachable(FiscalEventType $type): bool
    {
        return $this->policyFor($type) === self::RESERVED_UNREACHABLE;
    }

    public function hasCanonicalReader(FiscalEventType $type): bool
    {
        return $this->canonicalReaderMethodFor($type) !== null;
    }

    public function canonicalReaderMethodFor(FiscalEventType $type): ?string
    {
        return self::CANONICAL_READER_METHODS[$type->value] ?? null;
    }
}
