<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\DTOs\Reports\DateRangeData;
use App\Modules\Accounting\Application\Services\Reports\SalesReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Accounting\Concerns\InteractsWithOwnerReporting;
use Tests\TestCase;

/**
 * SalesReportService powers the owner-dashboard DRILL-DOWN breakdowns
 * (salesByLocation / topSkus / revenueByCategory / paymentMethodBreakdown).
 * It must report SALES only — returns belong to the separate returns metric
 * (see OwnerSalesSummaryService) — otherwise the breakdowns disagree with the
 * headline KPIs. Audit finding F-5.
 *
 * The fix (filter receipt_type='sale') is correct regardless of how a return's
 * `total` is signed in storage, because it excludes the return rows entirely.
 */
final class SalesReportServiceReturnsTest extends TestCase
{
    use InteractsWithOwnerReporting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOwnerReportingFixtures();
    }

    private function range(): DateRangeData
    {
        return new DateRangeData(CarbonImmutable::parse('2026-06-09'), CarbonImmutable::parse('2026-06-16'));
    }

    public function test_sales_by_location_excludes_return_receipts(): void
    {
        $sale = $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-10 10:00:00', '100.00');
        // A return in the same window/location must NOT change gross sales or the count.
        $this->seedReturn($this->locationA, $this->terminalA, '2026-06-12 10:00:00', '-50.00', $sale);

        $rows = $this->app->make(SalesReportService::class)->salesByLocation(
            $this->range(), [$this->company->id], [$this->locationA->id], 'day',
        );

        $gross = array_sum(array_map(static fn ($r): float => (float) $r->gross_sales, $rows));
        $count = array_sum(array_map(static fn ($r): int => $r->receipt_count, $rows));

        $this->assertSame(100.0, $gross, 'Returns must not be summed into gross sales.');
        $this->assertSame(1, $count, 'Return receipts must not be counted as sales.');
    }
}
