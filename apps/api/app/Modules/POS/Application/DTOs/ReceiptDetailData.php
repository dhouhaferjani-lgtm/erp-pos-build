<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\Product\Domain\Product;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use App\Shared\Domain\QuantityScale;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ReceiptDetailData extends Data
{
    /**
     * @param  list<ReceiptDetailLineData>  $lines
     * @param  list<ReceiptDetailVatData>  $vat_details
     * @param  list<ReceiptDetailPaymentData>  $payments
     * @param  list<ReceiptReturnLineageData>  $return_receipts
     * @param  list<array<string, mixed>>  $refund_policy_alerts
     */
    public function __construct(
        public readonly string $id,
        public readonly string $receipt_number,
        public readonly string $posted_at,
        public readonly string $invoice_type_code,
        public readonly bool $training_flag,
        public readonly string $receipt_type,
        public readonly string $fiscal_status,
        public readonly string $location_id,
        public readonly ?string $location_name,
        public readonly string $terminal_id,
        public readonly string $terminal_code,
        public readonly string $cashier_id,
        public readonly string $cashier_name,
        public readonly string $currency,
        public readonly string $subtotal,
        public readonly string $tax_amount,
        public readonly string $discount_amount,
        public readonly ?string $cash_rounding_adjustment,
        public readonly ?string $cash_rounding_denomination,
        public readonly ?string $change_due,
        public readonly string $total,
        public readonly ?string $notes,
        public readonly bool $is_voided,
        public readonly ?string $voided_at,
        public readonly string $fiscal_hash,
        public readonly ?string $previous_hash,
        public readonly int $chain_sequence,
        public readonly int $receipt_year,
        public readonly ?string $fiscal_event_id,
        public readonly ?string $synced_at,
        public readonly ?string $sync_error,
        public readonly array $refund_policy_alerts,
        public readonly array $lines,
        public readonly array $vat_details,
        public readonly array $payments,
        public readonly array $return_receipts,
        public readonly ?string $original_receipt_id,
        public readonly ?ReceiptOriginalLineageData $original_receipt,
    ) {}

    /**
     * @param  array<string, numeric-string>  $returnedQuantities
     * @param  list<ReceiptReturnLineageData>  $returnReceipts
     */
    public static function fromReceipt(
        Receipt $receipt,
        CurrencyScaleResolverInterface $currencyScaleResolver,
        array $returnedQuantities,
        array $returnReceipts = [],
        ?ReceiptOriginalLineageData $originalReceipt = null,
    ): self {
        $moneyScale = $currencyScaleResolver->getScale($receipt->currency);
        $money = static fn (string $value): string => CurrencyScale::bcformatStrict($value, $moneyScale);
        $nullableMoney = static fn (?string $value): ?string => $value === null ? null : CurrencyScale::bcformatStrict($value, $moneyScale);
        $isLegacyReturn = $receipt->receipt_type->value === 'return'
            && $receipt->fiscal_event_id === null
            && $receipt->invoice_type_code === 'SALE';
        $formattedTotal = $money((string) $receipt->total);
        $reportingTotal = $isLegacyReturn && str_starts_with($formattedTotal, '-')
            ? substr($formattedTotal, 1)
            : $formattedTotal;

        $lines = $receipt->lines->map(function (ReceiptLine $line) use ($money, $returnedQuantities): ReceiptDetailLineData {
            $product = $line->relationLoaded('product') ? $line->getRelation('product') : null;
            $unit = $product instanceof Product && $product->relationLoaded('unitOfMeasure')
                ? $product->getRelation('unitOfMeasure')
                : null;
            $quantityDecimals = $unit instanceof Unit ? $unit->decimal_places : QuantityScale::SCALE;
            $roundingMethod = $unit instanceof Unit ? $unit->rounding_method->value : null;

            return new ReceiptDetailLineData(
                id: $line->id,
                line_number: $line->line_number,
                product_id: $line->product_id,
                product_name: $line->product_name,
                product_code: $line->product_code,
                quantity: QuantityScale::formatForUnit((string) $line->quantity, $quantityDecimals, $roundingMethod),
                quantity_decimals: $quantityDecimals,
                unit_price: $money((string) $line->unit_price),
                discount_amount: $money((string) $line->discount_amount),
                vat_rate: (string) $line->tax_rate,
                vat_amount: $money((string) $line->tax_amount),
                line_total: $money((string) $line->line_total),
                returned_quantity: QuantityScale::formatForUnit(
                    $returnedQuantities[$line->id] ?? '0',
                    $quantityDecimals,
                    $roundingMethod,
                ),
            );
        })->values()->all();

        $vatDetails = $receipt->vatDetails->map(fn (ReceiptVatDetail $detail): ReceiptDetailVatData => new ReceiptDetailVatData(
            tax_rate: (string) $detail->tax_rate,
            net_amount: $money((string) $detail->net_amount),
            vat_amount: $money((string) $detail->vat_amount),
            gross_amount: $money((string) $detail->gross_amount),
        ))->values()->all();

        $payments = $receipt->payments->map(fn (ReceiptPayment $payment): ReceiptDetailPaymentData => new ReceiptDetailPaymentData(
            id: $payment->id,
            payment_method: $payment->payment_type,
            amount: $money((string) $payment->amount),
            card_last_four: $payment->card_last_four,
            instrument_serial: $payment->instrument_serial,
            transaction_reference: $payment->transaction_reference,
            authorization_code: $payment->authorization_code,
        ))->values()->all();

        /** @var list<array<string, mixed>> $refundPolicyAlerts */
        $refundPolicyAlerts = is_array($receipt->refund_policy_alerts) ? $receipt->refund_policy_alerts : [];

        return new self(
            id: $receipt->id,
            receipt_number: $receipt->receipt_number,
            posted_at: $receipt->posted_at->toIso8601String(),
            invoice_type_code: $isLegacyReturn ? 'REFUND' : $receipt->invoice_type_code,
            training_flag: $receipt->training_flag,
            receipt_type: $receipt->receipt_type->value,
            fiscal_status: $receipt->fiscal_status->value,
            location_id: $receipt->location_id,
            location_name: $receipt->location->name,
            terminal_id: $receipt->terminal_id,
            terminal_code: $receipt->terminal->code,
            cashier_id: $receipt->cashier_id,
            cashier_name: $receipt->cashier_name,
            currency: $receipt->currency,
            subtotal: $money((string) $receipt->subtotal),
            tax_amount: $money((string) $receipt->tax_amount),
            discount_amount: $money((string) $receipt->discount_amount),
            cash_rounding_adjustment: $nullableMoney($receipt->cash_rounding_adjustment),
            cash_rounding_denomination: $nullableMoney($receipt->cash_rounding_denomination),
            change_due: $nullableMoney($receipt->change_due),
            total: $reportingTotal,
            notes: $receipt->notes,
            is_voided: $receipt->is_voided,
            voided_at: $receipt->voided_at?->toISOString(),
            fiscal_hash: $receipt->fiscal_hash,
            previous_hash: $receipt->previous_hash,
            chain_sequence: $receipt->chain_sequence,
            receipt_year: $receipt->receipt_year,
            fiscal_event_id: $receipt->fiscal_event_id,
            synced_at: $receipt->synced_at?->toISOString(),
            sync_error: $receipt->sync_error,
            refund_policy_alerts: $refundPolicyAlerts,
            lines: array_values($lines),
            vat_details: array_values($vatDetails),
            payments: array_values($payments),
            return_receipts: $returnReceipts,
            original_receipt_id: $receipt->original_receipt_id,
            original_receipt: $originalReceipt,
        );
    }
}
