<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs;

/**
 * SALE_RECEIPT payload — canonical 28-key Candidate C-v3 shape (synthesis v5 + Phase 4 approval references).
 *
 * Replaces the Phase 1 v1 10-key shape (currency / discount_total / lines /
 * payment_lines / subtotal / tax_total / total / vat_breakdown / voucher_redemptions /
 * currency_scale). Per owner D4 (synthesis v5 §16 status / §11 Amended A4 closure),
 * the 28-key shape was rewritten in place — there was no production tenant to
 * migrate.
 *
 * This ONE DTO class serves every SALE_RECEIPT `event_version`; the version
 * only changes which keys are REQUIRED, and that is enforced by
 * `FiscalPayloadConstraintValidator`, not here:
 *   - v2 (M4) adds `variant_id`/`variant_name`/`variant_sku` per line item;
 *   - v3 (cash rounding, spec §4.4) adds the two top-level
 *     `cash_rounding_adjustment` / `cash_rounding_denomination` siblings,
 *     surfaced below as nullable properties (NULL == "v1/v2, key absent").
 *
 * Field semantics + invariants are documented in:
 *   - `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md`
 *   - `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md` §3
 *
 * Money fields are NON-NEGATIVE bcformat strings. Refunds are modeled via
 * `invoice_type_code='REFUND'` + non-null `original_receipt_reference`, NOT
 * negative payload amounts. `vat_breakdown` is the partition of `line_items[]`
 * by `(vat_rate, tax_category_code)` — validator enforces (§6.C).
 *
 * `vat_breakdown` cardinality + content is asserted by
 * `FiscalPayloadConstraintValidator::validateSaleReceiptPayload()`; this DTO
 * surface stores the parsed sub-arrays as untyped `list<array<string, mixed>>`.
 * Typed canonical-view DTOs (`SellerDTO`, `BuyerDTO`, `LineItemDTO`, etc.)
 * live in `App\Modules\Fiscal\Domain\DTOs\Canonical\` and are constructed via
 * `CanonicalPayloadReader::forSaleReceipt()`.
 */
