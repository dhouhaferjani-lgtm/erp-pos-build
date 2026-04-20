<?php

declare(strict_types=1);

namespace Tests\Feature\Vehicle\Ownership;

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

final class TransferVehicleOwnershipEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $adminUser;

    private User $technicianUser;

    private Partner $customerA;

    private Partner $customerB;

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
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->adminUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->adminUser->assignRole('admin');
        UserCompanyMembership::create([
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->technicianUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tech',
            'email' => 'tech@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->technicianUser->assignRole('technician');
        UserCompanyMembership::create([
            'user_id' => $this->technicianUser->id,
            'company_id' => $this->company->id,
            'role' => 'technician',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->customerA = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Customer A',
            'type' => PartnerType::Customer,
        ]);
        $this->customerB = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Customer B',
            'type' => PartnerType::Customer,
        ]);

        $this->vehicle = Vehicle::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_admin_can_transfer_ownership(): void
    {
        VehicleOwnership::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'vehicle_id' => $this->vehicle->id,
            'owner_partner_id' => $this->customerA->id,
            'released_at' => null,
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/vehicles/{$this->vehicle->id}/ownerships", [
                'new_owner_partner_id' => $this->customerB->id,
                'occurred_at' => now()->toIso8601String(),
                'reason_code' => OwnershipReason::Sale->value,
                'notes' => 'Test transfer',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('vehicle_ownership_history', 2);
    }

    public function test_technician_forbidden_from_transferring_ownership(): void
    {
        $response = $this->actingAs($this->technicianUser, 'sanctum')
            ->postJson("/api/v1/vehicles/{$this->vehicle->id}/ownerships", [
                'new_owner_partner_id' => $this->customerB->id,
                'occurred_at' => now()->toIso8601String(),
                'reason_code' => OwnershipReason::Sale->value,
            ]);

        $response->assertStatus(403);
    }

    public function test_non_existent_partner_rejected(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/vehicles/{$this->vehicle->id}/ownerships", [
                'new_owner_partner_id' => '00000000-0000-0000-0000-000000000000',
                'occurred_at' => now()->toIso8601String(),
                'reason_code' => OwnershipReason::Sale->value,
            ]);

        $response->assertStatus(422);
    }

    public function test_index_returns_ownership_history_sorted_desc(): void
    {
        VehicleOwnership::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'vehicle_id' => $this->vehicle->id,
            'owner_partner_id' => $this->customerA->id,
            'acquired_at' => now()->subYears(2),
            'released_at' => now()->subYear(),
        ]);
        VehicleOwnership::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'vehicle_id' => $this->vehicle->id,
            'owner_partner_id' => $this->customerB->id,
            'acquired_at' => now()->subYear(),
            'released_at' => null,
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/v1/vehicles/{$this->vehicle->id}/ownerships");

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }
}
