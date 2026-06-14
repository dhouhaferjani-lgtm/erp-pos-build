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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 2 — REST `POST /pos/shifts/open` is retired for device-authoritative
 * (v3) terminals: shifts are minted + authored on the device. The endpoint
 * returns a hard 409 so a stray web/admin open can never fork a second shift
 * (Decision 3; mirrors ZReportSyncController's Z_SESSION_DEVICE_AUTHORITY_REQUIRED).
 * Legacy pre-cutover (v<3) terminals are unaffected.
 */
final class ShiftOpenV3GuardTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        // Demo tenant so the WEB_POS_DEMO_ONLY browser gate passes and the
        // request reaches the controller (the 409 is what we're testing).
        $tenant = Tenant::factory()->create(['is_demo' => true]);
        $this->company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $this->cashier = User::factory()->create(['tenant_id' => $tenant->id]);
        UserCompanyMembership::firstOrCreate([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
        ], ['role' => 'admin']);

        // Permissions are tenant-team scoped; pin the team before granting.
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->cashier->givePermissionTo('pos.manage_shifts');
    }

    private function makeTerminal(int $fiscalSchemaVersion): Terminal
    {
        $location = Location::factory()->create(['company_id' => $this->company->id]);

        return Terminal::factory()->create([
            'tenant_id' => $this->company->tenant_id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'fiscal_schema_version' => $fiscalSchemaVersion,
        ]);
    }

    public function test_v3_terminal_open_returns_device_authority_409(): void
    {
        $terminal = $this->makeTerminal(3);
        Sanctum::actingAs($this->cashier);

        $response = $this->postJson('/api/v1/pos/shifts/open', [
            'terminal_code' => $terminal->code,
            'opening_cash' => '100.00',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'SHIFT_DEVICE_AUTHORITY_REQUIRED');
    }

    public function test_v2_terminal_open_does_not_hit_the_device_authority_guard(): void
    {
        $terminal = $this->makeTerminal(2);
        Sanctum::actingAs($this->cashier);

        $response = $this->postJson('/api/v1/pos/shifts/open', [
            'terminal_code' => $terminal->code,
            'opening_cash' => '100.00',
        ]);

        $this->assertNotSame('SHIFT_DEVICE_AUTHORITY_REQUIRED', $response->json('error.code'));
        $this->assertNotSame(409, $response->getStatusCode());
    }

    public function test_v3_terminal_close_returns_device_authority_409(): void
    {
        $terminal = $this->makeTerminal(3);
        $shift = $this->makeOpenShift($terminal);
        Sanctum::actingAs($this->cashier);

        $response = $this->postJson("/api/v1/pos/shifts/{$shift->id}/close", [
            'actual_cash' => '100.00',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'SHIFT_DEVICE_AUTHORITY_REQUIRED');
    }

    public function test_v3_terminal_sync_close_returns_device_authority_409(): void
    {
        $terminal = $this->makeTerminal(3);
        $shift = $this->makeOpenShift($terminal);
        Sanctum::actingAs($this->cashier);

        $response = $this->postJson("/api/v1/pos/shifts/{$shift->id}/sync-close", [
            'actual_cash' => '100.00',
            'closed_at' => '2026-06-14T18:00:00Z',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'SHIFT_DEVICE_AUTHORITY_REQUIRED');
    }

    private function makeOpenShift(Terminal $terminal): Shift
    {
        return Shift::create([
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'opening_cash' => '100.0000',
            'status' => ShiftStatus::Open,
            'opened_at' => '2026-06-14 08:00:00',
        ]);
    }
}
