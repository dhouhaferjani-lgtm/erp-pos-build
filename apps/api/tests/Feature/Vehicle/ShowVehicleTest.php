<?php

declare(strict_types=1);

namespace Tests\Feature\Vehicle;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Vehicle\Domain\Enums\OwnershipReason;
use App\Modules\Vehicle\Domain\Vehicle;
use App\Modules\Vehicle\Domain\VehicleOwnership;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ShowVehicleTest extends TestCase
{
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
            'vertical' => \App\Enums\Vertical::Mechanic,
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
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
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
            'name' => 'Alice Current',
            'type' => PartnerType::Customer,
        ]);

        $this->vehicle = Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'license_plate' => 'SHOW-123',
            'brand' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2020,
        ]);
    }

    public function test_show_returns_vehicle_with_current_owner_data_shape(): void
    {
        // Open ownership row so currentOwnership loads
        VehicleOwnership::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'vehicle_id' => $this->vehicle->id,
            'owner_partner_id' => $this->customer->id,
            'acquired_at' => now()->subDays(5),
            'released_at' => null,
            'reason_code' => OwnershipReason::InitialRegistration,
            'notes' => null,
            'recorded_by_user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/vehicles/{$this->vehicle->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'vehicle' => [
                        'id',
                        'license_plate',
                        'current_owner_partner_id',
                        'current_owner_display_name',
                    ],
                    'current_ownership',
                    'recent_mileage_readings',
                ],
            ])
            ->assertJsonPath('data.vehicle.current_owner_partner_id', $this->customer->id)
            ->assertJsonPath('data.vehicle.current_owner_display_name', 'Alice Current');
    }

    public function test_show_returns_null_current_ownership_when_no_open_ownership(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/vehicles/{$this->vehicle->id}");

        $response->assertOk()
            ->assertJsonPath('data.current_ownership', null)
            ->assertJsonPath('data.vehicle.current_owner_partner_id', null);
    }
}
