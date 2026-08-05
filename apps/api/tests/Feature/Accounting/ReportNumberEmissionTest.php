<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\DTOs\Reports\DateRangeData;
use App\Modules\Accounting\Application\Services\Reports\CashRegisterReportService;
use App\Modules\Accounting\Application\Services\Reports\SalesReportService;
use App\Modules\Accounting\Application\Services\Reports\StockAlertReportService;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Shift;
use App\Modules\Product\Domain\Product;
use App\Modules\Uom\Domain\Entities\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Accounting\Concerns\InteractsWithOwnerReporting;
use Tests\TestCase;

/**
 * L4 — currency-blind emission (W-7 F-2, same family as W-6 D6).
 *
 * `FormatsReportNumbers::decimalString()` used to do three things wrong on every
 * owner-report money figure: it cast to `(float)` (CLAUDE.md rule 19 — never let
 * a float touch money), it formatted at a hardcoded `scale = 2` (TND is scale 3,
 * so the millime was truncated away) and it then `rtrim`med the trailing zeros,
 * emitting `300.000` as `"300"`. It was also used for QUANTITIES, which are
 * unit-scaled and never currency-scaled.
 *
 * These tests pin the fixed contract: money is emitted at the COMPANY CURRENCY
 * scale with no trimming, quantities at the product unit's decimal places, and
 * percentages at a fixed 2 dp (a percent is not a currency).
 */
final class ReportNumberEmissionTest extends TestCase
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

    /** Bind the request-time company context the scale resolver reads the currency from. */
    private function bindCompanyCurrency(string $currency, string $countryCode): void
    {
        $this->company->forceFill(['currency' => $currency, 'country_code' => $countryCode])->save();
        $context = $this->app->make(CompanyContext::class);
        $context->clear();
        $context->setCompanyId($this->company->id);
    }

    private function seedUnit(int $decimalPlaces): Unit
    {
        return Unit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_system' => false,
            'decimal_places' => $decimalPlaces,
        ]);
    }

    public function test_sales_by_location_emits_money_at_the_tnd_currency_scale_without_trimming(): void
    {
        $this->bindCompanyCurrency('TND', 'TN');
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-10 10:00:00', '300.000');

        $rows = $this->app->make(SalesReportService::class)->salesByLocation(
            $this->range(), [$this->company->id], [$this->locationA->id], 'day',
        );

        $this->assertCount(1, $rows);
        $this->assertSame(
            '300.000',
            $rows[0]->gross_sales,
            'TND gross_sales must keep its millime — not "300" (rtrim) nor "300.00" (scale 2).',
        );
    }

    public function test_sales_by_location_emits_money_at_the_eur_currency_scale(): void
    {
        $this->bindCompanyCurrency('EUR', 'FR');
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-10 10:00:00', '181.100');

        $rows = $this->app->make(SalesReportService::class)->salesByLocation(
            $this->range(), [$this->company->id], [$this->locationA->id], 'day',
        );

        $this->assertSame('181.10', $rows[0]->gross_sales, 'A EUR company emits scale 2, not scale 3.');
    }

    public function test_top_skus_emit_money_at_currency_scale_and_quantity_at_unit_precision(): void
    {
        $this->bindCompanyCurrency('TND', 'TN');

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'unit_id' => $this->seedUnit(2)->id,
        ]);

        $this->seedReceiptWithLine(
            $product, $this->locationA, $this->terminalA, '2026-06-10 10:00:00', '12.500', '2.5000',
        );

        $rows = $this->app->make(SalesReportService::class)->topSkus(
            $this->range(), [$this->company->id], [$this->locationA->id], 10, 'revenue',
        );

        $this->assertCount(1, $rows);
        $this->assertSame('12.500', $rows[0]->revenue, 'Revenue is money → currency scale 3.');
        $this->assertSame(
            '2.50',
            $rows[0]->quantity,
            'Quantity is NOT money: it follows the unit decimal_places (2), never the currency scale.',
        );
    }

    public function test_cash_reconciliation_emits_the_variance_at_the_currency_scale(): void
    {
        $this->bindCompanyCurrency('TND', 'TN');

        Shift::create([
            'terminal_id' => $this->terminalA->id,
            'cashier_id' => $this->owner->id,
            'shift_number' => 1,
            'opening_cash' => '0.000',
            'expected_cash' => '300.000',
            'actual_cash' => '299.500',
            'variance' => '-0.500',
            'status' => ShiftStatus::Closed,
            'opened_at' => '2026-06-10 08:00:00',
            'closed_at' => '2026-06-10 18:00:00',
        ]);

        $rows = $this->app->make(CashRegisterReportService::class)->reconciliationSummary(
            $this->range(), [$this->company->id], [$this->locationA->id],
        );

        $this->assertCount(1, $rows);
        $this->assertSame('300.000', $rows[0]->expected_cash);
        $this->assertSame('299.500', $rows[0]->counted_cash);
        $this->assertSame(
            '-0.500',
            $rows[0]->variance,
            'The cash-count variance is the launch-critical Z/EOD figure: it must never lose the millime.',
        );
    }

    public function test_stock_alerts_emit_quantities_at_unit_precision_not_currency_scale(): void
    {
        $this->bindCompanyCurrency('TND', 'TN');

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'unit_id' => $this->seedUnit(3)->id,
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->locationA->id,
            'product_id' => $product->id,
            'quantity' => '12.5000',
            'min_quantity' => '100.0000',
        ]);

        $rows = $this->app->make(StockAlertReportService::class)->lowStockAcrossLocations(
            [$this->company->id], [$this->locationA->id], 100,
        );

        $this->assertCount(1, $rows);
        $this->assertSame('12.500', $rows[0]->quantity, 'Quantity follows the unit precision (3), not currency scale 2.');
        $this->assertSame('100.000', $rows[0]->min_quantity);
    }

    public function test_payment_method_breakdown_emits_money_at_currency_scale_and_percent_at_two_dp(): void
    {
        $this->bindCompanyCurrency('TND', 'TN');
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-10 10:00:00', '300.000');

        $rows = $this->app->make(SalesReportService::class)->paymentMethodBreakdown(
            $this->range(), [$this->company->id], [$this->locationA->id],
        );

        $this->assertCount(1, $rows);
        $this->assertSame('300.000', $rows[0]->amount);
        $this->assertSame('100.00', $rows[0]->percentage, 'A percentage is not currency-scaled: it stays at 2 dp.');
    }

    public function test_revenue_by_category_emits_money_at_currency_scale(): void
    {
        $this->bindCompanyCurrency('TND', 'TN');

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'unit_id' => $this->seedUnit(2)->id,
        ]);

        $this->seedReceiptWithLine(
            $product, $this->locationA, $this->terminalA, '2026-06-10 10:00:00', '181.100', '1.0000',
        );

        $rows = $this->app->make(SalesReportService::class)->revenueByCategory(
            $this->range(), [$this->company->id], [$this->locationA->id],
        );

        $this->assertCount(1, $rows);
        $this->assertSame('181.100', $rows[0]->revenue);
        $this->assertSame('100.00', $rows[0]->percentage);
    }
}
