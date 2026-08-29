<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Domain\DTOs\AccountChargePayload;
use App\Modules\Fiscal\Domain\DTOs\AccountStatusChangedPayload;
use App\Modules\Fiscal\Domain\DTOs\CashDrawerMovementPayload;
use App\Modules\Fiscal\Domain\DTOs\DepositReceiptPayload;
use App\Modules\Fiscal\Domain\DTOs\OperatorApprovalGrantedPayload;
use App\Modules\Fiscal\Domain\DTOs\OverrideAccountStatusPayload;
use App\Modules\Fiscal\Domain\DTOs\OverrideCreditLimitPayload;
use App\Modules\Fiscal\Domain\DTOs\OverrideDiscountLimitPayload;
use App\Modules\Fiscal\Domain\DTOs\OverrideTenderTolerancePayload;
use App\Modules\Fiscal\Domain\DTOs\OverrideVoidOrReturnPayload;
use App\Modules\Fiscal\Domain\DTOs\SessionClosePayload;
use App\Modules\Fiscal\Domain\DTOs\SessionOpenPayload;
use App\Modules\Fiscal\Domain\DTOs\XReportPayload;
use App\Modules\Fiscal\Domain\DTOs\ZCashDrawerMovementPayload;
use App\Modules\Fiscal\Domain\DTOs\ZReportPayload;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Shared\Domain\CashRoundingCaps;
use App\Shared\Domain\TransactionRemiseSplit;
use App\Shared\Domain\Validation\CountryTaxNumberRules;
use LogicException;
use RuntimeException;

/**
 * Per-event payload constraint validator — spec v7 §7.6.
 *
 * Extracted from `StrictCanonicalParser` round-2 (Task 24 Opus F2 +
 * Codex T24-P1 convergent finding) so the SAME constraint surface gates
 * BOTH the canonical-bytes parse path (`StrictCanonicalParser::parse()`)
 * AND the operator-supplied corrected payload path
 * (`ParseFailureResolutionService::resolve()`).
 *
 * **Pass 2A.PHP.1 rewrite** — SALE_RECEIPT now validates the
 * 28-key Candidate C-v3 canonical contract (synthesis v5 §3 + §6 + Phase 4 approval references).
 * The 10-key v1 shape is REJECTED. Pass 2A.PHP.2 migrates the
 * consumer-side projection + Nf525 sub-method bifurcation.
 *
 * **Per-event clauses (Phase 1):**
 *   - SALE_RECEIPT — 28-key nested shape per Candidate C-v3 §3:
 *     * Top-level money fields (subtotal, vat_total, total,
 *       transaction_discount_amount) at currency_scale, NON-NEGATIVE.
 *     * Total arithmetic cross-check (§6.D): subtotal + vat_total ==
 *       total + transaction_discount_amount at currency_scale.
 *     * Discount-reason consistency (§6.A): amount==0 iff reason==null;
 *       comparison via BCMath bccomp at currency_scale (bcformat at
 *       scale=2 emits "0.00", not literal "0").
 *     * Nested seller / buyer / line_items[] / payments[] /
 *       vat_breakdown[] / original_receipt_reference / vouchers_redeemed[].
 *     * VAT partition algorithm (§6.C): vat_breakdown is the partition
 *       of line_items by (vat_rate, tax_category_code). Sums via bcadd
 *       at currency_scale; equality via bccomp.
 *     * Scale invariant (§6.B field table): every bcformat field matches
 *       moneyRegex at its declared scale.
 *     * Enums: invoice_type_code ∈ {SALE,REFUND,VOID,TRAINING};
 *       consumption_mode ∈ {dine_in,takeaway}|null; non_collected_subtype
 *       ∈ {servizi,beni,omaggio,successiva}|null.
 *     * **R2 closures (Pass 2A.PHP.1 round-2 Codex findings):**
 *       - N-01: `currency_scale` restricted to `{0, 2, 3}` allowlist;
 *         forensic prefix `payload_currency_scale_unsupported`.
 *       - N-02: identity fields (`cashier_id`, `terminal_id`, `shift_id`,
 *         `receipt_uuid`, `original_receipt_reference.fiscal_event_id`,
 *         `original_receipt_reference.original_receipt_uuid`) validated
 *         as lowercase-hex UUID (`payload_uuid_format_mismatch`).
 *         `event_time_device` validated as ISO 8601 with milliseconds +
 *         timezone offset / Z (`payload_datetime_format_mismatch`).
 *       - N-03: TRAINING-flag coupling invariant —
 *         `(invoice_type_code === 'TRAINING') ⟺ training_flag`
 *         (`payload_training_flag_mismatch`).
 *     * Tax-number validation: universal baseline
 *       `^[A-Za-z0-9 \-/.]{4,40}$` + country-specific strict regex
 *       for FR / TN / SA / DE / IT (Phase 1.5.2).
 *     * tax_jurisdiction_country_code: ISO 3166-1 alpha-2 (`^[A-Z]{2}$`).
 *   - CHAIN_BREAK_DETECTED — unchanged from v1.
 *   - CHAIN_RESTART — unchanged from v1.
 *   - TERMINAL_REGISTRY_SNAPSHOT — unchanged from v1.
 *
 * **Default arm `throw new LogicException`** — adding a new
 * Phase 1 FiscalEventType without a matching clause here is a planning
 * defect, not a runtime data anomaly. Mirrors the parser's own
 * Opus P2-3 round-2 fix.
 *
 * **Throws** `RuntimeException` on constraint violation. The parser
 * wraps this as `sub_array_shape:<message>` per its existing
 * `ParseResult::failure()` discipline; the resolver wraps it as
 * `InvalidCorrectedPayloadException` so the caller sees a typed
 * boundary error.
 */
final class FiscalPayloadConstraintValidator
{
    /** 64-char lowercase hex — spec §4 hash format. */
    private const LOWER_HEX_64 = '/^[0-9a-f]{64}$/D';

    /** ISO 3166-1 alpha-2 country code. */
    private const ISO_3166_ALPHA_2 = '/^[A-Z]{2}$/D';

    /** ISO 8601 date `YYYY-MM-DD`. */
    private const ISO_8601_DATE = '/^\d{4}-\d{2}-\d{2}$/D';

