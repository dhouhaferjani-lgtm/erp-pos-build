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

class ListVehiclesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

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
    }

    public function test_can_list_vehicles(): void
    {
        Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'license_plate' => 'ABC-123',
            'brand' => 'Toyota',
            'model' => 'Corolla',
        ]);

        Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'license_plate' => 'XYZ-789',
            'brand' => 'Honda',
            'model' => 'Civic',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/vehicles');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'license_plate', 'brand', 'model', 'year', 'created_at'],
                ],
                'meta' => ['current_page', 'per_page', 'total'],
            ]);
    }

    public function test_list_is_paginated(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            Vehicle::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'license_plate' => "PLT-{$i}",
                'brand' => 'Brand',
                'model' => 'Model',
            ]);
        }

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/vehicles');

        $response->assertOk()
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.per_page', 15);

        $this->assertCount(15, $response->json('data'));
    }

    public function test_can_filter_by_partner(): void
    {
        $otherCustomer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Jane Smith',
            'type' => PartnerType::Customer,
        ]);

        Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'license_plate' => 'ABC-123',
            'brand' => 'Toyota',
            'model' => 'Corolla',
        ]);

        Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $otherCustomer->id,
            'license_plate' => 'XYZ-789',
            'brand' => 'Honda',
            'model' => 'Civic',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/vehicles?partner_id={$this->customer->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.license_plate', 'ABC-123');
    }

    public function test_can_search_by_license_plate(): void
    {
        Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'license_plate' => 'ABC-123',
            'brand' => 'Toyota',
            'model' => 'Corolla',
        ]);

        Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'license_plate' => 'XYZ-789',
            'brand' => 'Honda',
            'model' => 'Civic',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/vehicles?search=ABC');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.license_plate', 'ABC-123');
    }

    public function test_can_search_by_vin(): void
    {
        Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'license_plate' => 'ABC-123',
            'brand' => 'Toyota',
            'model' => 'Corolla',
            'vin' => 'JT2BF22K1W0123456',
        ]);

        Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'license_plate' => 'XYZ-789',
            'brand' => 'Honda',
            'model' => 'Civic',
            'vin' => 'WVWZZZ3CZWE123456',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/vehicles?search=JT2BF22');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.brand', 'Toyota');
    }

    public function test_only_shows_vehicles_from_current_tenant(): void
    {
        Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'license_plate' => 'MY-123',
            'brand' => 'Toyota',
            'model' => 'Corolla',
        ]);

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

        Vehicle::create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'license_plate' => 'OTHER-123',
            'brand' => 'Honda',
            'model' => 'Civic',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/vehicles');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.license_plate', 'MY-123');
    }

    public function test_can_get_single_vehicle(): void
    {
        $vehicle = Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'license_plate' => 'ABC-123',
            'brand' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2020,
            'color' => 'Blue',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/vehicles/{$vehicle->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $vehicle->id)
            ->assertJsonPath('data.license_plate', 'ABC-123')
            ->assertJsonPath('data.brand', 'Toyota')
            ->assertJsonPath('data.year', 2020);
    }

    public function test_returns_404_for_nonexistent_vehicle(): void
    {
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/vehicles/{$fakeId}");

        $response->assertNotFound();
    }

    public function test_cannot_view_vehicle_from_another_tenant(): void
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
            ->getJson("/api/v1/vehicles/{$otherVehicle->id}");

        $response->assertNotFound();
    }

    public function test_unauthenticated_user_cannot_list_vehicles(): void
    {
        $response = $this->getJson('/api/v1/vehicles');

        $response->assertUnauthorized();
    }

    public function test_viewer_can_list_vehicles(): void
    {
        Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'license_plate' => 'ABC-123',
            'brand' => 'Toyota',
            'model' => 'Corolla',
        ]);

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
            ->getJson('/api/v1/vehicles');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
