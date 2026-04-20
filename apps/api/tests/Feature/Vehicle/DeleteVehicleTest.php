<?php

declare(strict_types=1);

namespace Tests\Feature\Vehicle;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Vehicle\Domain\Vehicle;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DeleteVehicleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->vehicle = Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'license_plate' => 'DEL-123',
            'brand' => 'Toyota',
            'model' => 'Corolla',
        ]);
    }

    public function test_can_delete_vehicle(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/vehicles/{$this->vehicle->id}");

        $response->assertNoContent();

        $this->assertSoftDeleted('vehicles', [
            'id' => $this->vehicle->id,
        ]);
    }

    public function test_returns_404_for_nonexistent_vehicle(): void
    {
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/vehicles/{$fakeId}");

        $response->assertNotFound();
    }

    public function test_unauthenticated_user_cannot_delete_vehicle(): void
    {
        $response = $this->deleteJson("/api/v1/vehicles/{$this->vehicle->id}");

        $response->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_delete_vehicle(): void
    {
        $viewerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Viewer User',
            'email' => 'viewer@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $viewerUser->assignRole('viewer');

        UserCompanyMembership::create([
            'user_id' => $viewerUser->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        $response = $this->actingAs($viewerUser, 'sanctum')
            ->deleteJson("/api/v1/vehicles/{$this->vehicle->id}");

        $response->assertForbidden();
    }

    public function test_cannot_delete_vehicle_from_another_tenant(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $otherCompany = Company::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'tax_id' => 'TAX456',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $otherVehicle = Vehicle::create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'license_plate' => 'OTHER-123',
            'brand' => 'Honda',
            'model' => 'Civic',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/vehicles/{$otherVehicle->id}");

        $response->assertNotFound();

        $this->assertDatabaseHas('vehicles', [
            'id' => $otherVehicle->id,
            'deleted_at' => null,
        ]);
    }

    public function test_deleted_vehicle_not_shown_in_list(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/vehicles/{$this->vehicle->id}")
            ->assertNoContent();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/vehicles');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
