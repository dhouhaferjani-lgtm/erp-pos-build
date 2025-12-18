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

class LocationTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $defaultLocation;

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

        $this->defaultLocation = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Shop',
            'code' => 'MAIN',
            'type' => LocationType::Shop,
            'is_default' => true,
            'is_active' => true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
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

    public function test_can_list_locations_for_current_company(): void
    {
        // Create additional location
        Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse',
            'code' => 'WH-001',
            'type' => LocationType::Warehouse,
            'is_default' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/locations');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'company_id',
                        'name',
                        'code',
                        'type',
                        'is_default',
                        'is_active',
                    ],
                ],
                'meta',
            ]);
    }

    public function test_cannot_list_locations_for_other_company(): void
    {
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        Location::create([
            'company_id' => $otherCompany->id,
            'name' => 'Other Location',
            'code' => 'OTHER',
            'type' => LocationType::Shop,
            'is_default' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/locations');

        $response->assertOk();
        // Should only see locations from current company
        $names = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertContains('Main Shop', $names);
        $this->assertNotContains('Other Location', $names);
    }

    public function test_can_create_location_with_valid_data(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/locations', [
                'name' => 'New Warehouse',
                'type' => 'warehouse',
                'phone' => '+33 1 23 45 67 89',
                'email' => 'warehouse@example.com',
                'address_street' => '123 Industrial Ave',
                'address_city' => 'Paris',
                'address_postal_code' => '75001',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'New Warehouse')
            ->assertJsonPath('data.type', 'warehouse')
            ->assertJsonPath('data.phone', '+33 1 23 45 67 89')
            ->assertJsonPath('data.is_default', false)
            ->assertJsonPath('data.is_active', true);

        // Verify code was auto-generated
        $this->assertNotNull($response->json('data.code'));
    }

    public function test_cannot_create_location_without_name(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/locations', [
                'type' => 'warehouse',
            ]);

        $this->assertApiValidationErrors($response, ['name']);
    }

    public function test_cannot_create_location_without_type(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/locations', [
                'name' => 'New Location',
            ]);

        $this->assertApiValidationErrors($response, ['type']);
    }

    public function test_can_create_location_with_custom_code(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/locations', [
                'name' => 'Custom Code Location',
                'code' => 'CUSTOM-001',
                'type' => 'office',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.code', 'CUSTOM-001');
    }

    public function test_can_update_location(): void
    {
        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Original Name',
            'code' => 'ORIG',
            'type' => LocationType::Warehouse,
            'is_default' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson("/api/v1/locations/{$location->id}", [
                'name' => 'Updated Name',
                'phone' => '+33 9 87 65 43 21',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.phone', '+33 9 87 65 43 21');
    }

    public function test_cannot_update_location_from_other_company(): void
    {
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $otherLocation = Location::create([
            'company_id' => $otherCompany->id,
            'name' => 'Other Location',
            'code' => 'OTHER',
            'type' => LocationType::Shop,
            'is_default' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson("/api/v1/locations/{$otherLocation->id}", [
                'name' => 'Hacked Name',
            ]);

        $response->assertNotFound();
    }

    public function test_can_delete_non_default_location(): void
    {
        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Deletable Location',
            'code' => 'DEL',
            'type' => LocationType::Warehouse,
            'is_default' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->deleteJson("/api/v1/locations/{$location->id}");

        $response->assertNoContent();

        $this->assertDatabaseMissing('locations', [
            'id' => $location->id,
            'deleted_at' => null,
        ]);
    }

    public function test_cannot_delete_default_location(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->deleteJson("/api/v1/locations/{$this->defaultLocation->id}");

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'CANNOT_DELETE_DEFAULT_LOCATION');
    }

    public function test_can_get_single_location(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson("/api/v1/locations/{$this->defaultLocation->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $this->defaultLocation->id)
            ->assertJsonPath('data.name', 'Main Shop');
    }

    public function test_unauthenticated_user_cannot_access_locations(): void
    {
        $response = $this->getJson('/api/v1/locations');

        $response->assertUnauthorized();
    }

    public function test_can_set_location_as_default(): void
    {
        $newLocation = Location::create([
            'company_id' => $this->company->id,
            'name' => 'New Default',
            'code' => 'NEW',
            'type' => LocationType::Shop,
            'is_default' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/locations/{$newLocation->id}/set-default");

        $response->assertOk()
            ->assertJsonPath('data.is_default', true);

        // Verify old default is no longer default
        $this->defaultLocation->refresh();
        $this->assertFalse($this->defaultLocation->is_default);
    }

    public function test_locations_are_ordered_by_is_default_then_name(): void
    {
        Location::create([
            'company_id' => $this->company->id,
            'name' => 'Alpha Warehouse',
            'code' => 'ALPHA',
            'type' => LocationType::Warehouse,
            'is_default' => false,
            'is_active' => true,
        ]);

        Location::create([
            'company_id' => $this->company->id,
            'name' => 'Zebra Office',
            'code' => 'ZEBRA',
            'type' => LocationType::Office,
            'is_default' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/locations');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->toArray();

        // Default location should be first
        $this->assertEquals('Main Shop', $names[0]);
    }
}
