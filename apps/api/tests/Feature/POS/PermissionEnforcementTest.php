<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests verifying POS controller permission enforcement.
 *
 * Tests that endpoints return 403 when user lacks required permissions.
 * This proves Gate::authorize() calls are present and functional.
 */
final class PermissionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private User $userWithoutPermissions;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $this->userWithoutPermissions = User::factory()->create(['tenant_id' => $tenant->id]);

        // Create company membership
        \App\Modules\Company\Domain\UserCompanyMembership::create([
            'user_id' => $this->userWithoutPermissions->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        Sanctum::actingAs($this->userWithoutPermissions);
    }

    public function test_terminal_index_requires_manage_terminals_permission(): void
    {
        $response = $this->getJson('/api/v1/pos/terminals');

        $response->assertStatus(403);
    }

    public function test_web_terminal_requires_operate_terminal_permission(): void
    {
        $response = $this->postJson('/api/v1/pos/terminals/web', [
            'location_id' => '00000000-0000-0000-0000-000000000001',
        ]);

        $response->assertStatus(403);
    }

    public function test_shift_index_requires_operate_terminal_permission(): void
    {
        $response = $this->getJson('/api/v1/pos/shifts');

        $response->assertStatus(403);
    }

    public function test_receipt_index_requires_view_receipts_permission(): void
    {
        $response = $this->getJson('/api/v1/pos/receipts');

        $response->assertStatus(403);
    }

    public function test_receipt_void_requires_void_receipts_permission(): void
    {
        $response = $this->postJson('/api/v1/pos/receipts/00000000-0000-0000-0000-000000000001/void', [
            'reason' => 'Test void',
        ]);

        $response->assertStatus(403);
    }

    public function test_report_x_requires_view_reports_permission(): void
    {
        $response = $this->postJson('/api/v1/pos/reports/x', [
            'terminal_id' => '00000000-0000-0000-0000-000000000001',
        ]);

        $response->assertStatus(403);
    }

    public function test_cash_drawer_balance_requires_operate_terminal_permission(): void
    {
        $response = $this->getJson('/api/v1/pos/cash-drawer/00000000-0000-0000-0000-000000000001/balance');

        $response->assertStatus(403);
    }
}
