<?php

declare(strict_types=1);

namespace Tests\Feature\Vehicle;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Vehicle\Domain\Vehicle;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class UpdateVehicleTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
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

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'John Doe',
            'type' => PartnerType::Customer,
        ]);

        $this->vehicle = Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'license_plate' => 'ABC-123',
            'brand' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2020,
        ]);
    }

    public function test_can_update_vehicle_brand(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/vehicles/{$this->vehicle->id}", [
                'brand' => 'Honda',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.brand', 'Honda');

        $this->assertDatabaseHas('vehicles', [
            'id' => $this->vehicle->id,
            'brand' => 'Honda',
        ]);
    }

    public function test_can_update_vehicle_mileage(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/vehicles/{$this->vehicle->id}", [
                'mileage' => 75000,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.mileage', 75000);
    }

    public function test_license_plate_uniqueness_on_update(): void
    {
        Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'license_plate' => 'XYZ-789',
            'brand' => 'Honda',
            'model' => 'Civic',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/vehicles/{$this->vehicle->id}", [
                'license_plate' => 'XYZ-789',
            ]);

        $this->assertApiValidationErrors($response, ['license_plate']);
    }

    public function test_can_update_own_license_plate_to_same_value(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/vehicles/{$this->vehicle->id}", [
                'license_plate' => 'ABC-123',
                'color' => 'Red',
            ]);

        $response->assertOk();
    }

    public function test_can_assign_vehicle_to_different_partner(): void
    {
        $newCustomer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Jane Smith',
            'type' => PartnerType::Customer,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/vehicles/{$this->vehicle->id}", [
                'partner_id' => $newCustomer->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.partner_id', $newCustomer->id);
    }

    public function test_returns_404_for_nonexistent_vehicle(): void
    {
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/vehicles/{$fakeId}", [
                'brand' => 'Honda',
            ]);

        $response->assertNotFound();
    }

    public function test_unauthenticated_user_cannot_update_vehicle(): void
    {
        $response = $this->patchJson("/api/v1/vehicles/{$this->vehicle->id}", [
            'brand' => 'Honda',
        ]);

        $response->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_update_vehicle(): void
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
            ->patchJson("/api/v1/vehicles/{$this->vehicle->id}", [
                'brand' => 'Honda',
            ]);

        $response->assertForbidden();
    }

    public function test_cannot_update_vehicle_from_another_tenant(): void
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
            ->patchJson("/api/v1/vehicles/{$otherVehicle->id}", [
                'brand' => 'Hacked',
            ]);

        $response->assertNotFound();
    }
}
