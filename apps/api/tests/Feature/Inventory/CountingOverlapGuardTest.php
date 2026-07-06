<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\InventoryCountingService;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\Exceptions\OverlappingCountingException;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task B5: variant-aware overlapping-count guard.
 *
 * Activation (activate / activateDraft) must refuse to bring a counting into
 * an active status when any of its items intersect another ACTIVE counting's
 * items on (product_id, location_id, variant_id) — null-variant-aware.
 * Finalize re-validates the same guard.
 *
 * Countings and items are constructed directly (as CountingReasonTest.php
 * does) rather than through the scope/stock-level item generator: the guard
 * is pure item-grain overlap logic, independent of how the items were
 * populated, and building StockLevel + variant rows would add unrelated
 * complexity (variant consistency checks in StockAdjustmentService).
 */
final class CountingOverlapGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Location $otherLocation;

    private Product $productX;

    private Product $productY;

    private InventoryCountingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Overlap Guard Tenant',
            'slug' => 'overlap-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Overlap Guard Company',
            'legal_name' => 'Overlap Guard Company LLC',
            'tax_id' => 'OVL-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Overlap Guard User',
            'email' => 'overlap-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-OVL-01',
            'name' => 'Overlap Guard Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->otherLocation = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-OVL-02',
            'name' => 'Overlap Guard Second Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->productX = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'OVL-X',
            'name' => 'Overlap Product X',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '1.000',
        ]);

        $this->productY = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'OVL-Y',
            'name' => 'Overlap Product Y',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '1.000',
        ]);

        $this->service = app(InventoryCountingService::class);
    }

    public function test_activate_blocked_when_overlapping_active_counting_same_grain_null_variant(): void
    {
        $active = $this->createCounting(CountingStatus::Count1InProgress);
        $this->createItem($active, $this->productX, $this->location, null);

        $activating = $this->createCounting(CountingStatus::Draft);
        $this->createItem($activating, $this->productX, $this->location, null);

        $this->expectException(OverlappingCountingException::class);

        try {
            $this->service->activate($activating, $this->user);
        } finally {
            $this->assertSame(
                CountingStatus::Draft,
                $this->freshStatus($activating),
                'Activation must not have transitioned the counting when the guard fires.'
            );
        }
    }

    public function test_activate_allows_same_product_and_location_with_different_variants(): void
    {
        $variantOne = (string) Str::uuid();
        $variantTwo = (string) Str::uuid();

        $active = $this->createCounting(CountingStatus::Count1InProgress);
        $this->createItem($active, $this->productX, $this->location, $variantOne);

        $activating = $this->createCounting(CountingStatus::Draft);
        $this->createItem($activating, $this->productX, $this->location, $variantTwo);

        $this->service->activate($activating, $this->user);

        $this->assertSame(CountingStatus::Count1InProgress, $this->freshStatus($activating));
    }

    public function test_activate_allows_disjoint_products(): void
    {
        $active = $this->createCounting(CountingStatus::Count1InProgress);
        $this->createItem($active, $this->productX, $this->location, null);

        $activating = $this->createCounting(CountingStatus::Draft);
        $this->createItem($activating, $this->productY, $this->location, null);

        $this->service->activate($activating, $this->user);

        $this->assertSame(CountingStatus::Count1InProgress, $this->freshStatus($activating));
    }

    public function test_activate_allows_disjoint_locations(): void
    {
        $active = $this->createCounting(CountingStatus::Count1InProgress);
        $this->createItem($active, $this->productX, $this->location, null);

        $activating = $this->createCounting(CountingStatus::Draft);
        $this->createItem($activating, $this->productX, $this->otherLocation, null);

        $this->service->activate($activating, $this->user);

        $this->assertSame(CountingStatus::Count1InProgress, $this->freshStatus($activating));
    }

    public function test_activate_allows_when_conflicting_counting_was_cancelled(): void
    {
        $cancelled = $this->createCounting(CountingStatus::Cancelled);
        $this->createItem($cancelled, $this->productX, $this->location, null);

        $activating = $this->createCounting(CountingStatus::Draft);
        $this->createItem($activating, $this->productX, $this->location, null);

        $this->service->activate($activating, $this->user);

        $this->assertSame(CountingStatus::Count1InProgress, $this->freshStatus($activating));
    }

    public function test_activate_draft_blocked_when_overlapping_active_counting(): void
    {
        $active = $this->createCounting(CountingStatus::Count1InProgress);
        $this->createItem($active, $this->productX, $this->location, null);

        $draft = $this->createCounting(CountingStatus::Draft, [
            'count_1_user_id' => $this->user->id,
        ]);
        $this->createItem($draft, $this->productX, $this->location, null);

        $this->expectException(OverlappingCountingException::class);

        try {
            $this->service->activateDraft($draft, $this->company->id, $this->user, true);
        } finally {
            $this->assertSame(CountingStatus::Draft, $this->freshStatus($draft));
        }
    }

    public function test_finalize_reveralidates_and_aborts_on_overlap_introduced_after_activation(): void
    {
        // Counting A: activated first, holds product X at the shared location.
        $countingA = $this->createCounting(CountingStatus::Draft);
        $this->createItem($countingA, $this->productX, $this->location, null);
        $this->service->activate($countingA, $this->user);
        $this->assertSame(CountingStatus::Count1InProgress, $this->freshStatus($countingA));

        // Counting B: activates disjoint (product Y), so activation succeeds.
        $countingB = $this->createCounting(CountingStatus::Draft, [
            'requires_count_2' => false,
        ]);
        $itemB = $this->createItem($countingB, $this->productY, $this->location, null);
        $this->service->activate($countingB, $this->user);
        $countingB->refresh();
        $this->assertSame(CountingStatus::Count1InProgress, $countingB->status);

        // Resolve B's original item and drive it to pending_review, mirroring
        // what checkPhaseCompletion() would do once every item is counted.
        $itemB->resolution_method = ItemResolutionMethod::AutoAllMatch;
        $itemB->final_qty = $itemB->theoretical_qty;
        $itemB->save();
        $countingB->transitionTo(CountingStatus::Count1Completed);
        $countingB->transitionTo(CountingStatus::PendingReview);

        // An unexpected item lands on B that happens to match A's active
        // grain (product X @ shared location, null variant) — constructed
        // directly per the brief, since the normal "add unexpected item"
        // flow only mutates scope_filters.product_ids and does not
        // deterministically materialize a colliding item row.
        InventoryCountingItem::create([
            'counting_id' => $countingB->id,
            'product_id' => $this->productX->id,
            'location_id' => $this->location->id,
            'variant_id' => null,
            'theoretical_qty' => '0.0000',
            'final_qty' => '1.0000',
            'resolution_method' => ItemResolutionMethod::AutoAllMatch,
            'is_unexpected_item' => true,
        ]);

        $this->expectException(OverlappingCountingException::class);

        try {
            $this->service->finalize($countingB, $this->user);
        } finally {
            $this->assertSame(
                CountingStatus::PendingReview,
                $this->freshStatus($countingB),
                'Finalize must not transition the counting when the re-check fires.'
            );
        }
    }

    // --- Helpers ---

    private function freshStatus(InventoryCounting $counting): CountingStatus
    {
        return ($counting->fresh() ?? $counting)->status;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCounting(CountingStatus $status, array $overrides = []): InventoryCounting
    {
        $defaults = [
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->user->id,
            'scope_type' => CountingScopeType::FullInventory,
            'scope_filters' => [],
            'status' => $status,
            'requires_count_2' => false,
            'requires_count_3' => false,
            'allow_unexpected_items' => true,
            'count_1_user_id' => $this->user->id,
        ];

        return InventoryCounting::create(array_merge($defaults, $overrides));
    }

    private function createItem(
        InventoryCounting $counting,
        Product $product,
        Location $location,
        ?string $variantId,
    ): InventoryCountingItem {
        return InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'variant_id' => $variantId,
            'theoretical_qty' => '5.0000',
            'resolution_method' => ItemResolutionMethod::Pending,
        ]);
    }
}
