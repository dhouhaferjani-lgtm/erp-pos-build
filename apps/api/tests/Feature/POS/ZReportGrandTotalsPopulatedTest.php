<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature test: server-generated Z-reports must populate grand_totals.
 *
 * Context: for online-only tenants whose Z-reports are generated server-side
 * (POST /api/v1/pos/reports/z rather than synced up from offline clients),
 * `pos_z_reports.grand_totals` previously stayed NULL. The POS client then
 * called pullZChainState() and clobbered local cumulative_* counters with
 * zero defaults.
 *
 * The invariant these tests pin: each server-generated Z-report carries the
 * shift's gross_sales / tax_amount / refunds_amount added onto the previous
 * Z's cumulative counters, expressed as currency-scale-aligned decimal strings.
 */
final class ZReportGrandTotalsPopulatedTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $user;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        // These suites exercise the DEVICE/server flow on routes that are
        // web-gated to demo tenants (EnsureWebPosDemoTenant, owner decision
        // 2026-06-11); the device marker keeps them reaching the layer
        // under test.
        $this->defaultHeaders['X-Client-Type'] = 'pos-tauri';

        $this->tenant = Tenant::factory()->create();
        // TND so the resolved scale is 3 — the precision path we care about.
        $this->company = Company::factory()
            ->tunisia()
            ->create(['tenant_id' => $this->tenant->id]);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.generate_z_report', 'sanctum');
        $this->user->givePermissionTo('pos.generate_z_report');

        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        Sanctum::actingAs($this->user);
    }

    public function test_new_z_report_cumulative_sales_equals_prior_plus_shift_total(): void
    {
        // Seed a prior, already-closed Z-report with non-null grand_totals.
        $closedShift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Closed,
            'opened_at' => now()->subDay(),
            'closed_at' => now()->subDay()->addHours(8),
            'opening_cash' => '100.000',
        ]);

        ZReport::create([
            'terminal_id' => $this->terminal->id,
            'shift_id' => $closedShift->id,
            'z_number' => 1,
            'fiscal_hash' => str_repeat('a', 64),
            'previous_z_hash' => null,
            'report_data' => ['sales_count' => 10],
            'grand_totals' => [
                'cumulative_sales' => '500.000',
                'cumulative_tax' => '95.000',
                'cumulative_refunds' => '25.000',
                'perpetual_grand_total' => '475.000',
                'receipt_count_lifetime' => 10,
            ],
            'generated_by' => $this->user->id,
            'generated_at' => now()->subDay()->addHours(8),
        ]);

        // Open a new shift and drop in one receipt.
        Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => 2,
            'status' => ShiftStatus::Open,
            'opened_at' => now()->subHour(),
            'opening_cash' => '100.000',
        ]);

        $this->createReceipt(chainSequence: 11, subtotal: '100.000', tax: '19.000', total: '119.000');

        $response = $this->postJson('/api/v1/pos/reports/z', [
            'terminal_id' => $this->terminal->id,
        ]);

        $response->assertStatus(201);

        /** @var ZReport $newZ */
        $newZ = ZReport::findOrFail($response->json('data.id'));

        $this->assertNotNull(
            $newZ->grand_totals,
            'grand_totals must be populated on server-generated Z-reports',
        );
        $this->assertSame('619.000', $newZ->grand_totals['cumulative_sales']);
        $this->assertSame('114.000', $newZ->grand_totals['cumulative_tax']);
        $this->assertSame('25.000', $newZ->grand_totals['cumulative_refunds']);
        $this->assertSame('594.000', $newZ->grand_totals['perpetual_grand_total']);
        $this->assertSame(11, $newZ->grand_totals['receipt_count_lifetime']);
    }

    public function test_first_z_report_cumulative_sales_equals_shift_total(): void
    {
        Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => now()->subHour(),
            'opening_cash' => '100.000',
        ]);

        $this->createReceipt(chainSequence: 1, subtotal: '50.000', tax: '9.500', total: '59.500');

        $response = $this->postJson('/api/v1/pos/reports/z', [
            'terminal_id' => $this->terminal->id,
        ]);

        $response->assertStatus(201);

        /** @var ZReport $newZ */
        $newZ = ZReport::findOrFail($response->json('data.id'));

        $this->assertNotNull($newZ->grand_totals);
        $this->assertSame('59.500', $newZ->grand_totals['cumulative_sales']);
        $this->assertSame('9.500', $newZ->grand_totals['cumulative_tax']);
        $this->assertSame('0.000', $newZ->grand_totals['cumulative_refunds']);
        $this->assertSame('59.500', $newZ->grand_totals['perpetual_grand_total']);
        $this->assertSame(1, $newZ->grand_totals['receipt_count_lifetime']);
    }

    private function createReceipt(int $chainSequence, string $subtotal, string $tax, string $total): Receipt
    {
        return Receipt::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'receipt_number' => sprintf('T001-C042-L01-POS01-2026-%08d', $chainSequence),
            'chain_sequence' => $chainSequence,
            'receipt_year' => 2026,
            'fiscal_hash' => hash('sha256', "receipt-{$chainSequence}"),
            'previous_hash' => $chainSequence > 1 ? hash('sha256', 'receipt-'.($chainSequence - 1)) : null,
            'vat_breakdown_hash' => hash('sha256', 'vat'),
            'payment_methods_hash' => hash('sha256', 'payment'),
            'posted_at' => now(),
            'cashier_id' => $this->user->id,
            'cashier_name' => 'Test User',
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'discount_amount' => '0.000',
            'total' => $total,
            'currency' => 'TND',
            'is_voided' => false,
            'is_training' => false,
        ]);
    }
}
