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
use App\Modules\Vehicle\Domain\Enums\OwnershipReason;
use App\Modules\Vehicle\Domain\VehicleOwnership;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Closes audit finding 🔴-1 from 2026-04-21 autospecs-ops-audit:
 * POST /api/v1/vehicles must open a vehicle_ownership_history row when
 * partner_id is supplied so the customer→vehicle lineage is preserved
 * from the start of the chain.
 */
final class VehicleCreationOpensOwnershipHistoryTest extends TestCase
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

    public function test_creating_vehicle_with_partner_opens_initial_ownership_row(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/vehicles', [
                'partner_id' => $this->customer->id,
                'license_plate' => 'ABC-123',
                'brand' => 'Toyota',
                'model' => 'Corolla',
            ]);

        $response->assertCreated();

        /** @var string $vehicleId */
        $vehicleId = $response->json('data.id');

        // Exactly one ownership row, pointing at the supplied partner, open.
        $this->assertSame(
            1,
            VehicleOwnership::query()->where('vehicle_id', $vehicleId)->count(),
            'Expected exactly 1 ownership row to be opened on vehicle creation.',
        );

        $this->assertDatabaseHas('vehicle_ownership_history', [
            'vehicle_id' => $vehicleId,
            'owner_partner_id' => $this->customer->id,
            'released_at' => null,
            'reason_code' => OwnershipReason::InitialRegistration->value,
        ]);

        $row = VehicleOwnership::query()
            ->where('vehicle_id', $vehicleId)
            ->firstOrFail();
        $this->assertTrue($row->acquired_at->isAfter(now()->subMinute()));
        $this->assertSame($this->tenant->id, $row->tenant_id);
        $this->assertSame($this->company->id, $row->company_id);
        $this->assertSame($this->user->id, $row->recorded_by_user_id);

        // The DTO-level computed field must reflect the new open ownership.
        $response->assertJsonPath('data.current_owner_partner_id', $this->customer->id);
    }

    public function test_creating_vehicle_without_partner_does_not_open_any_ownership_row(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/vehicles', [
                'license_plate' => 'XYZ-789',
                'brand' => 'Honda',
                'model' => 'Civic',
            ]);

        $response->assertCreated();

        /** @var string $vehicleId */
        $vehicleId = $response->json('data.id');

        $this->assertSame(
            0,
            VehicleOwnership::query()->where('vehicle_id', $vehicleId)->count(),
            'No ownership row should be opened when partner_id is not supplied.',
        );

        $response->assertJsonPath('data.current_owner_partner_id', null);
    }

    public function test_creating_vehicle_with_invalid_partner_returns_422_and_opens_no_ownership(): void
    {
        $fakePartnerId = '00000000-0000-0000-0000-000000000000';

        $before = VehicleOwnership::query()->count();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/vehicles', [
                'partner_id' => $fakePartnerId,
                'license_plate' => 'ABC-123',
                'brand' => 'Toyota',
                'model' => 'Corolla',
            ]);

        $this->assertApiValidationErrors($response, ['partner_id']);

        $this->assertSame(
            $before,
            VehicleOwnership::query()->count(),
            'Invalid partner_id must not create any ownership row.',
        );
    }
}
