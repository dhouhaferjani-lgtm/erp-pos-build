<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ZReportServerAuthoringDispositionTest extends TestCase
{
    use RefreshDatabase;

    private Terminal $terminal;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'fiscal_schema_version' => 3,
        ]);

        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $company->id,
            'role' => 'admin',
        ]);

        $this->app->make(PermissionRegistrar::class)
            ->setPermissionsTeamId($tenant->id);
        $this->user->assignRole('manager');

        Sanctum::actingAs($this->user);
    }

    public function test_server_z_report_generation_is_rejected_for_cutover_terminal(): void
    {
        $response = $this->postJson('/api/v1/pos/reports/z', [
            'terminal_id' => $this->terminal->id,
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'Z_SESSION_DEVICE_AUTHORITY_REQUIRED');
        $this->assertSame(0, DB::table('pos_z_reports')->count());
        $this->assertSame(0, DB::table('pos_grandtotal_events')->count());
    }

    public function test_server_x_report_generation_is_rejected_for_cutover_terminal(): void
    {
        $response = $this->postJson('/api/v1/pos/reports/x', [
            'terminal_id' => $this->terminal->id,
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'Z_SESSION_DEVICE_AUTHORITY_REQUIRED');
        $this->assertSame(0, DB::table('pos_x_reports')->count());
    }

    public function test_legacy_z_report_sync_is_rejected_for_cutover_terminal_before_legacy_payload_validation(): void
    {
        $response = $this->postJson('/api/v1/pos/reports/z/sync', [
            'terminal_id' => $this->terminal->id,
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'Z_SESSION_DEVICE_AUTHORITY_REQUIRED');
        $this->assertSame(0, DB::table('pos_z_reports')->count());
    }
}
