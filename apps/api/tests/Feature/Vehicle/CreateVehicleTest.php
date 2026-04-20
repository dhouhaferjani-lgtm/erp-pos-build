<?php

declare(strict_types=1);

namespace Tests\Feature\Vehicle;

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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class CreateVehicleTest extends TestCase
{
    use AssertsApiValidation;
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

    public function test_license_plate_is_required(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/vehicles', [
                'partner_id' => $this->customer->id,
                'brand' => 'Toyota',
                'model' => 'Corolla',
            ]);

        $this->assertApiValidationErrors($response, ['license_plate']);
    }

    public function test_brand_is_required(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/vehicles', [
                'partner_id' => $this->customer->id,
                'license_plate' => 'ABC-123',
                'model' => 'Corolla',
            ]);

        $this->assertApiValidationErrors($response, ['brand']);
    }

    public function test_license_plate_must_be_unique_within_tenant(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/vehicles', [
                'partner_id' => $this->customer->id,
                'license_plate' => 'ABC-123',
                'brand' => 'Toyota',
                'model' => 'Corolla',
            ])
            ->assertCreated();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/vehicles', [
                'partner_id' => $this->customer->id,
                'license_plate' => 'ABC-123',
                'brand' => 'Honda',
                'model' => 'Civic',
            ]);

        $this->assertApiValidationErrors($response, ['license_plate']);
    }

    public function test_vin_format_validation(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/vehicles', [
                'partner_id' => $this->customer->id,
                'license_plate' => 'ABC-123',
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'vin' => 'INVALID',
            ]);

        $this->assertApiValidationErrors($response, ['vin']);
    }

    public function test_vin_must_be_unique_within_tenant(): void
    {
        $vin = 'JT2BF22K1W0123456';

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/vehicles', [
                'partner_id' => $this->customer->id,
                'license_plate' => 'ABC-123',
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'vin' => $vin,
            ])
            ->assertCreated();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/vehicles', [
                'partner_id' => $this->customer->id,
                'license_plate' => 'XYZ-789',
                'brand' => 'Honda',
                'model' => 'Civic',
                'vin' => $vin,
            ]);

        $this->assertApiValidationErrors($response, ['vin']);
    }

    public function test_year_must_be_valid(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/vehicles', [
                'partner_id' => $this->customer->id,
                'license_plate' => 'ABC-123',
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'year' => 1800,
            ]);

        $this->assertApiValidationErrors($response, ['year']);
    }

    public function test_mileage_must_be_non_negative(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/vehicles', [
                'partner_id' => $this->customer->id,
                'license_plate' => 'ABC-123',
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'mileage' => -100,
            ]);

        $this->assertApiValidationErrors($response, ['mileage']);
    }

    public function test_partner_must_exist(): void
    {
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/vehicles', [
                'partner_id' => $fakeId,
                'license_plate' => 'ABC-123',
                'brand' => 'Toyota',
                'model' => 'Corolla',
            ]);

        $this->assertApiValidationErrors($response, ['partner_id']);
    }

    public function test_successful_creation_returns_201(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/vehicles', [
                'partner_id' => $this->customer->id,
                'license_plate' => 'ABC-123',
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'year' => 2020,
                'color' => 'Blue',
                'mileage' => 50000,
                'vin' => 'JT2BF22K1W0123456',
                'engine_code' => '1ZZ-FE',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.license_plate', 'ABC-123')
            ->assertJsonPath('data.brand', 'Toyota')
            ->assertJsonPath('data.model', 'Corolla')
            ->assertJsonPath('data.year', 2020)
            ->assertJsonPath('data.mileage', 50000)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'partner_id',
                    'license_plate',
                    'brand',
                    'model',
                    'year',
                    'color',
                    'mileage',
                    'vin',
                    'engine_code',
                    'created_at',
                ],
                'meta',
            ]);

        $this->assertDatabaseHas('vehicles', [
            'license_plate' => 'ABC-123',
            'brand' => 'Toyota',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_can_create_vehicle_without_partner(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/vehicles', [
                'license_plate' => 'XYZ-789',
                'brand' => 'Honda',
                'model' => 'Civic',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.partner_id', null);
    }

    public function test_unauthenticated_user_cannot_create_vehicle(): void
    {
        $response = $this->postJson('/api/v1/vehicles', [
            'partner_id' => $this->customer->id,
            'license_plate' => 'ABC-123',
            'brand' => 'Toyota',
            'model' => 'Corolla',
        ]);

        $response->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_create_vehicle(): void
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
            ->postJson('/api/v1/vehicles', [
                'partner_id' => $this->customer->id,
                'license_plate' => 'ABC-123',
                'brand' => 'Toyota',
                'model' => 'Corolla',
            ]);

        $response->assertForbidden();
    }
}
