<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\POS\Domain\Services\ZReportHashService;
use Tests\TestCase;

final class HashInputScale4EquivalenceTest extends TestCase
{
    private ZReportHashService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(ZReportHashService::class);
    }

    public function test_hash_input_normalizes_all_monetary_fields_to_scale_3_for_schema_v2(): void
    {
        $reportData = [
            'schema_version' => 2,
            'opening_cash' => '10.00',
            'cash_counts' => [[
                'payment_method_id' => 'pm-1',
                'currency_code' => 'EUR',
                'expected_amount' => '830.00',
                'actual_amount' => '830.00',
                'variance_amount' => '0.00',
            ]],
        ];

        $normalized = $this->service->normalizeForHash($reportData);

        $this->assertSame('10.000', $normalized['opening_cash']);
        $this->assertSame('830.000', $normalized['cash_counts'][0]['expected_amount']);
        $this->assertSame('830.000', $normalized['cash_counts'][0]['actual_amount']);
        $this->assertSame('0.000', $normalized['cash_counts'][0]['variance_amount']);
    }

    public function test_hash_input_leaves_v1_reports_at_original_scale(): void
    {
        $reportData = ['opening_cash' => '10.000'];
        $normalized = $this->service->normalizeForHash($reportData);

        $this->assertSame('10.000', $normalized['opening_cash']);
    }

    public function test_scale_drift_within_same_currency_normalizes_to_equal_hash(): void
    {
        // Simulates client-side EUR formatting (scale 2) vs server-side widened formatting (scale 4),
        // same currency, same numeric values. These MUST hash identically after normalize-to-scale-4.
        $clientFormatted = [
            'schema_version' => 2,
            'opening_cash' => '10.00',
            'cash_counts' => [[
                'payment_method_id' => 'pm',
                'currency_code' => 'EUR',
                'expected_amount' => '100.00',
                'actual_amount' => '100.00',
                'variance_amount' => '0.00',
            ]],
        ];
        $serverFormatted = [
            'schema_version' => 2,
            'opening_cash' => '10.0000',
            'cash_counts' => [[
                'payment_method_id' => 'pm',
                'currency_code' => 'EUR',
                'expected_amount' => '100.0000',
                'actual_amount' => '100.0000',
                'variance_amount' => '0.0000',
            ]],
        ];

        $hash = fn (array $d) => hash('sha256', json_encode(
            $this->service->normalizeForHash($d),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        $this->assertSame($hash($clientFormatted), $hash($serverFormatted));
    }

    public function test_currency_code_is_part_of_hash_input(): void
    {
        // Two payloads identical except currency_code. They represent genuinely different
        // economic records and MUST hash differently — otherwise tampering with currency_code
        // on stored report_data would not break the fiscal chain.
        $eur = [
            'schema_version' => 2,
            'opening_cash' => '10.00',
            'cash_counts' => [[
                'payment_method_id' => 'pm',
                'currency_code' => 'EUR',
                'expected_amount' => '100.00',
                'actual_amount' => '100.00',
                'variance_amount' => '0.00',
            ]],
        ];
        $tnd = $eur;
        $tnd['cash_counts'][0]['currency_code'] = 'TND';

        $hash = fn (array $d) => hash('sha256', json_encode(
            $this->service->normalizeForHash($d),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        $this->assertNotSame($hash($eur), $hash($tnd));
    }
}
