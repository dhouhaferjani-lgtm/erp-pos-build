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
use App\Modules\Inventory\Domain\InventoryCountingEvent;
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

    /**
     * B5 deadlock escape: a count sitting in PendingReview (blocked from
     * finalizing by the overlap guard, or simply awaiting review) must still
     * be cancellable, and cancellation must not post any stock movement.
     */
    public function test_pending_review_counting_can_be_cancelled_without_posting_stock_movements(): void
    {
        $counting = $this->createCounting(CountingStatus::Draft);
        $item = $this->createItem($counting, $this->productX, $this->location, null);
        $this->service->activate($counting, $this->user);

        $item->resolution_method = ItemResolutionMethod::AutoAllMatch;
        $item->final_qty = $item->theoretical_qty;
        $item->save();

        $counting->transitionTo(CountingStatus::Count1Completed);
        $counting->transitionTo(CountingStatus::PendingReview);

        $this->assertDatabaseCount('stock_movements', 0);

        $this->service->cancel($counting, 'no longer needed', $this->user);

        $this->assertSame(CountingStatus::Cancelled, $this->freshStatus($counting));
        $this->assertNotNull(($counting->fresh() ?? $counting)->cancelled_at);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('inventory_counting_events', [
            'counting_id' => $counting->id,
            'event_type' => InventoryCountingEvent::COUNTING_CANCELLED,
        ]);
    }

    /**
     * B5 deadlock: two countings each land in PendingReview holding an
     * overlapping (product, location, variant) grain — the guard blocks
     * BOTH finalize() calls, since each sees the other as an active
     * conflicting counting. Cancelling one is the only application-level
     * escape; the survivor must then finalize cleanly.
     */
    public function test_cancelling_one_of_two_mutually_blocking_countings_unblocks_the_other(): void
    {
        // Counting A: activates first, holding product X. No conflict yet.
        $countingA = $this->createCounting(CountingStatus::Draft);
        $itemAX = $this->createItem($countingA, $this->productX, $this->location, null);
        $this->service->activate($countingA, $this->user);

        $itemAX->resolution_method = ItemResolutionMethod::AutoAllMatch;
        $itemAX->final_qty = $itemAX->theoretical_qty;
        $itemAX->save();
        $countingA->transitionTo(CountingStatus::Count1Completed);
        $countingA->transitionTo(CountingStatus::PendingReview);

        // Counting B: activates on disjoint product Y, so activation succeeds.
        $countingB = $this->createCounting(CountingStatus::Draft, [
            'requires_count_2' => false,
        ]);
        $itemBY = $this->createItem($countingB, $this->productY, $this->location, null);
        $this->service->activate($countingB, $this->user);

        $itemBY->resolution_method = ItemResolutionMethod::AutoAllMatch;
        $itemBY->final_qty = $itemBY->theoretical_qty;
        $itemBY->save();
        $countingB->transitionTo(CountingStatus::Count1Completed);
        $countingB->transitionTo(CountingStatus::PendingReview);

        // An unexpected item lands on A that matches B's active grain
        // (product Y @ shared location, null variant) — constructed directly
        // as the normal "add unexpected item" flow does not deterministically
        // materialize a colliding row (see the finalize-revalidation test
        // above for the same rationale).
        InventoryCountingItem::create([
            'counting_id' => $countingA->id,
            'product_id' => $this->productY->id,
            'location_id' => $this->location->id,
            'variant_id' => null,
            'theoretical_qty' => '0.0000',
            'final_qty' => '1.0000',
            'resolution_method' => ItemResolutionMethod::AutoAllMatch,
            'is_unexpected_item' => true,
        ]);

        // Both are now mutually blocked: A's product-Y item conflicts with
        // B (active/PendingReview), and B's product-Y item conflicts with A
        // (also active/PendingReview).
        try {
            $this->service->finalize($countingA, $this->user);
            $this->fail('Expected finalize(countingA) to throw OverlappingCountingException.');
        } catch (OverlappingCountingException) {
            // expected
        }
        $this->assertSame(CountingStatus::PendingReview, $this->freshStatus($countingA));

        try {
            $this->service->finalize($countingB, $this->user);
            $this->fail('Expected finalize(countingB) to throw OverlappingCountingException.');
        } catch (OverlappingCountingException) {
            // expected
        }
        $this->assertSame(CountingStatus::PendingReview, $this->freshStatus($countingB));

        $this->assertDatabaseCount('stock_movements', 0);

        // The application-level escape: cancel one side of the deadlock.
        $this->service->cancel($countingB, 'breaking overlap deadlock', $this->user);
        $this->assertSame(CountingStatus::Cancelled, $this->freshStatus($countingB));
        $this->assertDatabaseCount('stock_movements', 0);

        // The survivor now finalizes cleanly: the only remaining conflicting
        // counting is Cancelled, which is not an ACTIVE_OVERLAP_STATUSES member.
        $this->service->finalize($countingA, $this->user);
        $this->assertSame(CountingStatus::Finalized, $this->freshStatus($countingA));
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
