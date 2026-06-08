<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReportGenerationService;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for BG10 — shift close requires Z report.
 */
final class ShiftCloseRequiresZReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $user;

    private Terminal $terminal;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestData();
        Sanctum::actingAs($this->user);
    }

    public function test_close_without_z_report_returns_422(): void
    {
        $response = $this->postJson("/api/v1/pos/shifts/{$this->shift->id}/close", [
            'actual_cash' => '100.00',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'Z_REPORT_REQUIRED');
    }

    public function test_close_after_z_report_succeeds(): void
    {
        // Add a receipt to satisfy the shift (Z report can be empty but shift must be open)
        Receipt::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'receipt_number' => 'T001-C042-L01-POS01-2026-00000001',
            'chain_sequence' => 1,
            'receipt_year' => 2026,
            'fiscal_hash' => hash('sha256', 'receipt-1'),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', 'vat'),
            'payment_methods_hash' => hash('sha256', 'payment'),
            'posted_at' => now(),
            'cashier_id' => $this->user->id,
            'cashier_name' => 'Test User',
            'subtotal' => '42.02',
            'tax_amount' => '7.98',
            'discount_amount' => '0.00',
            'total' => '50.00',
            'currency' => 'TND',
            'is_voided' => false,
            'is_training' => false,
        ]);

        // Generate Z report first
        $reportService = $this->app->make(ReportGenerationService::class);
        $reportService->generateZReport($this->terminal, $this->user);

        // Now close the shift
        $response = $this->postJson("/api/v1/pos/shifts/{$this->shift->id}/close", [
            'actual_cash' => '110.00',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'CLOSED');
    }

    private function setupTestData(): void
    {
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.manage_shifts', 'sanctum');
        Permission::findOrCreate('pos.generate_z_report', 'sanctum');
        $this->user->givePermissionTo('pos.manage_shifts');
        $this->user->givePermissionTo('pos.generate_z_report');

        $this->location = Location::factory()->create(['company_id' => $this->company->id]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        $this->shift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
            'opening_cash' => '100.00',
        ]);
    }
}
