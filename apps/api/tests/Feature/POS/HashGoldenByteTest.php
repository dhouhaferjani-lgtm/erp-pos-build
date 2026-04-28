<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\POS\Domain\Services\ZReportHashService;
use Tests\TestCase;

/**
 * Golden byte round-trip test for ZReportHashService normalization.
 *
 * Pins the SHA-256 hash of a canonical schema-2 Z-report payload.
 * The TypeScript zReportHashService.test.ts asserts the SAME golden byte,
 * proving PHP ↔ TypeScript hash parity.
 *
 * Golden bytes pinned 2026-04-25 — if PHP normalization changes, update
 * TS normalizeForHash in apps/pos/src/lib/fiscal/zReportHashService.ts too.
 */
final class HashGoldenByteTest extends TestCase
{
    /**
     * Canonical schema-2 payload exercising all normalized fields:
     *   - top-level monetary strings (opening_cash, expected_cash, gross_sales, net_sales, tax_amount)
     *   - payment_methods[].total_amount
     *   - cash_counts[].expected_amount / actual_amount / variance_amount
     *   - variance_summary.aggregate_amount
     *   - tolerance_summary.totalAmount
     *   - schema_version as integer
     *
     * @return array<string, mixed>
     */
    private function canonicalPayload(): array
    {
        return [
            'schema_version' => 2,
            'opening_cash' => '10.00',
            'expected_cash' => '820.00',
            'gross_sales' => '1432.50',
            'net_sales' => '1203.78',
            'tax_amount' => '228.72',
            'sales_count' => 3,
            'refunds_count' => 0,
            'refunds_amount' => '0.00',
            'voided_count' => 0,
            'vat_breakdown' => [
                ['tax_rate' => 20.0, 'net_amount' => '1003.78', 'vat_amount' => '200.76', 'gross_amount' => '1204.54'],
                ['tax_rate' => 10.0, 'net_amount' => '200.00', 'vat_amount' => '20.00', 'gross_amount' => '220.00'],
            ],
            'payment_methods' => [
                ['payment_type' => 'CASH', 'total_amount' => '820.00', 'transaction_count' => 2],
                ['payment_type' => 'CARD', 'total_amount' => '612.50', 'transaction_count' => 1],
            ],
            'cash_counts' => [
                [
                    'payment_method_id' => '11111111-1111-1111-1111-111111111111',
                    'currency_code' => 'EUR',
                    'expected_amount' => '820.00',
                    'actual_amount' => '820.00',
                    'variance_amount' => '0.00',
                    'variance_direction' => 'balanced',
                    'transaction_count' => 2,
                ],
            ],
            'variance_summary' => [
                'aggregate_amount' => '0.00',
                'aggregate_direction' => 'balanced',
                'severity' => 'none',
                'currency_code' => 'EUR',
            ],
            'tolerance_summary' => [
                'totalAmount' => '0.000',
                'currencyCode' => 'EUR',
                'writeoffCount' => 0,
            ],
            'shift_fields' => null,
        ];
    }

    public function test_normalize_for_hash_produces_scale3_monetary_strings(): void
    {
        $service = new ZReportHashService;
        $normalized = $service->normalizeForHash($this->canonicalPayload());

        $this->assertSame('10.000', $normalized['opening_cash']);
        $this->assertSame('820.000', $normalized['expected_cash']);
        $this->assertSame('1432.500', $normalized['gross_sales']);
        $this->assertSame('1203.780', $normalized['net_sales']);
        $this->assertSame('228.720', $normalized['tax_amount']);
        $this->assertSame('820.000', $normalized['payment_methods'][0]['total_amount']);
        $this->assertSame('612.500', $normalized['payment_methods'][1]['total_amount']);
        $this->assertSame('820.000', $normalized['cash_counts'][0]['expected_amount']);
        $this->assertSame('820.000', $normalized['cash_counts'][0]['actual_amount']);
        $this->assertSame('0.000', $normalized['cash_counts'][0]['variance_amount']);
        $this->assertSame('0.000', $normalized['variance_summary']['aggregate_amount']);
        $this->assertSame('0.000', $normalized['tolerance_summary']['totalAmount']);
    }

    public function test_normalize_for_hash_skips_v1_payloads(): void
    {
        $service = new ZReportHashService;
        $v1Payload = ['opening_cash' => '10.00', 'gross_sales' => '100.00'];
        $normalized = $service->normalizeForHash($v1Payload);

        // v1 payloads must pass through unchanged
        $this->assertSame($v1Payload, $normalized);
    }

    public function test_golden_byte_hash_matches_canonical_payload(): void
    {
        // Golden bytes pinned 2026-04-25 — if PHP normalization changes, update
        // TS normalizeForHash in apps/pos/src/lib/fiscal/zReportHashService.ts too.
        // The TypeScript test in apps/pos/src/lib/fiscal/__tests__/zReportHashService.test.ts
        // asserts the SAME golden byte value.
        $expectedGoldenHash = '0cd3b69874b40888b7f6c4132afb8d7cc26a5b8dd45568efba5a8f941ccf1679';

        $service = new ZReportHashService;
        $normalized = $service->normalizeForHash($this->canonicalPayload());

        $reportDataJson = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertNotFalse($reportDataJson, 'json_encode must not fail');

        $zNumber = 1;
        $terminalId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $generatedAt = '2026-04-25T10:00:00+00:00';

        $serialized = sprintf('%d|%s|%s|%s', $zNumber, $terminalId, $generatedAt, $reportDataJson);
        $payload = 'GENESIS|'.$serialized;

        $actualHash = hash('sha256', $payload);

        $this->assertSame($expectedGoldenHash, $actualHash);
    }
}
