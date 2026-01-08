<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Vehicle\Domain\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature test for RequireModule middleware protecting vertical-specific routes.
 *
 * These tests verify end-to-end that:
 * 1. Users can access module routes when their tenant's vertical includes the module
 * 2. Users are blocked (403) when their tenant's vertical doesn't include the module
 * 3. Authentication is required (401) for module-protected routes
 */
class ModuleAccessControlTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $mechanicTenant;

    private Tenant $retailTenant;

    private Company $mechanicCompany;

    private Company $retailCompany;

    private User $mechanicUser;

    private User $retailUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mechanic tenant (has Vehicle module)
        $this->mechanicTenant = Tenant::factory()->create([
            'vertical' => 'mechanic',
            'enabled_extras' => json_encode([]),
        ]);

        $this->mechanicCompany = Company::factory()->create([
            'tenant_id' => $this->mechanicTenant->id,
        ]);

        $this->mechanicUser = User::factory()->create([
            'tenant_id' => $this->mechanicTenant->id,
        ]);

        // Add user to mechanic company
        UserCompanyMembership::create([
            'user_id' => $this->mechanicUser->id,
            'company_id' => $this->mechanicCompany->id,
            'role' => 'admin',
        ]);

        // Create retail tenant (does NOT have Vehicle module)
        $this->retailTenant = Tenant::factory()->create([
            'vertical' => 'retail',
            'enabled_extras' => json_encode([]),
        ]);

        $this->retailCompany = Company::factory()->create([
            'tenant_id' => $this->retailTenant->id,
        ]);

        $this->retailUser = User::factory()->create([
            'tenant_id' => $this->retailTenant->id,
        ]);

        // Add user to retail company
        UserCompanyMembership::create([
            'user_id' => $this->retailUser->id,
            'company_id' => $this->retailCompany->id,
            'role' => 'admin',
        ]);

        // Create permissions (shared across tenants, but assignments are per-tenant)
        \Spatie\Permission\Models\Permission::create(['name' => 'vehicles.view', 'guard_name' => 'sanctum']);
        \Spatie\Permission\Models\Permission::create(['name' => 'products.view', 'guard_name' => 'sanctum']);
    }

    public function test_mechanic_user_can_access_vehicle_routes(): void
    {
        // Set permissions team and give user permission to view vehicles
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->mechanicTenant->id);
        $this->mechanicUser->givePermissionTo('vehicles.view');

        Sanctum::actingAs($this->mechanicUser);

        $response = $this->getJson('/api/v1/vehicles', [
            'X-Company-Id' => $this->mechanicCompany->id,
        ]);

        // Should get 200 OK - module middleware allows, permission middleware allows
        $response->assertStatus(200);
    }

    public function test_retail_user_cannot_access_vehicle_routes(): void
    {
        Sanctum::actingAs($this->retailUser);

        $response = $this->getJson('/api/v1/vehicles', [
            'X-Company-Id' => $this->retailCompany->id,
        ]);

        // Should get 403 Forbidden from module middleware
        $response->assertStatus(403);
        $response->assertJson([
            'message' => "Module 'Vehicle' is not enabled for this business type",
        ]);
    }

    public function test_retail_user_cannot_access_specific_vehicle(): void
    {
        Sanctum::actingAs($this->retailUser);

        // Create a vehicle for the mechanic tenant
        $partner = Partner::factory()->create([
            'tenant_id' => $this->mechanicTenant->id,
            'company_id' => $this->mechanicCompany->id,
        ]);
        $vehicle = Vehicle::factory()->create([
            'tenant_id' => $this->mechanicTenant->id,
            'company_id' => $this->mechanicCompany->id,
            'partner_id' => $partner->id,
        ]);

        $response = $this->getJson("/api/v1/vehicles/{$vehicle->id}", [
            'X-Company-Id' => $this->retailCompany->id,
        ]);

        // Should get 403 Forbidden from module middleware
        $response->assertStatus(403);
        $response->assertJson([
            'message' => "Module 'Vehicle' is not enabled for this business type",
        ]);
    }

    public function test_retail_user_cannot_create_vehicle(): void
    {
        Sanctum::actingAs($this->retailUser);

        $partner = Partner::factory()->create([
            'tenant_id' => $this->retailTenant->id,
            'company_id' => $this->retailCompany->id,
        ]);

        $response = $this->postJson('/api/v1/vehicles', [
            'partner_id' => $partner->id,
            'make' => 'Toyota',
            'model' => 'Camry',
            'year' => 2020,
            'registration' => 'ABC123',
        ], [
            'X-Company-Id' => $this->retailCompany->id,
        ]);

        // Should get 403 Forbidden from module middleware
        $response->assertStatus(403);
        $response->assertJson([
            'message' => "Module 'Vehicle' is not enabled for this business type",
        ]);
    }

    public function test_retail_user_cannot_update_vehicle(): void
    {
        Sanctum::actingAs($this->retailUser);

        $partner = Partner::factory()->create([
            'tenant_id' => $this->mechanicTenant->id,
            'company_id' => $this->mechanicCompany->id,
        ]);
        $vehicle = Vehicle::factory()->create([
            'tenant_id' => $this->mechanicTenant->id,
            'company_id' => $this->mechanicCompany->id,
            'partner_id' => $partner->id,
        ]);

        $response = $this->patchJson("/api/v1/vehicles/{$vehicle->id}", [
            'make' => 'Honda',
        ], [
            'X-Company-Id' => $this->retailCompany->id,
        ]);

        // Should get 403 Forbidden from module middleware
        $response->assertStatus(403);
        $response->assertJson([
            'message' => "Module 'Vehicle' is not enabled for this business type",
        ]);
    }

    public function test_retail_user_cannot_delete_vehicle(): void
    {
        Sanctum::actingAs($this->retailUser);

        $partner = Partner::factory()->create([
            'tenant_id' => $this->mechanicTenant->id,
            'company_id' => $this->mechanicCompany->id,
        ]);
        $vehicle = Vehicle::factory()->create([
            'tenant_id' => $this->mechanicTenant->id,
            'company_id' => $this->mechanicCompany->id,
            'partner_id' => $partner->id,
        ]);

        $response = $this->deleteJson("/api/v1/vehicles/{$vehicle->id}", [], [
            'X-Company-Id' => $this->retailCompany->id,
        ]);

        // Should get 403 Forbidden from module middleware
        $response->assertStatus(403);
        $response->assertJson([
            'message' => "Module 'Vehicle' is not enabled for this business type",
        ]);
    }

    public function test_unauthenticated_user_cannot_access_vehicle_routes(): void
    {
        // No authentication

        $response = $this->getJson('/api/v1/vehicles');

        // Should get 401 Unauthorized from auth middleware
        // (before module middleware even runs)
        $response->assertStatus(401);
    }

    public function test_mechanic_with_fleet_extra_can_access_vehicle_routes(): void
    {
        // Update mechanic tenant to have Fleet extra enabled
        $this->mechanicTenant->update([
            'enabled_extras' => json_encode(['Fleet']),
        ]);

        // Set permissions team and give user permission to view vehicles
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->mechanicTenant->id);
        $this->mechanicUser->givePermissionTo('vehicles.view');

        Sanctum::actingAs($this->mechanicUser);

        $response = $this->getJson('/api/v1/vehicles', [
            'X-Company-Id' => $this->mechanicCompany->id,
        ]);

        // Should still have access (Vehicle is in default modules anyway)
        $response->assertStatus(200);
    }

    public function test_pharmacy_user_cannot_access_vehicle_routes(): void
    {
        // Create pharmacy tenant (does NOT have Vehicle module)
        $pharmacyTenant = Tenant::factory()->create([
            'vertical' => 'pharmacy',
            'enabled_extras' => json_encode([]),
        ]);

        $pharmacyCompany = Company::factory()->create([
            'tenant_id' => $pharmacyTenant->id,
        ]);

        $pharmacyUser = User::factory()->create([
            'tenant_id' => $pharmacyTenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $pharmacyUser->id,
            'company_id' => $pharmacyCompany->id,
            'role' => 'admin',
        ]);

        Sanctum::actingAs($pharmacyUser);

        $response = $this->getJson('/api/v1/vehicles', [
            'X-Company-Id' => $pharmacyCompany->id,
        ]);

        // Should get 403 Forbidden from module middleware
        $response->assertStatus(403);
        $response->assertJson([
            'message' => "Module 'Vehicle' is not enabled for this business type",
        ]);
    }

    public function test_core_modules_accessible_to_all_verticals(): void
    {
        // Both mechanic and retail should be able to access core modules
        // like products (Catalog module)

        // Set permissions team and give retail user permission to view products
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->retailTenant->id);
        $this->retailUser->givePermissionTo('products.view');

        Sanctum::actingAs($this->retailUser);

        $response = $this->getJson('/api/v1/products', [
            'X-Company-Id' => $this->retailCompany->id,
        ]);

        // Should get 200 OK - Catalog is a core module
        $response->assertStatus(200);

        // Set permissions team and give mechanic user permission to view products
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->mechanicTenant->id);
        $this->mechanicUser->givePermissionTo('products.view');

        Sanctum::actingAs($this->mechanicUser);

        $response = $this->getJson('/api/v1/products', [
            'X-Company-Id' => $this->mechanicCompany->id,
        ]);

        // Should get 200 OK - Catalog is a core module
        $response->assertStatus(200);
    }
}
