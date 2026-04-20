<?php

declare(strict_types=1);

namespace Tests\Feature\Vehicle\Ownership;

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
use App\Modules\Vehicle\Domain\VehicleOwnership;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PartnerVehiclesListTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_returns_only_vehicles_currently_owned_by_partner(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);
        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Company',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'status' => CompanyStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $user->assignRole('admin');
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'admin',
        ]);
        app(CompanyContext::class)->setCompanyId($company->id);

        $partner = Partner::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Current Owner',
            'type' => PartnerType::Customer,
        ]);
        $otherPartner = Partner::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Other',
            'type' => PartnerType::Customer,
        ]);

        $vehicleOwned = Vehicle::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        $vehicleOther = Vehicle::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        VehicleOwnership::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'vehicle_id' => $vehicleOwned->id,
            'owner_partner_id' => $partner->id,
            'released_at' => null,
        ]);
        VehicleOwnership::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'vehicle_id' => $vehicleOther->id,
            'owner_partner_id' => $otherPartner->id,
            'released_at' => null,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/partners/{$partner->id}/vehicles");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }
}
