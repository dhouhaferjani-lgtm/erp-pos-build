<?php

declare(strict_types=1);

namespace Tests\Integration\POS;

use App\Modules\POS\Domain\Services\ZReportHashService;
use Tests\TestCase;

final class HashGoldenByteTest extends TestCase
{
    public function test_php_side_json_bytes_match_the_golden_fixture(): void
    {
        $fixturePath = base_path('tests/Fixtures/z-report-v2-eur.json');
        $fixture = json_decode((string) file_get_contents($fixturePath), associative: true);

        $service = $this->app->make(ZReportHashService::class);

        $reportData = $service->normalizeForHash([
            'schema_version' => 2,
            'opening_cash' => $fixture['shift']['opening_cash'],
            'gross_sales' => $fixture['totals']['gross_sales'],
            'net_sales' => $fixture['totals']['net_sales'],
            'tax_amount' => $fixture['totals']['tax_amount'],
            'cash_counts' => $fixture['cash_counts'],
        ]);

        $json = json_encode($reportData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Z-report contract v1.1 (POS go-live PR #1, Phase 0.1.9) normalizes
        // monetary fields at scale 3 before hashing. The cross-language
        // fixture at apps/pos/tests/fixtures/z-report-v2-eur.json mirrors
        // this expectation so the Tauri-side SHA-256 of the same payload
        // matches byte-for-byte. Any change to scale here MUST be paired
        // with a sibling change to that fixture and to the documented
        // ZReportHashService::normalizeForHash() contract.
        $expected = '{"schema_version":2,"opening_cash":"10.000","gross_sales":"1432.500","net_sales":"1203.780","tax_amount":"228.720","cash_counts":[{"payment_method_id":"11111111-1111-1111-1111-111111111111","currency_code":"EUR","expected_amount":"830.000","actual_amount":"830.000","variance_amount":"0.000"}]}';

        $this->assertSame($expected, $json);
    }
}
