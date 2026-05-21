<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
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
 * 27-key Candidate C-v3 canonical contract (synthesis v5 §3 + §6).
 * The 10-key v1 shape is REJECTED. Pass 2A.PHP.2 migrates the
 * consumer-side projection + Nf525 sub-method bifurcation.
 *
 * **Per-event clauses (Phase 1):**
 *   - SALE_RECEIPT — 27-key nested shape per Candidate C-v3 §3:
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
 *     * Tax-number universal pattern: `^[A-Za-z0-9 \-/.]{4,40}$` + non-empty
 *       + no control chars + trimmed. Per-country strict regex DEFERRED.
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
     * Note: `product_id`, `buyer.customer_id`, `buyer.contact_id`,
     * `table_id` are documented as opaque `string`, NOT UUIDs
     * (synthesis v3 lines 61, 93, 116, 117) — they remain non-empty-string
     * checks elsewhere.
     */
    private const LOWER_HEX_UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    /** ISO 4217 alpha-3 currency code. */
    private const ISO_4217 = '/^[A-Z]{3}$/D';

    /**
     * Universal tax-number regex (synthesis v5 §7 — per-country strict
     * regex deferred to Phase 1.5).
     *
     * Length 4-40, characters [A-Za-z0-9 \-/.]. The validator additionally
     * rejects empty / control-char / untrimmed values.
     */
    private const TAX_NUMBER_UNIVERSAL = '/^[A-Za-z0-9 \-\/.]{4,40}$/D';

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

    private const ACCOUNT_PAYMENT_STALENESS_REASONS = ['never_synced', 'older_than_threshold', 'server_conflict_pending'];

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
     * SALE_RECEIPT: 27-key sorted-lex Candidate C-v3 list (synthesis v5 §3).
     *
     * @var array<value-of<FiscalEventType>, list<string>>
     */
    public const PAYLOAD_KEYS = [
        'SALE_RECEIPT' => [
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
    ];

    /**
     * Validate the payload's key set against the per-event expected keys.
     * Rejects both missing-required and extras. Returns a prefix-tagged
     * failure reason on violation; null when the key set is clean.
     *
     * @param  array<string, mixed>  $payload
     */
    public function validatePayloadKeySet(FiscalEventType $type, array $payload): ?string
    {
        $expected = self::PAYLOAD_KEYS[$type->value] ?? null;
        if ($expected === null) {
            return 'event_type_unimplemented:'.$type->value;
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
    public function validatePerEventConstraints(FiscalEventType $type, array $payload): void
    {
        match ($type) {
            FiscalEventType::SALE_RECEIPT => $this->validateSaleReceiptPayload($payload),
            FiscalEventType::CHAIN_BREAK_DETECTED => $this->validateChainBreakDetectedPayload($payload),
            FiscalEventType::CHAIN_RESTART => $this->validateChainRestartPayload($payload),
            FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT => $this->validateTerminalRegistrySnapshotPayload($payload),
            FiscalEventType::ACCOUNT_PAYMENT => $this->validateAccountPaymentPayload($payload),
            default => throw new LogicException(
                'FiscalPayloadConstraintValidator missing per-event clause for FiscalEventType::'.$type->name
            ),
        };
    }

    /**
     * SALE_RECEIPT — 27-key canonical Candidate C-v3 validator (synthesis v5).
     *
     * @param  array<string, mixed>  $payload
     */
    private function validateSaleReceiptPayload(array $payload): void
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

        // ---- 5. total arithmetic cross-check (§6.D) ----
        $subtotalN = $this->asNumericString($payload['subtotal'], 'subtotal');
        $vatTotalN = $this->asNumericString($payload['vat_total'], 'vat_total');
        $totalN = $this->asNumericString($payload['total'], 'total');
        $lhs = bcadd($subtotalN, $vatTotalN, $scale);
        $rhs = bcadd($totalN, $discountAmount, $scale);
        if (bccomp($lhs, $rhs, $scale) !== 0) {
            throw new RuntimeException(
                'payload_total_arithmetic_mismatch:lhs='.$lhs.':rhs='.$rhs
            );
        }

        // ---- 6. nested objects — seller (required) + buyer (nullable) +
        // ----    original_receipt_reference (nullable per invoice_type) ----
        $this->validateSeller($payload);
        $this->validateBuyer($payload);
        $this->validateOriginalReceiptReference($payload);

        // ---- 7. list containers — line_items, payments, vat_breakdown,
        // ----    vouchers_redeemed. List-ness checked before per-row validation. ----
        $lineItems = $this->requireList($payload, 'line_items');
        if (count($lineItems) === 0) {
            throw new RuntimeException('payload_line_items_empty:line_items must have >= 1 row');
        }
        foreach ($lineItems as $index => $row) {
            $this->validateLineItem($index, $row, $moneyRegex, $scale);
        }

        $payments = $this->requireList($payload, 'payments');
        if (count($payments) === 0) {
            throw new RuntimeException('payload_payments_empty:payments must have >= 1 row');
        }
        foreach ($payments as $index => $row) {
            $this->validatePayment($index, $row, $moneyRegex, $scale);
        }

        $vatBreakdown = $this->requireList($payload, 'vat_breakdown');
        if (count($vatBreakdown) === 0) {
            throw new RuntimeException('payload_vat_breakdown_empty:vat_breakdown must have >= 1 row');
        }
        foreach ($vatBreakdown as $index => $row) {
            $this->validateVatBreakdownRow($index, $row, $moneyRegex, $scale);
        }

        $vouchers = $this->requireList($payload, 'vouchers_redeemed');
        foreach ($vouchers as $index => $row) {
            $this->validateVoucherRedemption($index, $row, $moneyRegex, $scale);
        }

        // ---- 8. VAT partition algorithm (§6.C) — set equality + per-group
        // ----    amount equality via BCMath at currency_scale. ----
        // @phpstan-ignore-next-line argument.type — validated as list above
        $this->validateVatPartition($lineItems, $vatBreakdown, $scale);
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

        $this->validateSeller($payload);
        $this->validateAccountPaymentCustomer($payload['customer'] ?? null);
        $this->validateAccountPaymentPayment($payload['payment'] ?? null, $moneyRegex, $scale, (bool) $payload['training_flag']);
        $this->validateAccountPaymentBalanceSnapshot($payload['local_balance_snapshot'] ?? null, $moneyRegex, $scale);
        $this->validateAccountPaymentStaleness($payload['staleness'] ?? null);
        $this->validateAccountPaymentReferences($payload['references'] ?? null);
        $this->validateNullableAssoc($payload['regime_extensions'] ?? null, 'regime_extensions');
    }

    private function validateAccountPaymentCustomer(mixed $customer): void
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
        if (($row['tax_number'] ?? null) !== null) {
            $this->assertTaxNumber($row['tax_number'], 'customer.tax_number');
        }
        $this->validateAddress($row['address'] ?? null, 'customer.address', required: false);
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
        $this->assertOptionalNullableString($row, 'server_customer_alias_id', 'references.server_customer_alias_id');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateSeller(array $payload): void
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

        $this->assertTaxNumber($seller['tax_number'] ?? null, 'seller.tax_number');

        $this->validateAddress($seller['address'] ?? null, 'seller.address', required: true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateBuyer(array $payload): void
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

        foreach (['codice_fiscale', 'contact_id', 'customer_id', 'name'] as $field) {
            $value = $buyer[$field] ?? null;
            if ($value !== null && (! is_string($value) || $value === '')) {
                throw new RuntimeException('payload_buyer_'.$field.'_invalid:must be non-empty string or null; got '.var_export($value, true));
            }
        }

        if (($buyer['tax_number'] ?? null) !== null) {
            $this->assertTaxNumber($buyer['tax_number'], 'buyer.tax_number');
        }

        $this->validateAddress($buyer['address'] ?? null, 'buyer.address', required: false);
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
     * Validate one `line_items[i]` row.
     */
    private function validateLineItem(int|string $index, mixed $row, string $moneyRegex, int $scale): void
    {
        if (! is_array($row) || (count($row) > 0 && array_is_list($row))) {
            throw new RuntimeException("payload_line_item_invalid:line_items[{$index}] must be object; got ".get_debug_type($row));
        }
        /** @var array<string, mixed> $row */
        $expected = [
            'gtin', 'line_discount_amount', 'line_discount_reason', 'line_subtotal',
            'line_vat', 'name', 'non_collected_subtype', 'product_id', 'quantity',
            'sku', 'tax_category_code', 'unit_price', 'vat_rate',
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
     * Validate one `vat_breakdown[i]` row (structural; partition equality
     * checked separately).
     */
    private function validateVatBreakdownRow(int|string $index, mixed $row, string $moneyRegex, int $scale): void
    {
        if (! is_array($row) || (count($row) > 0 && array_is_list($row))) {
            throw new RuntimeException("payload_vat_breakdown_invalid:vat_breakdown[{$index}] must be object; got ".get_debug_type($row));
        }
        /** @var array<string, mixed> $row */
        $expected = ['gross_amount', 'net_amount', 'rate', 'tax_category_code', 'vat_amount'];
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
     */
    private function validateVatPartition(array $lineItems, array $vatBreakdown, int $scale): void
    {
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

        // Per-group amount equality.
        foreach ($groups as $key => $g) {
            $b = $breakdownByKey[$key];
            $bNet = $this->asNumericString($b['net_amount'], 'vat_breakdown.net_amount');
            $bVat = $this->asNumericString($b['vat_amount'], 'vat_breakdown.vat_amount');
            $bGross = $this->asNumericString($b['gross_amount'], 'vat_breakdown.gross_amount');
            $expectedGross = bcadd($g['sum_net'], $g['sum_vat'], $scale);
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

    /**
     * Universal tax_number validator (synthesis v5 §7) — per-country
     * strict regex DEFERRED to Phase 1.5.
     */
    private function assertTaxNumber(mixed $value, string $path): void
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
}
