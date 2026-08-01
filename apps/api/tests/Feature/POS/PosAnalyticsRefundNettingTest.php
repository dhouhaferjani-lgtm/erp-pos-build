<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * v4 refunds project a POSITIVE `total` under `receipt_type = 'return'`
 * (v3-refund-chain-integration spec §7.7); legacy returns stored it NEGATIVE.
 *
 * Every PosAnalyticsService aggregate that relied on the sign to net would ADD
 * refunds once `EnableV4RefundAuthoringCommand` runs. These tests pin the
 * both-sign-eras behaviour of the four at-risk aggregates
 * (ticket 2026-08-01-positive-refund-total-consumers):
 *
 *  - getSalesSummary          net_sales / gross_sales / tax_total
 *  - getSalesSummary          payment_breakdown (NEW-1)
 *  - getSalesByTimePeriod     period total
 *  - getCashierPerformance    total_sales / average_ticket
 *  - getCustomerAnalytics     top_customers.total_spent
 *  - getSalesByCategory       line_total (NEW-2)
 *  - getSalesByProduct        line_total AND quantity (NEW-2)
 *
 * Fixture (one window, BOTH sign eras present so a fix cannot pass by simply
 * flipping the convention). Every receipt carries a line; only the v4-era rows
 * carry tender legs, because the legacy path wrote none and could not
 * (pos_receipt_payments CHECKs amount > 0):
 *
 *   sale        2026-03-15 10:00   total  +36.000  line +36.000 / +3.0000  cash +36.000  disc +5.000
 *   v4 refund   2026-03-15 11:00   total  +12.000  line +12.000 / +1.0000  cash +12.000  disc +2.000
 *   legacy ret  2026-03-16 09:00   total   -6.000  line  -6.000 / -0.5000  (no tender row)  disc +1.000
 *
 *   net over the window = 36 − 12 − 6 = 18.000  (a blended SUM yields 42.000)
 *   net quantity        =  3 −  1 − 0.5 = 1.5000 (a blended SUM yields 4.5000)
 */
final class PosAnalyticsRefundNettingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Terminal $terminal;

    private Partner $partner;

    private PaymentMethod $cashMethod;

    private Product $product;

    private Receipt $sale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.view_reports', 'sanctum');
        $this->user->givePermissionTo('pos.view_reports');

        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->cashMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash',
            'code' => 'CASH',
        ]);
        $category = Category::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Beverages',
        ]);
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Espresso',
            'category_id' => $category->id,
        ]);

        $this->seedBothSignEras();

        Sanctum::actingAs($this->user);
    }

    public function test_summary_nets_positive_v4_and_negative_legacy_refunds(): void
    {
        $data = $this->getJson('/api/v1/pos/analytics/summary?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->json('data');

        // 36 − 12 − 6 = 18 (blended SUM would report 42).
        $this->assertSame(0, bccomp($this->money($data['net_sales']), '18.000', 3), 'net_sales must net both refund sign eras');
        // 30 − 10 − 5 = 15 (blended SUM would report 45).
        $this->assertSame(0, bccomp($this->money($data['gross_sales']), '15.000', 3), 'gross_sales must net both refund sign eras');
        // 6 − 2 − 1 = 3 (blended SUM would report 9).
        $this->assertSame(0, bccomp($this->money($data['tax_total']), '3.000', 3), 'tax_total must net both refund sign eras');

        // Refund-scoped arms are per-row ABS and must stay magnitude-correct.
        $this->assertSame(2, $data['refund_count']);
        $this->assertSame(0, bccomp($this->money($data['refund_total']), '18.000', 3));
        // average_ticket is sale-only by construction.
        $this->assertSame(0, bccomp($this->money($data['average_ticket']), '36.00', 2));
    }

    public function test_sales_by_period_nets_positive_v4_and_negative_legacy_refunds(): void
    {
        $rows = $this->getJson('/api/v1/pos/analytics/sales-by-period?from=2026-03-01&to=2026-03-31&granularity=day')
            ->assertOk()
            ->json('data');

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[substr((string) $row['period'], 0, 10)] = $this->money($row['total']);
        }

        $this->assertArrayHasKey('2026-03-15', $byDay);
        $this->assertArrayHasKey('2026-03-16', $byDay);
        // 36 − 12 = 24 (blended SUM would report 48).
        $this->assertSame(0, bccomp($byDay['2026-03-15'], '24.000', 3), 'same-day sale + v4 refund must net');
        $this->assertSame(0, bccomp($byDay['2026-03-16'], '-6.000', 3), 'legacy negative return must not be double-counted');
    }

    public function test_cashier_performance_nets_positive_v4_and_negative_legacy_refunds(): void
    {
        // Second cashier with a NON-TERMINATING average (10.000 / 3), so the
        // rounding boundary is exercised rather than an exact integer.
        $otherCashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        foreach (['3.000', '3.000', '4.000'] as $index => $amount) {
            $this->createReceipt([
                'receipt_type' => ReceiptType::Sale,
                'cashier_id' => $otherCashier->id,
                'cashier_name' => 'Rounding Cashier',
                'posted_at' => '2026-03-18 1'.$index.':00:00',
                'subtotal' => $amount,
                'tax_amount' => '0.000',
                'total' => $amount,
            ]);
        }

        $rows = $this->getJson('/api/v1/pos/analytics/cashiers?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $rows);
        $this->assertSame('Test Cashier', $rows[0]['cashier_name']);
        $this->assertSame(3, $rows[0]['receipt_count']);
        // 36 − 12 − 6 = 18 (blended SUM would report 42).
        $this->assertSame(0, bccomp($this->money($rows[0]['total_sales']), '18.000', 3), 'total_sales must net both refund sign eras');
        // average stays total_sales / receipt_count = 18 / 3.
        $this->assertSame(0, bccomp($this->money($rows[0]['average_ticket']), '6.00', 2), 'average_ticket must average the netted contribution');

        // Money leaves the service as a fixed-scale DECIMAL STRING — never a
        // float round-trip, never a variable-width '6' (precision rule 19).
        $this->assertIsString($rows[0]['average_ticket']);
        $this->assertSame('6.00', $rows[0]['average_ticket']);

        // 10.000 / 3 = 3.3333… → half-away-from-zero at the display boundary.
        $this->assertSame('Rounding Cashier', $rows[1]['cashier_name']);
        $this->assertSame(3, $rows[1]['receipt_count']);
        $this->assertSame(0, bccomp($this->money($rows[1]['total_sales']), '10.000', 3));
        $this->assertIsString($rows[1]['average_ticket']);
        $this->assertSame('3.33', $rows[1]['average_ticket']);
    }

    /**
     * NEW-1 — the payment breakdown lives in the SAME method (and the SAME DTO)
     * as `net_sales`. A v4 refund projects a POSITIVE `pos_receipt_payments.amount`
     * leg (PosCoreReceiptProjection::writePayment writes the canonical amount
     * verbatim), so a blended SUM made `SalesSummaryData` contradict itself: a
     * netted `net_sales` beside a gross-of-refunds `payment_breakdown`.
     *
     * Note the era asymmetry, which is what makes `-ABS` exactly right here:
     * `pos_receipt_payments.amount` can never be negative (CHECK amount > 0) and
     * legacy returns wrote NO payment row at all, so the return arm only ever
     * sees a positive v4 payout leg.
     */
    public function test_summary_payment_breakdown_nets_v4_refund_payout_legs(): void
    {
        // Whole window: sale 36 collected − 12 paid out = 24 (blended: 48).
        $data = $this->getJson('/api/v1/pos/analytics/summary?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data['payment_breakdown']);
        $this->assertSame('cash', $data['payment_breakdown'][0]['payment_type']);
        $this->assertSame(0, bccomp($this->money($data['payment_breakdown'][0]['total']), '24.000', 3));

        // v4-era-only window (the legacy return is on 03-16 and carries no
        // payment row, since money never moved through one in that era). Here
        // every receipt has tender legs, so the DTO must RECONCILE WITH ITSELF:
        // net_sales === the summed payment breakdown.
        $v4Window = $this->getJson('/api/v1/pos/analytics/summary?from=2026-03-01&to=2026-03-15')
            ->assertOk()
            ->json('data');

        $paymentTotal = '0.000';
        foreach ($v4Window['payment_breakdown'] as $row) {
            $paymentTotal = bcadd($paymentTotal, $this->money($row['total']), 3);
        }

        $this->assertSame(0, bccomp($this->money($v4Window['net_sales']), '24.000', 3));
        $this->assertSame(0, bccomp($paymentTotal, '24.000', 3));
        $this->assertSame(
            0,
            bccomp($this->money($v4Window['net_sales']), $paymentTotal, 3),
            'net_sales and payment_breakdown are two views of the same money and must agree',
        );
    }

    /**
     * NEW-2 — line-level aggregates. v4 refund LINES project POSITIVE
     * `line_total`/`quantity` (PosCoreReceiptProjection::writeLines copies the
     * positive-magnitude payload verbatim); legacy return lines were NEGATIVE.
     * Category revenue was therefore off by 2x the refund while the headline
     * `net_sales` on the same dashboard was netted.
     */
    public function test_sales_by_category_nets_both_refund_sign_eras(): void
    {
        $rows = $this->getJson('/api/v1/pos/analytics/sales-by-category?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Beverages', $rows[0]['category_name']);
        $this->assertSame(3, $rows[0]['count']);
        // 36 − 12 − 6 = 18 (blended SUM would report 42).
        $this->assertSame(0, bccomp($this->money($rows[0]['total']), '18.000', 3), 'category revenue must net both refund sign eras');
    }

    /** NEW-2 — same defect on `line_total` AND on `quantity`. */
    public function test_sales_by_product_nets_both_refund_sign_eras_for_value_and_quantity(): void
    {
        $rows = $this->getJson('/api/v1/pos/analytics/sales-by-product?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Espresso', $rows[0]['product_name']);
        // 36 − 12 − 6 = 18 (blended SUM would report 42).
        $this->assertSame(0, bccomp($this->money($rows[0]['total']), '18.000', 3), 'product revenue must net both refund sign eras');
        // 3.0000 − 1.0000 − 0.5000 = 1.5000 (blended SUM would report 4.5000):
        // units sold must not GROW when goods come back.
        $this->assertSame(0, bccomp($this->money($rows[0]['quantity']), '1.5000', 4), 'product quantity must net both refund sign eras');
    }

    /**
     * ⚖️ Ruling — discount analysis EXCLUDES return receipts entirely (sale-only
     * population), rather than netting them or letting them in.
     *
     * The metric measures discounting BEHAVIOUR at sale time: refunding a
     * discounted sale neither grants a new discount nor retracts the historical
     * grant, so a return simply is not a member of the population.
     *
     * ⚠️ The exposure is WIDER than the round-2 review assumed. It reasoned that
     * the `discount_amount > 0` filter already dropped legacy return lines
     * because their discount was negative — but `pos_receipt_lines_amounts`
     * CHECKs `discount_amount >= 0` (and `unit_price >= 0`) in BOTH eras, so a
     * legacy return line records its discount as a POSITIVE magnitude and was
     * counted too. Only `line_total`/`quantity` ever went negative. Excluding
     * returns therefore corrects both eras, not just the v4 arm.
     *
     * Fixture: the sale carries a 5.000 discount, the v4 refund the mirror
     * 2.000, the legacy return 1.000. Every figure below is the sale's alone.
     */
    public function test_discount_analysis_ignores_refunds_of_discounted_sales(): void
    {
        $data = $this->getJson('/api/v1/pos/analytics/discounts?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->json('data');

        // 5.000 alone — including the v4 mirror line reports 7.000.
        $this->assertSame(0, bccomp($this->money($data['total_discount_amount']), '5.000', 3), 'a refund grants no new discount');
        // One discounted line, not two.
        $this->assertSame(1, $data['discount_count']);

        $this->assertCount(1, $data['by_reason']);
        $this->assertSame('Promo', $data['by_reason'][0]['reason']);
        $this->assertSame(1, $data['by_reason'][0]['count']);
        $this->assertSame(0, bccomp($this->money($data['by_reason'][0]['total_amount']), '5.000', 3));

        $this->assertCount(1, $data['top_discounted_products']);
        $this->assertSame('Espresso', $data['top_discounted_products'][0]['product_name']);
        $this->assertSame(0, bccomp($this->money($data['top_discounted_products'][0]['discount_amount']), '5.000', 3));
        // The quantity column the verifier flagged: the sale's 3.0000, never
        // 4.0000 (sale + refund mirror).
        $this->assertSame(0, bccomp($this->money($data['top_discounted_products'][0]['quantity']), '3.0000', 4));
    }

    public function test_customer_analytics_nets_positive_v4_and_negative_legacy_refunds(): void
    {
        $data = $this->getJson('/api/v1/pos/analytics/customers?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['unique_customers']);
        $this->assertCount(1, $data['top_customers']);
        // 36 − 12 − 6 = 18 (blended SUM would report 42).
        $this->assertSame(
            0,
            bccomp($this->money($data['top_customers'][0]['total_spent']), '18.000', 3),
            'total_spent must net both refund sign eras',
        );
    }

    /**
     * Coerce a JSON-decoded money field to a numeric-string for bcmath.
     *
     * @return numeric-string
     */
    private function money(mixed $value): string
    {
        $string = is_scalar($value) ? (string) $value : '';
        $this->assertTrue(is_numeric($string), 'money field must be numeric, got: '.$string);

        return $string;
    }

    private function seedBothSignEras(): void
    {
        $this->sale = $this->createReceipt([
            'receipt_type' => ReceiptType::Sale,
            'posted_at' => '2026-03-15 10:00:00',
            'subtotal' => '30.000',
            'tax_amount' => '6.000',
            'total' => '36.000',
        ]);
        $this->seedLine($this->sale, '36.000', '3.0000', discount: '5.000');
        $this->seedCashPayment($this->sale, '36.000');

        // v4-era refund: POSITIVE total under receipt_type='return' (spec §7.7),
        // with a POSITIVE line and a POSITIVE cash payout leg.
        $v4Refund = $this->createReceipt([
            'receipt_type' => ReceiptType::Return,
            'posted_at' => '2026-03-15 11:00:00',
            'subtotal' => '10.000',
            'tax_amount' => '2.000',
            'total' => '12.000',
            'original_receipt_id' => $this->sale->id,
            'return_reason' => ReturnReason::Other,
        ]);
        // Mirror line of a discounted sale: the v4 payload carries the
        // proportional discount as a POSITIVE magnitude.
        $this->seedLine($v4Refund, '12.000', '1.0000', discount: '2.000');
        $this->seedCashPayment($v4Refund, '12.000');

        // Legacy-era return: NEGATIVE total and NEGATIVE line. No payment row —
        // the legacy path never wrote one (ReceiptReturnService routes refunds
        // through PaymentRefundService / the cash drawer), and it could not:
        // pos_receipt_payments CHECKs amount > 0.
        $legacyReturn = $this->createReceipt([
            'receipt_type' => ReceiptType::Return,
            'posted_at' => '2026-03-16 09:00:00',
            'subtotal' => '-5.000',
            'tax_amount' => '-1.000',
            'total' => '-6.000',
            'original_receipt_id' => $this->sale->id,
            'return_reason' => ReturnReason::Other,
        ]);
        // NOTE: discount_amount is CHECKed >= 0 in BOTH eras
        // (pos_receipt_lines_amounts), so even a legacy return line records the
        // discount as a POSITIVE magnitude — see the discount-analysis test.
        $this->seedLine($legacyReturn, '-6.000', '-0.5000', discount: '1.000');
    }

    private function seedLine(Receipt $receipt, string $lineTotal, string $quantity, string $discount = '0.000'): void
    {
        ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'product_code' => $this->product->sku,
            'quantity' => $quantity,
            'unit' => 'pcs',
            'unit_price' => '12.000',
            'tax_rate' => '20.00',
            'tax_amount' => '0.000',
            'line_total' => $lineTotal,
            'discount_amount' => $discount,
            'discount_reason' => 'Promo',
        ]);
    }

    private function seedCashPayment(Receipt $receipt, string $amount): void
    {
        ReceiptPayment::create([
            'receipt_id' => $receipt->id,
            'payment_method_id' => $this->cashMethod->id,
            'payment_type' => 'cash',
            'amount' => $amount,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function createReceipt(array $attributes): Receipt
    {
        return Receipt::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'cashier_name' => 'Test Cashier',
            'partner_id' => $this->partner->id,
            'customer_name' => 'Netting Customer',
            'discount_amount' => '0.000',
            'is_voided' => false,
        ], $attributes));
    }
}
