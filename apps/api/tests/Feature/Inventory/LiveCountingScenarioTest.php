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
use App\Modules\Inventory\Application\Listeners\ApplyStockAdjustmentsOnCountingCompleted;
use App\Modules\Inventory\Application\Listeners\ExitOnboardingOnFullCountFinalized;
use App\Modules\Inventory\Application\Services\InventoryCountingService;
use App\Modules\Inventory\Application\Services\LocationNodeService;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Events\InventoryCountingCompleted;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task E1 — cross-layer, end-to-end "morning demo" scenario for live inventory
 * counting. One story test that walks the full stack the way the demo does:
 *
 *   POS sales (device movements) → zone-scoped live count (submit @ device
 *   time) → more POS sales → finalize (queued replay listener, no
 *   CompanyContext) → replay-reconciled opening balance → onboarding auto-exit
 *   on a qualifying full-location count.
 *
 * All arithmetic is hand-computed inline. The layers stitched together here —
 * device event time (`occurred_at`), skew-corrected count estimate
 * (`count_N_at_estimate` → `final_qty_as_of`), the (as_of, now] replay window,
 * onboarding first-count opening semantics, and the whole-location auto-exit —
 * each have their own focused sibling test (StockMovementOccurredAtTest,
 * CountTimestampSkewTest, ReplayFinalizeTest, OnboardingFirstCountTest,
 * ZoneScopedCountingTest, OnboardingLifecycleTest). This test proves they
 * compose correctly on the real create → activate → submit → finalize path.
 */
