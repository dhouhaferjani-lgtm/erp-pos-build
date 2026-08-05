<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\DTOs\Reports\DateRangeData;
use App\Modules\Accounting\Application\Services\Reports\CashRegisterReportService;
use App\Modules\Accounting\Application\Services\Reports\FormatsReportNumbers;
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

    /**
     * PINS THE ROUNDING SEMANTIC, not just the scale.
     *
     * `decimalString()` uses `CurrencyScale::bcround` (half AWAY FROM ZERO), not
     * `bcformat` (truncate). Both produce byte-identical output for every other
     * fixture in this class, because each has a zero 4th decimal — so without
     * this case a silent revert to truncation stays green.
     *
     * The ruling (L4 API precision gate, Q4): the POS device rounds the same way
     * — `apps/pos/src/lib/currency.ts:43-63` formats through `Intl.NumberFormat`
     * (`halfExpand`) and `apps/pos/src/lib/decimal.ts` uses `Big.RM = 1` — so
     * truncating server-side would MANUFACTURE a server-vs-device millime
     * disagreement on the Z/EOD surface. `pos_shifts.expected_cash` is
     * `DECIMAL(16,4)` in PostgreSQL, so a non-zero 4th decimal is representable
     * at rest and this is reachable, not theoretical.
     */
    public function test_cash_reconciliation_rounds_the_fourth_decimal_it_cannot_render(): void
    {
        $this->bindCompanyCurrency('TND', 'TN');

        Shift::create([
            'terminal_id' => $this->terminalA->id,
            'cashier_id' => $this->owner->id,
            'shift_number' => 1,
            'opening_cash' => '0.000',
            'expected_cash' => '300.0005',
            'actual_cash' => '299.4995',
            'variance' => '-0.5005',
            'status' => ShiftStatus::Closed,
            'opened_at' => '2026-06-10 08:00:00',
            'closed_at' => '2026-06-10 18:00:00',
        ]);

        $rows = $this->app->make(CashRegisterReportService::class)->reconciliationSummary(
            $this->range(), [$this->company->id], [$this->locationA->id],
        );

        $this->assertCount(1, $rows);
        $this->assertSame(
            '300.001',
            $rows[0]->expected_cash,
            'bcround, not bcformat: truncation would emit 300.000 and disagree with the device.',
        );
        $this->assertSame('299.500', $rows[0]->counted_cash, 'rounds UP at the half, same as the device.');
        $this->assertSame(
            '-0.501',
            $rows[0]->variance,
            'negatives round AWAY from zero, symmetric with positives — truncation would emit -0.500.',
        );
    }

    /**
     * The same rounding ruling at the OTHER currency scale, so a revert cannot
     * hide behind TND: a EUR company renders at 2 dp, and `777.775` must round
     * to `777.78` rather than truncate to `777.77`.
     */
    public function test_sales_by_location_rounds_at_the_eur_currency_scale(): void
    {
        $this->bindCompanyCurrency('EUR', 'FR');
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-10 10:00:00', '777.775');

        $rows = $this->app->make(SalesReportService::class)->salesByLocation(
            $this->range(), [$this->company->id], [$this->locationA->id], 'day',
        );

        $this->assertSame(
            '777.78',
            $rows[0]->gross_sales,
            'bcround, not bcformat: truncation would emit 777.77.',
        );
    }

    /**
     * The two cases above reach the formatter through SQLite, whose `SUM()` and
     * NUMERIC-affinity columns hand PHP a FLOAT. PostgreSQL — the production
     * driver — returns `numeric` as a STRING, so the shipping path is a
     * different branch of `numericString()` and is not covered by either.
     *
     * This exercises the trait directly on both branches, with the exact values
     * the L4 API precision gate ruled on.
     */
    public function test_the_emission_helpers_round_on_the_production_string_path_too(): void
    {
        $formatter = new class
        {
            use FormatsReportNumbers {
                decimalString as public money;
                quantityString as public quantity;
                percentString as public percent;
            }
        };

        // PostgreSQL shape: a numeric STRING, full precision, no conversion.
        $this->assertSame('300.001', $formatter->money('300.0005', 3), 'TND rounds the 4th decimal up');
        $this->assertSame('-0.501', $formatter->money('-0.5005', 3), 'and negatives round AWAY from zero');
        $this->assertSame('777.78', $formatter->money('777.775', 2), 'EUR rounds the 3rd decimal up');
        $this->assertSame('777.77', $formatter->money('777.774', 2), 'below the half it rounds down');

        // SQLite / PHP-computed shape: the same values as floats must agree,
        // digit for digit, with the string path.
        //
        // Domain of validity: the float branch normalises with `number_format` at
        // MONEY_NORMALISATION_SCALE (4), which is exact for every magnitude below
        // 1e13 — above that a double can no longer represent 4 decimals and the
        // two paths would diverge. That ceiling is three orders beyond
        // `decimal(16,4)`'s own headroom, so it is unreachable for money; the
        // string branch (the production path on PostgreSQL) has no such limit.
        $this->assertSame('300.001', $formatter->money(300.0005, 3));
        $this->assertSame('777.78', $formatter->money(777.775, 2));

        // Nothing is trimmed, and a zero keeps the scale (W-7 F-2's `rtrim`).
        $this->assertSame('300.000', $formatter->money('300', 3));
        $this->assertSame('0.000', $formatter->money(null, 3));

        // Quantities take the unit's precision, and percentages a fixed 2 dp.
        $this->assertSame('12.50', $formatter->quantity('12.5000', 2));
        $this->assertSame('12.5000', $formatter->quantity('12.5', null));
        $this->assertSame('33.33', $formatter->percent('33.3333'));
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
