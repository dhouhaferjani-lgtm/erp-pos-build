<?php

declare(strict_types=1);

namespace Tests\Feature\Vehicle\Mileage;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Vehicle\Domain\Enums\MileageSource;
use App\Modules\Vehicle\Domain\Vehicle;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class LogMileageEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $technicianUser;

    private User $viewerUser;

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

        $this->viewerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Viewer',
            'email' => 'viewer@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->viewerUser->assignRole('viewer');
        UserCompanyMembership::create([
            'user_id' => $this->viewerUser->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->vehicle = Vehicle::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_technician_can_log_mileage(): void
    {
        $response = $this->actingAs($this->technicianUser, 'sanctum')
            ->postJson("/api/v1/vehicles/{$this->vehicle->id}/mileage", [
                'mileage' => 85000,
                'recorded_at' => now()->toIso8601String(),
                'source' => MileageSource::Manual->value,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('vehicle_mileage_readings', 1);
    }

    public function test_viewer_forbidden_from_logging_mileage(): void
    {
        $response = $this->actingAs($this->viewerUser, 'sanctum')
            ->postJson("/api/v1/vehicles/{$this->vehicle->id}/mileage", [
                'mileage' => 85000,
                'recorded_at' => now()->toIso8601String(),
                'source' => MileageSource::Manual->value,
            ]);

        $response->assertStatus(403);
    }

    public function test_negative_mileage_is_rejected(): void
    {
        $response = $this->actingAs($this->technicianUser, 'sanctum')
            ->postJson("/api/v1/vehicles/{$this->vehicle->id}/mileage", [
                'mileage' => -1,
                'recorded_at' => now()->toIso8601String(),
                'source' => MileageSource::Manual->value,
            ]);

        $response->assertStatus(422);
    }
}
