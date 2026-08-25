<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Listeners\ApplyStockAdjustmentsOnCountingCompleted;
use App\Modules\Inventory\Application\Services\InventoryCountingService;
use App\Modules\Inventory\Domain\Enums\CountingItemFlagReason;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Task B3 — replay-based count finalize (cases a, b, c, d, i, j).
 *
 * The listener replays stock movements in (final_qty_as_of, now], computes
 * expected_now = final_qty + Σ signed_delta and adjustment = expected_now −
 * on_hand_now, applying it under the same lock order as adjust(). Guards flag
 * (basket window, negative-at-apply) and skip posting. Items with
 * final_qty_as_of IS NULL take the exact legacy final − theoretical path.
 */
final class ReplayFinalizeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Replay Tenant',
            'slug' => 'replay-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Replay Company',
            'legal_name' => 'Replay Company LLC',
            'tax_id' => 'RPL-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Replay User',
            'email' => 'replay-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-RPL-'.uniqid(),
            'name' => 'Replay Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
            'onboarding_mode' => false,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'RPL-'.uniqid(),
            'name' => 'Replay Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '1.000000',
        ]);
    }

    /** Set the current on-hand for the product's stock line. */
    private function setOnHand(string $quantity): StockLevel
    {
        return StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => $quantity,
            'reserved' => '0.0000',
        ]);
    }

    /**
     * Insert a raw movement with an explicit signed delta and event time.
     *
     * @param  numeric-string  $before
     * @param  numeric-string  $after
     */
    private function movement(
        string $before,
        string $after,
        CarbonImmutable $occurredAt,
        MovementType $type = MovementType::Issue,
        ?MovementReason $reason = null,
    ): StockMovement {
        return StockMovement::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'movement_type' => $type,
            'reason' => $reason,
            'quantity' => bcsub($after, $before, 4),
            'quantity_before' => $before,
            'quantity_after' => $after,
            'occurred_at' => $occurredAt,
        ]);
    }

    private function counting(int $windowMinutes = 15): InventoryCounting
    {
        return InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_id' => $this->location->id],
            'counting_number' => 'CNT-RPL-'.uniqid(),
            'status' => CountingStatus::Finalized,
            'ambiguity_window_minutes' => $windowMinutes,
            'created_by_user_id' => $this->user->id,
        ]);
    }

    private function item(
        InventoryCounting $counting,
        string $finalQty,
        ?CarbonImmutable $finalQtyAsOf,
        string $theoretical = '0.0000',
        ItemResolutionMethod $method = ItemResolutionMethod::AutoAllMatch,
        ?string $openingUnitCost = null,
    ): InventoryCountingItem {
        return InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => $theoretical,
            'final_qty' => $finalQty,
            'final_qty_as_of' => $finalQtyAsOf,
            'opening_unit_cost' => $openingUnitCost,
            'resolution_method' => $method,
        ]);
    }

    private function fire(InventoryCounting $counting): void
    {
        app(ApplyStockAdjustmentsOnCountingCompleted::class)->handle(new InventoryCountingCompleted(
            countingId: $counting->id,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            locationId: $this->location->id,
            countingNumber: (string) $counting->counting_number,
            itemsCount: 1,
            totalVariance: '0.0000',
            completedBy: $this->user->id,
            completedAt: now()->toIso8601String(),
        ));
    }

    // (a) count 20 @ T, 3 post-T sales netting −3, on-hand −5 → adjustment +22, ends 17.
    public function test_case_a_replay_forward_over_post_count_sales(): void
    {
        $t = CarbonImmutable::now()->subHours(3);
        $this->setOnHand('-5.0000');

        $this->movement('10.0000', '9.0000', $t->addMinutes(30));
        $this->movement('9.0000', '8.0000', $t->addMinutes(60));
        $this->movement('8.0000', '7.0000', $t->addMinutes(90));

        $counting = $this->counting();
        $this->item($counting, '20.0000', $t);

        $this->fire($counting);

        $this->assertSame('17.0000', StockLevel::where('product_id', $this->product->id)->value('quantity'));
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'movement_type' => MovementType::Adjustment->value,
            'reason' => MovementReason::CountCorrection->value,
            'quantity' => '22.0000',
        ]);
    }

    // (b) a sale before T is not double-deducted (excluded from the replay window).
    public function test_case_b_pre_count_sale_excluded(): void
    {
        $t = CarbonImmutable::now()->subHours(3);
        $this->setOnHand('12.0000');

        // Pre-T sale of 5 — already reflected in the counted shelf quantity.
        $this->movement('20.0000', '15.0000', $t->subHour());
        // Post-T sale of 3.
        $this->movement('15.0000', '12.0000', $t->addMinutes(30));

        $counting = $this->counting();
        $this->item($counting, '20.0000', $t);

        $this->fire($counting);

        // expected_now = 20 + (−3) = 17 (pre-T −5 excluded); NOT 12.
        $this->assertSame('17.0000', StockLevel::where('product_id', $this->product->id)->value('quantity'));
    }

    /**
     * (c) a movement 5 min from T (window 15) → basket_window is ANNOTATED and
     * the correction still posts.
     *
     * 🚨 Campaign W4-6 rewrote this sentinel. It used to pin "nothing posted",
     * which is exactly the behaviour that made a finalized count report zero
     * variance and change nothing: in a shop that keeps selling while it counts,
     * every counted line has moved within ±15 minutes of being counted. The
     * replay — `expected_now = 20 + (−1) = 19` here — is what keeps the nearby
     * sale counted exactly once; the nearby movement is evidence for the
     * reviewer, not grounds to leave shelf and ledger disagreeing.
     */
    public function test_case_c_basket_window_annotates_but_still_posts(): void
    {
        $t = CarbonImmutable::now()->subHours(3);
        $this->setOnHand('10.0000');
        $this->movement('11.0000', '10.0000', $t->addMinutes(5));

        $counting = $this->counting(15);
        $item = $this->item($counting, '20.0000', $t);

        $this->fire($counting);

        // 20 counted at T, 1 sold after T → 19 expected, 10 on hand → +9.
        $this->assertSame('19.0000', StockLevel::where('product_id', $this->product->id)->value('quantity'));
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'movement_type' => MovementType::Adjustment->value,
            'reason' => MovementReason::CountCorrection->value,
            'quantity' => '9.0000',
        ]);

        $item->refresh();
        $this->assertContains(CountingItemFlagReason::BasketWindow->value, $item->flag_reasons ?? []);
        $this->assertTrue($item->is_flagged, 'the reviewer still sees the ambiguity');
        $this->assertNotNull($item->replay_audit);
    }

    /**
     * Document-per-action: a count that AGREES with the shelf writes no movement
     * at all. The campaign found the inverse — the only rows written were
     * `qty 0.0000, 25 -> 25` no-ops for the two agreeing lines, while the two
     * real variances were suppressed.
     */
    public function test_an_agreeing_line_writes_no_movement(): void
    {
        $t = CarbonImmutable::now()->subHours(3);
        $this->setOnHand('25.0000');

        $counting = $this->counting(15);
        $item = $this->item($counting, '25.0000', $t, theoretical: '25.0000');

        $this->fire($counting);

        $this->assertSame('25.0000', StockLevel::where('product_id', $this->product->id)->value('quantity'));
        $this->assertDatabaseMissing('stock_movements', [
            'product_id' => $this->product->id,
            'reason' => MovementReason::CountCorrection->value,
        ]);

        // The line was still evaluated — the audit is the proof.
        $item->refresh();
        $this->assertNotNull($item->replay_audit);
    }

    // (d) non-onboarding negative-at-apply → negative_at_apply flag, nothing posted.
    public function test_case_d_negative_at_apply_flags_and_skips(): void
    {
        $t = CarbonImmutable::now()->subHours(3);
        $this->setOnHand('2.0000');
        // Big post-T sale drives expected_now negative: 2 + (−10) = −8.
        $this->movement('12.0000', '2.0000', $t->addMinutes(45));

        $counting = $this->counting(15);
        $item = $this->item($counting, '2.0000', $t);

        $this->fire($counting);

        $this->assertSame('2.0000', StockLevel::where('product_id', $this->product->id)->value('quantity'));
        $this->assertDatabaseMissing('stock_movements', [
            'product_id' => $this->product->id,
            'reason' => MovementReason::CountCorrection->value,
        ]);

        $item->refresh();
        $this->assertContains(CountingItemFlagReason::NegativeAtApply->value, $item->flag_reasons ?? []);
        $this->assertTrue($item->is_flagged);
    }

    // (i) final_qty_as_of IS NULL → exact legacy final − theoretical delta path.
    public function test_case_i_legacy_delta_when_as_of_null(): void
    {
        $this->setOnHand('10.0000');

        $counting = $this->counting();
        $this->item($counting, '12.0000', null, theoretical: '10.0000');

        $this->fire($counting);

        // Legacy: delta = 12 − 10 = 2 applied to current 10 → 12.
        $this->assertSame('12.0000', StockLevel::where('product_id', $this->product->id)->value('quantity'));
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'movement_type' => MovementType::Adjustment->value,
            'reason' => MovementReason::CountCorrection->value,
        ]);
    }

    // (j) manual override → final_qty_as_of stamped to resolved_at.
    public function test_case_j_manual_override_as_of_is_resolved_at(): void
    {
        $counting = $this->counting();
        $counting->status = CountingStatus::PendingReview;
        $counting->save();

        $item = InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '10.0000',
            'resolution_method' => ItemResolutionMethod::Pending,
        ]);

        /** @var InventoryCountingService $service */
        $service = app(InventoryCountingService::class);
        $service->manualOverride($item, '15.0000', 'counted by hand', $this->user);

        $item->refresh();
        $this->assertNotNull($item->final_qty_as_of);
        $this->assertNotNull($item->resolved_at);
        $this->assertSame(
            $item->resolved_at->toIso8601String(),
            $item->final_qty_as_of->toIso8601String(),
        );
    }

    // finalize() stamps auto-resolved items' final_qty_as_of from the supplying count estimate.
    public function test_finalize_stamps_final_qty_as_of_from_count_estimate(): void
    {
        // Fake only the finalize event so the queued stock listener does not run;
        // Eloquent model events (the counting-event hash chain) must still fire.
        Event::fake([InventoryCountingCompleted::class]);

        $estimate = CarbonImmutable::now()->subHour();
        $counting = $this->counting();
        $counting->status = CountingStatus::PendingReview;
        $counting->save();

        $item = InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '10.0000',
            'count_1_qty' => '13.0000',
            'count_1_at_estimate' => $estimate,
            'final_qty' => '13.0000',
            'resolution_method' => ItemResolutionMethod::AutoCountersAgree,
        ]);

        /** @var InventoryCountingService $service */
        $service = app(InventoryCountingService::class);
        $service->finalize($counting, $this->user);

        $item->refresh();
        $this->assertNotNull($item->final_qty_as_of);
        $this->assertSame($estimate->toIso8601String(), $item->final_qty_as_of->toIso8601String());
    }

    // Queue-retry idempotency (replay path): re-running handle() on the same
    // finalized counting must NOT re-sum the already-posted correction. Without
    // the marker guard the posted correction (occurred_at now₁ > T) falls inside
    // the second attempt's replay window and inflates on-hand (17 → 39).
    public function test_retry_does_not_double_apply_replay_item(): void
    {
        $t = CarbonImmutable::now()->subHours(3);
        $this->setOnHand('-5.0000');
        $this->movement('10.0000', '9.0000', $t->addMinutes(30));
        $this->movement('9.0000', '8.0000', $t->addMinutes(60));
        $this->movement('8.0000', '7.0000', $t->addMinutes(90));

        $counting = $this->counting();
        $item = $this->item($counting, '20.0000', $t);

        // Attempt 1 — applies once, ends at 17.
        $this->fire($counting);
        $this->assertSame('17.0000', StockLevel::where('product_id', $this->product->id)->value('quantity'));

        $item->refresh();
        $this->assertNotNull($item->replay_audit);
        $auditAfterFirst = $item->replay_audit;
        $this->assertSame(1, StockMovement::where('product_id', $this->product->id)
            ->where('reason', MovementReason::CountCorrection->value)->count());

        // Attempt 2 — queue retry of the WHOLE job. Marker guard must skip.
        $this->fire($counting);

        $this->assertSame('17.0000', StockLevel::where('product_id', $this->product->id)->value('quantity'));
        $this->assertSame(1, StockMovement::where('product_id', $this->product->id)
            ->where('reason', MovementReason::CountCorrection->value)->count());
        $item->refresh();
        $this->assertSame($auditAfterFirst, $item->replay_audit);
    }

    // Queue-retry idempotency (legacy path): the applied-marker is the posted
    // movement keyed by reference COUNTING:{number}; re-running must not apply
    // final − theoretical a second time on top of the corrected on-hand.
    public function test_retry_does_not_double_apply_legacy_item(): void
    {
        $this->setOnHand('10.0000');
        $counting = $this->counting();
        $reference = 'COUNTING:'.$counting->counting_number;
        $this->item($counting, '12.0000', null, theoretical: '10.0000');

        // Attempt 1 — legacy delta 12 − 10 = 2 → on-hand 12.
        $this->fire($counting);
        $this->assertSame('12.0000', StockLevel::where('product_id', $this->product->id)->value('quantity'));
        $this->assertSame(1, StockMovement::where('product_id', $this->product->id)
            ->where('reference', $reference)->count());

        // Attempt 2 — retry. Existing-movement guard must skip.
        $this->fire($counting);
        $this->assertSame('12.0000', StockLevel::where('product_id', $this->product->id)->value('quantity'));
        $this->assertSame(1, StockMovement::where('product_id', $this->product->id)
            ->where('reference', $reference)->count());
    }

    // Partial-failure shape: item A applied on attempt 1 (marker + movement),
    // item B not yet reached. The retry must skip A and post ONLY B.
    public function test_partial_failure_retry_posts_only_unapplied_items(): void
    {
        $t = CarbonImmutable::now()->subHours(3);

        // Product A — simulate a successful attempt-1 apply: on-hand already 17,
        // marker stamped, its correction movement already posted.
        $this->setOnHand('17.0000');
        $counting = $this->counting();
        $itemA = $this->item($counting, '20.0000', $t);
        $itemA->replay_audit = [
            'windowFrom' => $t->toIso8601String(),
            'windowTo' => CarbonImmutable::now()->toIso8601String(),
            'replayedDelta' => '0.0000',
            'onHandAtApply' => '17.0000',
            'expectedAtApply' => '17.0000',
        ];
        $itemA->expected_qty_at_apply = '17.0000';
        $itemA->save();
        $this->movement('0.0000', '17.0000', CarbonImmutable::now(), MovementType::Adjustment, MovementReason::CountCorrection);

        // Product B — a second line NOT yet applied (replay_audit null).
        $productB = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'RPL-B-'.uniqid(),
            'name' => 'Replay Product B',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '1.000000',
        ]);
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $productB->id,
            'location_id' => $this->location->id,
            'quantity' => '10.0000',
            'reserved' => '0.0000',
        ]);
        $itemB = InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $productB->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '0.0000',
            'final_qty' => '15.0000',
            'final_qty_as_of' => $t,
            'resolution_method' => ItemResolutionMethod::AutoAllMatch,
        ]);

        // Retry the whole job.
        $this->fire($counting);

        // A untouched: on-hand 17, still exactly one correction, marker intact.
        $this->assertSame('17.0000', StockLevel::where('product_id', $this->product->id)->value('quantity'));
        $this->assertSame(1, StockMovement::where('product_id', $this->product->id)
            ->where('reason', MovementReason::CountCorrection->value)->count());

        // B now applied exactly once: on-hand 15, one correction, marker set.
        $this->assertSame('15.0000', StockLevel::where('product_id', $productB->id)->value('quantity'));
        $this->assertSame(1, StockMovement::where('product_id', $productB->id)
            ->where('reason', MovementReason::CountCorrection->value)->count());
        $itemB->refresh();
        $this->assertNotNull($itemB->replay_audit);
    }

    /**
     * DPA Wave 3D — T21's cost basis, on BOTH counting paths.
     *
     * Before T21 both paths wrote a NULL `unit_cost` (§0t.6), so a GL leg valued
     * at post time would have posted `amount = 0` — a silent zero-value
     * shrinkage. The cost is now resolved once from
     * `Product::resolveMovementUnitCost()` BEFORE the movement is created and
     * written ON it, which is what lets the GL amount be derived from the row
     * rather than from a since-changed WAC.
     *
     * Driver-agnostic on purpose: this pins the persisted row, not the posting.
     * The posting itself needs a real root commit and lives in the `[PG]`
     * CountCorrectionGlPostingTest.
     */
    public function test_both_counting_paths_persist_the_row_unit_cost(): void
    {
        $this->product->update(['cost_price' => '7.500000']);

        // REPLAY path — the movement postCountCorrection() writes.
        $t = CarbonImmutable::now()->subHours(3);
        $this->setOnHand('10.0000');
        $replayCounting = $this->counting();
        $this->item($replayCounting, '6.0000', $t);
        $this->fire($replayCounting);

        $replayMovement = StockMovement::where('product_id', $this->product->id)
            ->where('reason', MovementReason::CountCorrection->value)
            ->sole();
        $this->assertSame(MovementType::Adjustment, $replayMovement->movement_type);
        $this->assertSame('7.500000', (string) $replayMovement->unit_cost);

        // LEGACY path — final_qty_as_of IS NULL, same one basis.
        $legacyCounting = $this->counting();
        $this->item($legacyCounting, '3.0000', null, theoretical: '6.0000');
        $this->fire($legacyCounting);

        $legacyMovement = StockMovement::where('product_id', $this->product->id)
            ->where('reason', MovementReason::CountCorrection->value)
            ->where('reference', 'COUNTING:'.$legacyCounting->counting_number)
            ->sole();
        $this->assertSame('7.500000', (string) $legacyMovement->unit_cost);
    }
}
