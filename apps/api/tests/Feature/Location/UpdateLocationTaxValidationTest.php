<?php

declare(strict_types=1);

namespace Tests\Feature\Location;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class UpdateLocationTaxValidationTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => '732829320',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
    }

    public function test_rejects_malformed_tax_id_on_update_when_country_is_submitted(): void
    {
        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'B',
            'code' => 'B',
            'type' => LocationType::Shop,
            'address_country' => 'FR',
            'is_default' => false,
            'is_active' => true,
            'tax_id' => '73282932000074',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson("/api/v1/locations/{$location->id}", [
                'address_country' => 'FR',
                'tax_id' => 'BAD',
            ]);

        $this->assertApiValidationErrors($response, ['tax_id']);
    }

    public function test_rejects_malformed_tax_id_on_update_using_existing_country(): void
    {
        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'B',
            'code' => 'B',
            'type' => LocationType::Shop,
            'address_country' => 'FR',
            'is_default' => false,
            'is_active' => true,
            'tax_id' => '73282932000074',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson("/api/v1/locations/{$location->id}", [
                'tax_id' => 'BAD',
            ]);

        $this->assertApiValidationErrors($response, ['tax_id']);
    }

    public function test_numeric_tax_id_returns_validation_error_instead_of_server_error(): void
    {
        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'B',
            'code' => 'B',
            'type' => LocationType::Shop,
            'address_country' => 'FR',
            'is_default' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson("/api/v1/locations/{$location->id}", [
                'tax_id' => 123,
            ]);

        $this->assertApiValidationErrors($response, ['tax_id']);
    }

    public function test_accepts_valid_siret_on_update_using_existing_country(): void
    {
        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'B',
            'code' => 'B',
            'type' => LocationType::Shop,
            'address_country' => 'FR',
            'is_default' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson("/api/v1/locations/{$location->id}", [
                'tax_id' => '73282932000074',
            ]);

        $response->assertOk();
    }

    public function test_accepts_clearing_tax_id_to_inherit(): void
    {
        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'B',
            'code' => 'B',
            'type' => LocationType::Warehouse,
            'address_country' => 'FR',
            'is_default' => false,
            'is_active' => true,
            'tax_id' => '73282932000074',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson("/api/v1/locations/{$location->id}", ['tax_id' => null]);

        $response->assertOk();
    }
}
