<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Domain\DTOs\AccountChargePayload;
use App\Modules\Fiscal\Domain\DTOs\AccountPaymentPayload;
use App\Modules\Fiscal\Domain\DTOs\AccountStatusChangedPayload;
use App\Modules\Fiscal\Domain\DTOs\CashDrawerMovementPayload;
use App\Modules\Fiscal\Domain\DTOs\ChainBreakDetectedPayload;
use App\Modules\Fiscal\Domain\DTOs\ChainRestartPayload;
use App\Modules\Fiscal\Domain\DTOs\DepositReceiptPayload;
use App\Modules\Fiscal\Domain\DTOs\OperatorApprovalGrantedPayload;
use App\Modules\Fiscal\Domain\DTOs\OverrideAccountStatusPayload;
use App\Modules\Fiscal\Domain\DTOs\OverrideCreditLimitPayload;
use App\Modules\Fiscal\Domain\DTOs\OverrideDiscountLimitPayload;
use App\Modules\Fiscal\Domain\DTOs\OverrideTenderTolerancePayload;
use App\Modules\Fiscal\Domain\DTOs\OverrideVoidOrReturnPayload;
use App\Modules\Fiscal\Domain\DTOs\SaleReceiptPayload;
use App\Modules\Fiscal\Domain\DTOs\SessionClosePayload;
use App\Modules\Fiscal\Domain\DTOs\SessionOpenPayload;
use App\Modules\Fiscal\Domain\DTOs\TerminalRegistrySnapshotPayload;
use App\Modules\Fiscal\Domain\DTOs\XReportPayload;
use App\Modules\Fiscal\Domain\DTOs\ZCashDrawerMovementPayload;
use App\Modules\Fiscal\Domain\DTOs\ZReportPayload;
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
        // SaleReceiptV2 (M4): version 2 adds variant_id/variant_name/
        // variant_sku to each line_items[] row (null for non-variant lines).
        //
        // SaleReceiptV3 (cash rounding, spec §4.4): version 3 adds the two
        // signed cash-rounding siblings `cash_rounding_adjustment` +
        // `cash_rounding_denomination` at the payload top level. Both are
        // REQUIRED-always on v3 (canonical zero when no rounding applied) and
        // FORBIDDEN on v1/v2 — see
        // `FiscalPayloadConstraintValidator::SALE_RECEIPT_PAYLOAD_KEYS_V3`.
        //
        // The authoring version below is INERT for SALE_RECEIPT: no server
        // path authors one (every `fiscal_events.event_version` for this type
        // is copied from the DEVICE envelope in `OutboxIngestor`). It is
        // bumped so the registry states the current contract; what actually
        // admits a v3 receipt is SUPPORTED_VERSIONS.
        //
        // Versions 1 and 2 remain parseable FOREVER (Events are Immutable
        // Forever) — see SUPPORTED_VERSIONS.
        // SaleReceiptV5 (D-1 post-remise VAT base, owner ruling 2026-08-25):
        // version 5 seals `subtotal` / `vat_total` / `vat_breakdown[]` NET of
        // the ticket-level remise, ventilated pro-rata per rate, and adds
        // `discount_allocated` to every `vat_breakdown[]` row. The aggregate
        // identity flips with it (the discount is no longer added back). See
        // `FiscalPayloadConstraintValidator::SALE_RECEIPT_PAYLOAD_KEYS_V5`.
        //
        // Versions 1..4 remain parseable FOREVER (Events are Immutable
        // Forever) — see SUPPORTED_VERSIONS. In particular v3 stays valid so a
        // device still on an older build keeps projecting: the cutover is
        // strictly FORWARD-ONLY.
        FiscalEventType::SALE_RECEIPT->value => [SaleReceiptPayload::class, 5],
        FiscalEventType::CHAIN_BREAK_DETECTED->value => [ChainBreakDetectedPayload::class, 1],
        FiscalEventType::CHAIN_RESTART->value => [ChainRestartPayload::class, 1],
        FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT->value => [TerminalRegistrySnapshotPayload::class, 1],
        FiscalEventType::ACCOUNT_PAYMENT->value => [AccountPaymentPayload::class, 1],
        FiscalEventType::ACCOUNT_CHARGE->value => [AccountChargePayload::class, 1],
        FiscalEventType::ACCOUNT_STATUS_CHANGED->value => [AccountStatusChangedPayload::class, 1],
        FiscalEventType::DEPOSIT_RECEIPT->value => [DepositReceiptPayload::class, 1],
        FiscalEventType::OPERATOR_APPROVAL_GRANTED->value => [OperatorApprovalGrantedPayload::class, 1],
        FiscalEventType::OVERRIDE_CREDIT_LIMIT->value => [OverrideCreditLimitPayload::class, 1],
        FiscalEventType::OVERRIDE_ACCOUNT_STATUS->value => [OverrideAccountStatusPayload::class, 1],
        FiscalEventType::OVERRIDE_DISCOUNT_LIMIT->value => [OverrideDiscountLimitPayload::class, 1],
        FiscalEventType::OVERRIDE_TENDER_TOLERANCE->value => [OverrideTenderTolerancePayload::class, 1],
        FiscalEventType::OVERRIDE_VOID_OR_RETURN->value => [OverrideVoidOrReturnPayload::class, 1],
        FiscalEventType::OPENING_FLOAT->value => [ZCashDrawerMovementPayload::class, 1],
        FiscalEventType::CASH_IN->value => [ZCashDrawerMovementPayload::class, 1],
        FiscalEventType::CASH_OUT->value => [CashDrawerMovementPayload::class, 1],
        FiscalEventType::SAFE_DROP->value => [CashDrawerMovementPayload::class, 1],
        FiscalEventType::CASH_CORRECTION->value => [ZCashDrawerMovementPayload::class, 1],
        FiscalEventType::SESSION_OPEN->value => [SessionOpenPayload::class, 1],
        FiscalEventType::SESSION_CLOSE->value => [SessionClosePayload::class, 1],
        FiscalEventType::X_REPORT->value => [XReportPayload::class, 1],
        FiscalEventType::Z_REPORT->value => [ZReportPayload::class, 1],
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
     * Event types whose historical versions remain parseable alongside the
     * current authoring version. Per "Events are Immutable Forever", a
     * version bump NEVER retires the older parse path.
     *
     * @var array<value-of<FiscalEventType>, list<int>>
     */
    private const SUPPORTED_VERSIONS = [
        // v4 (refund/void chain integration, spec §2/§17): adds the signed
        // REFUND authoring path (`invoice_type_code = 'REFUND'`) on top of
        // v3's cash-rounding contract. VOID is fail-closed at authoring
        // (`FiscalEventPayloadRegistry.ts`'s `VoidAuthoringProhibitedError`)
        // and rejected server-side by `FiscalPayloadConstraintValidator`'s
        // per-version constraint check, not a second branch here.
        //
        // v5 (D-1 post-remise VAT base, owner ruling 2026-08-25): the sealed
        // taxable base excludes the remise. v3 stays in this list forever —
        // receipts already in a chain, and devices not yet on the new build,
        // must keep parsing. What stops a NEW device authoring the old shape
        // is the forward-only version gate on the device's recorded
        // `app_version` (see `SaleReceiptForwardVersionGate`), not this list.
        FiscalEventType::SALE_RECEIPT->value => [1, 2, 3, 4, 5],
    ];

    /**
     * The CURRENT authoring version (what new events are stamped with).
     *
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

    /**
     * Every event_version the parser accepts for this type. Defaults to
     * exactly the authoring version when no historical versions exist.
     *
     * @return list<int>
     *
     * @throws FiscalEventTypeNotImplemented when the type is reserved but unimplemented in Phase 1.
     */
    public function supportedVersionsFor(FiscalEventType $type): array
    {
        return self::SUPPORTED_VERSIONS[$type->value] ?? [$this->eventVersionFor($type)];
    }

    public function isImplemented(FiscalEventType $type): bool
    {
        return isset(self::PHASE_1_MAP[$type->value]);
    }
}
