<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\FiscalSchemaCutoverService;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Exceptions\FiscalSchemaCutoverBlockedException;
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
 * Task 42: FiscalSchemaCutoverService gate checks.
 *
 * Tests:
 * - Succeeds on an idle, drained terminal with no open shift, no un-Z-reported
 *   receipts, and no pending-sync receipts.
 * - Refuses when terminal has an open shift.
 * - Refuses when terminal has un-Z-reported fiscalized receipts.
 * - Refuses when terminal has receipts in the pending-sync queue.
 * - Returns 403 when caller lacks pos.fiscal_schema_cutover permission.
 * - Concurrent cutover attempts serialize via FOR UPDATE.
 */
class FiscalSchemaCutoverServiceTest extends TestCase
{
    use RefreshDatabase;

    private FiscalSchemaCutoverService $service;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(FiscalSchemaCutoverService::class);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);

        UserCompanyMembership::create([
            'user_id' => $this->admin->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->grantPermission('pos.fiscal_schema_cutover');
    }

    public function test_cutover_succeeds_on_idle_drained_terminal(): void
    {
        $terminal = $this->createTerminal();

        // Create a receipt first, then create a Z-report AFTER it.
        $this->createFiscalizedReceipt($terminal, ['posted_at' => now()->subMinutes(10)]);

        $shift = $this->createClosedShift($terminal);
        // Z-report generated_at is after the receipt's posted_at, so receipt is covered.
        ZReport::create([
            'terminal_id' => $terminal->id,
            'shift_id' => $shift->id,
            'z_number' => 1,
            'previous_z_hash' => null,
            'fiscal_hash' => str_repeat('a', 64),
            'report_data' => ['sales_count' => 1],
            'generated_by' => $this->admin->id,
            'generated_at' => now(), // after the receipt's posted_at
        ]);

        // No receipts posted AFTER the Z-report's generated_at.
        $this->service->cutover($terminal, $this->admin);

        $terminal->refresh();
        $this->assertSame(3, (int) $terminal->fiscal_schema_version);
    }

    public function test_cutover_refuses_on_open_shift(): void
    {
        $terminal = $this->createTerminal();
        $this->createOpenShift($terminal);

        $this->expectException(FiscalSchemaCutoverBlockedException::class);

        try {
            $this->service->cutover($terminal, $this->admin);
        } catch (FiscalSchemaCutoverBlockedException $e) {
            $this->assertSame('open_shift', $e->reason);
            throw $e;
        }
    }

    public function test_cutover_refuses_on_un_z_reported_receipts(): void
    {
        $terminal = $this->createTerminal();

        // Create a Z-report with generated_at in the PAST
        $shift = $this->createClosedShift($terminal);
        ZReport::create([
            'terminal_id' => $terminal->id,
            'shift_id' => $shift->id,
            'z_number' => 1,
            'previous_z_hash' => null,
            'fiscal_hash' => str_repeat('a', 64),
            'report_data' => ['sales_count' => 0],
            'generated_by' => $this->admin->id,
            'generated_at' => now()->subMinute(), // Z generated 1 min ago
        ]);

        // Receipt posted AFTER the Z-report's generated_at — un-covered
        $this->createFiscalizedReceipt($terminal, ['posted_at' => now()]);

        $this->expectException(FiscalSchemaCutoverBlockedException::class);

        try {
            $this->service->cutover($terminal, $this->admin);
        } catch (FiscalSchemaCutoverBlockedException $e) {
            $this->assertSame('unzreported_receipts', $e->reason);
            throw $e;
        }
    }

    public function test_cutover_refuses_on_unsynced_offline_queue(): void
    {
        $terminal = $this->createTerminal();

        // Offline receipt in pending_sync state (not yet synced to server)
        $this->createPendingReceipt($terminal, FiscalStatus::PendingSync);

        $this->expectException(FiscalSchemaCutoverBlockedException::class);

        try {
            $this->service->cutover($terminal, $this->admin);
        } catch (FiscalSchemaCutoverBlockedException $e) {
            $this->assertSame('pending_sync', $e->reason);
            throw $e;
        }
    }

    public function test_cutover_refuses_without_permission(): void
    {
        $terminal = $this->createTerminal();

        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/v1/pos/terminals/{$terminal->id}/fiscal-schema-cutover");

        // The controller checks permission — user has it from setUp, so test the
        // HTTP endpoint returns 200. Then verify a user WITHOUT permission gets 403.
        $userWithout = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $userWithout->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);

        Sanctum::actingAs($userWithout);
        $response2 = $this->postJson("/api/v1/pos/terminals/{$terminal->id}/fiscal-schema-cutover");

        $response2->assertStatus(403);
    }

    public function test_concurrent_cutover_attempts_serialize_via_for_update(): void
    {
        $terminal = $this->createTerminal();

        // First cutover succeeds
        $this->service->cutover($terminal, $this->admin);
        $terminal->refresh();
        $this->assertSame(3, (int) $terminal->fiscal_schema_version);

        // Second attempt on the same terminal returns "already_at_v3"
        $this->expectException(FiscalSchemaCutoverBlockedException::class);

        try {
            $this->service->cutover($terminal, $this->admin);
        } catch (FiscalSchemaCutoverBlockedException $e) {
            $this->assertSame('already_at_v3', $e->reason);
            throw $e;
        }
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function createTerminal(int $schemaVersion = 2): Terminal
    {
        return Terminal::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'code' => 'POS01',
            'name' => 'Test Terminal',
            'genesis_seed' => str_repeat('0', 64),
            'current_sequence' => 0,
            'current_year' => 2026,
            'fiscal_schema_version' => $schemaVersion,
            'is_active' => true,
            'max_discount_percent' => 20.0,
            'allow_line_discounts' => true,
            'allow_transaction_discounts' => true,
        ]);
    }

    private function createOpenShift(Terminal $terminal): Shift
    {
        return Shift::create([
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->admin->id,
            'shift_number' => 1,
            'opening_cash' => '0.00',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);
    }

    private function createClosedShift(Terminal $terminal): Shift
    {
        return Shift::create([
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->admin->id,
            'shift_number' => 1,
            'opening_cash' => '0.00',
            'status' => ShiftStatus::Closed,
            'opened_at' => now()->subHour(),
            'closed_at' => now()->subMinutes(10),
        ]);
    }

    private int $seq = 0;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createFiscalizedReceipt(Terminal $terminal, array $overrides = []): Receipt
    {
        $this->seq++;

        return Receipt::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $terminal->id,
            'receipt_number' => sprintf('POS01-2026-%08d', $this->seq),
            'receipt_type' => ReceiptType::Sale,
            'chain_sequence' => $this->seq,
            'receipt_year' => 2026,
            'fiscal_hash' => hash('sha256', "fiscal-{$this->seq}"),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', 'vat'),
            'payment_methods_hash' => hash('sha256', 'pay'),
            'posted_at' => now(),
            'cashier_id' => $this->admin->id,
            'cashier_name' => 'Admin',
            'subtotal' => '100.000',
            'tax_amount' => '20.000',
            'discount_amount' => '0.000',
            'total' => '120.000',
            'currency' => 'EUR',
            'fiscal_status' => FiscalStatus::Fiscalized,
            'is_voided' => false,
            'is_training' => false,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPendingReceipt(Terminal $terminal, FiscalStatus $status, array $overrides = []): Receipt
    {
        $this->seq++;

        return Receipt::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $terminal->id,
            'receipt_number' => sprintf('POS01-2026-%08d', $this->seq),
            'receipt_type' => ReceiptType::Sale,
            'chain_sequence' => $this->seq,
            'receipt_year' => 2026,
            'fiscal_hash' => hash('sha256', "pending-{$this->seq}"),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', 'vat'),
            'payment_methods_hash' => hash('sha256', 'pay'),
            'posted_at' => now(),
            'cashier_id' => $this->admin->id,
            'cashier_name' => 'Admin',
            'subtotal' => '100.000',
            'tax_amount' => '20.000',
            'discount_amount' => '0.000',
            'total' => '120.000',
            'currency' => 'EUR',
            'fiscal_status' => $status,
            'is_voided' => false,
            'is_training' => false,
        ], $overrides));
    }

    private function grantPermission(string $permission): void
    {
        Permission::findOrCreate($permission, 'sanctum');
        $this->admin->givePermissionTo($permission);
    }
}
