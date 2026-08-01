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
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
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
 *  - getSalesByTimePeriod     period total
 *  - getCashierPerformance    total_sales / average_ticket
 *  - getCustomerAnalytics     top_customers.total_spent
 *
 * Fixture (one window, BOTH sign eras present so a fix cannot pass by simply
 * flipping the convention):
 *
 *   sale        2026-03-15 10:00   total  +36.000  (subtotal 30, tax 6)
 *   v4 refund   2026-03-15 11:00   total  +12.000  (subtotal 10, tax 2)
 *   legacy ret  2026-03-16 09:00   total   -6.000  (subtotal -5, tax -1)
 *
 *   net over the window = 36 − 12 − 6 = 18.000  (a blended SUM yields 42.000)
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

        // v4-era refund: POSITIVE total under receipt_type='return' (spec §7.7).
        $this->createReceipt([
            'receipt_type' => ReceiptType::Return,
            'posted_at' => '2026-03-15 11:00:00',
            'subtotal' => '10.000',
            'tax_amount' => '2.000',
            'total' => '12.000',
            'original_receipt_id' => $this->sale->id,
            'return_reason' => ReturnReason::Other,
        ]);

        // Legacy-era return: NEGATIVE total.
        $this->createReceipt([
            'receipt_type' => ReceiptType::Return,
            'posted_at' => '2026-03-16 09:00:00',
            'subtotal' => '-5.000',
            'tax_amount' => '-1.000',
            'total' => '-6.000',
            'original_receipt_id' => $this->sale->id,
            'return_reason' => ReturnReason::Other,
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
