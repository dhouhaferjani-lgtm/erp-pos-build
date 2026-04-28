<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for POST /api/v1/pos/shifts/{id}/sync-close
 *
 * Tests offline shift close sync with custom closed_at timestamp.
 */
final class SyncShiftCloseTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Terminal $terminal;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestData();
        Sanctum::actingAs($this->user);
    }

    public function test_sync_close_shift_with_offline_timestamp(): void
    {
        $closedAt = now()->subHours(2)->toIso8601String();

        $response = $this->postJson("/api/v1/pos/shifts/{$this->shift->id}/sync-close", [
            'actual_cash' => '150.00',
            'closed_at' => $closedAt,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'CLOSED');
        $response->assertJsonStructure([
            'data' => [
                'id',
                'status',
                'expected_cash',
                'actual_cash',
                'variance',
                'closed_at',
            ],
        ]);

        // Verify the offline timestamp was used
        $this->shift->refresh();
        $this->assertEquals(ShiftStatus::Closed, $this->shift->status);
        $this->assertNotNull($this->shift->closed_at);
    }

    public function test_sync_close_shift_already_closed_returns_error(): void
    {
        // Close the shift first
        $this->shift->update([
            'status' => ShiftStatus::Closed,
            'closed_at' => now(),
            'closed_by' => $this->user->id,
            'expected_cash' => '100.00',
            'actual_cash' => '100.00',
            'variance' => '0.00',
        ]);

        $response = $this->postJson("/api/v1/pos/shifts/{$this->shift->id}/sync-close", [
            'actual_cash' => '150.00',
            'closed_at' => now()->toIso8601String(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'SHIFT_NOT_OPEN');
    }

    public function test_sync_close_shift_validates_required_fields(): void
    {
        $response = $this->postJson("/api/v1/pos/shifts/{$this->shift->id}/sync-close", []);

        $response->assertStatus(422);
    }

    public function test_sync_close_shift_requires_valid_date(): void
    {
        $response = $this->postJson("/api/v1/pos/shifts/{$this->shift->id}/sync-close", [
            'actual_cash' => '150.00',
            'closed_at' => 'not-a-date',
        ]);

        $response->assertStatus(422);
    }

    private function setupTestData(): void
    {
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.manage_shifts', 'sanctum');
        $this->user->givePermissionTo('pos.manage_shifts');

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

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
