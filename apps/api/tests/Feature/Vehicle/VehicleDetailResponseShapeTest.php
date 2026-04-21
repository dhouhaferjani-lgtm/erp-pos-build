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
use App\Modules\Vehicle\Domain\Vehicle;
use App\Modules\Vehicle\Domain\VehicleOwnership;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Closes audit finding 🟠-3 — Vehicle API list vs detail response shape mismatch.
 *
 * `GET /api/v1/vehicles` already returns `{data: [{vehicle-fields...}]}` where
 * each element is a flat vehicle record. But `GET /api/v1/vehicles/{id}` was
 * returning `{data: {vehicle: {...}, current_ownership, recent_mileage_readings}}`
 * — an extra `vehicle` wrapper that broke OpenAPI-style clients and forced
 * frontend consumers to double-reach through `.data.vehicle.license_plate`
 * instead of the canonical `.data.license_plate`.
 *
 * Per docs/conventions/01-API-RESPONSES.md the single-resource shape is
 * `{data: {...entity-fields}}` with related collections alongside at the same
 * level. This test enforces that shape for the vehicle detail endpoint.
 */
final class VehicleDetailResponseShapeTest extends TestCase
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
            'name' => 'Shape Test Tenant',
            'slug' => 'shape-test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Shape Test Company',
            'legal_name' => 'Shape Test LLC',
            'tax_id' => 'SHAPE-TAX',
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
            'name' => 'Shape User',
            'email' => 'shape@example.com',
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
            'name' => 'Shape Owner',
            'type' => PartnerType::Customer,
        ]);

        $this->vehicle = Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'license_plate' => 'SHAPE-123',
            'brand' => 'Peugeot',
            'model' => '308',
            'year' => 2022,
        ]);

        VehicleOwnership::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'vehicle_id' => $this->vehicle->id,
            'owner_partner_id' => $this->customer->id,
            'acquired_at' => now()->subDays(3),
            'released_at' => null,
            'reason_code' => OwnershipReason::InitialRegistration,
            'notes' => null,
            'recorded_by_user_id' => $this->user->id,
        ]);
    }

    public function test_detail_response_returns_flat_vehicle_fields_under_data(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/vehicles/{$this->vehicle->id}");

        $response->assertOk();

        // Vehicle fields must live directly under `data`, not nested under
        // `data.vehicle`. This mirrors the list endpoint shape.
        $response->assertJsonPath('data.id', $this->vehicle->id);
        $response->assertJsonPath('data.license_plate', 'SHAPE-123');
        $response->assertJsonPath('data.brand', 'Peugeot');
        $response->assertJsonPath('data.model', '308');
        $response->assertJsonPath('data.current_owner_partner_id', $this->customer->id);
        $response->assertJsonPath('data.current_owner_display_name', 'Shape Owner');

        // Related collections remain alongside the vehicle fields (not wrapped
        // in a sub-object), documenting the relational siblings.
        $response->assertJsonStructure([
            'data' => [
                'id',
                'license_plate',
                'brand',
                'model',
                'current_ownership',
                'recent_mileage_readings',
            ],
            'meta' => ['timestamp'],
        ]);

        // Guard against regression: `data.vehicle` must NOT exist.
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertArrayNotHasKey(
            'vehicle',
            $data,
            'Detail response must not nest vehicle fields under a `vehicle` key. See 01-API-RESPONSES.md.'
        );
    }

    public function test_detail_response_shape_is_consistent_with_list_shape(): void
    {
        $listResponse = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/vehicles');
        $listResponse->assertOk();

        /** @var array<int, array<string, mixed>> $listItems */
        $listItems = $listResponse->json('data');
        $this->assertIsArray($listItems);
        $this->assertNotEmpty($listItems);

        $listItem = collect($listItems)->firstWhere('id', $this->vehicle->id);
        $this->assertIsArray($listItem, 'List endpoint must return the created vehicle.');
        $this->assertArrayHasKey('license_plate', $listItem);

        $detailResponse = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/vehicles/{$this->vehicle->id}");
        $detailResponse->assertOk();

        /** @var array<string, mixed> $detailData */
        $detailData = $detailResponse->json('data');

        // Every scalar field present in the list entry must be present at the
        // same key in the detail entry — same envelope, regardless of siblings.
        foreach (['id', 'license_plate', 'brand', 'model', 'tenant_id', 'company_id'] as $field) {
            $this->assertArrayHasKey(
                $field,
                $detailData,
                "Detail `data` must expose `{$field}` at the top level (list shape)."
            );
            $this->assertSame($listItem[$field], $detailData[$field]);
        }
    }
}
