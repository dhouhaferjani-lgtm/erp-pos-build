<?php

declare(strict_types=1);

namespace Tests\Unit\POS\Services;

use App\Modules\POS\Domain\Services\ZReportHashService;
use PHPUnit\Framework\TestCase;

/**
 * Task 40: ZReportHashService v3 normalization path.
 *
 * Tests:
 * - v3 normalizes the new monetary keys (refunds_amount, vouchers_issued_amount,
 *   vouchers_redeemed_amount) at scale 3.
 * - v2 reports still normalize under the legacy path (no new keys).
 * - v1 reports pass through unchanged.
 */
class ZReportHashServiceV3Test extends TestCase
{
    private ZReportHashService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ZReportHashService;
    }

    public function test_v3_normalize_handles_new_monetary_keys(): void
    {
        $reportData = [
            'schema_version' => 3,
            'gross_sales' => '1234.5678',
            'net_sales' => '1000.12345',
            'tax_amount' => '234.44',
            'refunds_amount' => '50.1234',
            'vouchers_issued_amount' => '25.6789',
            'vouchers_redeemed_amount' => '10.0001',
            'refunds_count' => 2,
            'vouchers_issued_count' => 1,
            'vouchers_redeemed_count' => 1,
        ];

        $normalized = $this->service->normalizeForHash($reportData);

        // New v3 monetary keys must be rounded to scale 3.
        $this->assertSame('50.123', $normalized['refunds_amount']);
        $this->assertSame('25.678', $normalized['vouchers_issued_amount']); // bcformat truncates, not rounds
        $this->assertSame('10.000', $normalized['vouchers_redeemed_amount']);

        // Inherited v2 keys still normalized (bcformat truncates to scale 3).
        $this->assertSame('1234.567', $normalized['gross_sales']);
        $this->assertSame('234.440', $normalized['tax_amount']);

        // Integer keys pass through unchanged.
        $this->assertSame(2, $normalized['refunds_count']);
        $this->assertSame(3, (int) $normalized['schema_version']);
    }

    public function test_v2_reports_still_normalize_under_legacy_path(): void
    {
        $reportData = [
            'schema_version' => 2,
            'gross_sales' => '500.12345',
            'net_sales' => '400.00',
            'tax_amount' => '100.12345',
            // v3 keys absent — must not cause errors
        ];

        $normalized = $this->service->normalizeForHash($reportData);

        // v2 keys normalized.
        $this->assertSame('500.123', $normalized['gross_sales']);
        $this->assertSame('100.123', $normalized['tax_amount']);

        // v3 keys not injected.
        $this->assertArrayNotHasKey('refunds_amount', $normalized);
        $this->assertArrayNotHasKey('vouchers_issued_amount', $normalized);
        $this->assertArrayNotHasKey('vouchers_redeemed_amount', $normalized);
    }

    public function test_v1_reports_pass_through_unchanged(): void
    {
        $reportData = [
            'sales_count' => 5,
            'gross_sales' => '500.1234567',
        ];

        $normalized = $this->service->normalizeForHash($reportData);

        // No modification for v1.
        $this->assertSame('500.1234567', $normalized['gross_sales']);
    }

    public function test_v3_normalize_skips_absent_new_keys_without_error(): void
    {
        // v3 report that is missing the new keys (e.g. an incomplete record).
        $reportData = [
            'schema_version' => 3,
            'gross_sales' => '100.000',
        ];

        // Should not throw.
        $normalized = $this->service->normalizeForHash($reportData);

        $this->assertSame('100.000', $normalized['gross_sales']);
        $this->assertArrayNotHasKey('refunds_amount', $normalized);
    }
}