final readonly class SaleReceiptPayload
{
    /**
     * @param  array<string, mixed>  $buyer  null OR BuyerBlock per §3
     * @param  list<array<string, mixed>>  $approvalReferences
     * @param  list<array<string, mixed>>  $lineItems
     * @param  array<string, mixed>  $originalReceiptReference  null on plain SALE
     * @param  list<array<string, mixed>>  $payments
     * @param  array<string, mixed>  $seller  REQUIRED — SellerBlock per §3
     * @param  list<array<string, mixed>>  $vatBreakdown
     * @param  list<array<string, mixed>>  $vouchersRedeemed
     */
    public function __construct(
        public array $approvalReferences,
        public string $businessDate,
        public ?array $buyer,
        public string $cashierId,
        public string $cashierName,
        public ?string $consumptionMode,
        public string $currencyCode,
        public int $currencyScale,
        public string $eventTimeDevice,
        public string $invoiceTypeCode,
        public array $lineItems,
        public ?string $lotteryCode,
        public ?string $notes,
        public ?array $originalReceiptReference,
        public array $payments,
        public string $receiptUuid,
        public array $seller,
        public string $shiftId,
        public string $subtotal,
        public ?string $tableId,
        public string $terminalId,
        public string $total,
        public bool $trainingFlag,
        public string $transactionDiscountAmount,
        public ?string $transactionDiscountReason,
        public array $vatBreakdown,
        public string $vatTotal,
        public array $vouchersRedeemed,
        /**
         * v3 signed cash-rounding adjustment (`rounded_total − exact_total`).
         * NULL on v1/v2 payloads — absent means zero, never "unknown".
         */
        public ?string $cashRoundingAdjustment = null,
        /**
         * v3 applied rounding denomination, normalized at currency scale.
         * NULL on v1/v2 payloads.
         */
        public ?string $cashRoundingDenomination = null,
        /**
         * v4 refund/void chain integration (spec §3.3/§17): strict parallel
         * array to `lineItems`, one entry per line, ONLY present on a v4
         * REFUND payload. NULL on v1/v2/v3 payloads — this is the v4
         * discriminator this DTO surface uses (mirrors the `cashRoundingAdjustment
         * !== null` v3 discriminator above): `refundDestination` and the
         * "settlement_allocation key present" fact are only ever emitted
         * alongside a non-null value here.
         *
         * @var list<array<string, mixed>>|null
         */
        public ?array $originalLineReferences = null,
        /**
         * v4 refund destination literal (`'cash'` for launch, spec §3.4).
         * NULL on v1/v2/v3 payloads.
         */
        public ?string $refundDestination = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        // Required nested objects — seller MUST be present; constraint
        // validator enforces inner-object shape afterwards.
        $seller = FiscalPayloadArrayGuards::requireArray($data, 'seller');
        // @phpstan-ignore-next-line argument.type (validator asserts assoc shape; DTO surface stores raw)

        // Required list-shaped containers; validator enforces list-ness +
        // per-row monetary fields. DTO surface stores raw.
        $approvalReferences = FiscalPayloadArrayGuards::requireArray($data, 'approval_references');
        $lineItems = FiscalPayloadArrayGuards::requireArray($data, 'line_items');
        $payments = FiscalPayloadArrayGuards::requireArray($data, 'payments');
        $vatBreakdown = FiscalPayloadArrayGuards::requireArray($data, 'vat_breakdown');
        $vouchersRedeemed = FiscalPayloadArrayGuards::requireArray($data, 'vouchers_redeemed');

        // Optional nested objects — `buyer` + `original_receipt_reference`.
        $buyer = FiscalPayloadArrayGuards::optionalArray($data, 'buyer');
        $originalReceiptReference = FiscalPayloadArrayGuards::optionalArray($data, 'original_receipt_reference');

        return new self(
            // @phpstan-ignore-next-line argument.type
            approvalReferences: $approvalReferences,
            businessDate: FiscalPayloadArrayGuards::requireString($data, 'business_date'),
            // @phpstan-ignore-next-line argument.type
            buyer: $buyer,
            cashierId: FiscalPayloadArrayGuards::requireString($data, 'cashier_id'),
            cashierName: FiscalPayloadArrayGuards::requireString($data, 'cashier_name'),
            consumptionMode: FiscalPayloadArrayGuards::optionalString($data, 'consumption_mode'),
            currencyCode: FiscalPayloadArrayGuards::requireString($data, 'currency_code'),
            currencyScale: FiscalPayloadArrayGuards::requireInt($data, 'currency_scale'),
            eventTimeDevice: FiscalPayloadArrayGuards::requireString($data, 'event_time_device'),
            invoiceTypeCode: FiscalPayloadArrayGuards::requireString($data, 'invoice_type_code'),
            // @phpstan-ignore-next-line argument.type
            lineItems: $lineItems,
            lotteryCode: FiscalPayloadArrayGuards::optionalString($data, 'lottery_code'),
            notes: FiscalPayloadArrayGuards::optionalString($data, 'notes'),
            // @phpstan-ignore-next-line argument.type
            originalReceiptReference: $originalReceiptReference,
            // @phpstan-ignore-next-line argument.type
            payments: $payments,
            receiptUuid: FiscalPayloadArrayGuards::requireString($data, 'receipt_uuid'),
            // @phpstan-ignore-next-line argument.type
            seller: $seller,
            shiftId: FiscalPayloadArrayGuards::requireString($data, 'shift_id'),
            subtotal: FiscalPayloadArrayGuards::requireString($data, 'subtotal'),
            tableId: FiscalPayloadArrayGuards::optionalString($data, 'table_id'),
            terminalId: FiscalPayloadArrayGuards::requireString($data, 'terminal_id'),
            total: FiscalPayloadArrayGuards::requireString($data, 'total'),
            trainingFlag: FiscalPayloadArrayGuards::requireBool($data, 'training_flag'),
            transactionDiscountAmount: FiscalPayloadArrayGuards::requireString($data, 'transaction_discount_amount'),
            transactionDiscountReason: FiscalPayloadArrayGuards::optionalString($data, 'transaction_discount_reason'),
            // @phpstan-ignore-next-line argument.type
            vatBreakdown: $vatBreakdown,
            vatTotal: FiscalPayloadArrayGuards::requireString($data, 'vat_total'),
            // @phpstan-ignore-next-line argument.type
            vouchersRedeemed: $vouchersRedeemed,
            // v3 (cash rounding): present-and-nullable rather than optional —
            // `array_key_exists` distinguishes "v1/v2 payload, key absent"
            // from "v3 payload carrying an explicit null" (the LineItemDTO
            // variant_id precedent). The constraint validator is what forbids
            // an explicit null on v3.
            cashRoundingAdjustment: array_key_exists('cash_rounding_adjustment', $data)
                ? FiscalPayloadArrayGuards::optionalString($data, 'cash_rounding_adjustment')
                : null,
            cashRoundingDenomination: array_key_exists('cash_rounding_denomination', $data)
                ? FiscalPayloadArrayGuards::optionalString($data, 'cash_rounding_denomination')
                : null,
            // v4 (refund/void chain, spec §3.3/§17): present-and-nullable —
            // same array_key_exists discriminator pattern as the v3 fields
            // above. The constraint validator forbids these keys on v1/v2/v3
            // and requires them (non-null) on v4.
            // @phpstan-ignore-next-line argument.type (validator asserts list<object> shape; DTO surface stores raw)
            originalLineReferences: array_key_exists('original_line_references', $data)
                ? FiscalPayloadArrayGuards::requireArray($data, 'original_line_references')
                : null,
            refundDestination: FiscalPayloadArrayGuards::optionalString($data, 'refund_destination'),
        );
    }

    /**
     * Version-faithful array round-trip.
     *
     * The two v3 cash-rounding keys are emitted ONLY when the DTO actually
     * carries them, so a v1/v2 payload round-trips to its exact 28-key shape
     * (absent, not null) and a v3 payload round-trips to all 30 keys in
     * lexicographic position. Emitting them unconditionally would inject
     * `payload_extra_field` keys into every historical receipt.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [
            'approval_references' => $this->approvalReferences,
            'business_date' => $this->businessDate,
            'buyer' => $this->buyer,
            'cash_rounding_adjustment' => $this->cashRoundingAdjustment,
            'cash_rounding_denomination' => $this->cashRoundingDenomination,
            'cashier_id' => $this->cashierId,
            'cashier_name' => $this->cashierName,
            'consumption_mode' => $this->consumptionMode,
            'currency_code' => $this->currencyCode,
            'currency_scale' => $this->currencyScale,
            'event_time_device' => $this->eventTimeDevice,
            'invoice_type_code' => $this->invoiceTypeCode,
            'line_items' => $this->lineItems,
            'lottery_code' => $this->lotteryCode,
            'notes' => $this->notes,
            'original_line_references' => $this->originalLineReferences,
            'original_receipt_reference' => $this->originalReceiptReference,
            'payments' => $this->payments,
            'receipt_uuid' => $this->receiptUuid,
            'refund_destination' => $this->refundDestination,
            'seller' => $this->seller,
            'settlement_allocation' => null,
            'shift_id' => $this->shiftId,
            'subtotal' => $this->subtotal,
            'table_id' => $this->tableId,
            'terminal_id' => $this->terminalId,
            'total' => $this->total,
            'training_flag' => $this->trainingFlag,
            'transaction_discount_amount' => $this->transactionDiscountAmount,
            'transaction_discount_reason' => $this->transactionDiscountReason,
            'vat_breakdown' => $this->vatBreakdown,
            'vat_total' => $this->vatTotal,
            'vouchers_redeemed' => $this->vouchersRedeemed,
        ];

        if ($this->cashRoundingAdjustment === null && $this->cashRoundingDenomination === null) {
            unset($array['cash_rounding_adjustment'], $array['cash_rounding_denomination']);
        }

        // v4 (refund/void chain, spec §3.3/§3.4): the three keys travel
        // together — `original_line_references` is this DTO's v4 marker
        // (never non-null without `refund_destination`/`settlement_allocation`
        // also being present on a correctly-constructed v4 payload).
        if ($this->originalLineReferences === null) {
            unset($array['original_line_references'], $array['refund_destination'], $array['settlement_allocation']);
        }

        return $array;
    }
}
