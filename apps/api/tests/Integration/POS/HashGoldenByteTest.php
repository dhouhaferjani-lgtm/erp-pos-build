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

        $expected = '{"schema_version":2,"opening_cash":"10.0000","gross_sales":"1432.5000","net_sales":"1203.7800","tax_amount":"228.7200","cash_counts":[{"payment_method_id":"11111111-1111-1111-1111-111111111111","currency_code":"EUR","expected_amount":"830.0000","actual_amount":"830.0000","variance_amount":"0.0000"}]}';

        $this->assertSame($expected, $json);
    }
}
