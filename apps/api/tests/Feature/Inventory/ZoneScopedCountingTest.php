<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Listeners\ApplyStockAdjustmentsOnCountingCompleted;
use App\Modules\Inventory\Application\Services\InventoryCountingService;
use App\Modules\Inventory\Application\Services\LocationNodeService;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Events\InventoryCountingCompleted;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingEvent;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\LocationNode;
use App\Modules\Inventory\Domain\ProductPlacement;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Inventory\Presentation\Requests\CreateCountingRequest;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Task C1: zone-scoped counting + assign-as-you-count + zero-stock inclusion.
 *
 * - Node scope sources items from live product placements under each selected
 *   node subtree; theoretical qty is current location-level on-hand.
 * - Submitting the first count of a zone-scoped session (single zone) preserves
 *   a descendant placement and re-homes only unplaced or out-of-subtree products.
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

    private function makeChildNode(LocationNode $parent, string $code, LocationNodeType $type): LocationNode
    {
        return $this->zoneService->createNode(
            tenantId: $this->tenant->id,
            locationId: $parent->location_id,
            parentId: $parent->id,
            type: $type,
            name: $type->value.' '.$code,
            code: $code,
        );
    }

    private function makeVariant(Product $product, string $code, bool $isActive = true): ProductVariant
    {
        return ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_code' => $code,
            'sku' => $product->sku.'-'.$code,
            'name_suffix' => $code,
            'is_active' => $isActive,
        ]);
    }

    private function setVariantStock(Product $product, ProductVariant $variant, string $quantity): StockLevel
    {
        return StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'location_id' => $this->location->id,
            'quantity' => $quantity,
            'reserved' => '0.0000',
        ]);
    }

    public function test_node_scope_seeds_its_entire_subtree_and_expands_all_variants_without_prefix_collision(): void
    {
        $aisleA1 = $this->makeZone('A1');
        $rackA1 = $this->makeChildNode($aisleA1, 'R1', LocationNodeType::Rack);
        $binA1 = $this->makeChildNode($rackA1, 'B1', LocationNodeType::Bin);

        $aisleA10 = $this->makeZone('A10');
        $rackA10 = $this->makeChildNode($aisleA10, 'R10', LocationNodeType::Rack);

        $variantProduct = $this->makeProduct('VARIANT-SUBTREE');
        $small = $this->makeVariant($variantProduct, 'SMALL');
        $largeInactive = $this->makeVariant($variantProduct, 'LARGE', false);
        $this->setVariantStock($variantProduct, $small, '3.0000');
        $this->setVariantStock($variantProduct, $largeInactive, '7.0000');
        $this->zoneService->assignProduct($this->tenant->id, $variantProduct->id, $this->location->id, $binA1->id);

        $plainProduct = $this->makeProduct('PLAIN-SUBTREE');
        $this->setStock($plainProduct, '5.0000');
        $this->zoneService->assignProduct($this->tenant->id, $plainProduct->id, $this->location->id, $rackA1->id);

        $prefixCollision = $this->makeProduct('PREFIX-A10');
        $this->setStock($prefixCollision, '11.0000');
        $this->zoneService->assignProduct($this->tenant->id, $prefixCollision->id, $this->location->id, $rackA10->id);

        $counting = $this->service->create([
            'scope_type' => CountingScopeType::Zone->value,
            'scope_filters' => ['location_id' => $this->location->id, 'zone_ids' => [$aisleA1->id]],
            'count_1_user_id' => (string) $this->user->id,
            'requires_count_2' => false,
        ], $this->user, $this->company->id);

        $items = $counting->items()->orderBy('product_id')->orderBy('variant_id')->get();
        $this->assertCount(3, $items);
        $this->assertFalse($items->contains('product_id', $prefixCollision->id), 'A1 must not include the A10 subtree.');

        $variantItems = $items->where('product_id', $variantProduct->id)->keyBy('variant_id');
        $this->assertCount(2, $variantItems, 'A placed variant product must expand to all live variants.');
        $smallItem = $variantItems->get($small->id);
        $largeItem = $variantItems->get($largeInactive->id);
        $this->assertInstanceOf(InventoryCountingItem::class, $smallItem);
        $this->assertInstanceOf(InventoryCountingItem::class, $largeItem);
        $this->assertSame('3.0000', (string) $smallItem->theoretical_qty);
        $this->assertSame('7.0000', (string) $largeItem->theoretical_qty);
        $this->assertFalse($items->contains(
            static fn (InventoryCountingItem $item): bool => $item->product_id === $variantProduct->id && $item->variant_id === null,
        ));

        $plainItem = $items->firstWhere('product_id', $plainProduct->id);
        $this->assertNotNull($plainItem);
        $this->assertNull($plainItem->variant_id);
        $this->assertSame('5.0000', (string) $plainItem->theoretical_qty);
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
        $withStockItem = $byProduct->get($withStock->id);
        $noStockItem = $byProduct->get($noStock->id);
        $this->assertInstanceOf(InventoryCountingItem::class, $withStockItem);
        $this->assertInstanceOf(InventoryCountingItem::class, $noStockItem);
        $this->assertFalse($byProduct->has($otherZone->id), 'Zone B product must not appear.');

        $this->assertSame('5.0000', (string) $withStockItem->theoretical_qty);
        $this->assertSame('0.0000', (string) $noStockItem->theoretical_qty,
            'A product with no stock row must have theoretical 0.0000.');
    }

    public function test_submit_count_assigns_scanned_in_product_to_the_single_zone(): void
    {
        $zoneA = $this->makeZone('A');
        $unassigned = $this->makeProduct('UNASSIGNED');

        // Zone counting with a manually-added item for a product that has never
        // had a placement row (the scan-in / unexpected-item shape).
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

    public function test_zone_count_preserves_existing_descendant_placement(): void
    {
        $aisle = $this->makeZone('A1');
        $rack = $this->makeChildNode($aisle, 'R2', LocationNodeType::Rack);
        $bin = $this->makeChildNode($rack, 'B7', LocationNodeType::Bin);
        $product = $this->makeProduct('DESCENDANT-PLACEMENT');
        $this->setStock($product, '4.0000');
        $this->zoneService->assignProduct($this->tenant->id, $product->id, $this->location->id, $bin->id);

        $counting = $this->service->create([
            'scope_type' => CountingScopeType::Zone->value,
            'scope_filters' => ['location_id' => $this->location->id, 'zone_ids' => [$aisle->id]],
            'count_1_user_id' => (string) $this->user->id,
            'requires_count_2' => false,
        ], $this->user, $this->company->id);

        $this->service->activate($counting, $this->user);
        $item = $counting->items()->sole();
        $this->service->submitCount($item, 1, '4.0000', null, $this->user);

        $placement = ProductPlacement::query()
            ->where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->sole();

        $this->assertSame($bin->id, $placement->node_id);
    }

    public function test_zone_count_rehomes_existing_placement_outside_subtree(): void
    {
        $countedAisle = $this->makeZone('A1');
        $outsideAisle = $this->makeZone('B9');
        $outsideRack = $this->makeChildNode($outsideAisle, 'R3', LocationNodeType::Rack);
        $product = $this->makeProduct('OUTSIDE-PLACEMENT');
        $this->zoneService->assignProduct($this->tenant->id, $product->id, $this->location->id, $outsideRack->id);

        $counting = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'scope_type' => CountingScopeType::Zone,
            'scope_filters' => ['location_id' => $this->location->id, 'zone_ids' => [$countedAisle->id]],
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
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '0.0000',
        ]);

        $this->service->submitCount($item, 1, '1.0000', null, $this->user);

        $placement = ProductPlacement::query()
            ->where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->sole();

        $this->assertSame($countedAisle->id, $placement->node_id);
    }

    public function test_zone_count_treats_similar_path_prefix_as_outside_subtree(): void
    {
        $countedAisle = $this->makeZone('A1');
        $collisionAisle = $this->makeZone('A10');
        $collisionRack = $this->makeChildNode($collisionAisle, 'R1', LocationNodeType::Rack);
        $product = $this->makeProduct('PREFIX-COLLISION');
        $this->zoneService->assignProduct($this->tenant->id, $product->id, $this->location->id, $collisionRack->id);

        $counting = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'scope_type' => CountingScopeType::Zone,
            'scope_filters' => ['location_id' => $this->location->id, 'zone_ids' => [$countedAisle->id]],
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
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '0.0000',
        ]);

        $this->service->submitCount($item, 1, '1.0000', null, $this->user);

        $placement = ProductPlacement::query()
            ->where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->sole();

        $this->assertSame($countedAisle->id, $placement->node_id);
    }

    public function test_zone_count_does_not_preserve_same_path_from_a_different_location(): void
    {
        $localAisle = $this->makeZone('A1');
        $localRack = $this->makeChildNode($localAisle, 'R1', LocationNodeType::Rack);
        $product = $this->makeProduct('CROSS-LOCATION-PATH');
        $this->zoneService->assignProduct($this->tenant->id, $product->id, $this->location->id, $localRack->id);
        $otherLocation = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-ZON-CROSS',
            'name' => 'Cross Location Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => false,
            'onboarding_mode' => false,
        ]);
        $foreignAisle = $this->makeZone('A1', $otherLocation);

        $counting = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'scope_type' => CountingScopeType::Zone,
            'scope_filters' => ['location_id' => $this->location->id, 'zone_ids' => [$foreignAisle->id]],
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
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '0.0000',
        ]);

        try {
            $this->service->submitCount($item, 1, '1.0000', null, $this->user);
            $this->fail('Expected the foreign-location node assignment to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame(
                "Node {$foreignAisle->id} is not in location {$this->location->id}.",
                $exception->getMessage(),
            );
        }

        $placement = ProductPlacement::query()
            ->where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->sole();
        $this->assertSame($localRack->id, $placement->node_id,
            'The rejected cross-location submission must leave the local placement unchanged.');
    }

    public function test_location_hierarchy_counting_flow_keeps_variant_stock_at_location_grain(): void
    {
        $aisle = $this->makeZone('A1');
        $rack = $this->makeChildNode($aisle, 'R1', LocationNodeType::Rack);
        $binFrom = $this->makeChildNode($rack, 'B1', LocationNodeType::Bin);
        $binTo = $this->makeChildNode($rack, 'B2', LocationNodeType::Bin);

        $product = $this->makeProduct('E2E-VARIANT');
        $small = $this->makeVariant($product, 'SMALL');
        $large = $this->makeVariant($product, 'LARGE');
        $this->setVariantStock($product, $small, '3.0000');
        $this->setVariantStock($product, $large, '7.0000');

        // End-to-end placement flow: create a hierarchy, assign in the node
        // panel, then bulk-move before opening the subtree count.
        $this->zoneService->assignProduct($this->tenant->id, $product->id, $this->location->id, $binFrom->id);
        $this->zoneService->bulkMove($this->tenant->id, [$product->id], $binTo->id);

        $this->assertDatabaseHas('product_placements', [
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'node_id' => $binTo->id,
            'deleted_at' => null,
        ]);
        $stockRowsBeforeCounting = StockLevel::query()
            ->where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->orderBy('variant_id')
            ->get()
            ->map(static fn (StockLevel $level): array => $level->getRawOriginal())
            ->all();

        $counting = $this->service->create([
            'scope_type' => CountingScopeType::Zone->value,
            'scope_filters' => ['location_id' => $this->location->id, 'zone_ids' => [$aisle->id]],
            'count_1_user_id' => (string) $this->user->id,
            'requires_count_2' => false,
        ], $this->user, $this->company->id);

        $items = $counting->items()->orderBy('variant_id')->get();
        $this->assertCount(2, $items, 'Selecting A1 must include the product placed in descendant B2.');
        $this->assertEqualsCanonicalizing([$small->id, $large->id], $items->pluck('variant_id')->all());

        $this->service->activate($counting, $this->user);
        foreach ($items as $item) {
            $this->service->submitCount(
                $item,
                1,
                (string) $item->theoretical_qty,
                null,
                $this->user,
            );
        }

        $counting->refresh();
        $this->assertSame(CountingStatus::PendingReview, $counting->status);
        $stockRowsAfterCounting = StockLevel::query()
            ->where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->orderBy('variant_id')
            ->get()
            ->map(static fn (StockLevel $level): array => $level->getRawOriginal())
            ->all();
        $this->assertSame($stockRowsBeforeCounting, $stockRowsAfterCounting,
            'Assign-as-you-count must not alter stock rows.');

        Event::fake([InventoryCountingCompleted::class]);
        $this->service->finalize($counting, $this->user);

        // Execute the queued stock listener under worker-like context. Equal
        // physical counts must not manufacture node-grain stock or corrections.
        app(CompanyContext::class)->clear();
        app(ApplyStockAdjustmentsOnCountingCompleted::class)->handle(new InventoryCountingCompleted(
            countingId: $counting->id,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            locationId: $this->location->id,
            countingNumber: (string) $counting->counting_number,
            itemsCount: $items->count(),
            totalVariance: '0.0000',
            completedBy: (string) $this->user->id,
            completedAt: now()->toIso8601String(),
        ));

        $this->assertSame('3.0000', (string) StockLevel::query()->where('variant_id', $small->id)->value('quantity'));
        $this->assertSame('7.0000', (string) StockLevel::query()->where('variant_id', $large->id)->value('quantity'));
        $corrections = StockMovement::query()
            ->where('product_id', $product->id)
            ->where('reason', MovementReason::CountCorrection)
            ->get();
        $this->assertCount(2, $corrections);
        foreach ($corrections as $correction) {
            $this->assertSame('0.0000', (string) $correction->quantity);
            $this->assertSame((string) $correction->quantity_before, (string) $correction->quantity_after);
        }
        $this->assertDatabaseHas('product_placements', [
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'node_id' => $binTo->id,
            'deleted_at' => null,
        ]);
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

    /**
     * Gate r1 (FE IMPORTANT-1, RULED at the API).
     *
     * Sales blocking is ENFORCED for exactly three scopes — CountingBlockService
     * ::activeBlockFor queries `whereIn('scope_type', [location, full_inventory,
     * product_location])` and scopeCoversLocation() returns false by default for
     * everything else. Accepting block_sales:true for `product` / `category`
     * persisted a guarantee no terminal ever received: the till kept selling the
     * counted items AND no late sale was flagged (flagging is gated on an active
     * block), while every operator surface said "sales blocked".
     */
    public function test_product_scope_rejects_block_sales(): void
    {
        $product = $this->makeProduct('BLOCK-PROD');
        $this->setStock($product, '5.0000');

        $response = $this->actingAs($this->user)->postJson('/api/v1/inventory/countings', [
            'scope_type' => CountingScopeType::Product->value,
            'scope_filters' => ['product_ids' => [$product->id]],
            'block_sales' => true,
            'count_1_user_id' => (string) $this->user->id,
            'requires_count_2' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertSame(
            'Sales blocking is only available for location, full-inventory and product-at-location counts',
            $response->json('error.errors.block_sales.0'),
        );
        $this->assertSame(0, InventoryCounting::query()->where('block_sales', true)->count());
    }

    public function test_category_scope_rejects_block_sales(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/inventory/countings', [
            'scope_type' => CountingScopeType::Category->value,
            'scope_filters' => ['category_ids' => ['7f8b0a2c-0f1e-4a3b-9c6d-2b1e5f0a7c31']],
            'block_sales' => true,
            'count_1_user_id' => (string) $this->user->id,
            'requires_count_2' => false,
        ]);

        $response->assertStatus(422);
        $this->assertSame(
            'Sales blocking is only available for location, full-inventory and product-at-location counts',
            $response->json('error.errors.block_sales.0'),
        );
    }

    /**
     * The refusal must not narrow the three scopes the engine DOES enforce.
     */
    public function test_product_location_scope_still_accepts_block_sales(): void
    {
        $product = $this->makeProduct('BLOCK-PL');
        $this->setStock($product, '5.0000');

        $response = $this->actingAs($this->user)->postJson('/api/v1/inventory/countings', [
            'scope_type' => CountingScopeType::ProductLocation->value,
            'scope_filters' => ['product_ids' => [$product->id], 'location_id' => $this->location->id],
            'block_sales' => true,
            'count_1_user_id' => (string) $this->user->id,
            'requires_count_2' => false,
        ]);

        $response->assertStatus(201);
        $created = InventoryCounting::query()->where('id', $response->json('data.id'))->firstOrFail();
        $this->assertTrue($created->block_sales);
    }

    /**
     * Gate r2 IMPORTANT-2 — the zero-item refusal must exist on the WEB path too.
     *
     * `create()` generates the items and `activate()` never regenerated or
     * counted them, so a scope that resolved to nothing (products all at zero
     * on-hand at the chosen location, a zone with no placements) became a LIVE
     * counting with 0 items, assignments stamped total_items = 0 and a
     * COUNTING_ACTIVATED event recording an empty count. The mobile path
     * (activateDraft) was guarded in r1; this was the unguarded twin.
     */
    public function test_activate_refuses_a_web_created_counting_that_resolved_to_zero_items(): void
    {
        // A product that exists but holds no stock at the counted location:
        // getStockLevelsForScope ends in `where('quantity','>',0)`.
        $product = $this->makeProduct('EMPTY-SCOPE');

        $created = $this->actingAs($this->user)->postJson('/api/v1/inventory/countings', [
            'scope_type' => CountingScopeType::ProductLocation->value,
            'scope_filters' => ['product_ids' => [$product->id], 'location_id' => $this->location->id],
            'count_1_user_id' => (string) $this->user->id,
            'requires_count_2' => false,
        ]);

        $created->assertStatus(201);
        $countingId = $created->json('data.id');

        $counting = InventoryCounting::query()->where('id', $countingId)->firstOrFail();
        $statusBefore = $counting->status;
        $this->assertSame(0, $counting->items()->count(), 'Precondition: the scope resolved to nothing.');

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/inventory/countings/{$countingId}/activate");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'BUSINESS_ERROR');
        $this->assertStringContainsString(
            'Nothing to count in this scope',
            (string) $response->json('error.message'),
        );

        $fresh = InventoryCounting::query()->where('id', $countingId)->firstOrFail();
        $this->assertSame($statusBefore, $fresh->status, 'The refused activation must roll the status back.');
        $this->assertSame(0, $fresh->items()->count());
        $this->assertSame(
            0,
            $fresh->assignments()->where('count_number', 1)->whereNotNull('started_at')->count(),
            'No assignment may have been started by a refused activation.',
        );
        $this->assertDatabaseMissing('inventory_counting_events', [
            'counting_id' => $countingId,
            'event_type' => InventoryCountingEvent::COUNTING_ACTIVATED,
        ]);
    }

    /**
     * The guard must not refuse a legitimate activation: same shape, but the
     * product actually holds stock at the location.
     */
    public function test_activate_still_accepts_a_counting_with_items(): void
    {
        $product = $this->makeProduct('FULL-SCOPE');
        $this->setStock($product, '4.0000');

        $created = $this->actingAs($this->user)->postJson('/api/v1/inventory/countings', [
            'scope_type' => CountingScopeType::ProductLocation->value,
            'scope_filters' => ['product_ids' => [$product->id], 'location_id' => $this->location->id],
            'count_1_user_id' => (string) $this->user->id,
            'requires_count_2' => false,
        ]);
        $created->assertStatus(201);
        $countingId = $created->json('data.id');

        $this->actingAs($this->user)
            ->postJson("/api/v1/inventory/countings/{$countingId}/activate")
            ->assertStatus(200);

        $fresh = InventoryCounting::query()->where('id', $countingId)->firstOrFail();
        $this->assertSame(CountingStatus::Count1InProgress, $fresh->status);
        $this->assertGreaterThan(0, $fresh->items()->count());
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
        $noStockItem = $byProduct->get($noStock->id);
        $negativeItem = $byProduct->get($negative->id);
        $this->assertInstanceOf(InventoryCountingItem::class, $noStockItem, 'Never-received product must be counted.');
        $this->assertInstanceOf(InventoryCountingItem::class, $negativeItem);
        $this->assertSame('0.0000', (string) $noStockItem->theoretical_qty);
        $this->assertSame('-3.0000', (string) $negativeItem->theoretical_qty);
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

        $this->assertCount(2, $items, 'Node draft must generate one item per placed product.');
        $assignedItem1 = $items->get($assigned1->id);
        $assignedItem2 = $items->get($assigned2->id);
        $this->assertInstanceOf(InventoryCountingItem::class, $assignedItem1);
        $this->assertInstanceOf(InventoryCountingItem::class, $assignedItem2);
        $this->assertSame('4.0000', (string) $assignedItem1->theoretical_qty);
        $this->assertSame('0.0000', (string) $assignedItem2->theoretical_qty);
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
        $counterContent = $counterViewResponse->getContent();
        $this->assertIsString($counterContent);
        $this->assertStringNotContainsString('theoretical_qty', $counterContent);
    }

    /**
     * Review MINOR: the zone_ids exists-rule itself must reject tombstoned
     * nodes so the defense isn't single-layered (previously only
     * validateZonesBelongToLocation — which needs location_id present —
     * caught them).
     */
    public function test_zone_ids_rule_rejects_tombstoned_nodes_at_rule_level(): void
    {
        $zone = $this->makeZone('TDZ');
        $this->zoneService->softDeleteSubtree($zone);

        $request = new CreateCountingRequest(app(CompanyContext::class));
        $rules = $request->rules();

        $validator = Validator::make(
            ['scope_filters' => ['zone_ids' => [$zone->id]]],
            ['scope_filters.zone_ids.*' => $rules['scope_filters.zone_ids.*']],
        );

        $this->assertTrue(
            $validator->errors()->has('scope_filters.zone_ids.0'),
            'tombstoned node id must fail the exists rule itself',
        );

        // a live node still passes the same rule
        $live = $this->makeZone('LVZ');
        $validator = Validator::make(
            ['scope_filters' => ['zone_ids' => [$live->id]]],
            ['scope_filters.zone_ids.*' => $rules['scope_filters.zone_ids.*']],
        );
        $this->assertFalse($validator->errors()->has('scope_filters.zone_ids.0'));
    }
}