final class LiveCountingScenarioTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Product $product;

    private InventoryCountingService $countingService;

    private LocationNodeService $zoneService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Scenario Tenant',
            'slug' => 'scenario-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Scenario Company',
            'legal_name' => 'Scenario Company LLC',
            'tax_id' => 'SCN-TAX-'.uniqid(),
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
            'name' => 'Scenario User',
            'email' => 'scenario-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['inventory.view', 'inventory.adjust']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        // A brand-new parapharmacy location: sell-before-count onboarding, so
        // the first physical count of each line becomes its opening balance.
        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-SCN-'.uniqid(),
            'name' => 'Scenario Front Shop',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
            'onboarding_mode' => true,
        ]);

        // Product P with a known unit cost — this is both the opening-cost the
        // finalize gate requires and the WAC the opening balance will set.
        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'SCN-'.uniqid(),
            'name' => 'Scenario Product P',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '3.000000',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->countingService = app(InventoryCountingService::class);
        $this->zoneService = app(LocationNodeService::class);
    }

    /**
     * Record a POS-style sale of one unit as a device stock movement. POS sales
     * are `issue` / `pos_sale` — they never establish a supply-side baseline, so
     * a line that has only ever sold is still on its FIRST count (opening).
     *
     * @param  numeric-string  $before
     * @param  numeric-string  $after
     */
    private function posSale(string $before, string $after, CarbonImmutable $occurredAt): void
    {
        StockMovement::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'movement_type' => MovementType::Issue,
            'reason' => MovementReason::POSSale,
            'quantity' => bcsub($after, $before, 4),
            'quantity_before' => $before,
            'quantity_after' => $after,
            'occurred_at' => $occurredAt,
        ]);
    }

    /** @param  numeric-string  $quantity */
    private function setOnHand(string $quantity): void
    {
        StockLevel::updateOrCreate(
            [
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'product_id' => $this->product->id,
                'location_id' => $this->location->id,
                'variant_id' => null,
            ],
            ['quantity' => $quantity, 'reserved' => '0.0000'],
        );
    }

    public function test_morning_demo_live_count_reconciles_via_replay_and_exits_onboarding(): void
    {
        // Freeze the clock so every timestamp (device count estimate, replay
        // window bounds) is exact and the assertions are deterministic.
        $anchor = CarbonImmutable::now()->startOfSecond();
        $this->travelTo($anchor);

        // T = the instant the shelf is physically counted (one hour before now).
        $t = $anchor->subMinutes(60);

        // --- Before the count: two POS sales drive on-hand to -2. ---
        // Both are BEFORE T and outside the ±15min basket window, so the replay
        // MUST exclude them (they are already reflected in the counted shelf qty).
        $this->posSale('0.0000', '-1.0000', $t->subMinutes(45));
        $this->posSale('-1.0000', '-2.0000', $t->subMinutes(30));
        $this->setOnHand('-2.0000');

        // --- Zone-scoped live count (sales keep flowing: block_sales = false). ---
        $zone = $this->zoneService->createNode(
            tenantId: $this->tenant->id,
            locationId: $this->location->id,
            parentId: null,
            type: LocationNodeType::Zone,
            name: 'Front Shelf',
            code: 'A1',
        );
        $this->zoneService->assignProduct($this->tenant->id, $this->product->id, $this->location->id, $zone->id);

        $counting = $this->countingService->create([
            'scope_type' => CountingScopeType::Zone->value,
            'scope_filters' => ['location_id' => $this->location->id, 'zone_ids' => [$zone->id]],
            'block_sales' => false,
            'count_1_user_id' => (string) $this->user->id,
            'requires_count_2' => false,
        ], $this->user, $this->company->id);

        $this->countingService->activate($counting, $this->user);

        /** @var InventoryCountingItem $item */
        $item = $counting->items()->where('product_id', $this->product->id)->firstOrFail();

        // The counter finds 20 on the shelf and submits with the device clock.
        // device_now == server now (skew 0) so count_1_at_estimate == T exactly.
        $this->countingService->submitCount($item, 1, '20.0000', null, $this->user, $t, $anchor);

        $item->refresh();
        $this->assertSame($t->toIso8601String(), $item->count_1_at_estimate->toIso8601String(),
            'A skew-free device submit must estimate the count instant at T.');

        // --- After the count: three more POS sales drive on-hand to -5. ---
        // All AFTER T and inside the replay window (T, now] → net delta -3.
        $this->posSale('-2.0000', '-3.0000', $t->addMinutes(20));
        $this->posSale('-3.0000', '-4.0000', $t->addMinutes(30));
        $this->posSale('-4.0000', '-5.0000', $t->addMinutes(40));
        $this->setOnHand('-5.0000');

        // --- Finalize. Fake only the completion event so the queued listener
        // does not auto-run via afterCommit; we drive it explicitly below to
        // mirror the worker (no CompanyContext), per operational rule 20. ---
        Event::fake([InventoryCountingCompleted::class]);
        // submitCount transitioned a separately-loaded instance to PendingReview;
        // refresh this handle so finalize sees the current status.
        $counting->refresh();
        $this->countingService->finalize($counting, $this->user);

        $item->refresh();
        // finalize stamps the replay boundary from the supplying count estimate.
        $this->assertSame($t->toIso8601String(), $item->final_qty_as_of->toIso8601String());

        // Worker reality: no company is bound when the finalize listener runs.
        app(CompanyContext::class)->clear();
        app(ApplyStockAdjustmentsOnCountingCompleted::class)->handle(
            $this->completedEvent($counting),
        );
        // The listener persisted the audit/flags on its own item instance.
        $item->refresh();

        // --- Assert the replay-reconciled opening balance. ---
        // expected_now = final_qty + Σ(post-T deltas) = 20 + (-3) = 17.
        // adjustment  = expected_now - on_hand_now   = 17 - (-5) = +22.
        $this->assertSame('17.0000', StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->location->id)
            ->whereNull('variant_id')
            ->value('quantity'));

        /** @var StockMovement $opening */
        $opening = StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('movement_type', MovementType::Opening)
            ->where('reason', MovementReason::OpeningBalance)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('22.0000', (string) $opening->quantity);
        $this->assertSame('-5.0000', (string) $opening->quantity_before);
        $this->assertSame('17.0000', (string) $opening->quantity_after);
        $this->assertSame('3.000000', (string) $opening->unit_cost);

        // Onboarding first count posts an opening, NOT a shrinkage correction.
        $this->assertDatabaseMissing('stock_movements', [
            'product_id' => $this->product->id,
            'reason' => MovementReason::CountCorrection->value,
        ]);

        // Replay audit explains the reconciliation for the review page.
        $audit = $item->replay_audit;
        $this->assertIsArray($audit);
        $this->assertSame($t->toIso8601String(), $audit['windowFrom']);
        $this->assertSame('-3.0000', $audit['replayedDelta']);
        $this->assertSame('-5.0000', $audit['onHandAtApply']);
        $this->assertSame('17.0000', $audit['expectedAtApply']);

        // No blocking replay flags (basket window, negative-at-apply, pending cost).
        $this->assertEmpty($item->flag_reasons ?? []);

        // Opening set the product WAC to the entered cost (prior on-hand ≤ 0).
        $this->assertSame('3.000000', (string) Product::whereKey($this->product->id)->value('cost_price'));

        // Assign-as-you-count labelled P onto the counted zone.
        $this->assertDatabaseHas('product_placements', [
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'node_id' => $zone->id,
        ]);

        // A partial (zone) count never exits onboarding on its own.
        $this->assertTrue($this->location->fresh()->onboarding_mode);

        // --- A qualifying full-location count flips onboarding off (C3). ---
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $fullCount = $this->countingService->create([
            'scope_type' => CountingScopeType::Location->value,
            'scope_filters' => ['location_ids' => [$this->location->id]],
            'count_1_user_id' => (string) $this->user->id,
            'requires_count_2' => false,
        ], $this->user, $this->company->id);

        // Onboarding-location full count sweeps the whole active catalog.
        $this->assertTrue($fullCount->fresh()->includes_zero_stock);

        $this->countingService->activate($fullCount, $this->user);

        /** @var InventoryCountingItem $fullItem */
        $fullItem = $fullCount->items()->where('product_id', $this->product->id)->firstOrFail();
        // Count matches the freshly-established on-hand of 17 (no further drift).
        $this->countingService->submitCount($fullItem, 1, '17.0000', null, $this->user);

        $fullCount->refresh();
        $this->countingService->finalize($fullCount, $this->user);

        app(CompanyContext::class)->clear();
        app(ExitOnboardingOnFullCountFinalized::class)->handle(
            $this->completedEvent($fullCount),
        );

        $this->assertFalse($this->location->fresh()->onboarding_mode,
            'A finalized whole-location count must auto-exit onboarding mode.');

        $this->travelBack();
    }

    private function completedEvent(InventoryCounting $counting): InventoryCountingCompleted
    {
        return new InventoryCountingCompleted(
            countingId: $counting->id,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            locationId: $this->location->id,
            countingNumber: (string) $counting->counting_number,
            itemsCount: $counting->items()->count(),
            totalVariance: '0.0000',
            completedBy: (string) $this->user->id,
            completedAt: now()->toIso8601String(),
        );
    }
}
