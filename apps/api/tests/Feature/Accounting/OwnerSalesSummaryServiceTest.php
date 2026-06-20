<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\DTOs\Reports\DateRangeData;
use App\Modules\Accounting\Application\Services\Reports\OwnerSalesSummaryService;
use App\Modules\Product\Domain\Product;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Accounting\Concerns\InteractsWithOwnerReporting;
use Tests\TestCase;

final class OwnerSalesSummaryServiceTest extends TestCase
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

    public function test_summary_separates_gross_sales_returns_and_computes_deltas(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'name' => 'Widget', 'sku' => 'W1']);
        // current window: sales 100 + 200, return -50 (references sale #1), items 2 + 3
        $sale1 = $this->seedReceiptWithLine($product, $this->locationA, $this->terminalA, '2026-06-10 10:00:00', '100.00', '2.0000');
        $this->seedReceiptWithLine($product, $this->locationB, $this->terminalB, '2026-06-11 10:00:00', '200.00', '3.0000');
        $this->seedReturn($this->locationA, $this->terminalA, '2026-06-12 10:00:00', '-50.00', $sale1);
        // prior window (2026-06-01..06-08): sale 150
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-05 10:00:00', '150.00');

        $summary = $this->app->make(OwnerSalesSummaryService::class)->summary(
            $this->range(), [$this->company->id], [$this->locationA->id, $this->locationB->id],
        );

        $this->assertSame('300.00', $summary->grossSales);
        $this->assertSame('50.00', $summary->returnsAmount);
        $this->assertSame('250.00', $summary->netSales);
        $this->assertSame(2, $summary->salesCount);
        $this->assertSame(1, $summary->returnsCount);
        $this->assertSame('5.0000', $summary->itemsSold);
        $this->assertSame('150.00', $summary->averageBasket);
        $this->assertSame('150.00', $summary->delta->grossSalesAbs);
        $this->assertSame('100.00', $summary->delta->grossSalesPct);  // (300-150)/150*100
    }

    public function test_zero_previous_period_yields_null_percentages(): void
    {
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-10 10:00:00', '100.00');

        $summary = $this->app->make(OwnerSalesSummaryService::class)->summary(
            $this->range(), [$this->company->id], [$this->locationA->id],
        );

        $this->assertNull($summary->delta->grossSalesPct);
        $this->assertNull($summary->delta->salesCountPct);
    }

    public function test_percentage_rounds_half_away_from_zero(): void
    {
        // prior 3 (count), current 5 → 66.666.. → 66.67
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-03 10:00:00', '10.00');
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-04 10:00:00', '10.00');
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-05 10:00:00', '10.00');
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-10 10:00:00', '10.00');
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-11 10:00:00', '10.00');
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-12 10:00:00', '10.00');
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-13 10:00:00', '10.00');
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-14 10:00:00', '10.00');

        $summary = $this->app->make(OwnerSalesSummaryService::class)->summary(
            $this->range(), [$this->company->id], [$this->locationA->id],
        );

        $this->assertSame('66.67', $summary->delta->salesCountPct);
    }

    public function test_average_basket_rounds_half_away_from_zero(): void
    {
        // gross 100.01 over 2 sales → 50.005 → 50.01 (round, not truncate), EUR scale 2
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-10 10:00:00', '100.00');
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-11 10:00:00', '0.01');

        $summary = $this->app->make(OwnerSalesSummaryService::class)->summary(
            $this->range(), [$this->company->id], [$this->locationA->id],
        );

        $this->assertSame('50.01', $summary->averageBasket);
    }

    public function test_empty_scope_returns_zeroed_summary_with_blank_currency(): void
    {
        // Empty companyIds/locationIds must short-circuit before any DB query
        // and return a fully-zeroed SalesSummaryData with an empty currencyCode.
        // zero() calls getScaleSafe(null, 3) → scale 3 for money → '0.000'
        // and QTY_SCALE = 4 for itemsSold → '0.0000'.
        $summary = $this->app->make(OwnerSalesSummaryService::class)->summary(
            $this->range(), [], [],
        );

        $this->assertSame('', $summary->currencyCode);
        $this->assertSame('0.000', $summary->grossSales);
        $this->assertSame('0.000', $summary->returnsAmount);
        $this->assertSame('0.000', $summary->netSales);
        $this->assertSame(0, $summary->salesCount);
        $this->assertSame(0, $summary->returnsCount);
        $this->assertSame('0.0000', $summary->itemsSold);
        $this->assertNull($summary->averageBasket);
        $this->assertSame('0.000', $summary->delta->grossSalesAbs);
        $this->assertNull($summary->delta->grossSalesPct);
    }
}
