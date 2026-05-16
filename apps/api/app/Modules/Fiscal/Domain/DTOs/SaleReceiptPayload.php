<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs;

/**
 * SALE_RECEIPT payload.
 *
 * Carries the receipt business document fields as serialized into
 * `canonical_bytes` by the device. The shape mirrors Phase 1 spec v7 §4
 * (canonical serialization contract) — sorted keys, integer-only numbers,
 * decimal monetary values as `CurrencyScale::bcformat()` strings.
 *
 * Sub-array shapes are documented here but kept as plain `array`:
 * - `lines`: each item is `{product_id, quantity, unit_price, line_total, vat_rate, ...}`
 * - `vat_breakdown`: each item is `{rate, base, amount}`
 * - `payment_lines`: each item is `{payment_method_id, amount, tendered, change}`
 * - `voucher_redemptions`: each item is `{voucher_id, code, amount}`
 *
 * Refinement of the sub-array types is deferred to a future task once the
 * StrictCanonicalParser (Task 16) lands and the projection callers
 * (Tasks 21-22) need typed access.
 */
final readonly class SaleReceiptPayload
{
    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $vatBreakdown
     * @param  list<array<string, mixed>>  $paymentLines
     * @param  list<array<string, mixed>>  $voucherRedemptions
     */
    public function __construct(
        public string $currency,
        public int $currencyScale,
        public array $lines,
        public string $subtotal,
        public string $discountTotal,
        public string $taxTotal,
        public string $total,
        public array $vatBreakdown,
        public array $paymentLines,
        public array $voucherRedemptions,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            currency: (string) $data['currency'],
            currencyScale: (int) $data['currency_scale'],
            lines: $data['lines'],
            subtotal: (string) $data['subtotal'],
            discountTotal: (string) $data['discount_total'],
            taxTotal: (string) $data['tax_total'],
            total: (string) $data['total'],
            vatBreakdown: $data['vat_breakdown'],
            paymentLines: $data['payment_lines'],
            voucherRedemptions: $data['voucher_redemptions'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'currency_scale' => $this->currencyScale,
            'lines' => $this->lines,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discountTotal,
            'tax_total' => $this->taxTotal,
            'total' => $this->total,
            'vat_breakdown' => $this->vatBreakdown,
            'payment_lines' => $this->paymentLines,
            'voucher_redemptions' => $this->voucherRedemptions,
        ];
    }
}
