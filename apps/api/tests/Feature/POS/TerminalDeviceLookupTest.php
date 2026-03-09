<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class TerminalDeviceLookupTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $this->user->givePermissionTo('pos.operate_terminal');

        Sanctum::actingAs($this->user);
    }

    public function test_find_by_device_returns_terminal_with_matching_hardware_identifier(): void
    {
        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'hardware_identifier' => 'HW-MATCH-001',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/pos/terminals/by-device/HW-MATCH-001');

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $terminal->id);
    }

    public function test_find_by_device_returns_null_when_no_match(): void
    {
        $response = $this->getJson('/api/v1/pos/terminals/by-device/HW-UNKNOWN-999');

        $response->assertStatus(200);
        $response->assertJsonPath('data', null);
    }

    public function test_find_by_device_scoped_to_company(): void
    {
        // Create terminal in a different company with the same hardware ID
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'location_id' => $this->location->id,
            'hardware_identifier' => 'HW-CROSS-COMPANY',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/pos/terminals/by-device/HW-CROSS-COMPANY');

        $response->assertStatus(200);
        $response->assertJsonPath('data', null);
    }

    public function test_find_by_device_requires_operate_terminal_permission(): void
    {
        $userWithoutPermission = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $userWithoutPermission->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        Sanctum::actingAs($userWithoutPermission);

        $response = $this->getJson('/api/v1/pos/terminals/by-device/HW-ANY');

        $response->assertStatus(403);
    }
}
