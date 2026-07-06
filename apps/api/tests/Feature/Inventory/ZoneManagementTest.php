<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\ZoneService;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class ZoneManagementTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Location $shop;

    private Product $product;

    private Product $productTwo;

    private ZoneService $zoneService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Zone Test Tenant',
            'slug' => 'zone-test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Zone Test Company',
            'legal_name' => 'Zone Test Company LLC',
            'tax_id' => 'TAX-ZONE-001',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Zone Test User',
            'email' => 'zone-user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'inventory.view',
            'inventory.adjust',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-ZN-01',
            'name' => 'Zone Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->shop = Location::create([
            'company_id' => $this->company->id,
            'code' => 'SH-ZN-01',
            'name' => 'Zone Shop',
            'type' => 'shop',
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'ZONE-WIDGET',
            'name' => 'Zone Widget',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '5.0000',
            'sale_price' => '10.0000',
        ]);

        $this->productTwo = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'ZONE-GADGET',
            'name' => 'Zone Gadget',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '3.0000',
            'sale_price' => '6.0000',
        ]);

        $this->zoneService = app(ZoneService::class);
    }

    // ------------------------------------------------------------------
    // CRUD
    // ------------------------------------------------------------------

    public function test_create_zone_via_api(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/inventory/zones', [
            'location_id' => $this->warehouse->id,
            'name' => 'Aisle 1',
            'code' => 'A1',
            'sort_order' => 1,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.location_id', $this->warehouse->id);
        $response->assertJsonPath('data.code', 'A1');
        $response->assertJsonPath('data.name', 'Aisle 1');
        $response->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('location_zones', [
            'location_id' => $this->warehouse->id,
            'code' => 'A1',
            'name' => 'Aisle 1',
        ]);
    }

    public function test_index_lists_zones_scoped_to_location(): void
    {
        $this->zoneService->createZone($this->tenant->id, $this->warehouse->id, 'Aisle 1', 'A1', sortOrder: 2);
        $this->zoneService->createZone($this->tenant->id, $this->warehouse->id, 'Aisle 2', 'A2', sortOrder: 1);
        $this->zoneService->createZone($this->tenant->id, $this->shop->id, 'Front', 'F1');

        $response = $this->actingAs($this->user)->getJson('/api/v1/inventory/zones?location_id='.$this->warehouse->id);

        $response->assertStatus(200);
        $codes = array_column($response->json('data'), 'code');
        $this->assertSame(['A2', 'A1'], $codes, 'Expected zones ordered by sort_order');
    }

    public function test_index_requires_valid_location_id(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/inventory/zones?location_id=not-a-uuid');

        $response->assertStatus(422);
        $this->assertApiErrorCode($response, 'ZONE_INVALID_LOCATION');
    }

    public function test_update_zone_via_api(): void
    {
        $zone = $this->zoneService->createZone($this->tenant->id, $this->warehouse->id, 'Aisle 1', 'A1');

        $response = $this->actingAs($this->user)->patchJson("/api/v1/inventory/zones/{$zone->id}", [
            'name' => 'Aisle One Renamed',
            'sort_order' => 5,
            'is_active' => false,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'Aisle One Renamed');
        $response->assertJsonPath('data.sort_order', 5);
        $response->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('location_zones', [
            'id' => $zone->id,
            'name' => 'Aisle One Renamed',
            'is_active' => false,
        ]);
    }

    public function test_delete_zone_via_api_cascades_product_assignments(): void
    {
        $zone = $this->zoneService->createZone($this->tenant->id, $this->warehouse->id, 'Aisle 1', 'A1');
        $this->zoneService->assignProduct($this->product->id, $this->warehouse->id, $zone->id);

        $this->assertDatabaseHas('product_zone_assignments', [
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'zone_id' => $zone->id,
        ]);

        $response = $this->actingAs($this->user)->deleteJson("/api/v1/inventory/zones/{$zone->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('location_zones', ['id' => $zone->id]);
        $this->assertDatabaseMissing('product_zone_assignments', [
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
        ]);
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    public function test_create_zone_with_duplicate_code_same_location_returns_422(): void
    {
        $this->zoneService->createZone($this->tenant->id, $this->warehouse->id, 'Aisle 1', 'A1');

        $response = $this->actingAs($this->user)->postJson('/api/v1/inventory/zones', [
            'location_id' => $this->warehouse->id,
            'name' => 'Aisle 1 Duplicate',
            'code' => 'A1',
        ]);

        $this->assertApiValidationErrors($response, ['code']);
    }

    public function test_create_zone_with_same_code_different_location_is_allowed(): void
    {
        $this->zoneService->createZone($this->tenant->id, $this->warehouse->id, 'Aisle 1', 'A1');

        $response = $this->actingAs($this->user)->postJson('/api/v1/inventory/zones', [
            'location_id' => $this->shop->id,
            'name' => 'Front Aisle',
            'code' => 'A1',
        ]);

        $response->assertStatus(201);
    }

    public function test_update_zone_code_uniqueness_ignores_self(): void
    {
        $zone = $this->zoneService->createZone($this->tenant->id, $this->warehouse->id, 'Aisle 1', 'A1');

        $response = $this->actingAs($this->user)->patchJson("/api/v1/inventory/zones/{$zone->id}", [
            'code' => 'A1',
        ]);

        $response->assertStatus(200);
    }

    // ------------------------------------------------------------------
    // Bulk assign — upsert semantics
    // ------------------------------------------------------------------

    public function test_bulk_assign_products_upserts_and_reassignment_moves_without_duplicate(): void
    {
        $zoneA = $this->zoneService->createZone($this->tenant->id, $this->warehouse->id, 'Aisle A', 'AA');
        $zoneB = $this->zoneService->createZone($this->tenant->id, $this->warehouse->id, 'Aisle B', 'AB');

        $firstResponse = $this->actingAs($this->user)->postJson(
            "/api/v1/inventory/zones/{$zoneA->id}/assign-products",
            ['product_ids' => [$this->product->id, $this->productTwo->id]],
        );
        $firstResponse->assertStatus(200);

        $this->assertDatabaseCount('product_zone_assignments', 2);
        $this->assertDatabaseHas('product_zone_assignments', [
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'zone_id' => $zoneA->id,
        ]);

        // Reassign product 1 to zone B — moves the existing row, no duplicate.
        $secondResponse = $this->actingAs($this->user)->postJson(
            "/api/v1/inventory/zones/{$zoneB->id}/assign-products",
            ['product_ids' => [$this->product->id]],
        );
        $secondResponse->assertStatus(200);

        $this->assertDatabaseCount('product_zone_assignments', 2);
        $this->assertDatabaseHas('product_zone_assignments', [
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'zone_id' => $zoneB->id,
        ]);
        $this->assertDatabaseMissing('product_zone_assignments', [
            'product_id' => $this->product->id,
            'zone_id' => $zoneA->id,
        ]);

        $productsResponse = $this->actingAs($this->user)->getJson("/api/v1/inventory/zones/{$zoneB->id}/products");
        $productsResponse->assertStatus(200);
        $ids = array_column($productsResponse->json('data'), 'product_id');
        $this->assertContains($this->product->id, $ids);
        $this->assertNotContains($this->productTwo->id, $ids);
    }

    // ------------------------------------------------------------------
    // Cross-location guard on ZoneService::assignProduct (used directly by
    // Task C1) — the exact signature the guard protects.
    // ------------------------------------------------------------------

    public function test_assign_product_service_rejects_cross_location_mismatch(): void
    {
        $zoneAtWarehouse = $this->zoneService->createZone($this->tenant->id, $this->warehouse->id, 'Aisle 1', 'A1');

        $this->expectException(InvalidArgumentException::class);

        $this->zoneService->assignProduct($this->product->id, $this->shop->id, $zoneAtWarehouse->id);
    }

    // ------------------------------------------------------------------
    // Str::isUuid() guards
    // ------------------------------------------------------------------

    public function test_update_with_non_uuid_id_returns_404(): void
    {
        $response = $this->actingAs($this->user)->patchJson('/api/v1/inventory/zones/not-a-uuid', ['name' => 'X']);

        $response->assertStatus(404);
    }

    public function test_destroy_with_non_uuid_id_returns_404(): void
    {
        $response = $this->actingAs($this->user)->deleteJson('/api/v1/inventory/zones/not-a-uuid');

        $response->assertStatus(404);
    }

    public function test_products_endpoint_with_non_uuid_id_returns_404(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/inventory/zones/not-a-uuid/products');

        $response->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // Cross-company isolation
    // ------------------------------------------------------------------

    public function test_cannot_update_zone_belonging_to_another_company(): void
    {
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Zone Company',
            'legal_name' => 'Other Zone Company LLC',
            'tax_id' => 'TAX-ZONE-002',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $otherLocation = Location::create([
            'company_id' => $otherCompany->id,
            'code' => 'WH-OTHER-01',
            'name' => 'Other Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $foreignZone = $this->zoneService->createZone($this->tenant->id, $otherLocation->id, 'Foreign Aisle', 'FA1');

        $response = $this->actingAs($this->user)->patchJson("/api/v1/inventory/zones/{$foreignZone->id}", [
            'name' => 'Hijacked',
        ]);

        $response->assertStatus(404);
    }
}
