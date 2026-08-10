<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Canonical SALE_RECEIPT fiscal-event fixture builder for the two LIVE POS stock
 * writers (`PosCoreReceiptProjection::decrementStockForLines` /
 * `::restockForLines`).
 *
 * Extracted (DPA Wave 3, sub-wave 3A) from the byte-identical private helpers in
 * `PosCoreReceiptProjectionVariantStockTest` and
 * `PosCoreReceiptProjectionRefundStockTest` so the Wave-3 characterisation (T1)
 * and cost-snapshot (T5) suites do not fork a THIRD copy of the 28-key payload.
 * The existing suites are deliberately left untouched — this trait is additive.
 *
 * The consuming test class must expose `$tenantId`, `$companyId`, `$locationId`,
 * `$terminalId` and `$operatorId`.
 */
trait BuildsPosSaleReceiptEvents
{
    /**
     * A REFUND/VOID SALE_RECEIPT referencing `$original`, so
     * `resolveOriginalReceiptId()` resolves the locally-projected original and
     * the event maps to `ReceiptType::Return` (the restock path).
     */
    protected function posRefundReceiptEvent(
        FiscalEvent $original,
        string $productId,
        ?string $variantId,
        string $quantity,
        string $invoiceTypeCode = 'REFUND',
    ): FiscalEvent {
        return $this->posSaleReceiptEvent(
            productId: $productId,
            variantId: $variantId,
            quantity: $quantity,
            invoiceTypeCode: $invoiceTypeCode,
            sequenceNumber: 2,
            receiptUuid: '00000000-0000-4000-8000-000000000002',
            originalReceiptReference: [
                'fiscal_event_id' => $original->id,
                'original_business_date' => $original->business_date->toDateString(),
                'original_receipt_uuid' => '00000000-0000-4000-8000-000000000001',
                'refund_reason' => $invoiceTypeCode === 'VOID' ? 'Operator error — voided' : 'Customer changed mind',
            ],
        );
    }

    /**
     * Persist a verified SALE_RECEIPT `fiscal_events` row for a single product
     * (optionally variant) line.
     *
     * @param  array<string, mixed>|null  $originalReceiptReference
     */
    protected function posSaleReceiptEvent(
        string $productId,
        ?string $variantId,
        string $quantity,
        string $invoiceTypeCode = 'SALE',
        int $sequenceNumber = 1,
        string $receiptUuid = '00000000-0000-4000-8000-000000000001',
        ?array $originalReceiptReference = null,
    ): FiscalEvent {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $previousHash = str_repeat('0', 64);

        $qtyCanonical = str_contains($quantity, '.') ? $quantity : $quantity.'.000';

        $lineItem = [
            'gtin' => null,
            'line_discount_amount' => '0.00',
            'line_discount_reason' => null,
            'line_subtotal' => '10.00',
            'line_vat' => '0.00',
            'name' => 'Wave3 Test Item',
            'non_collected_subtype' => null,
            'product_id' => $productId,
            'quantity' => $qtyCanonical,
            'sku' => 'SKU-W3',
            'tax_category_code' => 'Z',
            'unit_price' => '10.00',
            'variant_id' => $variantId,
            'variant_name' => $variantId !== null ? 'Red / L' : null,
            'variant_sku' => $variantId !== null ? 'SKU-W3-RED-L' : null,
            'vat_rate' => '0.00',
        ];

        $payload = [
            'business_date' => $businessDate->toDateString(),
            'approval_references' => [],
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'consumption_mode' => null,
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'event_time_device' => '2026-05-20T14:30:00.000Z',
            'invoice_type_code' => $invoiceTypeCode,
            'line_items' => [$lineItem],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => $originalReceiptReference,
            'payments' => [
                [
                    'amount' => '10.00',
                    'foreign_currency_amount' => null,
                    'foreign_currency_code' => null,
                    'instrument_serial' => null,
                    'instrument_type' => null,
                    'method_code' => 'CASH',
                ],
            ],
            'receipt_uuid' => $receiptUuid,
            'seller' => [
                'address' => ['city' => 'Paris', 'country_code' => 'FR', 'postal_code' => '75001', 'street' => '1 rue de la Paix'],
                'name' => 'Default Seller S.A.',
                'tax_jurisdiction_country_code' => 'FR',
                'tax_number' => '12345678901234',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => '10.00',
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => '10.00',
            'training_flag' => false,
            'transaction_discount_amount' => '0.00',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [
                [
                    'gross_amount' => '10.00',
                    'net_amount' => '10.00',
                    'rate' => '0.00',
                    'tax_category_code' => 'Z',
                    'vat_amount' => '0.00',
                ],
            ],
            'vat_total' => '0.00',
            'vouchers_redeemed' => [],
        ];

        $canonicalArray = [
            'business_date' => $businessDate->toDateString(),
            'company_id' => $this->companyId,
            'event_time_device' => $eventTime->format('Y-m-d\TH:i:s\Z'),
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 2,
            'operator_id' => $this->operatorId,
            'payload' => $payload,
            'previous_hash' => $previousHash,
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => $sequenceNumber,
            'signature_version' => 'hash-chain-integrity-v1',
            'tenant_id' => $this->tenantId,
            'terminal_id' => $this->terminalId,
        ];

        $canonicalBytes = $this->canonicalEncodePosEvent($canonicalArray);
        $currentHash = hash('sha256', $canonicalBytes);

        $event = FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => 2,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => $eventTime,
            'business_date' => $businessDate,
            'last_server_time_seen' => null,
            'server_received_at' => $eventTime,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => $previousHash,
            'current_hash' => $currentHash,
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ]);

        return $event->refresh();
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function canonicalEncodePosEvent(array $value): string
    {
        $sorted = $this->sortRecursivePosEvent($value);
        $json = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('canonical encode failed');
        }

        return $json;
    }

    private function sortRecursivePosEvent(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($v) => $this->sortRecursivePosEvent($v), $value);
        }
        ksort($value);

        return array_map(fn ($v) => $this->sortRecursivePosEvent($v), $value);
    }
}
