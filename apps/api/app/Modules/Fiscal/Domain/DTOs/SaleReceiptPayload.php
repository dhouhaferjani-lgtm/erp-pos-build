<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs;

/**
 * SALE_RECEIPT payload — canonical 27-key Candidate C-v3 shape (synthesis v5).
 *
 * Replaces the Phase 1 v1 10-key shape (currency / discount_total / lines /
 * payment_lines / subtotal / tax_total / total / vat_breakdown / voucher_redemptions /
 * currency_scale). Per owner D4 (synthesis v5 §16 status / §11 Amended A4 closure),
 * event_version stays at 1 — there is no production tenant to migrate, so we
 * rewrite v1 in place. `FiscalEventPayloadRegistry::PHASE_1_MAP` mapping for
 * SALE_RECEIPT remains `[SaleReceiptPayload::class, 1]`.
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
     * @param  list<array<string, mixed>>  $lineItems
     * @param  array<string, mixed>  $originalReceiptReference  null on plain SALE
     * @param  list<array<string, mixed>>  $payments
     * @param  array<string, mixed>  $seller  REQUIRED — SellerBlock per §3
     * @param  list<array<string, mixed>>  $vatBreakdown
     * @param  list<array<string, mixed>>  $vouchersRedeemed
     */
    public function __construct(
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
        $lineItems = FiscalPayloadArrayGuards::requireArray($data, 'line_items');
        $payments = FiscalPayloadArrayGuards::requireArray($data, 'payments');
        $vatBreakdown = FiscalPayloadArrayGuards::requireArray($data, 'vat_breakdown');
        $vouchersRedeemed = FiscalPayloadArrayGuards::requireArray($data, 'vouchers_redeemed');

        // Optional nested objects — `buyer` + `original_receipt_reference`.
        $buyer = FiscalPayloadArrayGuards::optionalArray($data, 'buyer');
        $originalReceiptReference = FiscalPayloadArrayGuards::optionalArray($data, 'original_receipt_reference');

        return new self(
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
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'business_date' => $this->businessDate,
            'buyer' => $this->buyer,
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
            'original_receipt_reference' => $this->originalReceiptReference,
            'payments' => $this->payments,
            'receipt_uuid' => $this->receiptUuid,
            'seller' => $this->seller,
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
    }
}
