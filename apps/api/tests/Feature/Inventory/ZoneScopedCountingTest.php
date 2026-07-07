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
use App\Modules\Inventory\Application\Services\InventoryCountingService;
use App\Modules\Inventory\Application\Services\LocationNodeService;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\LocationNode;
use App\Modules\Inventory\Domain\ProductPlacement;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Task C1: zone-scoped counting + assign-as-you-count + zero-stock inclusion.
 *
 * - Zone scope sources items from `product_zone_assignments` for the given
 *   zone_ids; theoretical qty is the current on-hand (incl. 0 / no stock row).
 * - Submitting the first count of a zone-scoped session (single zone) upserts
 *   the counted product into that zone via LocationNodeService::assignProduct.
 * - `block_sales=true` is rejected for a zone scope (soft advisory only).
 * - Onboarding-location full counts (and opt-in full/location counts) source
 *   the whole active catalog LEFT JOIN stock (theoretical 0 when absent) and
 *   flag `includes_zero_stock=true`.
 */
final class ZoneScopedCountingTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private InventoryCountingService $service;

    private LocationNodeService $zoneService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Zone Tenant',
            'slug' => 'zone-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Zone Company',
            'legal_name' => 'Zone Company LLC',
            'tax_id' => 'ZON-TAX-'.uniqid(),
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
            'name' => 'Zone User',
            'email' => 'zone-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['inventory.view', 'inventory.adjust']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-ZON-01',
            'name' => 'Zone Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
            'onboarding_mode' => false,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->service = app(InventoryCountingService::class);
        $this->zoneService = app(LocationNodeService::class);
    }

    private function makeProduct(string $sku, bool $isActive = true): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku.'-'.uniqid(),
            'name' => 'Product '.$sku,
            'type' => ProductType::Part,
            'is_active' => $isActive,
            'cost_price' => '1.000',
        ]);
    }

    private function setStock(Product $product, string $quantity, ?Location $location = null): StockLevel
    {
        return StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => ($location ?? $this->location)->id,
            'quantity' => $quantity,
            'reserved' => '0.0000',
        ]);
    }

    private function makeZone(string $code, ?Location $location = null): LocationNode
    {
        return $this->zoneService->createNode(
            tenantId: $this->tenant->id,
            locationId: ($location ?? $this->location)->id,
            parentId: null,
            type: LocationNodeType::Zone,
            name: 'Zone '.$code,
            code: $code,
        );
    }

    public function test_zone_count_generates_only_assigned_products_with_current_or_zero_theoretical(): void
    {
        $zoneA = $this->makeZone('A');
        $zoneB = $this->makeZone('B');

        $withStock = $this->makeProduct('IN-STOCK');
        $noStock = $this->makeProduct('NO-STOCK');
        $otherZone = $this->makeProduct('OTHER-ZONE');

        $this->setStock($withStock, '5.0000');
        // $noStock deliberately has NO stock row.
        $this->setStock($otherZone, '9.0000');

        $this->zoneService->assignProduct($this->tenant->id, $withStock->id, $this->location->id, $zoneA->id);
        $this->zoneService->assignProduct($this->tenant->id, $noStock->id, $this->location->id, $zoneA->id);
        $this->zoneService->assignProduct($this->tenant->id, $otherZone->id, $this->location->id, $zoneB->id);

        $counting = $this->service->create([
            'scope_type' => CountingScopeType::Zone->value,
            'scope_filters' => ['location_id' => $this->location->id, 'zone_ids' => [$zoneA->id]],
            'count_1_user_id' => (string) $this->user->id,
            'requires_count_2' => false,
        ], $this->user, $this->company->id);

        $items = $counting->items()->get();

        $this->assertCount(2, $items, 'Only products assigned to zone A should be counted.');

        $byProduct = $items->keyBy('product_id');
        $this->assertTrue($byProduct->has($withStock->id));
        $this->assertTrue($byProduct->has($noStock->id));
        $this->assertFalse($byProduct->has($otherZone->id), 'Zone B product must not appear.');

        $this->assertSame('5.0000', (string) $byProduct[$withStock->id]->theoretical_qty);
        $this->assertSame('0.0000', (string) $byProduct[$noStock->id]->theoretical_qty,
            'A product with no stock row must have theoretical 0.0000.');
    }

    public function test_submit_count_assigns_scanned_in_product_to_the_single_zone(): void
    {
        $zoneA = $this->makeZone('A');
        $unassigned = $this->makeProduct('UNASSIGNED');

        // Zone counting with a manually-added item for a product NOT yet
        // assigned to any zone (the scan-in / unexpected-item shape).
        $counting = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'scope_type' => CountingScopeType::Zone,
            'scope_filters' => ['location_id' => $this->location->id, 'zone_ids' => [$zoneA->id]],
            'counting_number' => 'CNT-ZON-'.uniqid(),
            'status' => CountingStatus::Count1InProgress,
            'requires_count_2' => false,
            'requires_count_3' => false,
            'allow_unexpected_items' => true,
            'created_by_user_id' => $this->user->id,
            'count_1_user_id' => $this->user->id,
        ]);

        $item = InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $unassigned->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '0.0000',
        ]);

        // Second uncounted item keeps the phase from completing so the count
        // submission exercises only the assignment side-effect.
        $filler = $this->makeProduct('FILLER');
        InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $filler->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '0.0000',
        ]);

        $this->assertDatabaseMissing('product_placements', [
            'product_id' => $unassigned->id,
            'location_id' => $this->location->id,
        ]);

        $this->service->submitCount($item, 1, '3.0000', null, $this->user);

        $assignment = ProductPlacement::query()
            ->where('product_id', $unassigned->id)
            ->where('location_id', $this->location->id)
            ->first();

        $this->assertNotNull($assignment, 'Submitting a count must assign the product to the zone.');
        $this->assertSame($zoneA->id, $assignment->node_id);
    }

    public function test_zone_scope_rejects_block_sales(): void
    {
        $zoneA = $this->makeZone('A');

        $response = $this->actingAs($this->user)->postJson('/api/v1/inventory/countings', [
            'scope_type' => CountingScopeType::Zone->value,
            'scope_filters' => ['location_id' => $this->location->id, 'zone_ids' => [$zoneA->id]],
            'block_sales' => true,
            'count_1_user_id' => (string) $this->user->id,
            'requires_count_2' => false,
        ]);

        $response->assertStatus(422);
    }

    public function test_zone_scope_rejects_zone_from_a_different_location_same_company(): void
    {
        $otherLocation = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-ZON-02',
            'name' => 'Second Zone Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => false,
            'onboarding_mode' => false,
        ]);

        $foreignZone = $this->makeZone('FOREIGN-LOC', $otherLocation);

        $response = $this->actingAs($this->user)->postJson('/api/v1/inventory/countings', [
            'scope_type' => CountingScopeType::Zone->value,
            'scope_filters' => ['location_id' => $this->location->id, 'zone_ids' => [$foreignZone->id]],
            'count_1_user_id' => (string) $this->user->id,
            'requires_count_2' => false,
        ]);

        $this->assertApiValidationErrors($response, ['scope_filters.zone_ids']);
    }

    public function test_zone_scope_rejects_zone_from_another_company_in_the_same_tenant(): void
    {
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Zone Company',
            'legal_name' => 'Other Zone Company LLC',
            'tax_id' => 'ZON-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $otherLocation = Location::create([
            'company_id' => $otherCompany->id,
            'code' => 'WH-ZON-OTHERCO',
            'name' => 'Other Company Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
            'onboarding_mode' => false,
        ]);

        $foreignZone = $this->makeZone('FOREIGN-CO', $otherLocation);

        $response = $this->actingAs($this->user)->postJson('/api/v1/inventory/countings', [
            'scope_type' => CountingScopeType::Zone->value,
            'scope_filters' => ['location_id' => $this->location->id, 'zone_ids' => [$foreignZone->id]],
            'count_1_user_id' => (string) $this->user->id,
            'requires_count_2' => false,
        ]);

        $this->assertApiValidationErrors($response, ['scope_filters.zone_ids']);
    }

    public function test_ambiguity_window_minutes_persists_when_provided(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/inventory/countings', [
            'scope_type' => CountingScopeType::Location->value,
            'scope_filters' => ['location_ids' => [$this->location->id]],
            'ambiguity_window_minutes' => 30,
            'count_1_user_id' => (string) $this->user->id,
            'requires_count_2' => false,
        ]);

        $response->assertCreated();
        $countingId = (string) $response->json('data.id');

        $this->assertSame(30, InventoryCounting::query()->findOrFail($countingId)->ambiguity_window_minutes);
    }

    public function test_ambiguity_window_minutes_defaults_to_15_when_omitted(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/inventory/countings', [
            'scope_type' => CountingScopeType::Location->value,
            'scope_filters' => ['location_ids' => [$this->location->id]],
            'count_1_user_id' => (string) $this->user->id,
            'requires_count_2' => false,
        ]);

        $response->assertCreated();
        $countingId = (string) $response->json('data.id');

        $this->assertSame(15, InventoryCounting::query()->findOrFail($countingId)->ambiguity_window_minutes);
    }

    public function test_ambiguity_window_minutes_rejects_non_integer(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/inventory/countings', [
            'scope_type' => CountingScopeType::Location->value,
            'scope_filters' => ['location_ids' => [$this->location->id]],
            'ambiguity_window_minutes' => 'abc',
            'count_1_user_id' => (string) $this->user->id,
            'requires_count_2' => false,
        ]);

        $response->assertStatus(422);
    }

    public function test_onboarding_full_count_includes_zero_and_negative_stock(): void
    {
        $this->location->onboarding_mode = true;
        $this->location->save();

        $noStock = $this->makeProduct('OB-NONE');
        $negative = $this->makeProduct('OB-NEG');
        $this->setStock($negative, '-3.0000');

        $counting = $this->service->create([
            'scope_type' => CountingScopeType::FullInventory->value,
            'scope_filters' => [],
            'count_1_user_id' => (string) $this->user->id,
            'requires_count_2' => false,
        ], $this->user, $this->company->id);

        $counting->refresh();
        $this->assertTrue($counting->includes_zero_stock,
            'Onboarding full count must flag includes_zero_stock.');

        $byProduct = $counting->items()->get()->keyBy('product_id');
        $this->assertTrue($byProduct->has($noStock->id), 'Never-received product must be counted.');
        $this->assertTrue($byProduct->has($negative->id));
        $this->assertSame('0.0000', (string) $byProduct[$noStock->id]->theoretical_qty);
        $this->assertSame('-3.0000', (string) $byProduct[$negative->id]->theoretical_qty);
    }

    public function test_non_onboarding_location_count_excludes_zero_stock(): void
    {
        $withStock = $this->makeProduct('NORM-IN');
        $noStock = $this->makeProduct('NORM-NONE');
        $this->setStock($withStock, '7.0000');

        $counting = $this->service->create([
            'scope_type' => CountingScopeType::Location->value,
            'scope_filters' => ['location_ids' => [$this->location->id]],
            'count_1_user_id' => (string) $this->user->id,
            'requires_count_2' => false,
        ], $this->user, $this->company->id);

        $counting->refresh();
        $this->assertFalse($counting->includes_zero_stock);

        $byProduct = $counting->items()->get()->keyBy('product_id');
        $this->assertTrue($byProduct->has($withStock->id));
        $this->assertFalse($byProduct->has($noStock->id),
            'Outside onboarding, a product with no stock row is excluded.');
    }

    /**
     * C1 report fix 1: a mobile-created zone draft carries zone_ids/location_id
     * in scope_filters but no product_ids — the item set is sourced from
     * product_zone_assignments (see resolveCountingItemSeeds/zoneItemSeeds), so
     * activateDraft must not reject it for lacking product_ids.
     */
    public function test_mobile_zone_draft_without_product_ids_activates_and_generates_items_from_assignments(): void
    {
        $zoneA = $this->makeZone('A');

        $assigned1 = $this->makeProduct('MOB-ZONE-1');
        $assigned2 = $this->makeProduct('MOB-ZONE-2');
        $this->setStock($assigned1, '4.0000');
        // $assigned2 deliberately has no stock row.

        $this->zoneService->assignProduct($this->tenant->id, $assigned1->id, $this->location->id, $zoneA->id);
        $this->zoneService->assignProduct($this->tenant->id, $assigned2->id, $this->location->id, $zoneA->id);

        $draft = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->user->id,
            'status' => CountingStatus::Draft,
            'scope_type' => CountingScopeType::Zone,
            // No product_ids at all — mobile zone drafts never carry them.
            'scope_filters' => ['location_id' => $this->location->id, 'zone_ids' => [$zoneA->id]],
            'requires_count_2' => false,
            'requires_count_3' => false,
            'allow_unexpected_items' => true,
            'count_1_user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/inventory/countings/{$draft->id}/activate-draft", [
                'activate_immediately' => true,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', CountingStatus::Count1InProgress->value);

        $draft->refresh();
        $items = $draft->items()->get()->keyBy('product_id');

        $this->assertCount(2, $items, 'Zone draft must generate one item per product_zone_assignment.');
        $this->assertTrue($items->has($assigned1->id));
        $this->assertTrue($items->has($assigned2->id));
        $this->assertSame('4.0000', (string) $items[$assigned1->id]->theoretical_qty);
        $this->assertSame('0.0000', (string) $items[$assigned2->id]->theoretical_qty);
    }

    /**
     * C1 report fix 1: a zone draft activation still requires zone_ids — an
     * empty zone_ids list generates no items and must be rejected up front,
     * mirroring CreateCountingRequest's zone scope rule.
     */
    public function test_zone_draft_without_zone_ids_is_rejected_on_activation(): void
    {
        $draft = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->user->id,
            'status' => CountingStatus::Draft,
            'scope_type' => CountingScopeType::Zone,
            'scope_filters' => ['location_id' => $this->location->id, 'zone_ids' => []],
            'requires_count_2' => false,
            'requires_count_3' => false,
            'count_1_user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/inventory/countings/{$draft->id}/activate-draft");

        $response->assertStatus(422);
        $this->assertStringContainsString('zone', (string) $response->json('error'));
    }

    /**
     * C1 report fix 2: block_sales, ambiguity_window_minutes and
     * includes_zero_stock must be present on both the admin detail response
     * and the counter-view response mobile polls — additive fields, no
     * existing key removed/renamed.
     */
    public function test_session_responses_expose_block_sales_and_window_and_zero_stock_flags(): void
    {
        $zoneA = $this->makeZone('A');
        $product = $this->makeProduct('MOB-SESSION');
        $this->zoneService->assignProduct($this->tenant->id, $product->id, $this->location->id, $zoneA->id);

        $counting = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->user->id,
            'status' => CountingStatus::Count1InProgress,
            'scope_type' => CountingScopeType::Zone,
            'scope_filters' => ['location_id' => $this->location->id, 'zone_ids' => [$zoneA->id]],
            'requires_count_2' => false,
            'requires_count_3' => false,
            'block_sales' => false,
            'ambiguity_window_minutes' => 20,
            'count_1_user_id' => $this->user->id,
        ]);

        InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '0.0000',
        ]);

        $detailResponse = $this->actingAs($this->user)
            ->getJson("/api/v1/inventory/countings/{$counting->id}");

        $detailResponse->assertStatus(200);
        $detailResponse->assertJsonStructure([
            'data' => ['block_sales', 'ambiguity_window_minutes', 'includes_zero_stock'],
        ]);
        $this->assertFalse($detailResponse->json('data.block_sales'));
        $this->assertSame(20, $detailResponse->json('data.ambiguity_window_minutes'));
        $this->assertFalse($detailResponse->json('data.includes_zero_stock'));

        $counterViewResponse = $this->actingAs($this->user)
            ->getJson("/api/v1/inventory/countings/{$counting->id}/counter-view");

        $counterViewResponse->assertStatus(200);
        $counterViewResponse->assertJsonStructure([
            'data' => ['counting' => ['block_sales', 'ambiguity_window_minutes', 'includes_zero_stock']],
        ]);
        $this->assertFalse($counterViewResponse->json('data.counting.block_sales'));
        $this->assertSame(20, $counterViewResponse->json('data.counting.ambiguity_window_minutes'));
        $this->assertFalse($counterViewResponse->json('data.counting.includes_zero_stock'));

        // The blind counter-view must still never leak theoretical_qty.
        $this->assertStringNotContainsString('theoretical_qty', $counterViewResponse->getContent());
    }
}
