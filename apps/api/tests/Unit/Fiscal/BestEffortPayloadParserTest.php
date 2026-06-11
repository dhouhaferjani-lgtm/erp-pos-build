<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Application\Services\BestEffortPayloadParser;
use App\Modules\Fiscal\Application\Services\FiscalEventPayloadRegistry;
use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
use App\Modules\Fiscal\Application\Services\StrictCanonicalParser;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use Tests\TestCase;

final class BestEffortPayloadParserTest extends TestCase
{
    public function test_returns_payload_without_defects_when_strict_parse_succeeds(): void
    {
        $result = $this->parser()->parse(
            $this->envelope('SALE_RECEIPT', $this->saleReceiptPayload()),
            FiscalEventType::SALE_RECEIPT,
        );

        $this->assertSame([], $result->defects);
        $this->assertSame('SALE', $result->parsed['invoice_type_code']);
        $this->assertCount(28, $result->parsed);
    }

    public function test_prefills_present_contract_keys_and_reports_invalid_fields(): void
    {
        $payload = $this->saleReceiptPayload();
        $payload['seller']['tax_number'] = 'TN-INVALID';
        $payload['unexpected_extra'] = 'rogue';
        unset($payload['buyer']);

        $result = $this->parser()->parse(
            $this->envelope('SALE_RECEIPT', $payload),
            FiscalEventType::SALE_RECEIPT,
        );

        $this->assertArrayHasKey('seller', $result->parsed);
        $this->assertArrayHasKey('total', $result->parsed);
        $this->assertArrayNotHasKey('unexpected_extra', $result->parsed);
        $this->assertArrayNotHasKey('buyer', $result->parsed);

        $defects = $result->defectsToArray();
        $this->assertContains('payload.buyer', array_column($defects, 'path'));
        $this->assertContains('payload.unexpected_extra', array_column($defects, 'path'));
        $this->assertContains('payload.seller', array_column($defects, 'path'));
        $this->assertContains('payload_missing_required', array_column($defects, 'code'));
        $this->assertContains('payload_extra_field', array_column($defects, 'code'));
    }

    public function test_invalid_json_returns_canonical_bytes_defect_without_silent_success(): void
    {
        $result = $this->parser()->parse(
            '{"event_type":"SALE_RECEIPT","payload":',
            FiscalEventType::SALE_RECEIPT,
        );

        $this->assertSame([], $result->parsed);
        $defects = $result->defectsToArray();
        $this->assertNotSame([], $defects);
        $this->assertContains('canonical_bytes', array_column($defects, 'path'));
    }

    private function parser(): BestEffortPayloadParser
    {
        $registry = new FiscalEventPayloadRegistry;
        $validator = new FiscalPayloadConstraintValidator;

        return new BestEffortPayloadParser(
            new StrictCanonicalParser($registry, $validator),
            $registry,
            $validator,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function test_v2_sale_receipt_lines_are_validated_with_the_v2_rules(): void
    {
        // M4 Codex P2-1: the repair path must validate against the
        // envelope's OWN event_version — a v2 payload mis-checked against
        // the v1 line-item allowlist would report bogus extra-key defects.
        $payload = $this->saleReceiptPayload();
        /** @var list<array<string, mixed>> $lines */
        $lines = $payload['line_items'];
        $lines[0]['variant_id'] = '44444444-4444-4444-8444-444444444444';
        $lines[0]['variant_name'] = 'Default item — Red / L';
        $lines[0]['variant_sku'] = 'SKU-DEFAULT-RED-L';
        ksort($lines[0]);
        $payload['line_items'] = $lines;

        $result = $this->parser()->parse(
            $this->envelope('SALE_RECEIPT', $payload, eventVersion: 2),
            FiscalEventType::SALE_RECEIPT,
        );

        $this->assertSame([], $result->defects);
    }

    private function envelope(string $eventType, array $payload, int $eventVersion = 1): string
    {
        $fields = [
            'business_date' => '2026-05-20',
            'chain_context' => 'operational',
            'company_id' => '00000000-0000-4000-8000-000000000002',
            'event_time_device' => '2026-05-20T14:30:00Z',
            'event_type' => $eventType,
            'event_version' => $eventVersion,
            'operator_id' => '00000000-0000-4000-8000-000000000003',
            'payload' => $payload,
            'previous_hash' => str_repeat('0', 64),
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'tenant_id' => '00000000-0000-4000-8000-000000000001',
            'terminal_id' => '00000000-0000-4000-8000-000000000004',
        ];
        ksort($fields);

        return (string) json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function saleReceiptPayload(): array
    {
        return [
            'business_date' => '2026-05-20',
            'approval_references' => [],
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'consumption_mode' => null,
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'event_time_device' => '2026-05-20T14:30:00.000Z',
            'invoice_type_code' => 'SALE',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.000',
                'line_discount_reason' => null,
                'line_subtotal' => '5.000',
                'line_vat' => '0.350',
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.000',
                'sku' => 'SKU-DEFAULT',
                'tax_category_code' => '',
                'unit_price' => '5.000',
                'vat_rate' => '7.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => [[
                'amount' => '5.350',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
            'receipt_uuid' => '00000000-0000-4000-8000-000000000001',
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 rue Test'],
                'name' => 'Default Seller',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '1234567AM000',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => '5.000',
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => '5.350',
            'training_flag' => false,
            'transaction_discount_amount' => '0.000',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => '5.350',
                'net_amount' => '5.000',
                'rate' => '7.00',
                'tax_category_code' => '',
                'vat_amount' => '0.350',
            ]],
            'vat_total' => '0.350',
            'vouchers_redeemed' => [],
        ];
    }
}
