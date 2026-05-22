<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Domain\DTOs\AccountChargePayload;
use App\Modules\Fiscal\Domain\DTOs\AccountPaymentPayload;
use App\Modules\Fiscal\Domain\DTOs\AccountStatusChangedPayload;
use App\Modules\Fiscal\Domain\DTOs\ChainBreakDetectedPayload;
use App\Modules\Fiscal\Domain\DTOs\ChainRestartPayload;
use App\Modules\Fiscal\Domain\DTOs\OperatorApprovalGrantedPayload;
use App\Modules\Fiscal\Domain\DTOs\OverrideAccountStatusPayload;
use App\Modules\Fiscal\Domain\DTOs\OverrideCreditLimitPayload;
use App\Modules\Fiscal\Domain\DTOs\OverrideDiscountLimitPayload;
use App\Modules\Fiscal\Domain\DTOs\OverrideTenderTolerancePayload;
use App\Modules\Fiscal\Domain\DTOs\OverrideVoidOrReturnPayload;
use App\Modules\Fiscal\Domain\DTOs\SaleReceiptPayload;
use App\Modules\Fiscal\Domain\DTOs\TerminalRegistrySnapshotPayload;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Exceptions\FiscalEventTypeNotImplemented;

/**
 * Resolves the payload DTO class + `event_version` for a fiscal-event type.
 *
 * Phase 1 implements four payload handlers — `SALE_RECEIPT`,
 * `CHAIN_BREAK_DETECTED`, `CHAIN_RESTART`, `TERMINAL_REGISTRY_SNAPSHOT`.
 * All other enum cases (Appendix A — `COMPANY_DAY_CLOSURE_MANIFEST`,
 * `SALE_VOID`, `Z_REPORT`, account-flow events, etc.) are RESERVED at the
 * vocabulary level so the chain can extend in later phases without
 * renumbering, but they have no DTO mapping today; the registry throws
 * `FiscalEventTypeNotImplemented` when asked to resolve them.
 *
 * This is intentionally a final class with no dependencies — it expresses
 * the static Phase 1 payload contract and is consumed by
 * `FiscalEventEngine.append()` (Task 15) and the server-side strict
 * parser (Task 16) to type-route the canonical payload bytes.
 */
final class FiscalEventPayloadRegistry
{
    /**
     * Phase 1 mapping: enum case → [dtoClass, eventVersion].
     *
     * @var array<value-of<FiscalEventType>, array{class-string, int}>
     */
    private const PHASE_1_MAP = [
        FiscalEventType::SALE_RECEIPT->value => [SaleReceiptPayload::class, 1],
        FiscalEventType::CHAIN_BREAK_DETECTED->value => [ChainBreakDetectedPayload::class, 1],
        FiscalEventType::CHAIN_RESTART->value => [ChainRestartPayload::class, 1],
        FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT->value => [TerminalRegistrySnapshotPayload::class, 1],
        FiscalEventType::ACCOUNT_PAYMENT->value => [AccountPaymentPayload::class, 1],
        FiscalEventType::ACCOUNT_CHARGE->value => [AccountChargePayload::class, 1],
        FiscalEventType::ACCOUNT_STATUS_CHANGED->value => [AccountStatusChangedPayload::class, 1],
        FiscalEventType::OPERATOR_APPROVAL_GRANTED->value => [OperatorApprovalGrantedPayload::class, 1],
        FiscalEventType::OVERRIDE_CREDIT_LIMIT->value => [OverrideCreditLimitPayload::class, 1],
        FiscalEventType::OVERRIDE_ACCOUNT_STATUS->value => [OverrideAccountStatusPayload::class, 1],
        FiscalEventType::OVERRIDE_DISCOUNT_LIMIT->value => [OverrideDiscountLimitPayload::class, 1],
        FiscalEventType::OVERRIDE_TENDER_TOLERANCE->value => [OverrideTenderTolerancePayload::class, 1],
        FiscalEventType::OVERRIDE_VOID_OR_RETURN->value => [OverrideVoidOrReturnPayload::class, 1],
    ];

    /**
     * @return class-string
     *
     * @throws FiscalEventTypeNotImplemented when the type is reserved but unimplemented in Phase 1.
     */
    public function dtoClassFor(FiscalEventType $type): string
    {
        $entry = self::PHASE_1_MAP[$type->value] ?? null;
        if ($entry === null) {
            throw new FiscalEventTypeNotImplemented($type);
        }

        return $entry[0];
    }

    /**
     * @throws FiscalEventTypeNotImplemented when the type is reserved but unimplemented in Phase 1.
     */
    public function eventVersionFor(FiscalEventType $type): int
    {
        $entry = self::PHASE_1_MAP[$type->value] ?? null;
        if ($entry === null) {
            throw new FiscalEventTypeNotImplemented($type);
        }

        return $entry[1];
    }

    public function isImplemented(FiscalEventType $type): bool
    {
        return isset(self::PHASE_1_MAP[$type->value]);
    }
}