    /**
     * ISO 8601 datetime with milliseconds AND timezone offset (Z or +/-HH:MM).
     *
     * Synthesis v3 line 51 + spec v7 §11.2 line 571 require
     * `event_time_device` in this exact shape. Examples:
     *   - `2026-05-20T14:30:00.000Z`
     *   - `2026-05-20T14:30:00.123+02:00`
     *   - `2026-05-20T14:30:00.500-05:30`
     *
     * Second-precision without milliseconds (`2026-05-20T14:30:00Z`) is
     * REJECTED per the synthesis contract.
     */
    private const ISO_8601_DATETIME_MS_TZ = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}(Z|[+-]\d{2}:\d{2})$/D';

    /**
     * Lowercase-hex UUID (any RFC 4122 form — version-agnostic at this layer).
     *
     * Matches `cashier_id`, `terminal_id`, `shift_id`, `receipt_uuid`,
     * `original_receipt_reference.fiscal_event_id`,
     * `original_receipt_reference.original_receipt_uuid` per synthesis v3
     * lines 46-95 + spec v7 §11.2 line 571.
     *
     * Note: `product_id`, legacy `buyer.customer_id`, `buyer.contact_id`, and
     * `table_id` were documented as opaque strings. SALE_RECEIPT v5 tightens
     * buyer.customer_id to UUID-or-null while v1-v4 remain grandfathered.
     */
    private const LOWER_HEX_UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    /** ISO 4217 alpha-3 currency code. */
    private const ISO_4217 = '/^[A-Z]{3}$/D';

    /**
     * Universal tax-number baseline (synthesis v5 §7).
     *
     * Length 4-40, characters [A-Za-z0-9 \-/.]. The validator additionally
     * rejects empty / control-char / untrimmed values before applying the
     * country-specific Phase 1.5.2 regex table below.
     */
    private const TAX_NUMBER_UNIVERSAL = '/^[A-Za-z0-9 \-\/.]{4,40}$/D';

    /**
     * Phase 1.5.2 seller/customer tax-number regex table.
     *
     * NOT a local copy: this IS `CountryTaxNumberRules::PATTERNS`, the single
     * source of truth shared with every entry-time validator (partner
     * `vat_number`, location `tax_id`, the advisory `TaxIdValidationService`).
     * A local copy is what let the partner-entry TN pattern drift into
     * rejecting-at-seal-time values it had itself accepted (research spec
     * 2026-08-23 §3.3).
     *
     * TN accepts slash-separated input after compact normalization
     * (`1234567/A/M/000` -> `1234567AM000`) but the canonical producer
     * emits the compact form.
     *
     * @var array<string, string>
     */
    private const TAX_NUMBER_PATTERNS = CountryTaxNumberRules::PATTERNS;

    /** FR buyer TVA intracommunautaire extension. */
    private const FR_BUYER_TVA_INTRACOM = '/^FR[0-9]{11}$/D';

    /** IT codice fiscale belongs in `buyer.codice_fiscale`, not `tax_number`. */
    private const IT_BUYER_CODICE_FISCALE = '/^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$/D';

    /** Phase-1 fixed quantity scale per synthesis v5 §6.B. */
    private const QUANTITY_SCALE = 3;

    /** Phase-1 fixed VAT-rate scale per synthesis v5 §6.B. */
    private const VAT_RATE_SCALE = 2;

    /**
     * Supported `currency_scale` allowlist per synthesis v3 §3 line 49-50
     * (`currency_scale: number; // 0|2|3`) + spec v7 §11.2 line 577.
     *
     * 0 = JPY-style (no fractional units)
     * 2 = EUR/USD/GBP/SAR (cents)
     * 3 = TND (millimes)
     *
     * Any other value at the payload boundary is rejected with
     * `payload_currency_scale_unsupported` — defends against drift from the
     * locked TS/PHP contract and the golden-vector domain.
     */
    private const SUPPORTED_CURRENCY_SCALES = [0, 2, 3];

    /** invoice_type_code domain (synthesis v5 §3). */
    private const INVOICE_TYPE_CODES = ['SALE', 'REFUND', 'VOID', 'TRAINING'];

    /** consumption_mode domain (synthesis v5 §3). */
    private const CONSUMPTION_MODES = ['dine_in', 'takeaway'];

    /** non_collected_subtype domain (synthesis v5 §3 — IT future). */
    private const NON_COLLECTED_SUBTYPES = ['servizi', 'beni', 'omaggio', 'successiva'];

    private const ACCOUNT_PAYMENT_CUSTOMER_SYNC_STATUSES = ['synced', 'pending_create'];

    private const ACCOUNT_PAYMENT_ALLOCATION_POLICIES = ['FIFO'];

    private const DEPOSIT_RECEIPT_ALLOCATION_POLICIES = ['FIFO'];

    private const ACCOUNT_PAYMENT_STALENESS_REASONS = ['never_synced', 'older_than_threshold', 'server_conflict_pending'];

    private const ACCOUNT_CHARGE_INVOICE_CLASSIFICATIONS = ['b2c_charge_receipt', 'b2b_facture_draft_requested'];

    private const ACCOUNT_CHARGE_STALE_POLICY_ACTIONS = ['allow', 'warn', 'block'];

    /** Foreign-currency scale lookup (synthesis v5 §6.B). */
    private const CURRENCY_SCALES = [
        'EUR' => 2,
        'USD' => 2,
        'GBP' => 2,
        'SAR' => 2,
        'TND' => 3,
        'JPY' => 0,
    ];

    /**
     * Expected payload key set per event type. Round-2 mirror of
     * `StrictCanonicalParser::PAYLOAD_KEYS` — extracted here so the
     * resolver enforces the SAME extras-rejection rule.
     *
     * SALE_RECEIPT: 28-key sorted-lex Candidate C-v3 list (synthesis v5 §3 + Phase 4).
     *
     * @var array<value-of<FiscalEventType>, list<string>>
     */
    public const PAYLOAD_KEYS = [
        'SALE_RECEIPT' => [
            'approval_references',
            'business_date',
            'buyer',
            'cashier_id',
            'cashier_name',
            'consumption_mode',
            'currency_code',
            'currency_scale',
            'event_time_device',
            'invoice_type_code',
            'line_items',
            'lottery_code',
            'notes',
            'original_receipt_reference',
            'payments',
            'receipt_uuid',
            'seller',
            'shift_id',
            'subtotal',
            'table_id',
            'terminal_id',
            'total',
            'training_flag',
            'transaction_discount_amount',
            'transaction_discount_reason',
            'vat_breakdown',
            'vat_total',
            'vouchers_redeemed',
        ],
        'CHAIN_BREAK_DETECTED' => [
            'last_good_hash', 'last_good_sequence',
            'offending_record_reference', 'reason',
        ],
        'CHAIN_RESTART' => [
            'last_good_anchor', 'new_genesis_reference',
            'operator_authorization_evidence', 'provenance_link',
        ],
        'TERMINAL_REGISTRY_SNAPSHOT' => [
            'prior_snapshot_link', 'snapshot_hash', 'terminals',
        ],
        'ACCOUNT_PAYMENT' => [
            'account_payment_uuid',
            'business_date',
            'cashier_id',
            'cashier_name',
            'currency_code',
            'currency_scale',
            'customer',
            'event_time_device',
            'local_balance_snapshot',
            'notes',
            'payment',
            'receipt_type_code',
            'references',
            'regime_extensions',
            'seller',
            'shift_id',
            'staleness',
            'terminal_id',
            'training_flag',
            'treasury_allocation_policy',
        ],
        'ACCOUNT_CHARGE' => AccountChargePayload::PAYLOAD_KEYS,
        'ACCOUNT_STATUS_CHANGED' => AccountStatusChangedPayload::PAYLOAD_KEYS,
        'DEPOSIT_RECEIPT' => DepositReceiptPayload::PAYLOAD_KEYS,
        'OPERATOR_APPROVAL_GRANTED' => OperatorApprovalGrantedPayload::PAYLOAD_KEYS,
        'OVERRIDE_CREDIT_LIMIT' => OverrideCreditLimitPayload::PAYLOAD_KEYS,
        'OVERRIDE_ACCOUNT_STATUS' => OverrideAccountStatusPayload::PAYLOAD_KEYS,
        'OVERRIDE_DISCOUNT_LIMIT' => OverrideDiscountLimitPayload::PAYLOAD_KEYS,
        'OVERRIDE_TENDER_TOLERANCE' => OverrideTenderTolerancePayload::PAYLOAD_KEYS,
        'OVERRIDE_VOID_OR_RETURN' => OverrideVoidOrReturnPayload::PAYLOAD_KEYS,
        'OPENING_FLOAT' => ZCashDrawerMovementPayload::PAYLOAD_KEYS,
        'CASH_IN' => ZCashDrawerMovementPayload::PAYLOAD_KEYS,
        'CASH_OUT' => CashDrawerMovementPayload::PAYLOAD_KEYS,
        'SAFE_DROP' => CashDrawerMovementPayload::PAYLOAD_KEYS,
        'CASH_CORRECTION' => ZCashDrawerMovementPayload::PAYLOAD_KEYS,
        'SESSION_OPEN' => SessionOpenPayload::PAYLOAD_KEYS,
        'SESSION_CLOSE' => SessionClosePayload::PAYLOAD_KEYS,
        'X_REPORT' => XReportPayload::PAYLOAD_KEYS,
        'Z_REPORT' => ZReportPayload::PAYLOAD_KEYS,
    ];

    /**
     * SALE_RECEIPT **v3** key set — the 28-key v2 contract plus the two
     * signed cash-rounding siblings (spec §4.4). Lexicographically sorted:
     * `cash_rounding_*` sorts between `buyer` and `cashier_id` because
     * `_` (0x5F) < `i` (0x69) under the code-unit ordering the device's JCS
     * canonicalizer uses.
     *
     * A NAMED constant, never a mutation of PAYLOAD_KEYS — v1/v2 events must
     * keep rejecting these keys as `payload_extra_field` forever.
     *
     * @var list<string>
     */
    public const SALE_RECEIPT_PAYLOAD_KEYS_V3 = [
        'approval_references',
        'business_date',
        'buyer',
        'cash_rounding_adjustment',
        'cash_rounding_denomination',
        'cashier_id',
        'cashier_name',
        'consumption_mode',
        'currency_code',
        'currency_scale',
        'event_time_device',
        'invoice_type_code',
        'line_items',
        'lottery_code',
        'notes',
        'original_receipt_reference',
        'payments',
        'receipt_uuid',
        'seller',
        'shift_id',
        'subtotal',
        'table_id',
        'terminal_id',
        'total',
        'training_flag',
        'transaction_discount_amount',
        'transaction_discount_reason',
        'vat_breakdown',
        'vat_total',
        'vouchers_redeemed',
    ];

    /**
     * SALE_RECEIPT **v5** key set — D-1 (owner ruling 2026-08-25, option (a)).
     *
     * The SAME 30 top-level keys as v3. What changes at v5 is the SEMANTICS:
     * `subtotal`, `vat_total` and every `vat_breakdown[]` row are sealed NET of
     * the ticket-level remise, ventilated pro-rata per rate, and each
     * breakdown row carries `discount_allocated`
     * ({@see SALE_RECEIPT_VAT_BREAKDOWN_KEYS_V5}).
     *
     * The aggregate identity flips with it:
     *   - v1/v2/v3: `subtotal + vat_total == (total - adjustment) + discount`
     *     (the discount is added BACK — the base was the PRE-discount gross);
     *   - v5:       `subtotal + vat_total == total - adjustment`.
     *
     * A NAMED constant, never a mutation of SALE_RECEIPT_PAYLOAD_KEYS_V3 — the
     * v3 record stays frozen forever, and v3 payloads keep being ACCEPTED
     * forever (older devices in the field; the cutover is forward-only).
     *
     * @var list<string>
     */
    public const SALE_RECEIPT_PAYLOAD_KEYS_V5 = [
        'approval_references',
        'business_date',
        'buyer',
        'cash_rounding_adjustment',
        'cash_rounding_denomination',
        'cashier_id',
        'cashier_name',
        'consumption_mode',
        'currency_code',
        'currency_scale',
        'event_time_device',
        'invoice_type_code',
        'line_items',
        'lottery_code',
        'notes',
        'original_receipt_reference',
        'payments',
        'receipt_uuid',
        'seller',
        'shift_id',
        'subtotal',
        'table_id',
        'terminal_id',
        'total',
        'training_flag',
        'transaction_discount_amount',
        'transaction_discount_reason',
        'vat_breakdown',
        'vat_total',
        'vouchers_redeemed',
    ];

    /**
     * `vat_breakdown[]` row key set at **v5** — the five v1..v4 keys plus
     * `discount_allocated`, this group's pro-rata share of the ticket remise.
     * Sorted; mirrors `FiscalEventEngine.ts`'s
     * `SALE_RECEIPT_VAT_BREAKDOWN_KEYS_V5` (the FiscalPayloadKeyDrift gate pins
     * the two lists against each other).
     *
     * @var list<string>
     */
    public const SALE_RECEIPT_VAT_BREAKDOWN_KEYS_V5 = [
        'discount_allocated',
        'gross_amount',
        'net_amount',
        'rate',
        'tax_category_code',
        'vat_amount',
    ];

    /**
     * The lowest `event_version` whose SALE_RECEIPT payload seals the taxable
     * base NET of the ticket remise (D-1). Mirrors
     * `FiscalEventPayloadRegistry.ts`'s `SALE_RECEIPT_AUTHORED_EVENT_VERSION`.
     */
    public const SALE_RECEIPT_POST_DISCOUNT_BASE_VERSION = 5;

    /**
     * SALE_RECEIPT **v4** key set — spec `2026-07-31-v3-refund-chain-integration.md`
     * §3.3/§3.4/§17. The v3 30-key contract plus three new top-level keys
     * authored ONLY by the device's REFUND normalization path
     * (`RefundReceiptV4Payload.ts`, §3.2): `original_line_references`,
     * `refund_destination`, `settlement_allocation`. Lexicographically
     * sorted — `original_line_references` sorts before
     * `original_receipt_reference` (`l` < `r`); `refund_destination`
     * sorts between `receipt_uuid` and `seller` (`c` < `f` < `s`);
     * `settlement_allocation` sorts between `seller` and `shift_id`
     * (`se` + `l` < `t`, then `e` < `h`).
     *
     * A NAMED constant, never a mutation of SALE_RECEIPT_PAYLOAD_KEYS_V3 —
     * v1/v2/v3 events must keep rejecting these keys as `payload_extra_field`
     * forever (Events are Immutable Forever).
     *
     * @var list<string>
     */
    public const SALE_RECEIPT_PAYLOAD_KEYS_V4 = [
        'approval_references',
        'business_date',
        'buyer',
        'cash_rounding_adjustment',
        'cash_rounding_denomination',
        'cashier_id',
        'cashier_name',
        'consumption_mode',
        'currency_code',
        'currency_scale',
        'event_time_device',
        'invoice_type_code',
        'line_items',
        'lottery_code',
        'notes',
        'original_line_references',
        'original_receipt_reference',
        'payments',
        'receipt_uuid',
        'refund_destination',
        'seller',
        'settlement_allocation',
        'shift_id',
        'subtotal',
        'table_id',
        'terminal_id',
        'total',
        'training_flag',
        'transaction_discount_amount',
        'transaction_discount_reason',
        'vat_breakdown',
        'vat_total',
        'vouchers_redeemed',
    ];

    /** original_line_references[].disposition domain — verbatim values of the
     *  existing `App\Modules\POS\Domain\Enums\ReturnLineDisposition` enum
     *  (spec §3.3): no new enum, no cross-module import, the v4 contract
     *  reuses the legacy vocabulary exactly. */
    private const RETURN_LINE_DISPOSITIONS = ['restock', 'scrap', 'not_received'];

    /** v4 refund_destination domain — single literal for launch (spec §3.4). */
    private const REFUND_DESTINATIONS = ['cash'];

    /**
     * Version-aware expected key set for an event type.
     *
     * Deliberately takes NO chain context: the z-session CASH_OUT/SAFE_DROP
     * override is a CHAIN-context concern and stays inside
     * {@see validatePayloadKeySet()}.
     *
     * @return list<string>|null null when the type has no registered contract
     */
    public function payloadKeysFor(FiscalEventType $type, int $eventVersion): ?array
    {
        // v4 is the REFUND-only fan-out, so it is matched EXACTLY. A `>= 4`
        // test would have handed v5 (D-1, a SALE version) the 33-key refund
        // set and refused every post-remise sale outright.
        if ($type === FiscalEventType::SALE_RECEIPT && $eventVersion === 4) {
            return self::SALE_RECEIPT_PAYLOAD_KEYS_V4;
        }
        if ($type === FiscalEventType::SALE_RECEIPT
            && $eventVersion >= self::SALE_RECEIPT_POST_DISCOUNT_BASE_VERSION) {
            return self::SALE_RECEIPT_PAYLOAD_KEYS_V5;
        }
        if ($type === FiscalEventType::SALE_RECEIPT && $eventVersion >= 3) {
            return self::SALE_RECEIPT_PAYLOAD_KEYS_V3;
        }

        return self::PAYLOAD_KEYS[$type->value] ?? null;
    }

    /**
     * Validate the payload's key set against the per-event expected keys.
     * Rejects both missing-required and extras. Returns a prefix-tagged
     * failure reason on violation; null when the key set is clean.
     *
     * `$eventVersion` defaults to 1 so every existing caller keeps today's
     * behavior; the four production call sites pass it explicitly.
     *
     * @param  array<string, mixed>  $payload
     */
    public function validatePayloadKeySet(
        FiscalEventType $type,
        array $payload,
        string $chainContext = 'operational',
        int $eventVersion = 1,
    ): ?string {
        $expected = $this->payloadKeysFor($type, $eventVersion);
        if ($expected === null) {
            return 'event_type_unimplemented:'.$type->value;
        }
        if (
            in_array($type, [FiscalEventType::CASH_OUT, FiscalEventType::SAFE_DROP], true)
            && in_array($chainContext, ['z_session', 'training_z_session'], true)
        ) {
            $expected = ZCashDrawerMovementPayload::PAYLOAD_KEYS;
        }

        if ($type === FiscalEventType::ACCOUNT_CHARGE && array_key_exists('payments', $payload)) {
            return 'payload_account_charge_payments_forbidden:payments is not valid on ACCOUNT_CHARGE';
        }

        $actual = array_keys($payload);
        $missing = array_diff($expected, $actual);
        if (count($missing) > 0) {
            sort($missing);

            return 'payload_missing_required:'.implode(',', $missing);
        }

        $extras = array_diff($actual, $expected);
        if (count($extras) > 0) {
            sort($extras);

            return 'payload_extra_field:'.implode(',', $extras);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws RuntimeException on constraint violation
     * @throws LogicException when invoked with a Phase 1 event type that
     *                        has no matching per-event clause (planning defect)
     */
    public function validatePerEventConstraints(
        FiscalEventType $type,
        array $payload,
        string $chainContext = 'operational',
        int $eventVersion = 1,
    ): void {
        match ($type) {
            FiscalEventType::SALE_RECEIPT => $this->validateSaleReceiptPayload($payload, $eventVersion),
            FiscalEventType::CHAIN_BREAK_DETECTED => $this->validateChainBreakDetectedPayload($payload),
            FiscalEventType::CHAIN_RESTART => $this->validateChainRestartPayload($payload),
            FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT => $this->validateTerminalRegistrySnapshotPayload($payload),
            FiscalEventType::ACCOUNT_PAYMENT => $this->validateAccountPaymentPayload($payload),
            FiscalEventType::ACCOUNT_CHARGE => $this->validateAccountChargePayload($payload),
            FiscalEventType::ACCOUNT_STATUS_CHANGED => $this->validateAccountStatusChangedPayload($payload),
            FiscalEventType::DEPOSIT_RECEIPT => $this->validateDepositReceiptPayload($payload),
            FiscalEventType::OPERATOR_APPROVAL_GRANTED => $this->validateOperatorApprovalGrantedPayload($payload),
            FiscalEventType::OVERRIDE_CREDIT_LIMIT,
            FiscalEventType::OVERRIDE_ACCOUNT_STATUS,
            FiscalEventType::OVERRIDE_DISCOUNT_LIMIT,
            FiscalEventType::OVERRIDE_TENDER_TOLERANCE,
            FiscalEventType::OVERRIDE_VOID_OR_RETURN => $this->validateOverridePayload($payload),
            FiscalEventType::CASH_OUT,
            FiscalEventType::SAFE_DROP => in_array($chainContext, ['z_session', 'training_z_session'], true)
                ? $this->validateZCashDrawerMovementPayload($payload)
                : $this->validateCashDrawerMovementPayload($payload),
            FiscalEventType::OPENING_FLOAT,
            FiscalEventType::CASH_IN,
            FiscalEventType::CASH_CORRECTION => $this->validateZCashDrawerMovementPayload($payload),
            FiscalEventType::SESSION_OPEN => $this->validateSessionOpenPayload($payload),
            FiscalEventType::SESSION_CLOSE,
            FiscalEventType::X_REPORT,
            FiscalEventType::Z_REPORT => $this->validateZReportFamilyPayload($payload),
            default => throw new LogicException(
                'FiscalPayloadConstraintValidator missing per-event clause for FiscalEventType::'.$type->name
            ),
        };
    }

    /**
     * Phase 4 payloads share the strict key-set gate above; nested business
     * invariants are enforced by their authoring services before canonical
     * bytes are sealed.
     *
     * @param  array<string, mixed>  $payload
     */
    private function validatePhase4Common(array $payload): void
    {
        $this->assertUuid($payload, 'tenant_id');
        $this->assertUuid($payload, 'company_id');
        $this->assertUuid($payload, 'terminal_id');
        $this->assertIsoDateTimeWithMs($payload, 'event_time_device');
        $this->assertBool($payload, 'training_flag');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateAccountStatusChangedPayload(array $payload): void
    {
        $this->validatePhase4Common($payload);
        $this->assertUuid($payload, 'actor_user_id');
        $this->assertUuid($payload, 'partner_id');
        $this->assertEnum($payload, 'old_status', ['active', 'suspended', 'closed', 'disputed']);
        $this->assertEnum($payload, 'new_status', ['active', 'suspended', 'closed', 'disputed']);
        $this->assertNonEmptyString($payload, 'reason');

        if (! is_int($payload['status_version'] ?? null) || $payload['status_version'] < 1) {
            throw new RuntimeException('payload_integer_format_mismatch:status_version');
        }

        if (! is_array($payload['partner_snapshot'] ?? null) || $payload['partner_snapshot'] === []) {
            throw new RuntimeException('payload_object_required:partner_snapshot');
        }
    }

    /**
     * Server-authored DEPOSIT_RECEIPT — back-office payment toward a customer
     * account. Leaner than ACCOUNT_PAYMENT (no device staleness / balance
     * snapshot / seller block); the server is the authority and the money is
     * settled FIFO with overflow → credit by the shared allocation engine.
     *
     * @param  array<string, mixed>  $payload
     */
    private function validateDepositReceiptPayload(array $payload): void
    {
        $this->validatePhase4Common($payload);

        $scale = $payload['currency_scale'] ?? null;
        if (! is_int($scale)) {
            throw new RuntimeException('payload_currency_scale_invalid:must be int; got '.var_export($scale, true));
        }
        if (! in_array($scale, self::SUPPORTED_CURRENCY_SCALES, true)) {
            throw new RuntimeException(
                'payload_currency_scale_unsupported:value='.$scale.':allowed='.implode(',', self::SUPPORTED_CURRENCY_SCALES)
            );
        }
        $moneyRegex = $this->moneyRegex($scale);

        $currencyCode = $payload['currency_code'] ?? null;
        if (! is_string($currencyCode) || preg_match(self::ISO_4217, $currencyCode) !== 1) {
            throw new RuntimeException('payload_currency_code_invalid:must be ISO 4217 alpha-3 uppercase; got '.var_export($currencyCode, true));
        }

        $this->assertUuid($payload, 'actor_user_id');
        $this->assertNonEmptyString($payload, 'actor_name');
        $this->assertIsoDate($payload, 'business_date');
        $this->assertUuid($payload, 'deposit_receipt_uuid');
        $this->assertUuid($payload, 'partner_id');
        $this->assertOptionalNullableString($payload, 'notes');
        $this->assertEnum($payload, 'treasury_allocation_policy', self::DEPOSIT_RECEIPT_ALLOCATION_POLICIES);

        $this->validateDepositReceiptCustomer($payload['customer'] ?? null, $payload['partner_id'] ?? null);
        $this->validateDepositReceiptPayment($payload['payment'] ?? null, $moneyRegex, $scale, (bool) $payload['training_flag']);
    }

    private function validateDepositReceiptCustomer(mixed $customer, mixed $partnerId): void
    {
        $row = $this->requireAssocObject($customer, 'customer');
        $expected = ['customer_category', 'customer_id', 'email', 'name', 'phone'];
        $this->assertExactObjectKeys($row, $expected, 'customer');
        $this->assertUuid($row, 'customer_id', 'customer.customer_id');
        $this->assertNonEmptyString($row, 'name', 'customer.name');
        $this->assertOptionalNullableString($row, 'phone', 'customer.phone');
        $this->assertOptionalNullableString($row, 'email', 'customer.email');
        $this->assertOptionalNullableString($row, 'customer_category', 'customer.customer_category');

        if ($row['customer_id'] !== $partnerId) {
            throw new RuntimeException('payload_deposit_receipt_customer_partner_mismatch:customer.customer_id must equal partner_id');
        }
    }

    private function validateDepositReceiptPayment(mixed $payment, string $moneyRegex, int $scale, bool $training): void
    {
        $row = $this->requireAssocObject($payment, 'payment');
        $expected = ['amount', 'method_code', 'repository_id'];
        $this->assertExactObjectKeys($row, $expected, 'payment');
        $this->assertMoneyString($row, 'amount', $moneyRegex, $scale, 'payment.amount');
        if (! $training && bccomp($this->asNumericString($row['amount'], 'payment.amount'), '0', $scale) === 0) {
            throw new RuntimeException('payload_deposit_receipt_amount_zero:payment.amount must be greater than zero unless training_flag=true');
        }
        $this->assertNonEmptyString($row, 'method_code', 'payment.method_code');
        $this->assertOptionalNullableString($row, 'repository_id', 'payment.repository_id');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateOperatorApprovalGrantedPayload(array $payload): void
    {
        $this->validatePhase4Common($payload);
        $this->assertUuid($payload, 'approval_id');
        $this->assertEnum($payload, 'approval_scope', [
            'close_shift_variance',
            'credit_limit_override',
            'account_status_override',
            'discount_limit_override',
            'tender_tolerance_override',
            'void_or_return_override',
            'cash_drawer_control',
            // v3-refund-chain-integration spec §4.5 errata T6: server-side
            // OPERATOR_APPROVAL_GRANTED-adjacent audit event evidencing a
            // disputed refund payout (§5.2's compensation flow, not a
            // device-authored override).
            'payout_dispute_evidence',
        ]);
        $this->assertUuid($payload, 'cashier_user_id');
        $this->assertUuid($payload, 'supervisor_user_id');
        $this->assertNonEmptyString($payload, 'policy_version');
        $this->assertNonEmptyString($payload, 'reason_code');
        $this->assertOptionalNullableString($payload, 'reason_text');
        $this->assertIsoDateTimeWithMs($payload, 'requested_at_device');
        $this->assertIsoDateTimeWithMs($payload, 'resolved_at_device');

        $this->validateSupervisorUserSnapshot($payload);
        $this->validatePhase4TargetObject($payload);

        if (($payload['regime_extensions'] ?? null) !== null && ! is_array($payload['regime_extensions'])) {
            throw new RuntimeException('payload_nullable_object_required:regime_extensions');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateOverridePayload(array $payload): void
    {
        $this->validatePhase4Common($payload);
        $this->assertUuid($payload, 'approval_event_id');
        $this->assertUuid($payload, 'approval_id');
        $this->assertEnum($payload, 'approval_scope', [
            'credit_limit_override',
            'account_status_override',
            'discount_limit_override',
            'tender_tolerance_override',
            'void_or_return_override',
            // spec §17 manifest (exact): both approval_scope assertEnum()
            // call sites gain the T6 literal.
            'payout_dispute_evidence',
        ]);
        $this->assertNonEmptyString($payload, 'policy_version');
        $this->assertNonEmptyString($payload, 'reason_code');
        $this->assertOptionalNullableString($payload, 'reason_text');
        $this->assertUuid($payload, 'supervisor_user_id');

        $this->validateOverrideContext($payload);
        $this->validatePhase4TargetObject($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateCashDrawerMovementPayload(array $payload): void
    {
        $this->validatePhase4Common($payload);
        $this->assertUuid($payload, 'approval_event_id');
        $this->assertUuid($payload, 'approval_id');
        $this->assertEnum($payload, 'approval_scope', ['cash_drawer_control']);
        $this->assertMoneyString($payload, 'amount', $this->moneyRegex(3), 3);
        $this->assertEnum($payload, 'operation_type', ['DEPOSIT', 'PAYOUT']);
        $this->assertNonEmptyString($payload, 'reason');
        $this->assertUuid($payload, 'shift_id');
        $this->assertUuid($payload, 'supervisor_user_id');
        $this->assertUuid($payload, 'target_reference_id');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateSessionOpenPayload(array $payload): void
    {
        $this->assertUuid($payload, 'session_id');
        $this->assertUuid($payload, 'shift_id');
        $this->assertPositiveInt($payload, 'shift_number');
        $this->assertIsoDate($payload, 'business_date');
        $this->assertIsoDateTimeWithMs($payload, 'opened_at_device');
        $this->assertUuid($payload, 'operator_id');
        $this->assertNonEmptyString($payload, 'operator_name');
        $this->assertUuid($payload, 'terminal_id');
        $this->assertNonEmptyString($payload, 'terminal_label');
        $this->assertCurrency($payload);
        $this->assertMoneyString($payload, 'opening_float_amount', $this->moneyRegex((int) $payload['currency_scale']), (int) $payload['currency_scale']);
        $this->assertBool($payload, 'training_flag');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateZCashDrawerMovementPayload(array $payload): void
    {
        $this->assertUuid($payload, 'movement_id');
        $this->assertUuid($payload, 'session_id');
        $this->assertUuid($payload, 'shift_id');
        $this->assertEnum($payload, 'movement_type', ['OPENING_FLOAT', 'CASH_IN', 'CASH_OUT', 'SAFE_DROP', 'CASH_CORRECTION']);
        $this->assertIsoDate($payload, 'business_date');
        $this->assertIsoDateTimeWithMs($payload, 'event_time_device');
        $this->assertUuid($payload, 'operator_id');
        $this->assertNonEmptyString($payload, 'operator_name');
        $this->assertCurrency($payload);
        $this->assertMoneyString($payload, 'amount', $this->moneyRegex((int) $payload['currency_scale']), (int) $payload['currency_scale']);
        $this->assertNonEmptyString($payload, 'reason_code');
        $this->assertOptionalNullableString($payload, 'reason_text');
        $this->assertOptionalNullableString($payload, 'cash_drawer_operation_id');
        $this->assertNullableObject($payload, 'approval');
        $this->assertBool($payload, 'training_flag');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateZReportFamilyPayload(array $payload): void
    {
        foreach (['session_id', 'shift_id', 'operator_id', 'terminal_id'] as $field) {
            $this->assertUuid($payload, $field);
        }
        $this->assertIsoDate($payload, 'business_date');
        $this->assertBool($payload, 'training_flag');
        $this->validateZFamilyVatBreakdownConsistency($payload);
    }

    /**
     * Aggregate-consistency invariant for the Z FAMILY (Z_REPORT / X_REPORT /
     * SESSION_CLOSE) — LEDGER C-6 item 4, finding F-4.
     *
     * Until this existed, `validateZReportFamilyPayload` checked six scalars and
     * never looked at `vat_breakdown`, which is the ONLY reason the Z family
     * escaped the invariant {@see validateSaleReceiptAggregateConsistency} has
     * enforced on SALE_RECEIPT since v1 — and therefore the reason two
     * gross-as-net defects (C-2 per-rate, C-6 headline) reached signed bytes
     * undetected on every taxed shift.
     *
     * ── EXECUTION CONTEXT (read before tightening anything here) ─────────────
     * This validator does NOT run only at ingest. `StrictCanonicalParser::parse()`
     * calls it, and that parser is invoked over ALREADY-STORED bytes by
     * `VerifyEventChainCommand` (`:544`, re-parsing every row's
     * `canonical_bytes`), by `QuarantineBestEffortParseController` (`:43,:91`)
     * and by `BestEffortPayloadParser` (`:24`), as well as at ingest by
     * `OutboxIngestor` (`:176`). A rule added here is therefore applied
     * retroactively to the entire sealed corpus — which is exactly why the
     * "fully legacy" case below is accepted rather than rejected.
     *
     * ── DEPLOYMENT PRECONDITION (mandatory coupling, do not ship alone) ──────
     * This rule PRESUPPOSES the C-6 device build. A terminal running
     * C-2-without-C-6 authors a CORRECTED per-rate breakdown against an
     * UNCORRECTED gross-as-net headline — precisely the disagreement rule 2 is
     * built to catch — so shipping this validator server-side while any terminal
     * in the fleet still runs that intermediate build would quarantine its
     * every Z. This must never reach production ahead of the device build that
     * carries the headline fix. (LEDGER carries the coupling amendment; this is
     * the code-side half of it.)
     *
     * ── WHAT IS ASSERTED ─────────────────────────────────────────────────────
     *   1. per group: `gross_amount == net_amount + vat_amount`, within ONE ulp
     *      at the derived scale.
     *   2. on a REFUND-FREE shift only: `Σ net_amount == net_sales` and
     *      `Σ vat_amount == tax_amount` — EXACT.
     *
     * Rule 1 carries one ulp of slack and rule 2 carries none, and the asymmetry
     * is deliberate. The three group figures are INDEPENDENTLY half-up-rounded
     * accumulators, so a line below the currency scale makes
     * `round(g−v) + round(v)` land one ulp away from `round(g)` — e.g. net 10.01
     * + vat 2.01 against a gross of 12.01 is a legitimately-signed shape that an
     * exact rule rejected. Given the execution context above, that would have
     * meant rejecting sealed history. One ulp is rounding; two is an error, and
     * is still rejected. The defect class this method exists to catch deviates
     * by the FULL VAT, orders of magnitude beyond the slack.
     *
     * Residual, stated rather than glossed: with N sub-scale groups the same
     * mechanism can in principle accumulate up to N ulp. The slack is fixed at
     * one because that is the reviewed shape; if a legitimate multi-group
     * sub-scale payload is ever observed to trip rule 1, widen it deliberately
     * here rather than by loosening rule 2.
     *
     * (2) is gated on `refunds_totals.count == 0` because the device's
     * `vat_breakdown` is SIGNED (a refund is SUBTRACTED from it) while the
     * headline totals are SALE-ONLY by design (refunds live in
     * `refunds_totals`). Once a refund exists the two cannot reconcile, and no
     * Z field carries the refund's VAT split with which to bridge them.
     * Asserting it unconditionally would quarantine every valid shift that took
     * a return.
     *
     * ── WHAT IS NOT ASSERTED, AND WHY ────────────────────────────────────────
     * `net_sales + tax_amount == gross_sales` is NOT claimed. `gross_sales` is
     * Σ receipt `total` (POST transaction-discount, POST cash-rounding) while
     * the other two are PRE both. The canonical receipt carries the identical
     * wedge and closes it with `transaction_discount_amount` +
     * `cash_rounding_adjustment` (identity 1 above); the Z payload carries
     * neither field, so the identity is unavailable — not omitted by oversight.
     *
     * A FULLY-LEGACY payload — breakdown AND headline both gross-as-net, which
     * is what every Z sealed before the C-2/C-6 device build looks like — PASSES.
     * Legacy `net_amount` was Σ `line_total` and legacy `net_sales` was Σ receipt
     * `subtotal`: the same number, so (1) and (2) both hold on it. Given the
     * execution context above, rejecting it would fail `fiscal:verify-chain` on
     * the whole pre-fix corpus. What IS caught is any payload where the two
     * DISAGREE — the post-C-2/pre-C-6 partial state, its reverse, and any future
     * single-site regression of one of the three device aggregation loops.
     *
     * ── SHAPE BEFORE ARITHMETIC ──────────────────────────────────────────────
     * Every Z-family money value passes {@see assertZFamilyMoney} BEFORE any
     * bcmath call. `is_numeric()` alone is not enough: it accepts `'1e2'`,
     * `'+12.00'` and `'.5'`, all of which bcmath rejects with a **ValueError**,
     * not a RuntimeException — so a corrupted stored Z used to FATAL
     * `fiscal:verify-chain` rather than record a parse failure. SALE_RECEIPT was
     * never exposed because `assertMoneyString` runs before its arithmetic; the
     * Z family had no equivalent. `StrictCanonicalParser` also now converts a
     * ValueError escaping ANY per-event validator into the parse-failure
     * channel, as defense for future validators.
     *
     * Presence is not this method's job: `validatePayloadKeySet` owns the exact
     * key set, so an ABSENT headline container skips the sum identities. A
     * PRESENT but malformed one is rejected, and so is a non-integer
     * `refunds_totals.count`: that gate used to fail OPEN, silently skipping
     * BOTH sum identities on a JSON `"0"` — the one shape that would let the
     * defect through the tripwire built to catch it.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws RuntimeException on aggregate inconsistency (→ quarantine)
     */
    private function validateZFamilyVatBreakdownConsistency(array $payload): void
    {
        $rows = $payload['vat_breakdown'] ?? null;
        if (! is_array($rows) || ($rows !== [] && ! array_is_list($rows))) {
            throw new RuntimeException('payload_array_required:vat_breakdown');
        }

        // Z_REPORT nests the headline under `receipt_totals`; X_REPORT and
        // SESSION_CLOSE use `sales_totals` (`zSessionAuthoring.ts:384/:427/:482`).
        $totals = null;
        foreach (['receipt_totals', 'sales_totals'] as $key) {
            $candidate = $payload[$key] ?? null;
            if (is_array($candidate) && ! array_is_list($candidate)) {
                $totals = $candidate;
                break;
            }
        }

        // ── Pass 1: SHAPE. Every money value is validated before any bcmath
        // call, so a malformed one can never reach the extension as a
        // ValueError (see the docblock). The validated strings are collected
        // here rather than re-read below, so pass 2 cannot accidentally consume
        // an unchecked value.
        /** @var list<array{net: numeric-string, vat: numeric-string, gross: numeric-string}> $groups */
        $groups = [];
        foreach ($rows as $index => $row) {
            $path = 'vat_breakdown.'.$index;
            $group = $this->requireAssocObject($row, $path);

            $groups[] = [
                'net' => $this->assertZFamilyMoney($group['net_amount'] ?? null, $path.'.net_amount'),
                'vat' => $this->assertZFamilyMoney($group['vat_amount'] ?? null, $path.'.vat_amount'),
                'gross' => $this->assertZFamilyMoney($group['gross_amount'] ?? null, $path.'.gross_amount'),
            ];
        }

        $headlineKey = $totals === null
            ? null
            : (array_key_exists('receipt_totals', $payload) && is_array($payload['receipt_totals'])
                ? 'receipt_totals'
                : 'sales_totals');
        /** @var numeric-string|null $netSales */
        $netSales = null;
        /** @var numeric-string|null $taxAmount */
        $taxAmount = null;
        if ($totals !== null && isset($totals['net_sales'], $totals['tax_amount'])) {
            $netSales = $this->assertZFamilyMoney($totals['net_sales'], $headlineKey.'.net_sales');
            $taxAmount = $this->assertZFamilyMoney($totals['tax_amount'], $headlineKey.'.tax_amount');
        }

        // The Z family carries `currency_scale` on Z_REPORT only — X_REPORT and
        // SESSION_CLOSE omit it — so the comparison scale is derived from the
        // values themselves. Every Z-family money string is `bcformat`ed at the
        // one currency scale, so the maximum observed fractional length IS that
        // scale and every comparison below is exact, never truncating.
        $scale = 0;
        foreach ($groups as $group) {
            foreach ($group as $value) {
                $scale = max($scale, $this->fractionalDigitsOf($value));
            }
        }
        if ($netSales !== null && $taxAmount !== null) {
            $scale = max($scale, $this->fractionalDigitsOf($netSales), $this->fractionalDigitsOf($taxAmount));
        }

        // One unit in the last place at the derived scale — the entire tolerance
        // rule 1 is allowed, and none of it applies to rule 2. Derived with
        // bcmath (10^-scale) rather than string concatenation so it is a
        // numeric-string by construction: '1' at scale 0, '0.01' at 2, '0.001'
        // at 3.
        $ulp = bcpow('10', (string) (-$scale), $scale);

        // ── Pass 2: ARITHMETIC.
        $sumNet = bcadd('0', '0', $scale);
        $sumVat = bcadd('0', '0', $scale);

        foreach ($groups as $index => $group) {
            $groupGross = bcadd($group['net'], $group['vat'], $scale);
            $deviation = bcsub($groupGross, $group['gross'], $scale);
            if (bccomp($deviation, '0', $scale) < 0) {
                $deviation = bcsub('0', $deviation, $scale);
            }
            if (bccomp($deviation, $ulp, $scale) > 0) {
                throw new RuntimeException(
                    'payload_aggregate_consistency:group_gross_ne_net_plus_vat:index='.$index
                    .':expected='.$groupGross.':got='.$group['gross'].':tolerance='.$ulp
                );
            }

            $sumNet = bcadd($sumNet, $group['net'], $scale);
            $sumVat = bcadd($sumVat, $group['vat'], $scale);
        }

        if ($netSales === null || $taxAmount === null) {
            return;
        }

        // Fail CLOSED on the refund gate: a non-integer count is corruption, not
        // a licence to skip both sum identities.
        $refundsTotals = $payload['refunds_totals'] ?? null;
        if (! is_array($refundsTotals) || array_is_list($refundsTotals)) {
            throw new RuntimeException('payload_object_required:refunds_totals');
        }
        $refundsCount = $refundsTotals['count'] ?? null;
        if (! is_int($refundsCount) || $refundsCount < 0) {
            throw new RuntimeException(
                'payload_field_invalid:refunds_totals.count:expected=non-negative int:got='.get_debug_type($refundsCount)
            );
        }
        if ($refundsCount !== 0) {
            return;
        }

        if (bccomp($sumNet, $netSales, $scale) !== 0) {
            throw new RuntimeException(
                'payload_aggregate_consistency:vat_breakdown_net_sum_ne_net_sales:expected='.$netSales.':got='.$sumNet
            );
        }
        if (bccomp($sumVat, $taxAmount, $scale) !== 0) {
            throw new RuntimeException(
                'payload_aggregate_consistency:vat_breakdown_vat_sum_ne_tax_amount:expected='.$taxAmount.':got='.$sumVat
            );
        }
    }

    /**
     * Strict shape gate for Z-family money, run BEFORE any bcmath call.
     *
     * SIGNED, unlike {@see moneyRegex()} — a Z `vat_breakdown` is net of
     * refunds, so a refund-only shift legitimately reports every bucket
     * negative. The scale is NOT fixed here (only Z_REPORT carries
     * `currency_scale`, and the comparison scale is derived from the values);
     * what is fixed is the FORM: optional minus, digits, optional decimal point
     * with 1–3 fractional digits — every currency scale this system supports
     * (0, 2, 3). That rejects exactly what `is_numeric()` waves through and
     * bcmath then fatals on: exponent notation, a leading `+`, a trailing or
     * leading bare point, hex, whitespace, `NaN`/`INF`.
     *
     * @return numeric-string
     */
    private function assertZFamilyMoney(mixed $value, string $path): string
    {
        if (! is_string($value)) {
            throw new RuntimeException(sprintf(
                'payload_money_type_mismatch:field=%s:expected=string:got=%s',
                $path,
                get_debug_type($value),
            ));
        }
        if (preg_match('/^-?(0|[1-9]\d*)(\.\d{1,3})?$/D', $value) !== 1) {
            throw new RuntimeException(sprintf(
                'payload_money_scale_mismatch:field=%s:value=%s',
                $path,
                $value,
            ));
        }

        /** @var numeric-string $value */
        return $value;
    }

    /**
     * Number of digits after the decimal point in a numeric string, 0 when
     * there is none. Used only to pick an EXACT comparison scale — never to
     * validate a value's shape.
     */
    private function fractionalDigitsOf(string $value): int
    {
        $dot = strrpos($value, '.');

        return $dot === false ? 0 : strlen($value) - $dot - 1;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateSupervisorUserSnapshot(array $payload): void
    {
        $snapshot = $payload['supervisor_user_snapshot'] ?? null;
        if (! is_array($snapshot) || $snapshot === []) {
            throw new RuntimeException('payload_object_required:supervisor_user_snapshot');
        }

        $this->assertNonEmptyString($snapshot, 'name', 'supervisor_user_snapshot.name');

        $roles = $snapshot['roles'] ?? null;
        if (! is_array($roles)) {
            throw new RuntimeException('payload_array_required:supervisor_user_snapshot.roles');
        }

        foreach ($roles as $index => $role) {
            if (! is_string($role) || $role === '') {
                throw new RuntimeException('payload_field_invalid:supervisor_user_snapshot.roles.'.$index);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateOverrideContext(array $payload): void
    {
        $context = $payload['override_context'] ?? null;
        if (! is_array($context) || $context === []) {
            throw new RuntimeException('payload_object_required:override_context');
        }

        $this->assertNonEmptyString($context, 'target_event_type', 'override_context.target_event_type');
        $this->assertNonEmptyString($context, 'target_reference_id', 'override_context.target_reference_id');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validatePhase4TargetObject(array $payload): void
    {
        $target = $payload['target'] ?? null;
        if (! is_array($target) || $target === []) {
            throw new RuntimeException('payload_object_required:target');
        }

        foreach (['tenant_id', 'company_id', 'terminal_id'] as $scopeField) {
            if (array_key_exists($scopeField, $target) && $target[$scopeField] !== $payload[$scopeField]) {
                throw new RuntimeException('payload_scope_mismatch:target.'.$scopeField);
            }
        }
    }

    /**
     * SALE_RECEIPT — 28-key canonical Candidate C-v3 validator (synthesis v5 + Phase 4).
     *
     * @param  array<string, mixed>  $payload
     */
    private function validateSaleReceiptPayload(array $payload, int $eventVersion = 1): void
    {
        // ---- 1. currency_scale + currency_code first — every subsequent
        // ----    money-string check depends on the scale. ----
        // N-01 closure: restrict to the {0, 2, 3} allowlist locked by the
        // canonical TS/PHP contract (synthesis v3 §3 line 49-50 +
        // spec v7 §11.2 line 577). Any other value would drift from the
        // golden-vector domain and from CURRENCY_SCALES below.
        $scale = $payload['currency_scale'] ?? null;
        if (! is_int($scale)) {
            throw new RuntimeException('payload_currency_scale_invalid:must be int; got '.var_export($scale, true));
        }
        if ($scale < 0 || $scale > 8) {
            // Defense-in-depth: keep the legacy range check as a typed
            // failure for the negative / huge-int path, so the allowlist
            // check below sees only plausible scales.
            throw new RuntimeException('payload_currency_scale_invalid:must be int 0-8; got '.$scale);
        }
        if (! in_array($scale, self::SUPPORTED_CURRENCY_SCALES, true)) {
            throw new RuntimeException(
                'payload_currency_scale_unsupported:value='.$scale.':allowed='.implode(',', self::SUPPORTED_CURRENCY_SCALES)
            );
        }
        $moneyRegex = $this->moneyRegex($scale);

        $currencyCode = $payload['currency_code'] ?? null;
        if (! is_string($currencyCode) || preg_match(self::ISO_4217, $currencyCode) !== 1) {
            throw new RuntimeException('payload_currency_code_invalid:must be ISO 4217 alpha-3 uppercase; got '.var_export($currencyCode, true));
        }

        // ---- 2. enum + format invariants on simple top-level fields ----
        $this->assertEnum($payload, 'invoice_type_code', self::INVOICE_TYPE_CODES);

        // ---- 2z. v4 (refund/void chain integration, spec §2 table) — the
        // ---- 2y. D-1 (gate r1 finding 1). v5 is a SALE/TRAINING version and
        // ---- nothing else. Adding 5 to the parseable set while narrowing the
        // ---- five refund guards to `=== 4` (correct in itself — a `>= 4` test
        // ---- would have handed v5 the 33-key refund set) left v5 with NO
        // ---- invoice-type restriction at all: the enum check above still
        // ---- admits REFUND and VOID, and the v4 block below no longer fires
        // ---- for them. A v5 REFUND therefore skipped the VOID prohibition,
        // ---- `validateOriginalLineReferences()`, the refund
        // ---- destination/settlement contract, `validateSingleCashLegPayment()`
        // ---- and the zero-discount rule — and
        // ---- `PosCoreReceiptProjection::resolveReceiptType()` keys off
        // ---- `invoice_type_code`, so such a payload would have projected as a
        // ---- RETURN: negative revenue, a restocking movement, and an original
        // ---- that was never validated. The hole did not exist before D-1 (the
        // ---- registry refused a v5 envelope outright); this clause closes it.
        if ($eventVersion >= self::SALE_RECEIPT_POST_DISCOUNT_BASE_VERSION) {
            $v5InvoiceType = $payload['invoice_type_code'] ?? null;
            if ($v5InvoiceType !== 'SALE' && $v5InvoiceType !== 'TRAINING') {
                throw new RuntimeException(
                    'payload_invoice_type_invalid:event_version>='
                    .self::SALE_RECEIPT_POST_DISCOUNT_BASE_VERSION
                    .' requires invoice_type_code=SALE|TRAINING; got '.var_export($v5InvoiceType, true)
                );
            }
        }

        // ---- device NEVER resolves event_version=4 for anything but a
        // ---- REFUND (`FiscalEventPayloadRegistry.ts`'s eventVersionFor()
        // ---- table); VOID authoring has no legitimate producer at any
        // ---- version and is explicitly rejected here at v4 parse (§8,
        // ---- §17 manifest: "VOID rejection at v4 parse"). ----
        if ($eventVersion === 4) {
            $invoiceType = $payload['invoice_type_code'] ?? null;
            if ($invoiceType === 'VOID') {
                throw new RuntimeException(
                    'payload_void_authoring_prohibited:event_version=4 payloads with invoice_type_code=VOID have no legitimate producer (spec §2/§8)'
                );
            }
            if ($invoiceType !== 'REFUND') {
                throw new RuntimeException(
                    'payload_invoice_type_invalid:event_version=4 requires invoice_type_code=REFUND; got '.var_export($invoiceType, true)
                );
            }
        }
        $this->assertOptionalEnum($payload, 'consumption_mode', self::CONSUMPTION_MODES);
        $this->assertBool($payload, 'training_flag');
        $this->assertIsoDate($payload, 'business_date');
        // N-02 closure: identity fields locked to lowercase-hex UUID
        // (synthesis v3 lines 46-95 + spec v7 §11.2 line 571).
        // `cashier_name` stays a non-empty display string.
        $this->assertUuid($payload, 'cashier_id');
        $this->assertNonEmptyString($payload, 'cashier_name');
        $this->assertIsoDateTimeWithMs($payload, 'event_time_device');
        $this->assertUuid($payload, 'receipt_uuid');
        $this->assertUuid($payload, 'shift_id');
        $this->assertUuid($payload, 'terminal_id');
        $this->assertOptionalNullableString($payload, 'notes');
        $this->assertOptionalNullableString($payload, 'table_id');
        $this->assertOptionalNullableString($payload, 'lottery_code');

        // ---- 2a. TRAINING-flag coupling invariant (N-03 closure). ----
        // Migration `2026_05_20_120000_*.php:22-32` documents `training_flag`
        // as denormalized from `invoice_type_code == 'TRAINING'`. Reporting
        // queries filter on `training_flag = FALSE`, so contradictory
        // payloads (`SALE`+true / `TRAINING`+false) would corrupt the
        // denormalization before PHP.2's projector writes the column.
        $invoiceTypeIsTraining = $payload['invoice_type_code'] === 'TRAINING';
        $trainingFlag = $payload['training_flag'];
        if ($invoiceTypeIsTraining !== $trainingFlag) {
            $flagLabel = $trainingFlag === true ? 'true' : 'false';
            throw new RuntimeException(
                'payload_training_flag_mismatch:invoice_type_code='.$payload['invoice_type_code'].':training_flag='.$flagLabel
            );
        }

        // ---- 3. top-level money fields — NON-NEGATIVE bcformat at currency_scale ----
        foreach (['subtotal', 'vat_total', 'total', 'transaction_discount_amount'] as $field) {
            $this->assertMoneyString($payload, $field, $moneyRegex, $scale);
        }

        // ---- 3a. v3 cash-rounding siblings (spec §4.4). ----
        // v1/v2 must NEVER carry these keys. The key-set gate already rejects
        // them as `payload_extra_field`, but validatePerEventConstraints is
        // reachable directly (BestEffortPayloadParser::schemaDefects), so the
        // constraint layer states the same rule independently.
        $hasAdjustment = array_key_exists('cash_rounding_adjustment', $payload);
        $hasDenomination = array_key_exists('cash_rounding_denomination', $payload);

        if ($eventVersion < 3) {
            if ($hasAdjustment || $hasDenomination) {
                throw new RuntimeException(
                    'payload_cash_rounding_forbidden_for_version:event_version='.$eventVersion
                );
            }
            // Absent fields ⇒ zero ⇒ v1/v2 identities are unchanged.
            $roundingAdjustment = bcadd('0', '0', $scale);
        } else {
            $missingRounding = [];
            if (! $hasAdjustment) {
                $missingRounding[] = 'cash_rounding_adjustment';
            }
            if (! $hasDenomination) {
                $missingRounding[] = 'cash_rounding_denomination';
            }
            if ($missingRounding !== []) {
                throw new RuntimeException('payload_missing_required:'.implode(',', $missingRounding));
            }

            $this->assertSignedMoneyString($payload, 'cash_rounding_adjustment', $scale);
            // The denomination is NON-negative and already normalized to the
            // currency scale by the policy contract (§4.2) before it can reach
            // a payload, so the existing non-negative regex is correct here.
            $this->assertMoneyString($payload, 'cash_rounding_denomination', $moneyRegex, $scale);

            $roundingAdjustment = $this->asNumericString(
                $payload['cash_rounding_adjustment'],
                'cash_rounding_adjustment',
            );
        }

        // ---- 4. discount-reason consistency (§6.A) — BCMath comparison,
        // ----    NOT literal "0" string equality (bcformat at scale=2 emits "0.00"). ----
        $discountAmount = $this->asNumericString($payload['transaction_discount_amount'], 'transaction_discount_amount');
        $discountReason = $payload['transaction_discount_reason'] ?? null;
        if ($discountReason !== null && ! is_string($discountReason)) {
            throw new RuntimeException('payload_field_invalid:transaction_discount_reason must be string or null; got '.get_debug_type($discountReason));
        }
        $isZeroDiscount = bccomp($discountAmount, '0', $scale) === 0;
        $reasonPresent = $discountReason !== null;
        if ($isZeroDiscount && $reasonPresent) {
            throw new RuntimeException(
                'payload_discount_reason_mismatch:amount='.$discountAmount.':reason_present=true'
            );
        }
        if (! $isZeroDiscount && ! $reasonPresent) {
            throw new RuntimeException(
                'payload_discount_reason_mismatch:amount='.$discountAmount.':reason_present=false'
            );
        }

        // ---- 4a. v4 REFUND: transaction_discount_amount is ALWAYS canonical
        // ---- zero (spec §3.5's ⚖️ orchestrator ruling — launch refuses any
        // ---- refund of an original whose OWN transaction_discount_amount is
        // ---- non-zero, so a v4 refund payload can never legitimately carry
        // ---- a non-zero one; this is the server-side mirror of the device's
        // ---- pre-authoring refusal, not a new runtime branch on the
        // ---- device side). ----
        if ($eventVersion === 4 && ! $isZeroDiscount) {
            throw new RuntimeException(
                'payload_v4_refund_transaction_discount_must_be_zero:transaction_discount_amount='.$discountAmount
            );
        }

        // ---- 5. total arithmetic cross-check (§6.D) ----
        $subtotalN = $this->asNumericString($payload['subtotal'], 'subtotal');
        $vatTotalN = $this->asNumericString($payload['vat_total'], 'vat_total');
        $totalN = $this->asNumericString($payload['total'], 'total');
        $lhs = bcadd($subtotalN, $vatTotalN, $scale);
        // Spec §4.1 identity (1): subtotal + vat_total == (total − adj) + discount.
        // Absent fields ⇒ adj = 0 ⇒ this reduces to the v1/v2 identity exactly.
        //
        // D-1: at v5 the discount term DISAPPEARS — the taxable base is already
        // net of the remise. This is the FIRST of the two places the identity
        // is evaluated (the second is
        // {@see validateSaleReceiptAggregateConsistency()}); both must thread
        // the version or a correct post-remise ticket is refused here before it
        // ever reaches the aggregate check.
        $rhs = bcsub($totalN, $roundingAdjustment, $scale);
        if ($eventVersion < self::SALE_RECEIPT_POST_DISCOUNT_BASE_VERSION) {
            $rhs = bcadd($rhs, $discountAmount, $scale);
        }
        if (bccomp($lhs, $rhs, $scale) !== 0) {
            throw new RuntimeException(
                'payload_total_arithmetic_mismatch:lhs='.$lhs.':rhs='.$rhs
            );
        }

        // ---- 5a. v3 rounding binds — NORMATIVE ORDER (spec §4.1). ----
        if ($eventVersion >= 3) {
            $this->validateCashRoundingBinds($payload, $totalN, $roundingAdjustment, $scale);
        }

        // ---- 6. nested objects — seller (required) + buyer (nullable) +
        // ----    original_receipt_reference (nullable per invoice_type) ----
        $this->validateSeller($payload);
        $this->validateBuyer($payload, $eventVersion);
        $this->validateOriginalReceiptReference($payload);

        // ---- 6z. v4-only nested contract (spec §3.3/§3.4) — reached only
        // ---- when invoice_type_code === 'REFUND' (2z above already
        // ---- fail-closed on every other value at v4). `original_line_references[]`
        // ---- is validated against `line_items` for the strict parallel-array
        // ---- invariant BEFORE line_items' own per-row validation below, so
        // ---- both lists are validated by their own natural shape checks
        // ---- first (requireList) and then cross-checked here.
        if ($eventVersion === 4) {
            $this->validateOriginalLineReferences($payload);
            $this->validateRefundDestinationAndSettlementAllocation($payload);
        }

        // ---- 7. list containers — line_items, payments, vat_breakdown,
        // ----    vouchers_redeemed. List-ness checked before per-row validation. ----
        $approvalReferences = $this->requireList($payload, 'approval_references');
        foreach ($approvalReferences as $index => $row) {
            $this->validateSaleReceiptApprovalReference($index, $row);
        }

        $lineItems = $this->requireList($payload, 'line_items');
        if (count($lineItems) === 0) {
            throw new RuntimeException('payload_line_items_empty:line_items must have >= 1 row');
        }
        foreach ($lineItems as $index => $row) {
            $this->validateLineItem($index, $row, $moneyRegex, $scale, $eventVersion);
        }

        $payments = $this->requireList($payload, 'payments');
        if (count($payments) === 0) {
            // ---- D-1 / G3-A fold-in. A 100 %-comp receipt tenders nothing,
            // ---- and `pos_receipt_payments CHECK (amount > 0)` refuses the
            // ---- 0.000 leg the device used to emit — which made the whole
            // ---- receipt unprojectable on PostgreSQL. At v5 the device emits
            // ---- NO tender row for a full comp and the discount line carries
            // ---- the story; the DB CHECK is kept exactly as it is. Allowed
            // ---- ONLY when the ticket is genuinely fully comped: `total`,
            // ---- `subtotal` and `vat_total` are all canonical zero and the
            // ---- remise is positive. Every earlier version, and every
            // ---- non-comped v5 ticket, still requires >= 1 row.
            if ($eventVersion < self::SALE_RECEIPT_POST_DISCOUNT_BASE_VERSION
                || ! $this->isFullyCompedSaleReceipt($payload, $scale)) {
                throw new RuntimeException('payload_payments_empty:payments must have >= 1 row');
            }
        }
        foreach ($payments as $index => $row) {
            $this->validatePayment($index, $row, $moneyRegex, $scale);
        }

        // ---- 7a. v4 single-cash-leg contract (spec §3.7, §17 manifest:
        // ---- "single-cash-leg + instrument_type null assertions"). ----
        if ($eventVersion === 4) {
            $this->validateSingleCashLegPayment($payments);
        }

        $vatBreakdown = $this->requireList($payload, 'vat_breakdown');
        if (count($vatBreakdown) === 0) {
            throw new RuntimeException('payload_vat_breakdown_empty:vat_breakdown must have >= 1 row');
        }
        foreach ($vatBreakdown as $index => $row) {
            $this->validateVatBreakdownRow($index, $row, $moneyRegex, $scale, $eventVersion);
        }

        $vouchers = $this->requireList($payload, 'vouchers_redeemed');
        foreach ($vouchers as $index => $row) {
            $this->validateVoucherRedemption($index, $row, $moneyRegex, $scale);
        }

        // ---- 8. VAT partition algorithm (§6.C) — set equality + per-group
        // ----    amount equality via BCMath at currency_scale. ----
        // @phpstan-ignore-next-line argument.type — validated as list above
        $this->validateVatPartition($lineItems, $vatBreakdown, $scale, $eventVersion, $payload);

        // ---- 9. Aggregate consistency (NF525-meaningful) ----
        // NF525 secures the ticket AGGREGATES (the VAT-declaration integrity):
        // the device-authored subtotal / vat_total / total / vat_breakdown must
        // be INTERNALLY consistent with one another. The server does NOT recompute
        // prices from unit_price (which is the cart's tax-INCLUSIVE figure) — it
        // only verifies the device's own aggregates add up. A violation routes
        // through the SAME RuntimeException → quarantine path (event stored, NOT
        // projected) without touching canonical_bytes / current_hash. All checks
        // are EXACT (bccomp === 0) at the payload's currency_scale via bcmath.
        // @phpstan-ignore-next-line argument.type — validated as list above
        $this->validateSaleReceiptAggregateConsistency($payload, $vatBreakdown, $scale, $roundingAdjustment, $eventVersion);
    }

    /**
     * True when a v5 SALE_RECEIPT is a 100 % comp: `total`, `subtotal` and
     * `vat_total` are all BCMath-equivalent zero and the remise is positive.
     *
     * Structural gate for the empty-`payments` allowance only; the
     * arithmetic that PROVES the comp (Σ discount_allocated == the discount,
     * and Σ net + Σ vat == total) is enforced unconditionally by
     * {@see validateSaleReceiptAggregateConsistency()} a few lines later.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isFullyCompedSaleReceipt(array $payload, int $scale): bool
    {
        foreach (['total', 'subtotal', 'vat_total', 'transaction_discount_amount'] as $field) {
            if (! is_string($payload[$field] ?? null)) {
                return false;
            }
        }
        /** @var numeric-string $total */
        $total = $payload['total'];
        /** @var numeric-string $subtotal */
        $subtotal = $payload['subtotal'];
        /** @var numeric-string $vatTotal */
        $vatTotal = $payload['vat_total'];
        /** @var numeric-string $discount */
        $discount = $payload['transaction_discount_amount'];

        return bccomp($total, '0', $scale) === 0
            && bccomp($subtotal, '0', $scale) === 0
            && bccomp($vatTotal, '0', $scale) === 0
            && bccomp($discount, '0', $scale) > 0;
    }

    /**
     * Aggregate-consistency invariant for SALE_RECEIPT (NF525 VAT-declaration
     * integrity). Verifies the device's own ticket aggregates are internally
     * consistent; does NOT recompute prices.
     *
     *   1. subtotal + vat_total == total
     *   2. Σ vat_breakdown[].net_amount == subtotal
     *   3. Σ vat_breakdown[].vat_amount == vat_total
     *   4. per group: gross_amount == net_amount + vat_amount
     *
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $vatBreakdown
     * @param  numeric-string  $roundingAdjustment  v3 signed cash-rounding adjustment; '0' on v1/v2
     *
     * @throws RuntimeException on aggregate inconsistency (→ quarantine)
     */
    private function validateSaleReceiptAggregateConsistency(
        array $payload,
        array $vatBreakdown,
        int $scale,
        string $roundingAdjustment = '0',
        int $eventVersion = 1,
    ): void {
        $subtotal = $this->asNumericString($payload['subtotal'], 'subtotal');
        $vatTotal = $this->asNumericString($payload['vat_total'], 'vat_total');
        $total = $this->asNumericString($payload['total'], 'total');
        $discount = $this->asNumericString($payload['transaction_discount_amount'], 'transaction_discount_amount');

        // 1. subtotal + vat_total == total (+ transaction_discount_amount).
        //
        // The canonical contract carries `subtotal` (net) and `vat_total` BEFORE
        // the ticket-level discount, while `total` is the gross AFTER it
        // (`receiptService.ts`: total = subtotalGross − transactionDiscountAmount).
        // So the NF525-meaningful identity is subtotal + vat_total == total +
        // transaction_discount_amount. For the discount-free case this reduces to
        // subtotal + vat_total == total. Omitting the discount term here would
        // FALSE-POSITIVE on every valid ticket carrying a transaction discount —
        // exactly the failure class this rework removes at the line level.
        $isPostDiscountBase = $eventVersion >= self::SALE_RECEIPT_POST_DISCOUNT_BASE_VERSION;
        $subtotalPlusVat = bcadd($subtotal, $vatTotal, $scale);
        // v3 folds the signed cash-rounding adjustment out of `total` before
        // the NF525 aggregate identity is evaluated; on v1/v2 the default '0'
        // makes this byte-identical to the previous expression.
        //
        // D-1: at v5 the discount term DISAPPEARS. The base is already net of
        // the remise, so adding it back would refuse every correct post-remise
        // ticket — and, read the other way, this is exactly what REFUSES a
        // v5-declared payload still carrying the pre-discount base.
        $expectedRhs = bcsub($total, $roundingAdjustment, $scale);
        if (! $isPostDiscountBase) {
            $expectedRhs = bcadd($expectedRhs, $discount, $scale);
        }
        if (bccomp($subtotalPlusVat, $expectedRhs, $scale) !== 0) {
            throw new RuntimeException(
                'payload_aggregate_consistency:subtotal_plus_vat_ne_total:expected='.$subtotalPlusVat.':got='.$expectedRhs
            );
        }

        // 2 + 3. Σ vat_breakdown nets / vats == subtotal / vat_total.
        // 4. per group gross == net + vat.
        // 5 (v5). Σ vat_breakdown discount_allocated == transaction_discount_amount,
        //         and every money field non-negative.
        $sumNet = bcadd('0', '0', $scale);
        $sumVat = bcadd('0', '0', $scale);
        $sumDiscount = bcadd('0', '0', $scale);
        foreach ($vatBreakdown as $row) {
            $net = $this->asNumericString($row['net_amount'], 'vat_breakdown.net_amount');
            $vat = $this->asNumericString($row['vat_amount'], 'vat_breakdown.vat_amount');
            $gross = $this->asNumericString($row['gross_amount'], 'vat_breakdown.gross_amount');
            if ($isPostDiscountBase) {
                $allocated = $this->asNumericString($row['discount_allocated'], 'vat_breakdown.discount_allocated');
                if (bccomp($allocated, '0', $scale) < 0) {
                    throw new RuntimeException(
                        'payload_aggregate_consistency:group_discount_allocated_negative:got='.$allocated
                    );
                }
                $sumDiscount = bcadd($sumDiscount, $allocated, $scale);
            }

            $groupGross = bcadd($net, $vat, $scale);
            if (bccomp($groupGross, $gross, $scale) !== 0) {
                throw new RuntimeException(
                    'payload_aggregate_consistency:group_gross_ne_net_plus_vat:expected='.$groupGross.':got='.$gross
                );
            }

            $sumNet = bcadd($sumNet, $net, $scale);
            $sumVat = bcadd($sumVat, $vat, $scale);
        }

        if (bccomp($sumNet, $subtotal, $scale) !== 0) {
            throw new RuntimeException(
                'payload_aggregate_consistency:vat_breakdown_net_sum_ne_subtotal:expected='.$subtotal.':got='.$sumNet
            );
        }
        if (bccomp($sumVat, $vatTotal, $scale) !== 0) {
            throw new RuntimeException(
                'payload_aggregate_consistency:vat_breakdown_vat_sum_ne_vat_total:expected='.$vatTotal.':got='.$sumVat
            );
        }
        if ($isPostDiscountBase && bccomp($sumDiscount, $discount, $scale) !== 0) {
            throw new RuntimeException(
                'payload_aggregate_consistency:vat_breakdown_discount_sum_ne_transaction_discount:expected='.$discount.':got='.$sumDiscount
            );
        }
    }

    private function validateSaleReceiptApprovalReference(int $index, mixed $row): void
    {
        if (! is_array($row) || array_is_list($row)) {
            throw new RuntimeException('payload_approval_references_'.$index.'_invalid:must be object');
        }

        $expected = [
            'approval_event_id',
            'approval_id',
            'approval_scope',
            'override_event_id',
            'policy_version',
            'supervisor_user_id',
            'target_reference_id',
        ];
        $actual = array_keys($row);
        $missing = array_values(array_diff($expected, $actual));
        if ($missing !== []) {
            throw new RuntimeException('payload_approval_references_'.$index.'_missing_keys:'.implode(',', $missing));
        }
        $extra = array_values(array_diff($actual, $expected));
        if ($extra !== []) {
            throw new RuntimeException('payload_approval_references_'.$index.'_extra_keys:'.implode(',', $extra));
        }

        $this->assertUuid($row, 'approval_event_id', 'approval_references.'.$index.'.approval_event_id');
        $this->assertUuid($row, 'approval_id', 'approval_references.'.$index.'.approval_id');
        $this->assertEnum($row, 'approval_scope', [
            'discount_limit_override',
            'tender_tolerance_override',
            'void_or_return_override',
        ]);
        $this->assertUuid($row, 'override_event_id', 'approval_references.'.$index.'.override_event_id');
        $this->assertNonEmptyString($row, 'policy_version', 'approval_references.'.$index.'.policy_version');
        $this->assertUuid($row, 'supervisor_user_id', 'approval_references.'.$index.'.supervisor_user_id');
        $this->assertNonEmptyString($row, 'target_reference_id', 'approval_references.'.$index.'.target_reference_id');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateAccountPaymentPayload(array $payload): void
    {
        $scale = $payload['currency_scale'] ?? null;
        if (! is_int($scale)) {
            throw new RuntimeException('payload_currency_scale_invalid:must be int; got '.var_export($scale, true));
        }
        if (! in_array($scale, self::SUPPORTED_CURRENCY_SCALES, true)) {
            throw new RuntimeException(
                'payload_currency_scale_unsupported:value='.$scale.':allowed='.implode(',', self::SUPPORTED_CURRENCY_SCALES)
            );
        }
        $moneyRegex = $this->moneyRegex($scale);

        $currencyCode = $payload['currency_code'] ?? null;
        if (! is_string($currencyCode) || preg_match(self::ISO_4217, $currencyCode) !== 1) {
            throw new RuntimeException('payload_currency_code_invalid:must be ISO 4217 alpha-3 uppercase; got '.var_export($currencyCode, true));
        }

        $this->assertUuid($payload, 'account_payment_uuid');
        $this->assertIsoDate($payload, 'business_date');
        $this->assertUuid($payload, 'cashier_id');
        $this->assertNonEmptyString($payload, 'cashier_name');
        $this->assertIsoDateTimeWithMs($payload, 'event_time_device');
        $this->assertOptionalNullableString($payload, 'notes');
        $this->assertEnum($payload, 'receipt_type_code', ['ACCOUNT_PAYMENT']);
        $this->assertUuid($payload, 'shift_id');
        $this->assertUuid($payload, 'terminal_id');
        $this->assertBool($payload, 'training_flag');
        $this->assertEnum($payload, 'treasury_allocation_policy', self::ACCOUNT_PAYMENT_ALLOCATION_POLICIES);

        $sellerCountryCode = $this->validateSeller($payload);
        $this->validateAccountPaymentCustomer($payload['customer'] ?? null, $sellerCountryCode);
        $this->validateAccountPaymentPayment($payload['payment'] ?? null, $moneyRegex, $scale, (bool) $payload['training_flag']);
        $this->validateAccountPaymentBalanceSnapshot($payload['local_balance_snapshot'] ?? null, $moneyRegex, $scale);
        $this->validateAccountPaymentStaleness($payload['staleness'] ?? null);
        $this->validateAccountPaymentReferences($payload['references'] ?? null);
        $this->validateNullableAssoc($payload['regime_extensions'] ?? null, 'regime_extensions');
    }

    private function validateAccountPaymentCustomer(mixed $customer, string $sellerCountryCode): void
    {
        $row = $this->requireAssocObject($customer, 'customer');
        $expected = ['address', 'customer_category', 'customer_id', 'customer_sync_status', 'email', 'name', 'phone', 'tax_number'];
        $this->assertExactObjectKeys($row, $expected, 'customer');
        $this->assertUuid($row, 'customer_id', 'customer.customer_id');
        $this->assertEnum($row, 'customer_sync_status', self::ACCOUNT_PAYMENT_CUSTOMER_SYNC_STATUSES, 'customer.customer_sync_status');
        $this->assertNonEmptyString($row, 'name', 'customer.name');
        $this->assertOptionalNullableString($row, 'phone', 'customer.phone');
        $this->assertOptionalNullableString($row, 'email', 'customer.email');
        $this->assertOptionalNullableString($row, 'customer_category', 'customer.customer_category');
        $this->validateAddress($row['address'] ?? null, 'customer.address', required: false);
        if (($row['tax_number'] ?? null) !== null) {
            $this->assertTaxNumberForCountry(
                $row['tax_number'],
                $this->countryCodeFromAddress($row['address'] ?? null) ?? $sellerCountryCode,
                'customer.tax_number',
                buyer: true,
            );
        }
    }

    private function validateAccountPaymentPayment(mixed $payment, string $moneyRegex, int $scale, bool $training): void
    {
        $row = $this->requireAssocObject($payment, 'payment');
        $expected = ['amount', 'foreign_currency_amount', 'foreign_currency_code', 'instrument_serial', 'instrument_type', 'method_code', 'repository_id'];
        $this->assertExactObjectKeys($row, $expected, 'payment');
        $this->assertMoneyString($row, 'amount', $moneyRegex, $scale, 'payment.amount');
        if (! $training && bccomp($this->asNumericString($row['amount'], 'payment.amount'), '0', $scale) === 0) {
            throw new RuntimeException('payload_account_payment_amount_zero:payment.amount must be greater than zero unless training_flag=true');
        }
        $this->assertNonEmptyString($row, 'method_code', 'payment.method_code');
        $this->assertOptionalNullableString($row, 'repository_id', 'payment.repository_id');
        $this->assertOptionalNullableString($row, 'instrument_serial', 'payment.instrument_serial');
        $this->assertOptionalNullableString($row, 'instrument_type', 'payment.instrument_type');

        $foreignAmount = $row['foreign_currency_amount'];
        $foreignCode = $row['foreign_currency_code'];
        if ($foreignAmount === null && $foreignCode === null) {
            return;
        }
        if ($foreignAmount === null || $foreignCode === null) {
            throw new RuntimeException('payload_account_payment_foreign_currency_pair_invalid:payment foreign_currency_amount and foreign_currency_code must both be null or both non-null');
        }
        if (! is_string($foreignCode) || preg_match(self::ISO_4217, $foreignCode) !== 1) {
            throw new RuntimeException('payload_account_payment_foreign_currency_code_invalid:payment.foreign_currency_code must be ISO 4217 alpha-3 uppercase; got '.var_export($foreignCode, true));
        }
        $foreignScale = self::CURRENCY_SCALES[$foreignCode] ?? null;
        if ($foreignScale === null) {
            throw new RuntimeException('payload_account_payment_foreign_currency_unknown:payment.foreign_currency_code='.$foreignCode.' has no registered scale');
        }
        $this->assertMoneyString($row, 'foreign_currency_amount', $this->moneyRegex($foreignScale), $foreignScale, 'payment.foreign_currency_amount');
    }

    private function validateAccountPaymentBalanceSnapshot(mixed $snapshot, string $moneyRegex, int $scale): void
    {
        $row = $this->requireAssocObject($snapshot, 'local_balance_snapshot');
        $expected = [
            'balance_updated_at',
            'credit_balance_before',
            'net_balance_before',
            'payment_amount',
            'projected_credit_balance_after',
            'projected_net_balance_after',
            'projected_receivable_balance_after',
            'receivable_balance_before',
        ];
        $this->assertExactObjectKeys($row, $expected, 'local_balance_snapshot');
        foreach ([
            'credit_balance_before',
            'net_balance_before',
            'payment_amount',
            'projected_credit_balance_after',
            'projected_net_balance_after',
            'projected_receivable_balance_after',
            'receivable_balance_before',
        ] as $field) {
            $this->assertMoneyString($row, $field, $moneyRegex, $scale, 'local_balance_snapshot.'.$field);
        }
        $this->assertIsoDateTimeWithMs($row, 'balance_updated_at', 'local_balance_snapshot.balance_updated_at');
    }

    private function validateAccountPaymentStaleness(mixed $staleness): void
    {
        $row = $this->requireAssocObject($staleness, 'staleness');
        $expected = ['balance_snapshot_stale', 'customer_snapshot_stale', 'mirror_last_synced_at', 'staleness_reason'];
        $this->assertExactObjectKeys($row, $expected, 'staleness');
        $this->assertBool($row, 'customer_snapshot_stale', 'staleness.customer_snapshot_stale');
        $this->assertBool($row, 'balance_snapshot_stale', 'staleness.balance_snapshot_stale');
        if (($row['mirror_last_synced_at'] ?? null) !== null) {
            $this->assertIsoDateTimeWithMs($row, 'mirror_last_synced_at', 'staleness.mirror_last_synced_at');
        }
        $this->assertOptionalEnum($row, 'staleness_reason', self::ACCOUNT_PAYMENT_STALENESS_REASONS, 'staleness.staleness_reason');
        $stale = $row['customer_snapshot_stale'] === true || $row['balance_snapshot_stale'] === true;
        if ($stale && $row['staleness_reason'] === null) {
            throw new RuntimeException('payload_account_payment_staleness_reason_required:staleness_reason required when any stale flag is true');
        }
        if (! $stale && $row['staleness_reason'] !== null) {
            throw new RuntimeException('payload_account_payment_staleness_reason_mismatch:staleness_reason must be null when stale flags are false');
        }
    }

    private function validateAccountPaymentReferences(mixed $references): void
    {
        if ($references === null) {
            return;
        }
        $row = $this->requireAssocObject($references, 'references');
        $expected = ['external_reference', 'related_sale_receipt_event_id', 'server_customer_alias_id'];
        $this->assertExactObjectKeys($row, $expected, 'references');
        $this->assertOptionalNullableString($row, 'external_reference', 'references.external_reference');
        $this->assertOptionalNullableString($row, 'related_sale_receipt_event_id', 'references.related_sale_receipt_event_id');
        if ($row['server_customer_alias_id'] !== null) {
            throw new RuntimeException(
                'payload_account_payment_server_customer_alias_forbidden:references.server_customer_alias_id is reserved for ACCOUNT_PAYMENT_RECONCILED'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateAccountChargePayload(array $payload): void
    {
        $this->rejectAccountChargePaymentsKeyRecursively($payload);

        $scale = $payload['currency_scale'] ?? null;
        if (! is_int($scale)) {
            throw new RuntimeException('payload_currency_scale_invalid:must be int; got '.var_export($scale, true));
        }
        if (! in_array($scale, self::SUPPORTED_CURRENCY_SCALES, true)) {
            throw new RuntimeException(
                'payload_currency_scale_unsupported:value='.$scale.':allowed='.implode(',', self::SUPPORTED_CURRENCY_SCALES)
            );
        }
        $moneyRegex = $this->moneyRegex($scale);

        $currencyCode = $payload['currency_code'] ?? null;
        if (! is_string($currencyCode) || preg_match(self::ISO_4217, $currencyCode) !== 1) {
            throw new RuntimeException('payload_currency_code_invalid:must be ISO 4217 alpha-3 uppercase; got '.var_export($currencyCode, true));
        }

        $this->assertUuid($payload, 'account_charge_uuid');
        $this->assertIsoDate($payload, 'business_date');
        $this->assertUuid($payload, 'cashier_id');
        $this->assertNonEmptyString($payload, 'cashier_name');
        $this->assertIsoDateTimeWithMs($payload, 'event_time_device');
        $this->assertEnum($payload, 'invoice_classification', self::ACCOUNT_CHARGE_INVOICE_CLASSIFICATIONS);
        $this->assertOptionalNullableString($payload, 'notes');
        $this->assertEnum($payload, 'print_profile', ['ACCOUNT_CHARGE_RECEIPT']);
        $this->assertEnum($payload, 'receipt_type_code', ['ACCOUNT_CHARGE']);
        $this->assertUuid($payload, 'shift_id');
        $this->assertUuid($payload, 'terminal_id');
        $this->assertBool($payload, 'training_flag');

        foreach (['transaction_discount_amount'] as $field) {
            $this->assertMoneyString($payload, $field, $moneyRegex, $scale);
        }
        $this->validateDiscountReasonPair(
            $payload['transaction_discount_amount'],
            $payload['transaction_discount_reason'] ?? null,
            $scale,
            'payload_discount_reason_mismatch',
        );

        // ---- D-1 gate r2 finding 2 — the ACCOUNT_CHARGE remise refusal used to
        // ---- live HERE, unconditional. That was wrong twice over: this
        // ---- validator is also re-run over STORED events by
        // ---- `VerifyEventChainCommand`, so every historical discounted credit
        // ---- sale — already accepted, projected and in the AR ledger — would
        // ---- have started reporting as a payload-constraint failure; and it
        // ---- bound un-upgraded terminals, whose discounted on-account sales
        // ---- would have been quarantined at ingest the moment the server
        // ---- deployed (no `account_charge_receipts` row, no AR movement, no
        // ---- GL entry, for a sale the customer already walked out with) —
        // ---- inverting the deploy order, since SALE_RECEIPT v5 needs
        // ---- server-first.
        // ----
        // ---- The refusal now lives in `SaleReceiptForwardVersionGate`, gated
        // ---- on the SAME per-chain v5 watermark the sales arm uses: a terminal
        // ---- that has proven it can author the post-remise base may not then
        // ---- charge a remise to account on the pre-remise one. Un-upgraded
        // ---- devices keep the legacy path, and nothing is retroactive.

        $sellerCountryCode = $this->validateSeller($payload);
        $customerCategory = $this->validateAccountChargeCustomer($payload['customer'] ?? null, $sellerCountryCode);
        $this->validateBuyer($payload);
        $this->validateAccountChargeTerms($payload['charge_terms'] ?? null);
        $this->validateAccountChargeCreditDecision(
            $payload['credit_decision'] ?? null,
            $moneyRegex,
            $scale,
            (bool) $payload['training_flag'],
        );
        $this->validateAccountChargeTotals($payload['totals'] ?? null, $moneyRegex, $scale);
        $this->validateAccountChargeBalanceSnapshot($payload['local_balance_snapshot'] ?? null, $moneyRegex, $scale);
        $this->validateAccountChargeStaleness($payload['staleness'] ?? null);
        $this->validateAccountChargeReferences($payload['references'] ?? null);
        $this->validateNullableAssoc($payload['regime_extensions'] ?? null, 'regime_extensions');

        $lineItems = $this->requireList($payload, 'line_items');
        if (count($lineItems) === 0) {
            throw new RuntimeException('payload_line_items_empty:line_items must have >= 1 row');
        }
        foreach ($lineItems as $index => $row) {
            $this->validateAccountChargeLineItem($index, $row, $moneyRegex, $scale);
        }

        $vatBreakdown = $this->requireList($payload, 'vat_breakdown');
        if (count($vatBreakdown) === 0) {
            throw new RuntimeException('payload_vat_breakdown_empty:vat_breakdown must have >= 1 row');
        }
        foreach ($vatBreakdown as $index => $row) {
            $this->validateVatBreakdownRow($index, $row, $moneyRegex, $scale);
        }

        $this->validateVatPartition($lineItems, $vatBreakdown, $scale);
        $this->validateAccountChargeArithmetic($payload, $scale);

        if (($payload['invoice_classification'] ?? null) === 'b2b_facture_draft_requested' && $customerCategory !== 'business') {
            throw new RuntimeException(
                'payload_account_charge_invoice_classification_mismatch:b2b_facture_draft_requested requires customer.customer_category=business'
            );
        }
    }

    private function validateAccountChargeCustomer(mixed $customer, string $sellerCountryCode): ?string
    {
        $row = $this->requireAssocObject($customer, 'customer');
        $expected = ['account_identifier', 'address', 'customer_category', 'customer_id', 'customer_sync_status', 'email', 'name', 'phone', 'tax_number'];
        $this->assertExactObjectKeys($row, $expected, 'customer');
        $this->assertUuid($row, 'customer_id', 'customer.customer_id');
        $this->assertEnum($row, 'customer_sync_status', self::ACCOUNT_PAYMENT_CUSTOMER_SYNC_STATUSES, 'customer.customer_sync_status');
        $this->assertNonEmptyString($row, 'name', 'customer.name');
        $this->assertOptionalNullableString($row, 'phone', 'customer.phone');
        $this->assertOptionalNullableString($row, 'email', 'customer.email');
        $this->assertOptionalNullableString($row, 'customer_category', 'customer.customer_category');
        $this->assertOptionalNullableString($row, 'account_identifier', 'customer.account_identifier');
        $this->validateAddress($row['address'] ?? null, 'customer.address', required: false);
        if (($row['tax_number'] ?? null) !== null) {
            $this->assertTaxNumberForCountry(
                $row['tax_number'],
                $this->countryCodeFromAddress($row['address'] ?? null) ?? $sellerCountryCode,
                'customer.tax_number',
                buyer: true,
            );
        }

        return is_string($row['customer_category']) ? $row['customer_category'] : null;
    }

    private function validateAccountChargeTerms(mixed $terms): void
    {
        $row = $this->requireAssocObject($terms, 'charge_terms');
        $expected = ['due_date', 'payment_terms_days', 'terms_label'];
        $this->assertExactObjectKeys($row, $expected, 'charge_terms');

        if ($row['payment_terms_days'] !== null && (! is_int($row['payment_terms_days']) || $row['payment_terms_days'] < 0)) {
            throw new RuntimeException('payload_field_invalid:charge_terms.payment_terms_days must be non-negative int');
        }
        if ($row['payment_terms_days'] !== null && $row['due_date'] === null) {
            throw new RuntimeException('payload_account_charge_terms_invalid:charge_terms.due_date required when payment_terms_days is non-null');
        }
        if ($row['due_date'] !== null) {
            $this->assertIsoDate($row, 'due_date', 'charge_terms.due_date');
        }
        $this->assertOptionalNullableString($row, 'terms_label', 'charge_terms.terms_label');
    }

    private function validateAccountChargeCreditDecision(mixed $decision, string $moneyRegex, int $scale, bool $training): void
    {
        $row = $this->requireAssocObject($decision, 'credit_decision');
        $expected = [
            'credit_available_after',
            'credit_available_before',
            'credit_limit',
            'decision',
            'limit_exceeded',
            'mirror_stale_at_authoring',
            'override_evidence',
            'policy_version',
            'stale_policy_action',
            'warnings',
        ];
        $this->assertExactObjectKeys($row, $expected, 'credit_decision');
        $this->assertEnum($row, 'decision', ['approved', 'approved_with_override'], 'credit_decision.decision');
        $decision = $row['decision'];
        $this->assertBool($row, 'limit_exceeded', 'credit_decision.limit_exceeded');
        $this->assertBool($row, 'mirror_stale_at_authoring', 'credit_decision.mirror_stale_at_authoring');
        $this->assertNonEmptyString($row, 'policy_version', 'credit_decision.policy_version');
        $this->assertEnum($row, 'stale_policy_action', self::ACCOUNT_CHARGE_STALE_POLICY_ACTIONS, 'credit_decision.stale_policy_action');
        $this->validateAccountChargeOverrideEvidence($row['override_evidence'], $decision, $moneyRegex, $scale);
        foreach (['credit_available_after', 'credit_available_before', 'credit_limit'] as $field) {
            if ($row[$field] !== null) {
                $this->assertMoneyString($row, $field, $moneyRegex, $scale, 'credit_decision.'.$field);
            }
        }
        $warnings = $row['warnings'];
        if (! is_array($warnings) || ! array_is_list($warnings)) {
            throw new RuntimeException('payload_object_invalid:credit_decision.warnings must be list');
        }
        foreach ($warnings as $index => $warning) {
            if (! is_string($warning) || $warning === '') {
                throw new RuntimeException("payload_field_invalid:credit_decision.warnings[{$index}] must be non-empty string");
            }
            if (preg_match('/^[a-z][a-z0-9_]*$/D', $warning) !== 1) {
                throw new RuntimeException("payload_account_charge_credit_decision_invalid:warnings[{$index}] must be a stable lower_snake_case code");
            }
        }
        $sortedWarnings = $warnings;
        sort($sortedWarnings, SORT_STRING);
        if ($warnings !== $sortedWarnings) {
            throw new RuntimeException('payload_account_charge_credit_decision_invalid:warnings must be sorted stable codes');
        }
        if (! $training && $row['limit_exceeded'] === true && $decision !== 'approved_with_override') {
            throw new RuntimeException('payload_account_charge_credit_decision_invalid:limit_exceeded requires training_flag=true');
        }
    }

    private function validateAccountChargeOverrideEvidence(mixed $evidence, string $decision, string $moneyRegex, int $scale): void
    {
        if ($decision === 'approved') {
            if ($evidence !== null) {
                throw new RuntimeException('payload_account_charge_credit_decision_invalid:override_evidence must be null for approved decisions');
            }

            return;
        }

        $row = $this->requireAssocObject($evidence, 'credit_decision.override_evidence');
        $expected = [
            'approval_event_id',
            'approval_scope',
            'override_event_id',
            'policy_version',
            'target_account_status',
            'target_amount',
            'target_customer_id',
        ];
        $this->assertExactObjectKeys($row, $expected, 'credit_decision.override_evidence');
        $this->assertUuid($row, 'approval_event_id', 'credit_decision.override_evidence.approval_event_id');
        $this->assertEnum($row, 'approval_scope', ['credit_limit_override', 'account_status_override'], 'credit_decision.override_evidence.approval_scope');
        $this->assertUuid($row, 'override_event_id', 'credit_decision.override_evidence.override_event_id');
        $this->assertNonEmptyString($row, 'policy_version', 'credit_decision.override_evidence.policy_version');
        $this->assertEnum($row, 'target_account_status', ['active', 'suspended', 'closed', 'disputed'], 'credit_decision.override_evidence.target_account_status');
        $this->assertMoneyString($row, 'target_amount', $moneyRegex, $scale, 'credit_decision.override_evidence.target_amount');
        $this->assertUuid($row, 'target_customer_id', 'credit_decision.override_evidence.target_customer_id');
    }

    private function validateAccountChargeTotals(mixed $totals, string $moneyRegex, int $scale): void
    {
        $row = $this->requireAssocObject($totals, 'totals');
        $expected = ['amount_charged_to_account', 'grand_total_before_charge', 'subtotal', 'total', 'vat_total'];
        $this->assertExactObjectKeys($row, $expected, 'totals');
        foreach ($expected as $field) {
            $this->assertMoneyString($row, $field, $moneyRegex, $scale, 'totals.'.$field);
        }
    }

    private function validateAccountChargeBalanceSnapshot(mixed $snapshot, string $moneyRegex, int $scale): void
    {
        $row = $this->requireAssocObject($snapshot, 'local_balance_snapshot');
        $expected = [
            'balance_updated_at',
            'charge_amount',
            'credit_balance_before',
            'net_balance_before',
            'projected_credit_balance_after',
            'projected_net_balance_after',
            'projected_receivable_balance_after',
            'receivable_balance_before',
        ];
        $this->assertExactObjectKeys($row, $expected, 'local_balance_snapshot');
        foreach (array_slice($expected, 1) as $field) {
            $this->assertMoneyString($row, $field, $moneyRegex, $scale, 'local_balance_snapshot.'.$field);
        }
        $this->assertIsoDateTimeWithMs($row, 'balance_updated_at', 'local_balance_snapshot.balance_updated_at');
    }

    private function validateAccountChargeStaleness(mixed $staleness): void
    {
        $row = $this->requireAssocObject($staleness, 'staleness');
        $expected = ['balance_snapshot_stale', 'customer_snapshot_stale', 'mirror_last_synced_at', 'staleness_reason'];
        $this->assertExactObjectKeys($row, $expected, 'staleness');
        $this->assertBool($row, 'customer_snapshot_stale', 'staleness.customer_snapshot_stale');
        $this->assertBool($row, 'balance_snapshot_stale', 'staleness.balance_snapshot_stale');
        if (($row['mirror_last_synced_at'] ?? null) !== null) {
            $this->assertIsoDateTimeWithMs($row, 'mirror_last_synced_at', 'staleness.mirror_last_synced_at');
        }
        $this->assertOptionalEnum($row, 'staleness_reason', self::ACCOUNT_PAYMENT_STALENESS_REASONS, 'staleness.staleness_reason');
        $stale = $row['customer_snapshot_stale'] === true || $row['balance_snapshot_stale'] === true;
        if ($stale && $row['staleness_reason'] === null) {
            throw new RuntimeException('payload_account_charge_staleness_reason_required:staleness_reason required when any stale flag is true');
        }
        if (! $stale && $row['staleness_reason'] !== null) {
            throw new RuntimeException('payload_account_charge_staleness_reason_mismatch:staleness_reason must be null when stale flags are false');
        }
    }

    private function validateAccountChargeReferences(mixed $references): void
    {
        if ($references === null) {
            return;
        }
        $row = $this->requireAssocObject($references, 'references');
        $expected = ['external_reference', 'related_sale_receipt_event_id', 'server_customer_alias_id'];
        $this->assertExactObjectKeys($row, $expected, 'references');
        $this->assertOptionalNullableString($row, 'external_reference', 'references.external_reference');
        if ($row['related_sale_receipt_event_id'] !== null) {
            throw new RuntimeException(
                'payload_account_charge_reference_forbidden:references.related_sale_receipt_event_id is reserved for post-v1 split-sale links'
            );
        }
        if ($row['server_customer_alias_id'] !== null) {
            throw new RuntimeException(
                'payload_account_charge_reference_forbidden:references.server_customer_alias_id is reserved for projection state or follow-up events'
            );
        }
    }

    private function validateAccountChargeLineItem(int|string $index, mixed $row, string $moneyRegex, int $scale): void
    {
        if (! is_array($row) || (count($row) > 0 && array_is_list($row))) {
            throw new RuntimeException("payload_line_item_invalid:line_items[{$index}] must be object; got ".get_debug_type($row));
        }
        /** @var array<string, mixed> $row */
        $expected = [
            'gtin', 'line_discount_amount', 'line_discount_reason', 'line_subtotal',
            'line_uuid', 'line_vat', 'name', 'non_collected_subtype', 'product_id',
            'quantity', 'sku', 'tax_category_code', 'unit_price', 'vat_rate',
        ];
        $missing = array_diff($expected, array_keys($row));
        if (count($missing) > 0) {
            sort($missing);
            throw new RuntimeException("payload_line_item_missing_keys:line_items[{$index}]:".implode(',', $missing));
        }
        $extras = array_diff(array_keys($row), $expected);
        if (count($extras) > 0) {
            sort($extras);
            throw new RuntimeException("payload_line_item_extra_keys:line_items[{$index}]:".implode(',', $extras));
        }
        $path = "line_items[{$index}]";

        foreach (['unit_price', 'line_subtotal', 'line_vat', 'line_discount_amount'] as $field) {
            $this->assertMoneyString($row, $field, $moneyRegex, $scale, "{$path}.{$field}");
        }
        $this->assertMoneyString($row, 'quantity', $this->moneyRegex(self::QUANTITY_SCALE), self::QUANTITY_SCALE, "{$path}.quantity");
        $this->assertMoneyString($row, 'vat_rate', $this->moneyRegex(self::VAT_RATE_SCALE), self::VAT_RATE_SCALE, "{$path}.vat_rate");
        $this->assertUuid($row, 'line_uuid', "{$path}.line_uuid");
        $this->assertNonEmptyString($row, 'name', "{$path}.name");
        $this->assertNonEmptyString($row, 'product_id', "{$path}.product_id");
        $this->assertOptionalNullableString($row, 'sku', "{$path}.sku");

        $tcc = $row['tax_category_code'];
        if ($tcc !== null && ! is_string($tcc)) {
            throw new RuntimeException("payload_line_item_tax_category_invalid:{$path}.tax_category_code must be string or null; got ".get_debug_type($tcc));
        }
        if (is_string($tcc) && $tcc !== '' && preg_match('/^[A-Z0-9_\-]+$/D', $tcc) !== 1) {
            throw new RuntimeException("payload_line_item_tax_category_invalid:{$path}.tax_category_code must match ^[A-Z0-9_-]+$; got ".var_export($tcc, true));
        }
        foreach (['gtin', 'line_discount_reason'] as $field) {
            $this->assertOptionalNullableString($row, $field, "{$path}.{$field}");
        }
        $this->assertOptionalEnum($row, 'non_collected_subtype', self::NON_COLLECTED_SUBTYPES, "{$path}.non_collected_subtype");
        $this->validateDiscountReasonPair(
            $row['line_discount_amount'],
            $row['line_discount_reason'],
            $scale,
            "payload_line_discount_reason_mismatch:{$path}",
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateAccountChargeArithmetic(array $payload, int $scale): void
    {
        /** @var array<string, mixed> $totals */
        $totals = $payload['totals'];
        /** @var array<string, mixed> $snapshot */
        $snapshot = $payload['local_balance_snapshot'];

        $subtotal = $this->asNumericString($totals['subtotal'], 'totals.subtotal');
        $vatTotal = $this->asNumericString($totals['vat_total'], 'totals.vat_total');
        $total = $this->asNumericString($totals['total'], 'totals.total');
        $grandTotalBeforeCharge = $this->asNumericString($totals['grand_total_before_charge'], 'totals.grand_total_before_charge');
        if (bccomp($grandTotalBeforeCharge, $total, $scale) !== 0) {
            throw new RuntimeException('payload_account_charge_amount_mismatch:grand_total_before_charge must equal totals.total');
        }
        $discount = $this->asNumericString($payload['transaction_discount_amount'], 'transaction_discount_amount');
        $lhs = bcadd($subtotal, $vatTotal, $scale);
        $rhs = bcadd($total, $discount, $scale);
        if (bccomp($lhs, $rhs, $scale) !== 0) {
            throw new RuntimeException('payload_account_charge_amount_mismatch:subtotal_plus_vat='.$lhs.':total_plus_discount='.$rhs);
        }

        $amountCharged = $this->asNumericString($totals['amount_charged_to_account'], 'totals.amount_charged_to_account');
        if (bccomp($amountCharged, $total, $scale) !== 0) {
            throw new RuntimeException('payload_account_charge_amount_mismatch:amount_charged_to_account must equal totals.total');
        }
        $chargeAmount = $this->asNumericString($snapshot['charge_amount'], 'local_balance_snapshot.charge_amount');
        if (bccomp($chargeAmount, $amountCharged, $scale) !== 0) {
            throw new RuntimeException('payload_account_charge_amount_mismatch:local_balance_snapshot.charge_amount must equal totals.amount_charged_to_account');
        }

        $receivableBefore = $this->asNumericString($snapshot['receivable_balance_before'], 'local_balance_snapshot.receivable_balance_before');
        $projectedReceivable = $this->asNumericString($snapshot['projected_receivable_balance_after'], 'local_balance_snapshot.projected_receivable_balance_after');
        $expectedReceivable = bcadd($receivableBefore, $chargeAmount, $scale);
        if (bccomp($expectedReceivable, $projectedReceivable, $scale) !== 0) {
            throw new RuntimeException('payload_account_charge_balance_mismatch:projected_receivable_balance_after expected '.$expectedReceivable.' got '.$projectedReceivable);
        }

        $projectedCredit = $this->asNumericString($snapshot['projected_credit_balance_after'], 'local_balance_snapshot.projected_credit_balance_after');
        $projectedNet = $this->asNumericString($snapshot['projected_net_balance_after'], 'local_balance_snapshot.projected_net_balance_after');
        $expectedNet = bcsub($projectedReceivable, $projectedCredit, $scale);
        if (bccomp($expectedNet, '0', $scale) < 0) {
            $expectedNet = bcadd('0', '0', $scale);
        }
        if (bccomp($expectedNet, $projectedNet, $scale) !== 0) {
            throw new RuntimeException('payload_account_charge_balance_mismatch:projected_net_balance_after expected '.$expectedNet.' got '.$projectedNet);
        }
    }

    private function validateDiscountReasonPair(mixed $amount, mixed $reason, int $scale, string $prefix): void
    {
        $amountString = $this->asNumericString($amount, 'discount_amount');
        if ($reason !== null && (! is_string($reason) || $reason === '')) {
            throw new RuntimeException($prefix.':reason must be non-empty string or null');
        }
        $isZero = bccomp($amountString, '0', $scale) === 0;
        if ($isZero && $reason !== null) {
            throw new RuntimeException($prefix.':amount='.$amountString.':reason_present=true');
        }
        if (! $isZero && $reason === null) {
            throw new RuntimeException($prefix.':amount='.$amountString.':reason_present=false');
        }
    }

    private function rejectAccountChargePaymentsKeyRecursively(mixed $value, string $path = 'payload'): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            $segment = is_int($key) ? "[{$key}]" : ".{$key}";
            if ($key === 'payments') {
                throw new RuntimeException(
                    'payload_account_charge_payments_forbidden:payments is not valid anywhere on ACCOUNT_CHARGE at '.$path.$segment
                );
            }
            $this->rejectAccountChargePaymentsKeyRecursively($child, $path.$segment);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateSeller(array $payload): string
    {
        $seller = $payload['seller'] ?? null;
        if (! is_array($seller) || (count($seller) > 0 && array_is_list($seller))) {
            throw new RuntimeException('payload_seller_invalid:must be object; got '.get_debug_type($seller));
        }
        /** @var array<string, mixed> $seller */
        $sellerKeys = ['address', 'name', 'tax_jurisdiction_country_code', 'tax_number'];
        $missing = array_diff($sellerKeys, array_keys($seller));
        if (count($missing) > 0) {
            sort($missing);
            throw new RuntimeException('payload_seller_missing_keys:'.implode(',', $missing));
        }
        $extras = array_diff(array_keys($seller), $sellerKeys);
        if (count($extras) > 0) {
            sort($extras);
            throw new RuntimeException('payload_seller_extra_keys:'.implode(',', $extras));
        }

        $this->assertNonEmptyString($seller, 'name', 'seller.name');

        $jurisdiction = $seller['tax_jurisdiction_country_code'];
        if (! is_string($jurisdiction) || preg_match(self::ISO_3166_ALPHA_2, $jurisdiction) !== 1) {
            throw new RuntimeException('payload_seller_tax_jurisdiction_invalid:must be ISO 3166-1 alpha-2; got '.var_export($jurisdiction, true));
        }

        $this->assertTaxNumberForCountry($seller['tax_number'] ?? null, $jurisdiction, 'seller.tax_number');

        $this->validateAddress($seller['address'] ?? null, 'seller.address', required: true);

        return $jurisdiction;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateBuyer(array $payload, ?int $saleReceiptEventVersion = null): void
    {
        $buyer = $payload['buyer'] ?? null;
        if ($buyer === null) {
            return;
        }
        if (! is_array($buyer) || (count($buyer) > 0 && array_is_list($buyer))) {
            throw new RuntimeException('payload_buyer_invalid:must be object or null; got '.get_debug_type($buyer));
        }
        /** @var array<string, mixed> $buyer */
        $buyerKeys = ['address', 'codice_fiscale', 'contact_id', 'customer_id', 'name', 'tax_number'];
        $missing = array_diff($buyerKeys, array_keys($buyer));
        if (count($missing) > 0) {
            sort($missing);
            throw new RuntimeException('payload_buyer_missing_keys:'.implode(',', $missing));
        }
        $extras = array_diff(array_keys($buyer), $buyerKeys);
        if (count($extras) > 0) {
            sort($extras);
            throw new RuntimeException('payload_buyer_extra_keys:'.implode(',', $extras));
        }

        $nullableStringFields = ['codice_fiscale', 'contact_id', 'customer_id'];
        if ($saleReceiptEventVersion === null) {
            // ACCOUNT_CHARGE reuses this six-key buyer validator. Keep its
            // pre-M4 nullable-name behavior byte-for-byte; M4 tightens only
            // SALE_RECEIPT, whose caller always supplies an event version.
            $nullableStringFields[] = 'name';
        }

        foreach ($nullableStringFields as $field) {
            $value = $buyer[$field] ?? null;
            if ($value !== null && (! is_string($value) || $value === '')) {
                throw new RuntimeException('payload_buyer_'.$field.'_invalid:must be non-empty string or null; got '.var_export($value, true));
            }
        }

        if ($saleReceiptEventVersion !== null) {
            $name = $buyer['name'] ?? null;
            if (! is_string($name) || trim($name) === '') {
                throw new RuntimeException('payload_buyer_name_invalid:must be non-empty string; got '.var_export($name, true));
            }
        }

        $customerId = $buyer['customer_id'] ?? null;
        if ($saleReceiptEventVersion !== null
            && $saleReceiptEventVersion >= self::SALE_RECEIPT_POST_DISCOUNT_BASE_VERSION
            && $customerId !== null
            && (! is_string($customerId) || preg_match(self::LOWER_HEX_UUID, $customerId) !== 1)) {
            throw new RuntimeException(
                'payload_buyer_invalid:customer_id must be UUID or null for SALE_RECEIPT event_version>='.
                self::SALE_RECEIPT_POST_DISCOUNT_BASE_VERSION.'; got '.var_export($customerId, true)
            );
        }

        $this->validateAddress($buyer['address'] ?? null, 'buyer.address', required: false);
        $buyerCountryCode = $this->countryCodeFromAddress($buyer['address'] ?? null)
            ?? $this->sellerCountryCodeFromPayload($payload);

        if (($buyer['codice_fiscale'] ?? null) !== null) {
            $this->assertBuyerCodiceFiscale($buyer['codice_fiscale']);
        }

        if (($buyer['tax_number'] ?? null) !== null) {
            $this->assertTaxNumberForCountry($buyer['tax_number'], $buyerCountryCode, 'buyer.tax_number', buyer: true);
        }
    }

    /**
     * Validate seller / buyer address sub-object. When `$required` is false,
     * a null value passes.
     */
    private function validateAddress(mixed $address, string $path, bool $required): void
    {
        if ($address === null) {
            if ($required) {
                throw new RuntimeException('payload_address_invalid:'.$path.' is required; got null');
            }

            return;
        }
        if (! is_array($address) || (count($address) > 0 && array_is_list($address))) {
            throw new RuntimeException('payload_address_invalid:'.$path.' must be object; got '.get_debug_type($address));
        }
        /** @var array<string, mixed> $address */
        $expected = ['city', 'country_code', 'postal_code', 'street'];
        $missing = array_diff($expected, array_keys($address));
        if (count($missing) > 0) {
            sort($missing);
            throw new RuntimeException('payload_address_missing_keys:'.$path.':'.implode(',', $missing));
        }
        $extras = array_diff(array_keys($address), $expected);
        if (count($extras) > 0) {
            sort($extras);
            throw new RuntimeException('payload_address_extra_keys:'.$path.':'.implode(',', $extras));
        }
        $this->assertNonEmptyString($address, 'city', $path.'.city');
        $this->assertNonEmptyString($address, 'postal_code', $path.'.postal_code');
        $this->assertNonEmptyString($address, 'street', $path.'.street');

        $cc = $address['country_code'];
        if (! is_string($cc) || preg_match(self::ISO_3166_ALPHA_2, $cc) !== 1) {
            throw new RuntimeException('payload_address_country_code_invalid:'.$path.'.country_code must be ISO 3166-1 alpha-2; got '.var_export($cc, true));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateOriginalReceiptReference(array $payload): void
    {
        $ref = $payload['original_receipt_reference'] ?? null;
        $invoiceType = $payload['invoice_type_code'] ?? null;
        $refundOrVoid = $invoiceType === 'REFUND' || $invoiceType === 'VOID';

        if ($ref === null) {
            if ($refundOrVoid) {
                throw new RuntimeException('payload_invoice_type_invalid:original_receipt_reference required when invoice_type_code='.var_export($invoiceType, true));
            }

            return;
        }
        if (! is_array($ref) || (count($ref) > 0 && array_is_list($ref))) {
            throw new RuntimeException('payload_original_receipt_reference_invalid:must be object or null; got '.get_debug_type($ref));
        }
        /** @var array<string, mixed> $ref */
        $expected = ['fiscal_event_id', 'original_business_date', 'original_receipt_uuid', 'refund_reason'];
        $missing = array_diff($expected, array_keys($ref));
        if (count($missing) > 0) {
            sort($missing);
            throw new RuntimeException('payload_original_receipt_reference_missing_keys:'.implode(',', $missing));
        }
        $extras = array_diff(array_keys($ref), $expected);
        if (count($extras) > 0) {
            sort($extras);
            throw new RuntimeException('payload_original_receipt_reference_extra_keys:'.implode(',', $extras));
        }
        // N-02 closure: `fiscal_event_id` and `original_receipt_uuid`
        // are documented as UUIDs (synthesis v3 lines 71-73 + spec v7
        // §11.2). `refund_reason` is a free-text string.
        $this->assertUuid($ref, 'fiscal_event_id', 'original_receipt_reference.fiscal_event_id');
        $this->assertIsoDate($ref, 'original_business_date', 'original_receipt_reference.original_business_date');
        $this->assertUuid($ref, 'original_receipt_uuid', 'original_receipt_reference.original_receipt_uuid');
        $this->assertNonEmptyString($ref, 'refund_reason', 'original_receipt_reference.refund_reason');

        if (! $refundOrVoid) {
            throw new RuntimeException('payload_invoice_type_invalid:original_receipt_reference present but invoice_type_code='.var_export($invoiceType, true));
        }
    }

    /**
     * v4 `original_line_references[]` — spec §3.3's exact frozen shape,
     * strict parallel-array to `line_items[]` (kept from Revision 3's
     * positional-alignment/equality invariants: same length, `product_id`
     * equal, `quantity` equal at every index `i`).
     *
     * Reached only when `invoice_type_code === 'REFUND'` (§2z above already
     * fail-closed on every other value at v4).
     *
     * @param  array<string, mixed>  $payload
     */
    private function validateOriginalLineReferences(array $payload): void
    {
        $lineItems = $this->requireList($payload, 'line_items');
        $refs = $this->requireList($payload, 'original_line_references');

        if (count($refs) !== count($lineItems)) {
            throw new RuntimeException(
                'payload_original_line_references_length_mismatch:line_items='.count($lineItems).':original_line_references='.count($refs)
            );
        }
        if (count($refs) === 0) {
            throw new RuntimeException('payload_original_line_references_empty:original_line_references must have >= 1 row on a v4 REFUND');
        }

        foreach ($refs as $index => $row) {
            if (! is_array($row) || (count($row) > 0 && array_is_list($row))) {
                throw new RuntimeException("payload_original_line_reference_invalid:original_line_references[{$index}] must be object; got ".get_debug_type($row));
            }
            /** @var array<string, mixed> $row */
            $path = "original_line_references[{$index}]";
            $expected = ['disposition', 'original_line_index', 'product_id', 'quantity'];
            $missing = array_diff($expected, array_keys($row));
            if (count($missing) > 0) {
                sort($missing);
                throw new RuntimeException("payload_original_line_reference_missing_keys:{$path}:".implode(',', $missing));
            }
            $extras = array_diff(array_keys($row), $expected);
            if (count($extras) > 0) {
                sort($extras);
                throw new RuntimeException("payload_original_line_reference_extra_keys:{$path}:".implode(',', $extras));
            }

            $originalLineIndex = $row['original_line_index'];
            if (! is_int($originalLineIndex) || $originalLineIndex < 0) {
                throw new RuntimeException("payload_integer_format_mismatch:{$path}.original_line_index must be an integer >= 0; got ".var_export($originalLineIndex, true));
            }
            $this->assertNonEmptyString($row, 'product_id', "{$path}.product_id");
            $this->assertNonEmptyString($row, 'quantity', "{$path}.quantity");
            $this->assertEnum($row, 'disposition', self::RETURN_LINE_DISPOSITIONS, "{$path}.disposition");

            // Strict parallel-array invariants against line_items[index] —
            // NOT original_line_index (that indexes into the ORIGINAL
            // sale's line_items[], an entirely different, server-side-only
            // list this validator has no access to; the cross-reference is
            // resolved and verified server-side by the projector, §17).
            $lineItem = $lineItems[$index] ?? null;
            if (! is_array($lineItem)) {
                throw new RuntimeException("payload_original_line_reference_invalid:{$path} has no matching line_items[{$index}]");
            }
            /** @var array<string, mixed> $lineItem */
            if (($lineItem['product_id'] ?? null) !== $row['product_id']) {
                throw new RuntimeException(
                    "payload_original_line_reference_product_id_mismatch:{$path}.product_id must equal line_items[{$index}].product_id"
                );
            }
            if (($lineItem['quantity'] ?? null) !== $row['quantity']) {
                throw new RuntimeException(
                    "payload_original_line_reference_quantity_mismatch:{$path}.quantity must equal line_items[{$index}].quantity"
                );
            }
        }
    }

    /**
     * v4 `refund_destination` / `settlement_allocation` (spec §3.4,
     * unchanged from Revision 3): `refund_destination` is the single
     * `'cash'` literal for launch; `settlement_allocation` is
     * required-present-and-null on every launch v4 payload.
     *
     * @param  array<string, mixed>  $payload
     */
    private function validateRefundDestinationAndSettlementAllocation(array $payload): void
    {
        $this->assertEnum($payload, 'refund_destination', self::REFUND_DESTINATIONS);

        if (! array_key_exists('settlement_allocation', $payload)) {
            throw new RuntimeException('payload_missing_required:settlement_allocation');
        }
        if ($payload['settlement_allocation'] !== null) {
            throw new RuntimeException(
                'payload_settlement_allocation_not_null:settlement_allocation must be null on every launch v4 payload; got '.get_debug_type($payload['settlement_allocation'])
            );
        }
    }

    /**
     * SALE_RECEIPT line-item key sets by event_version.
     *
     * V1 — the original 13-key Candidate C-v3 line shape (immutable forever).
     * V2 (M4, SaleReceiptV2) — V1 + the variant identity keys
     * `variant_id` / `variant_name` / `variant_sku` (null for non-variant
     * lines), so the SIGNED record identifies the exact article the ticket
     * printed (NF525 line fidelity).
     *
     * The device-side mirror lives in `apps/pos/src/lib/fiscal/
     * FiscalEventEngine.ts` (`LINE_ITEM_KEYS`); the FiscalPayloadKeyDrift
     * vitest gate pins the two V2 lists against each other.
     */
    public const SALE_RECEIPT_LINE_ITEM_KEYS_V1 = [
        'gtin', 'line_discount_amount', 'line_discount_reason', 'line_subtotal',
        'line_vat', 'name', 'non_collected_subtype', 'product_id', 'quantity',
        'sku', 'tax_category_code', 'unit_price', 'vat_rate',
    ];

    public const SALE_RECEIPT_LINE_ITEM_KEYS_V2 = [
        'gtin', 'line_discount_amount', 'line_discount_reason', 'line_subtotal',
        'line_vat', 'name', 'non_collected_subtype', 'product_id', 'quantity',
        'sku', 'tax_category_code', 'unit_price', 'variant_id', 'variant_name',
        'variant_sku', 'vat_rate',
    ];

    /**
     * Validate one `line_items[i]` row.
     */
    private function validateLineItem(int|string $index, mixed $row, string $moneyRegex, int $scale, int $eventVersion = 1): void
    {
        if (! is_array($row) || (count($row) > 0 && array_is_list($row))) {
            throw new RuntimeException("payload_line_item_invalid:line_items[{$index}] must be object; got ".get_debug_type($row));
        }
        /** @var array<string, mixed> $row */
        $expected = $eventVersion >= 2
            ? self::SALE_RECEIPT_LINE_ITEM_KEYS_V2
            : self::SALE_RECEIPT_LINE_ITEM_KEYS_V1;
        $missing = array_diff($expected, array_keys($row));
        if (count($missing) > 0) {
            sort($missing);
            throw new RuntimeException("payload_line_item_missing_keys:line_items[{$index}]:".implode(',', $missing));
        }
        $extras = array_diff(array_keys($row), $expected);
        if (count($extras) > 0) {
            sort($extras);
            throw new RuntimeException("payload_line_item_extra_keys:line_items[{$index}]:".implode(',', $extras));
        }
        $path = "line_items[{$index}]";

        // Money fields at currency_scale.
        $this->assertMoneyString($row, 'unit_price', $moneyRegex, $scale, "{$path}.unit_price");
        $this->assertMoneyString($row, 'line_subtotal', $moneyRegex, $scale, "{$path}.line_subtotal");
        $this->assertMoneyString($row, 'line_vat', $moneyRegex, $scale, "{$path}.line_vat");
        $this->assertMoneyString($row, 'line_discount_amount', $moneyRegex, $scale, "{$path}.line_discount_amount");

        // quantity at fixed Phase-1 quantity_scale=3.
        $this->assertMoneyString($row, 'quantity', $this->moneyRegex(self::QUANTITY_SCALE), self::QUANTITY_SCALE, "{$path}.quantity");

        // vat_rate at fixed Phase-1 vat_rate_scale=2.
        $this->assertMoneyString($row, 'vat_rate', $this->moneyRegex(self::VAT_RATE_SCALE), self::VAT_RATE_SCALE, "{$path}.vat_rate");

        // Strings.
        $this->assertNonEmptyString($row, 'name', "{$path}.name");
        $this->assertNonEmptyString($row, 'product_id', "{$path}.product_id");
        $this->assertNonEmptyString($row, 'sku', "{$path}.sku");

        // tax_category_code — empty string OR uppercase alphanumeric / dash / underscore.
        $tcc = $row['tax_category_code'];
        if (! is_string($tcc)) {
            throw new RuntimeException("payload_line_item_tax_category_invalid:{$path}.tax_category_code must be string; got ".get_debug_type($tcc));
        }
        if ($tcc !== '' && preg_match('/^[A-Z0-9_\-]+$/D', $tcc) !== 1) {
            throw new RuntimeException("payload_line_item_tax_category_invalid:{$path}.tax_category_code must match ^[A-Z0-9_-]+$; got ".var_export($tcc, true));
        }

        // Nullable strings.
        foreach (['gtin', 'line_discount_reason'] as $f) {
            $v = $row[$f];
            if ($v !== null && (! is_string($v) || $v === '')) {
                throw new RuntimeException("payload_line_item_{$f}_invalid:{$path}.{$f} must be non-empty string or null; got ".var_export($v, true));
            }
        }

        // non_collected_subtype enum-or-null.
        $ncs = $row['non_collected_subtype'];
        if ($ncs !== null && (! is_string($ncs) || ! in_array($ncs, self::NON_COLLECTED_SUBTYPES, true))) {
            throw new RuntimeException("payload_line_item_non_collected_subtype_invalid:{$path}.non_collected_subtype must be one of ".implode('|', self::NON_COLLECTED_SUBTYPES).' or null; got '.var_export($ncs, true));
        }

        // SaleReceiptV2 (M4) variant identity: nullable; variant_id must be a
        // lowercase-hex UUID when present; a variant_name/variant_sku without
        // variant_id is an orphan identity and must never have been signed.
        if ($eventVersion >= 2) {
            $variantId = $row['variant_id'];
            if ($variantId !== null && (! is_string($variantId) || preg_match(self::LOWER_HEX_UUID, $variantId) !== 1)) {
                throw new RuntimeException("payload_line_item_variant_id_invalid:{$path}.variant_id must be lowercase-hex UUID or null; got ".var_export($variantId, true));
            }
            foreach (['variant_name', 'variant_sku'] as $f) {
                $v = $row[$f];
                if ($v !== null && (! is_string($v) || $v === '')) {
                    throw new RuntimeException("payload_line_item_{$f}_invalid:{$path}.{$f} must be non-empty string or null; got ".var_export($v, true));
                }
                if ($v !== null && $variantId === null) {
                    throw new RuntimeException("payload_line_item_variant_orphan:{$path}.{$f} present without variant_id");
                }
            }
        }

        // Discount-reason consistency at line level — same rule as the
        // invoice-level field. (Optional but symmetric; cheap to enforce.)
        $lda = $this->asNumericString($row['line_discount_amount'], "{$path}.line_discount_amount");
        $ldr = $row['line_discount_reason'];
        $isZero = bccomp($lda, '0', $scale) === 0;
        if ($isZero && $ldr !== null) {
            throw new RuntimeException(
                "payload_line_discount_reason_mismatch:{$path}:amount={$lda}:reason_present=true"
            );
        }
        if (! $isZero && $ldr === null) {
            throw new RuntimeException(
                "payload_line_discount_reason_mismatch:{$path}:amount={$lda}:reason_present=false"
            );
        }

        // NOTE: Deliberately NO per-line `line_subtotal == round(unit_price ×
        // quantity) − line_discount_amount` re-validation here. The canonical
        // `unit_price` is the cart's TAX-INCLUSIVE unit price (the POS cart is
        // tax-inclusive and writes the gross unit_price verbatim), while
        // `line_subtotal` is the NET amount (line_total − line_vat). Asserting
        // net == round(gross × qty) − discount compares a net field against a
        // gross product and FALSE-POSITIVES on every valid taxed receipt
        // (quarantining good events). NF525 does NOT mandate per-line arithmetic
        // re-validation — it secures the ticket AGGREGATES (VAT-declaration
        // integrity) and requires line detail to be conserved inalterably (the
        // hash already does that). Aggregate consistency is enforced in
        // validateSaleReceiptPayload(); the device proves its own line
        // arithmetic before signing.
    }

    /**
     * Validate one `payments[i]` row.
     */
    private function validatePayment(int|string $index, mixed $row, string $moneyRegex, int $scale): void
    {
        if (! is_array($row) || (count($row) > 0 && array_is_list($row))) {
            throw new RuntimeException("payload_payment_invalid:payments[{$index}] must be object; got ".get_debug_type($row));
        }
        /** @var array<string, mixed> $row */
        $expected = ['amount', 'foreign_currency_amount', 'foreign_currency_code', 'instrument_serial', 'instrument_type', 'method_code'];
        $missing = array_diff($expected, array_keys($row));
        if (count($missing) > 0) {
            sort($missing);
            throw new RuntimeException("payload_payment_missing_keys:payments[{$index}]:".implode(',', $missing));
        }
        $extras = array_diff(array_keys($row), $expected);
        if (count($extras) > 0) {
            sort($extras);
            throw new RuntimeException("payload_payment_extra_keys:payments[{$index}]:".implode(',', $extras));
        }
        $path = "payments[{$index}]";

        $this->assertMoneyString($row, 'amount', $moneyRegex, $scale, "{$path}.amount");
        $this->assertNonEmptyString($row, 'method_code', "{$path}.method_code");

        foreach (['instrument_serial', 'instrument_type'] as $f) {
            $v = $row[$f];
            if ($v !== null && (! is_string($v) || $v === '')) {
                throw new RuntimeException("payload_payment_{$f}_invalid:{$path}.{$f} must be non-empty string or null; got ".var_export($v, true));
            }
        }

        // Foreign-currency pair: both null OR both present-and-validated.
        $fca = $row['foreign_currency_amount'];
        $fcc = $row['foreign_currency_code'];
        if ($fca === null && $fcc === null) {
            return;
        }
        if ($fca === null || $fcc === null) {
            throw new RuntimeException("payload_payment_foreign_currency_pair_invalid:{$path} — foreign_currency_amount and foreign_currency_code must both be null or both non-null");
        }
        if (! is_string($fcc) || preg_match(self::ISO_4217, $fcc) !== 1) {
            throw new RuntimeException("payload_payment_foreign_currency_code_invalid:{$path}.foreign_currency_code must be ISO 4217 alpha-3 uppercase; got ".var_export($fcc, true));
        }
        $foreignScale = self::CURRENCY_SCALES[$fcc] ?? null;
        if ($foreignScale === null) {
            throw new RuntimeException("payload_payment_foreign_currency_unknown:{$path}.foreign_currency_code=".$fcc.' has no registered scale (extend CURRENCY_SCALES)');
        }
        // N-01 closure (defense-in-depth): the per-currency scale must
        // also fall within the {0, 2, 3} allowlist. The hardcoded
        // CURRENCY_SCALES table already honors this, but future entries
        // (e.g. crypto with 8-decimal precision) MUST go through an
        // explicit contract change.
        if (! in_array($foreignScale, self::SUPPORTED_CURRENCY_SCALES, true)) {
            throw new RuntimeException(
                'payload_currency_scale_unsupported:value='.$foreignScale.
                ':allowed='.implode(',', self::SUPPORTED_CURRENCY_SCALES).
                ':source='.$path.'.foreign_currency_code='.$fcc
            );
        }
        $this->assertMoneyString($row, 'foreign_currency_amount', $this->moneyRegex($foreignScale), $foreignScale, "{$path}.foreign_currency_amount");
    }

    /**
     * v4 single-cash-leg contract (spec §3.7, §3.5): `payments[]` is exactly
     * one row, `method_code === 'CASH'`, `instrument_type === null`. Reached
     * after every individual row already passed {@see validatePayment()}.
     *
     * @param  list<array<string, mixed>>  $payments
     */
    private function validateSingleCashLegPayment(array $payments): void
    {
        if (count($payments) !== 1) {
            throw new RuntimeException('payload_v4_refund_payments_not_single_leg:payments must have exactly 1 row; got '.count($payments));
        }
        $row = $payments[0];
        if (($row['method_code'] ?? null) !== 'CASH') {
            throw new RuntimeException('payload_v4_refund_payment_not_cash:payments[0].method_code must be CASH; got '.var_export($row['method_code'] ?? null, true));
        }
        if (($row['instrument_type'] ?? null) !== null) {
            throw new RuntimeException('payload_v4_refund_payment_instrument_type_must_be_null:payments[0].instrument_type must be null; got '.var_export($row['instrument_type'], true));
        }
    }

    /**
     * Validate one `vat_breakdown[i]` row (structural; partition equality
     * checked separately).
     */
    private function validateVatBreakdownRow(int|string $index, mixed $row, string $moneyRegex, int $scale, int $eventVersion = 1): void
    {
        if (! is_array($row) || (count($row) > 0 && array_is_list($row))) {
            throw new RuntimeException("payload_vat_breakdown_invalid:vat_breakdown[{$index}] must be object; got ".get_debug_type($row));
        }
        /** @var array<string, mixed> $row */
        // D-1: v5 rows carry `discount_allocated`. `$eventVersion` defaults to
        // 1 so every non-SALE_RECEIPT caller keeps the pre-D-1 five-key
        // contract byte-for-byte.
        $expected = $eventVersion >= self::SALE_RECEIPT_POST_DISCOUNT_BASE_VERSION
            ? self::SALE_RECEIPT_VAT_BREAKDOWN_KEYS_V5
            : ['gross_amount', 'net_amount', 'rate', 'tax_category_code', 'vat_amount'];
        $missing = array_diff($expected, array_keys($row));
        if (count($missing) > 0) {
            sort($missing);
            throw new RuntimeException("payload_vat_breakdown_missing_keys:vat_breakdown[{$index}]:".implode(',', $missing));
        }
        $extras = array_diff(array_keys($row), $expected);
        if (count($extras) > 0) {
            sort($extras);
            throw new RuntimeException("payload_vat_breakdown_extra_keys:vat_breakdown[{$index}]:".implode(',', $extras));
        }
        $path = "vat_breakdown[{$index}]";

        $this->assertMoneyString($row, 'net_amount', $moneyRegex, $scale, "{$path}.net_amount");
        $this->assertMoneyString($row, 'vat_amount', $moneyRegex, $scale, "{$path}.vat_amount");
        $this->assertMoneyString($row, 'gross_amount', $moneyRegex, $scale, "{$path}.gross_amount");
        $this->assertMoneyString($row, 'rate', $this->moneyRegex(self::VAT_RATE_SCALE), self::VAT_RATE_SCALE, "{$path}.rate");
        if ($eventVersion >= self::SALE_RECEIPT_POST_DISCOUNT_BASE_VERSION) {
            $this->assertMoneyString($row, 'discount_allocated', $moneyRegex, $scale, "{$path}.discount_allocated");
        }

        $tcc = $row['tax_category_code'];
        if (! is_string($tcc)) {
            throw new RuntimeException("payload_vat_breakdown_tax_category_invalid:{$path}.tax_category_code must be string; got ".get_debug_type($tcc));
        }
        if ($tcc !== '' && preg_match('/^[A-Z0-9_\-]+$/D', $tcc) !== 1) {
            throw new RuntimeException("payload_vat_breakdown_tax_category_invalid:{$path}.tax_category_code must match ^[A-Z0-9_-]+$; got ".var_export($tcc, true));
        }

        // Internal arithmetic check: net + vat == gross at scale.
        $netN = $this->asNumericString($row['net_amount'], "{$path}.net_amount");
        $vatN = $this->asNumericString($row['vat_amount'], "{$path}.vat_amount");
        $grossN = $this->asNumericString($row['gross_amount'], "{$path}.gross_amount");
        $sum = bcadd($netN, $vatN, $scale);
        if (bccomp($sum, $grossN, $scale) !== 0) {
            throw new RuntimeException(
                "payload_partition_gross_mismatch:rate={$row['rate']}:category={$tcc}:expected={$sum}:got={$grossN}"
            );
        }
    }

    /**
     * Validate one `vouchers_redeemed[i]` row.
     */
    private function validateVoucherRedemption(int|string $index, mixed $row, string $moneyRegex, int $scale): void
    {
        if (! is_array($row) || (count($row) > 0 && array_is_list($row))) {
            throw new RuntimeException("payload_voucher_invalid:vouchers_redeemed[{$index}] must be object; got ".get_debug_type($row));
        }
        /** @var array<string, mixed> $row */
        $expected = ['redeemed_amount', 'voucher_code'];
        $missing = array_diff($expected, array_keys($row));
        if (count($missing) > 0) {
            sort($missing);
            throw new RuntimeException("payload_voucher_missing_keys:vouchers_redeemed[{$index}]:".implode(',', $missing));
        }
        $extras = array_diff(array_keys($row), $expected);
        if (count($extras) > 0) {
            sort($extras);
            throw new RuntimeException("payload_voucher_extra_keys:vouchers_redeemed[{$index}]:".implode(',', $extras));
        }
        $path = "vouchers_redeemed[{$index}]";
        $this->assertMoneyString($row, 'redeemed_amount', $moneyRegex, $scale, "{$path}.redeemed_amount");
        $this->assertNonEmptyString($row, 'voucher_code', "{$path}.voucher_code");
    }

    /**
     * VAT partition algorithm per synthesis v5 §6.C.
     *
     * @param  list<mixed>  $lineItems
     * @param  list<mixed>  $vatBreakdown
     * @param  array<string, mixed>  $payload  read only at v5, for the ticket-wide remise (gate r2 finding 1)
     */
    private function validateVatPartition(array $lineItems, array $vatBreakdown, int $scale, int $eventVersion = 1, array $payload = []): void
    {
        $isPostDiscountBase = $eventVersion >= self::SALE_RECEIPT_POST_DISCOUNT_BASE_VERSION;
        // Group line_items by (vat_rate, tax_category_code). Money fields
        // were narrowed by validateLineItem above (assertMoneyString); we
        // re-narrow via asNumericString to satisfy PHPStan's BCMath types.
        /** @var array<string, array{rate: string, category: string, sum_net: numeric-string, sum_vat: numeric-string}> $groups */
        $groups = [];
        foreach ($lineItems as $row) {
            /** @var array<string, mixed> $row */
            $rate = is_string($row['vat_rate']) ? $row['vat_rate'] : '';
            $category = is_string($row['tax_category_code']) ? $row['tax_category_code'] : '';
            $key = $rate.'|'.$category;
            $netN = $this->asNumericString($row['line_subtotal'], 'line_subtotal');
            $vatN = $this->asNumericString($row['line_vat'], 'line_vat');
            if (! array_key_exists($key, $groups)) {
                $groups[$key] = [
                    'rate' => $rate,
                    'category' => $category,
                    'sum_net' => '0',
                    'sum_vat' => '0',
                ];
            }
            $groups[$key]['sum_net'] = bcadd($groups[$key]['sum_net'], $netN, $scale);
            $groups[$key]['sum_vat'] = bcadd($groups[$key]['sum_vat'], $vatN, $scale);
        }

        // Build breakdown-key map; reject duplicates inline.
        /** @var array<string, array<string, mixed>> $breakdownByKey */
        $breakdownByKey = [];
        foreach ($vatBreakdown as $row) {
            /** @var array<string, mixed> $row */
            $rate = is_string($row['rate']) ? $row['rate'] : '';
            $category = is_string($row['tax_category_code']) ? $row['tax_category_code'] : '';
            $key = $rate.'|'.$category;
            if (array_key_exists($key, $breakdownByKey)) {
                throw new RuntimeException(
                    "payload_partition_duplicate:rate={$rate}:category={$category}"
                );
            }
            $breakdownByKey[$key] = $row;
        }

        // Set equality: G_lines == G_breakdown.
        $linesSet = array_keys($groups);
        sort($linesSet);
        $breakdownSet = array_keys($breakdownByKey);
        sort($breakdownSet);
        if ($linesSet !== $breakdownSet) {
            throw new RuntimeException(
                'payload_partition_mismatch:lines_set='.implode(',', $linesSet).
                ':breakdown_set='.implode(',', $breakdownSet)
            );
        }

        // ---- D-1 gate r2 finding 1 — the ticket-wide denominator for the
        // ---- ALLOCATION band. r1 pinned each group's net/VAT SPLIT but
        // ---- nothing pinned its SHARE, and because `discNet + discVat ==
        // ---- allocated` holds per group, moving the whole remise onto a
        // ---- different rate group left EVERY aggregate identity intact while
        // ---- the declared VAT moved. Pushing 50.000 onto the exempt group of
        // ---- the ruling's own example sealed VAT 71.000 — the pre-D-1 figure
        // ---- the ruling exists to remove — at event_version 5, accepted.
        /** @var numeric-string $ticketLineGross */
        $ticketLineGross = bcadd('0', '0', $scale);
        if ($isPostDiscountBase) {
            foreach ($groups as $g) {
                $ticketLineGross = bcadd(
                    $ticketLineGross,
                    bcadd($g['sum_net'], $g['sum_vat'], $scale),
                    $scale,
                );
            }
        }
        /** @var numeric-string $declaredDiscount */
        $declaredDiscount = $isPostDiscountBase
            ? $this->asNumericString($payload['transaction_discount_amount'], 'transaction_discount_amount')
            : bcadd('0', '0', $scale);

        // Per-group amount equality.
        foreach ($groups as $key => $g) {
            $b = $breakdownByKey[$key];
            $bNet = $this->asNumericString($b['net_amount'], 'vat_breakdown.net_amount');
            $bVat = $this->asNumericString($b['vat_amount'], 'vat_breakdown.vat_amount');
            $bGross = $this->asNumericString($b['gross_amount'], 'vat_breakdown.gross_amount');
            $expectedGross = bcadd($g['sum_net'], $g['sum_vat'], $scale);

            // ---- D-1 (v5): the group is the line roll-up MINUS its share of
            // ---- the ticket remise, so a straight equality against the line
            // ---- sums would refuse every correct post-remise ticket. What is
            // ---- verified instead is that the sealed group is a TRUE SPLIT of
            // ---- the line sums:
            // ----   discNet = Σ line_subtotal − net_amount  >= 0
            // ----   discVat = Σ line_vat      − vat_amount  >= 0
            // ----   discNet + discVat == discount_allocated
            // ---- and gross == Σ line gross − discount_allocated.
            // ----
            // ---- This is EXACT and recomputation-free: the server never
            // ---- divides by a rate and never re-derives the device's VAT. It
            // ---- still pins the sealed numbers to the sealed lines, so a
            // ---- fabricated base cannot pass.
            if ($isPostDiscountBase) {
                $this->validateVatPartitionGroupV5(
                    $g,
                    $b,
                    $bNet,
                    $bVat,
                    $bGross,
                    $expectedGross,
                    $scale,
                    $ticketLineGross,
                    $declaredDiscount,
                );

                continue;
            }

            if (bccomp($g['sum_net'], $bNet, $scale) !== 0) {
                throw new RuntimeException(
                    "payload_partition_net_mismatch:rate={$g['rate']}:category={$g['category']}:expected={$g['sum_net']}:got=".$bNet
                );
            }
            if (bccomp($g['sum_vat'], $bVat, $scale) !== 0) {
                throw new RuntimeException(
                    "payload_partition_vat_mismatch:rate={$g['rate']}:category={$g['category']}:expected={$g['sum_vat']}:got=".$bVat
                );
            }
            if (bccomp($expectedGross, $bGross, $scale) !== 0) {
                throw new RuntimeException(
                    "payload_partition_gross_mismatch:rate={$g['rate']}:category={$g['category']}:expected={$expectedGross}:got=".$bGross
                );
            }
        }
    }

    /**
     * D-1 (v5) per-group partition check — the sealed group must be a TRUE
     * SPLIT of its line roll-up by the group's `discount_allocated`.
     *
     * Never recomputes VAT from a rate: only exact subtractions and equalities
     * at the payload's currency scale. A violation routes through the same
     * RuntimeException → quarantine path as every other partition failure.
     *
     * @param  array{rate: string, category: string, sum_net: numeric-string, sum_vat: numeric-string}  $group
     * @param  array<string, mixed>  $breakdown
     * @param  numeric-string  $breakdownNet
     * @param  numeric-string  $breakdownVat
     * @param  numeric-string  $breakdownGross
     * @param  numeric-string  $lineGross
     * @param  numeric-string  $ticketLineGross  Σ pre-remise gross over all groups (v5 only)
     * @param  numeric-string  $declaredDiscount  the ticket remise (v5 only)
     *
     * @throws RuntimeException on a partition violation (→ quarantine)
     */
    private function validateVatPartitionGroupV5(
        array $group,
        array $breakdown,
        string $breakdownNet,
        string $breakdownVat,
        string $breakdownGross,
        string $lineGross,
        int $scale,
        string $ticketLineGross = '0',
        string $declaredDiscount = '0',
    ): void {
        /** @var numeric-string $ticketLineGross */
        /** @var numeric-string $declaredDiscount */
        $rate = $group['rate'];
        $category = $group['category'];
        $allocated = $this->asNumericString($breakdown['discount_allocated'], 'vat_breakdown.discount_allocated');

        $discNet = bcsub($group['sum_net'], $breakdownNet, $scale);
        $discVat = bcsub($group['sum_vat'], $breakdownVat, $scale);
        if (bccomp($discNet, '0', $scale) < 0) {
            throw new RuntimeException(
                "payload_partition_net_exceeds_lines:rate={$rate}:category={$category}:lines={$group['sum_net']}:got=".$breakdownNet
            );
        }
        if (bccomp($discVat, '0', $scale) < 0) {
            throw new RuntimeException(
                "payload_partition_vat_exceeds_lines:rate={$rate}:category={$category}:lines={$group['sum_vat']}:got=".$breakdownVat
            );
        }

        $discSplit = bcadd($discNet, $discVat, $scale);
        if (bccomp($discSplit, $allocated, $scale) !== 0) {
            throw new RuntimeException(
                "payload_partition_discount_split_mismatch:rate={$rate}:category={$category}:expected={$allocated}:got=".$discSplit
            );
        }

        // ---- D-1 gate r1 finding 2. The checks above bound only the TOTAL of
        // ---- the two halves, leaving the split between them free. A device
        // ---- could take the whole remise out of the VAT half wherever the
        // ---- group could carry it — same lines, same total, same
        // ---- `Σ discount_allocated`, same group grosses — and under-declare
        // ---- output VAT by up to `min(remise, Σ line_vat)` per receipt with
        // ---- nothing raising a hand. On the ruling's own worked example that
        // ---- is 30.703 TND on ONE ticket, sealed into the chain and read
        // ---- verbatim by the declaration.
        // ----
        // ---- The pin is EXACT, and it is still not a recomputation of the
        // ---- group's VAT: `TransactionRemiseSplit` is a pure function of the
        // ---- allocated share, the rate and the group's OWN sealed line sums,
        // ---- and it is the same authority the device and the server-authored
        // ---- path both carve the remise with. The group's VAT remains
        // ---- `Σ line_vat − discVat`, where `Σ line_vat` is sealed line data
        // ---- this validator never second-guesses. No tolerance band is needed:
        // ---- the clamps that make a 100 %-comp land on exactly zero live
        // ---- INSIDE the shared rule, so the expected pair is reproducible to
        // ---- the millime rather than approximated.
        // `$rate` reaches here as the group KEY (`vat_rate` off the line items),
        // already pinned to `VAT_RATE_SCALE` by `assertMoneyString` on both the
        // line and the breakdown row. Re-narrowed for the shared kernel's
        // numeric-string contract rather than cast.
        $rateN = $this->asNumericString($rate, 'vat_breakdown.rate');
        [, $expectedDiscVat] = TransactionRemiseSplit::split(
            $allocated,
            $rateN,
            $group['sum_net'],
            $group['sum_vat'],
            $scale,
        );
        if (bccomp($discVat, $expectedDiscVat, $scale) !== 0) {
            throw new RuntimeException(
                "payload_partition_discount_vat_split_out_of_band:rate={$rate}:category={$category}"
                .":expected={$expectedDiscVat}:got=".$discVat
            );
        }

        $expectedGross = bcsub($lineGross, $allocated, $scale);
        if (bccomp($expectedGross, $breakdownGross, $scale) !== 0) {
            throw new RuntimeException(
                "payload_partition_gross_mismatch:rate={$rate}:category={$category}:expected={$expectedGross}:got=".$breakdownGross
            );
        }

        $this->assertRemiseAllocationInBand(
            $rate,
            $category,
            $allocated,
            $lineGross,
            $ticketLineGross,
            $declaredDiscount,
            $scale,
        );
    }

    /**
     * D-1 gate r2 finding 1 — bound this group's SHARE of the ticket remise.
     *
     * The device ventilates by largest-remainder pro-rata
     * (`vatDiscountAllocation.ts`): `exact_r = discount x gross_r / Σ gross`,
     * floored at the currency scale, with the residue handed out one ulp at a
     * time. So an honest share is `floor(exact_r)` or `floor(exact_r) + 1 ulp`
     * — and, in the degenerate case where a high-remainder group is already at
     * its own gross ceiling and the allocator's second capacity pass revisits,
     * `+ 2 ulp`.
     *
     * This is a BAND, deliberately not an equality. Pinning the allocation
     * exactly would make the server a CO-AUTHOR of the ventilation, and any
     * future device/server drift in the largest-remainder tie-break would
     * quarantine real sales — a materially different risk posture. The band
     * refuses every re-allocation that moves real money (the r2 probe moved the
     * whole remise onto one group, changing the declared VAT by 2.436 and
     * 5.547) while staying immune to tie-break drift.
     *
     * @param  numeric-string  $allocated
     * @param  numeric-string  $lineGross  this group's PRE-remise gross
     * @param  numeric-string  $ticketLineGross  Σ pre-remise gross over all groups
     * @param  numeric-string  $declaredDiscount
     *
     * @throws RuntimeException when the share is outside the band (→ quarantine)
     */
    private function assertRemiseAllocationInBand(
        string $rate,
        string $category,
        string $allocated,
        string $lineGross,
        string $ticketLineGross,
        string $declaredDiscount,
        int $scale,
    ): void {
        // A remise-free ticket has nothing to ventilate; the `Σ allocated ==
        // discount` + non-negativity checks already pin every share at zero.
        if (bccomp($declaredDiscount, '0', $scale) === 0
            || bccomp($ticketLineGross, '0', $scale) === 0) {
            return;
        }

        $ratioScale = $scale + TransactionRemiseSplit::RATIO_EXTRA_SCALE;
        $exact = bcdiv(
            bcmul($declaredDiscount, $lineGross, $ratioScale),
            $ticketLineGross,
            $ratioScale,
        );
        $ulp = TransactionRemiseSplit::ulp($scale);
        $lower = bcsub($exact, $ulp, $ratioScale);
        $upper = bcadd($exact, bcmul($ulp, '2', $scale), $ratioScale);

        if (bccomp($allocated, $lower, $ratioScale) < 0
            || bccomp($allocated, $upper, $ratioScale) > 0) {
            throw new RuntimeException(
                "payload_partition_discount_allocation_out_of_band:rate={$rate}:category={$category}"
                .':expected_pro_rata='.bcadd($exact, '0', $scale)
                .':got='.$allocated
            );
        }
    }

    // =================================================================
    // Existing event-type clauses — unchanged from v1
    // =================================================================

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateChainBreakDetectedPayload(array $payload): void
    {
        $this->assertHashField($payload, 'last_good_hash');

        $this->validateNonEmptyAssoc($payload, 'offending_record_reference');
        /** @var array<string, mixed> $ref */
        $ref = $payload['offending_record_reference'];
        if (array_key_exists('observed_previous_hash', $ref)) {
            $this->assertHashField($ref, 'observed_previous_hash', 'offending_record_reference.observed_previous_hash');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateChainRestartPayload(array $payload): void
    {
        $this->assertHashField($payload, 'new_genesis_reference');
        $this->validateNonEmptyAssoc($payload, 'last_good_anchor');
        $this->validateNonEmptyAssoc($payload, 'operator_authorization_evidence');
        $this->validateNonEmptyAssoc($payload, 'provenance_link');

        /** @var array<string, mixed> $anchor */
        $anchor = $payload['last_good_anchor'];
        if (array_key_exists('hash', $anchor)) {
            $this->assertHashField($anchor, 'hash', 'last_good_anchor.hash');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateTerminalRegistrySnapshotPayload(array $payload): void
    {
        $this->assertHashField($payload, 'snapshot_hash');
        if (($payload['prior_snapshot_link'] ?? null) !== null) {
            $this->assertHashField($payload, 'prior_snapshot_link');
        }

        $this->validateListOfAssoc($payload, 'terminals', $this->moneyRegex(0), 0, []);
    }

    // =================================================================
    // Shared helpers
    // =================================================================

    /**
     * @param  array<string, mixed>  $payload
     * @return list<mixed>
     */
    private function requireList(array $payload, string $key): array
    {
        $items = $payload[$key] ?? null;
        if (! is_array($items)) {
            throw new RuntimeException("payload_{$key}_invalid:must be a JSON list; got ".get_debug_type($items));
        }
        if (! array_is_list($items)) {
            throw new RuntimeException("payload_{$key}_invalid:must be a JSON list, not an object");
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $monetaryStringFields
     */
    private function validateListOfAssoc(array $payload, string $key, string $moneyRegex, int $moneyScale, array $monetaryStringFields): void
    {
        $items = $payload[$key] ?? null;
        if (! is_array($items)) {
            throw new RuntimeException("$key must be an array; got ".get_debug_type($items));
        }
        if (! array_is_list($items)) {
            throw new RuntimeException("$key must be a JSON list, not an object");
        }
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw new RuntimeException("{$key}[{$index}] must be an object; got ".get_debug_type($item));
            }
            if (count($item) === 0) {
                throw new RuntimeException("{$key}[{$index}] must be a non-empty object; got empty");
            }
            if (array_is_list($item)) {
                throw new RuntimeException("{$key}[{$index}] must be an object, not a list");
            }
            foreach ($monetaryStringFields as $field) {
                if (array_key_exists($field, $item)) {
                    $this->assertMoneyString($item, $field, $moneyRegex, $moneyScale, "{$key}[{$index}].{$field}");
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateNonEmptyAssoc(array $payload, string $key): void
    {
        $value = $payload[$key] ?? null;
        if (! is_array($value)) {
            throw new RuntimeException("$key must be an object; got ".get_debug_type($value));
        }
        if (count($value) === 0) {
            throw new RuntimeException("$key must be a non-empty object; got empty");
        }
        if (array_is_list($value)) {
            throw new RuntimeException("$key must be an object, not a list");
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertCurrency(array $payload): void
    {
        $currencyCode = $payload['currency_code'] ?? null;
        if (! is_string($currencyCode) || preg_match(self::ISO_4217, $currencyCode) !== 1) {
            throw new RuntimeException('payload_currency_code_invalid:must be ISO 4217 alpha-3 uppercase; got '.var_export($currencyCode, true));
        }

        $scale = $payload['currency_scale'] ?? null;
        if (! is_int($scale) || ! in_array($scale, self::SUPPORTED_CURRENCY_SCALES, true)) {
            throw new RuntimeException('payload_currency_scale_unsupported:value='.var_export($scale, true));
        }
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    private function assertNullableObject(array $bag, string $field): void
    {
        $value = $bag[$field] ?? null;
        if ($value !== null && (! is_array($value) || array_is_list($value))) {
            throw new RuntimeException('payload_object_or_null_required:'.$field);
        }
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    private function assertMoneyString(array $bag, string $field, string $regex, int $scale, ?string $reportAs = null): void
    {
        $label = $reportAs ?? $field;
        $value = $bag[$field] ?? null;
        if (! is_string($value)) {
            throw new RuntimeException(sprintf(
                'payload_money_type_mismatch:field=%s:expected=string:got=%s',
                $label,
                get_debug_type($value),
            ));
        }
        if (preg_match($regex, $value) !== 1) {
            throw new RuntimeException(sprintf(
                'payload_money_scale_mismatch:field=%s:value=%s:expected_scale=%d',
                $label,
                $value,
                $scale,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    private function assertHashField(array $bag, string $field, ?string $reportAs = null): void
    {
        $label = $reportAs ?? $field;
        $value = $bag[$field] ?? null;
        if (! is_string($value) || preg_match(self::LOWER_HEX_64, $value) !== 1) {
            throw new RuntimeException(sprintf(
                'invalid_hash_format:%s must be 64-char lowercase hex; got %s',
                $label,
                var_export($value, true),
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    private function assertNonEmptyString(array $bag, string $field, ?string $reportAs = null): void
    {
        $label = $reportAs ?? $field;
        $value = $bag[$field] ?? null;
        if (! is_string($value) || $value === '') {
            throw new RuntimeException(sprintf(
                'payload_field_invalid:%s must be non-empty string; got %s',
                $label,
                var_export($value, true),
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    private function assertIsoDate(array $bag, string $field, ?string $reportAs = null): void
    {
        $label = $reportAs ?? $field;
        $value = $bag[$field] ?? null;
        if (! is_string($value) || preg_match(self::ISO_8601_DATE, $value) !== 1) {
            throw new RuntimeException(sprintf(
                'payload_field_invalid:%s must be ISO 8601 date (YYYY-MM-DD); got %s',
                $label,
                var_export($value, true),
            ));
        }
    }

    /**
     * N-02 closure helper — validate `event_time_device`-style payload
     * timestamps as ISO 8601 with milliseconds AND timezone offset.
     *
     * @param  array<string, mixed>  $bag
     */
    private function assertIsoDateTimeWithMs(array $bag, string $field, ?string $reportAs = null): void
    {
        $label = $reportAs ?? $field;
        $value = $bag[$field] ?? null;
        if (! is_string($value) || preg_match(self::ISO_8601_DATETIME_MS_TZ, $value) !== 1) {
            throw new RuntimeException(sprintf(
                'payload_datetime_format_mismatch:field=%s:value=%s',
                $label,
                is_string($value) ? $value : var_export($value, true),
            ));
        }
    }

    /**
     * N-02 closure helper — validate UUID identity fields as
     * lowercase-hex per RFC 4122 (version-agnostic at this layer).
     *
     * @param  array<string, mixed>  $bag
     */
    private function assertUuid(array $bag, string $field, ?string $reportAs = null): void
    {
        $label = $reportAs ?? $field;
        $value = $bag[$field] ?? null;
        if (! is_string($value) || preg_match(self::LOWER_HEX_UUID, $value) !== 1) {
            throw new RuntimeException(sprintf(
                'payload_uuid_format_mismatch:field=%s:value=%s',
                $label,
                is_string($value) ? $value : var_export($value, true),
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    private function assertBool(array $bag, string $field, ?string $reportAs = null): void
    {
        $label = $reportAs ?? $field;
        $value = $bag[$field] ?? null;
        if (! is_bool($value)) {
            throw new RuntimeException(sprintf(
                'payload_field_invalid:%s must be bool; got %s',
                $label,
                get_debug_type($value),
            ));
        }
    }

    /**
     * Assert a payload field is a positive integer (>= 1). Mirrors the
     * `payload_integer_format_mismatch` failure shape used by the
     * `status_version` clause in `validateAccountStatusChangedPayload`.
     * Fails when the value is missing, not an int, or <= 0.
     *
     * @param  array<string, mixed>  $bag
     */
    private function assertPositiveInt(array $bag, string $field, ?string $reportAs = null): void
    {
        $label = $reportAs ?? $field;
        $value = $bag[$field] ?? null;
        if (! is_int($value) || $value < 1) {
            throw new RuntimeException(sprintf(
                'payload_integer_format_mismatch:%s must be a positive integer (>= 1); got %s',
                $label,
                var_export($value, true),
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $bag
     * @param  list<string>  $allowed
     */
    private function assertEnum(array $bag, string $field, array $allowed, ?string $reportAs = null): void
    {
        $label = $reportAs ?? $field;
        $value = $bag[$field] ?? null;
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new RuntimeException(sprintf(
                'payload_field_invalid:%s must be one of %s; got %s',
                $label,
                implode('|', $allowed),
                var_export($value, true),
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $bag
     * @param  list<string>  $allowed
     */
    private function assertOptionalEnum(array $bag, string $field, array $allowed, ?string $reportAs = null): void
    {
        $label = $reportAs ?? $field;
        $value = $bag[$field] ?? null;
        if ($value === null) {
            return;
        }
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new RuntimeException(sprintf(
                'payload_field_invalid:%s must be null or one of %s; got %s',
                $label,
                implode('|', $allowed),
                var_export($value, true),
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    private function assertOptionalNullableString(array $bag, string $field, ?string $reportAs = null): void
    {
        $label = $reportAs ?? $field;
        $value = $bag[$field] ?? null;
        if ($value === null) {
            return;
        }
        if (! is_string($value) || $value === '') {
            throw new RuntimeException(sprintf(
                'payload_field_invalid:%s must be non-empty string or null; got %s',
                $label,
                var_export($value, true),
            ));
        }
    }

    private function assertTaxNumberForCountry(mixed $value, string $countryCode, string $path, bool $buyer = false): void
    {
        $taxNumber = $this->assertTaxNumberBaseline($value, $path);
        $normalized = $this->normalizeTaxNumberForCountry($taxNumber, $countryCode);

        $patterns = [];
        $countryPattern = self::TAX_NUMBER_PATTERNS[$countryCode] ?? null;
        if ($countryPattern !== null) {
            $patterns[] = $countryPattern;
        }
        if ($buyer && $countryCode === 'FR') {
            $patterns[] = self::FR_BUYER_TVA_INTRACOM;
        }

        if ($patterns === []) {
            return;
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return;
            }
        }

        throw new RuntimeException(sprintf(
            'payload_tax_number_format_mismatch:field=%s:country=%s:value=%s',
            $path,
            $countryCode,
            $taxNumber,
        ));
    }

    private function assertBuyerCodiceFiscale(mixed $value): void
    {
        if (! is_string($value) || $value === '') {
            throw new RuntimeException('payload_buyer_codice_fiscale_invalid:must be non-empty string or null; got '.var_export($value, true));
        }
        if (preg_match(self::IT_BUYER_CODICE_FISCALE, $value) !== 1) {
            throw new RuntimeException(
                'payload_buyer_codice_fiscale_format_mismatch:field=buyer.codice_fiscale:value='.$value
            );
        }
    }

    private function normalizeTaxNumberForCountry(string $value, string $countryCode): string
    {
        // Shared with every entry-time validator so entry and seal can never
        // canonicalize differently. Behaviour is unchanged: TN long-form
        // slashes are stripped, every other country is untouched.
        return CountryTaxNumberRules::normalizeForMatching($countryCode, $value);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sellerCountryCodeFromPayload(array $payload): string
    {
        $seller = $payload['seller'] ?? null;
        if (is_array($seller) && is_string($seller['tax_jurisdiction_country_code'] ?? null)) {
            return $seller['tax_jurisdiction_country_code'];
        }

        return '';
    }

    private function countryCodeFromAddress(mixed $address): ?string
    {
        if (is_array($address) && is_string($address['country_code'] ?? null)) {
            return $address['country_code'];
        }

        return null;
    }

    /**
     * Universal tax_number baseline validator (synthesis v5 §7).
     *
     * @return non-empty-string
     */
    private function assertTaxNumberBaseline(mixed $value, string $path): string
    {
        if (! is_string($value)) {
            throw new RuntimeException(sprintf(
                'payload_tax_number_invalid:%s must be string; got %s',
                $path,
                get_debug_type($value),
            ));
        }
        if ($value === '') {
            throw new RuntimeException(sprintf(
                'payload_tax_number_invalid:%s must be non-empty; got empty string',
                $path,
            ));
        }
        if ($value !== trim($value)) {
            throw new RuntimeException(sprintf(
                'payload_tax_number_invalid:%s must be trimmed (no surrounding whitespace); got %s',
                $path,
                var_export($value, true),
            ));
        }
        // Reject control bytes (< 0x20) and DEL (0x7F).
        for ($i = 0, $len = strlen($value); $i < $len; $i++) {
            $b = ord($value[$i]);
            if ($b < 0x20 || $b === 0x7F) {
                throw new RuntimeException(sprintf(
                    'payload_tax_number_invalid:%s contains control byte 0x%02X at offset %d',
                    $path,
                    $b,
                    $i,
                ));
            }
        }
        if (preg_match(self::TAX_NUMBER_UNIVERSAL, $value) !== 1) {
            throw new RuntimeException(sprintf(
                'payload_tax_number_invalid:%s must match %s; got %s',
                $path,
                self::TAX_NUMBER_UNIVERSAL,
                var_export($value, true),
            ));
        }

        return $value;
    }

    /**
     * Narrow `mixed` to `numeric-string` so PHPStan accepts BCMath calls.
     *
     * Every money field is validated by `assertMoneyString` BEFORE this
     * helper runs (the regex is a strict superset of `is_numeric`), so
     * the runtime check here is defense-in-depth — it should never throw
     * on a well-formed payload that already passed validation.
     *
     * @return numeric-string
     */
    private function asNumericString(mixed $value, string $path): string
    {
        if (! is_string($value) || ! is_numeric($value)) {
            throw new RuntimeException(sprintf(
                'payload_money_type_mismatch:field=%s:expected=numeric-string:got=%s',
                $path,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireAssocObject(mixed $value, string $path): array
    {
        if (! is_array($value) || (count($value) > 0 && array_is_list($value))) {
            throw new RuntimeException('payload_object_invalid:'.$path.' must be object; got '.get_debug_type($value));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $expected
     */
    private function assertExactObjectKeys(array $row, array $expected, string $path): void
    {
        $missing = array_diff($expected, array_keys($row));
        if (count($missing) > 0) {
            sort($missing);
            throw new RuntimeException('payload_object_missing_keys:'.$path.':'.implode(',', $missing));
        }
        $extras = array_diff(array_keys($row), $expected);
        if (count($extras) > 0) {
            sort($extras);
            throw new RuntimeException('payload_object_extra_keys:'.$path.':'.implode(',', $extras));
        }
    }

    private function validateNullableAssoc(mixed $value, string $path): void
    {
        if ($value === null) {
            return;
        }
        $this->requireAssocObject($value, $path);
    }

    /**
     * Scale-aware regex matching `CurrencyScale::bcformat()` output —
     * **NON-NEGATIVE** per synthesis v5 §6.A.
     *
     *   - scale 0 (e.g. JPY/TND)  → `0`, `123`
     *   - scale 2 (e.g. EUR)      → `0.00`, `123.45`
     *   - scale 3 (e.g. TND)      → `0.000`, `123.456`
     *
     * Refunds are modeled via `invoice_type_code='REFUND'` +
     * `original_receipt_reference`, NOT negative amounts.
     */
    private function moneyRegex(int $scale): string
    {
        if ($scale === 0) {
            return '/^(0|[1-9]\d*)$/D';
        }

        return '/^(0|[1-9]\d*)\.\d{'.$scale.'}$/D';
    }

    /**
     * Scale-aware regex for a SIGNED bcformat money string (spec §4.4).
     * Mirrors {@see moneyRegex()} with an optional leading minus; the scale-0
     * branch has NO decimal point.
     */
    private function signedMoneyRegex(int $scale): string
    {
        if ($scale === 0) {
            return '/^-?(0|[1-9]\d*)$/D';
        }

        return '/^-?(0|[1-9]\d*)\.\d{'.$scale.'}$/D';
    }

    /**
     * Assert a signed money field and reject `-0` in every spelling.
     * Canonical zero is the UNSIGNED zero at the currency scale; a signed
     * zero would produce two byte-distinct encodings of the same value and
     * break replay determinism.
     *
     * @param  array<string, mixed>  $bag
     */
    private function assertSignedMoneyString(array $bag, string $field, int $scale): void
    {
        $this->assertMoneyString($bag, $field, $this->signedMoneyRegex($scale), $scale);

        /** @var string $value */
        $value = $bag[$field];
        if (str_starts_with($value, '-') && bccomp($this->asNumericString($value, $field), '0', $scale) === 0) {
            throw new RuntimeException(sprintf(
                'payload_money_negative_zero:field=%s:value=%s',
                $field,
                $value,
            ));
        }
    }

    /**
     * v3 cash-rounding binds in the NORMATIVE ORDER (spec §4.1):
     *   2a. denominator positivity FIRST — never reach bcmod with a zero
     *       divisor, because DivisionByZeroError is an `Error` that
     *       StrictCanonicalParser's `catch (RuntimeException)` does NOT catch
     *       and would kill the projection worker instead of quarantining.
     *   2b. |adj| <= denomination / 2, computed and compared at scale+1 so
     *       bcdiv truncation cannot reject a legal tie.
     *   2c. total is an exact multiple of the denomination.
     *   3.  static cap on the denomination (checked even when adj == 0), read
     *       from {@see CashRoundingCaps} — the SINGLE source shared with the
     *       policy resolver and the ops command. An unlisted scale is
     *       fail-closed (no sanctioned cap ⇒ no rounding).
     *
     * @param  array<string, mixed>  $payload
     * @param  numeric-string  $total
     * @param  numeric-string  $adjustment
     */
    private function validateCashRoundingBinds(array $payload, string $total, string $adjustment, int $scale): void
    {
        $denomination = $this->asNumericString(
            $payload['cash_rounding_denomination'],
            'cash_rounding_denomination',
        );

        if (bccomp($adjustment, '0', $scale) !== 0) {
            if (bccomp($denomination, '0', $scale) <= 0) {
                throw new RuntimeException(sprintf(
                    'payload_cash_rounding_denomination_not_positive:adjustment=%s:denomination=%s',
                    $adjustment,
                    $denomination,
                ));
            }

            $absAdjustment = bccomp($adjustment, '0', $scale) < 0
                ? bcmul($adjustment, '-1', $scale)
                : $adjustment;

            $half = bcdiv($denomination, '2', $scale + 1);
            if (bccomp($absAdjustment, $half, $scale + 1) > 0) {
                throw new RuntimeException(sprintf(
                    'payload_cash_rounding_adjustment_exceeds_half_denomination:adjustment=%s:half=%s',
                    $adjustment,
                    $half,
                ));
            }

            $remainder = bcmod($total, $denomination, $scale);
            if (bccomp($remainder, '0', $scale) !== 0) {
                throw new RuntimeException(sprintf(
                    'payload_cash_rounding_total_not_multiple:total=%s:denomination=%s:remainder=%s',
                    $total,
                    $denomination,
                    $remainder,
                ));
            }
        }

        if (! CashRoundingCaps::isWithinCap($denomination, $scale)) {
            throw new RuntimeException(sprintf(
                'payload_cash_rounding_denomination_above_cap:denomination=%s:cap=%s',
                $denomination,
                CashRoundingCaps::forScale($scale) ?? 'unlisted_scale:'.$scale,
            ));
        }
    }
}
