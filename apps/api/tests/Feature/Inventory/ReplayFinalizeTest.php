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

    // (c) a movement 5 min from T (window 15) → basket_window flag, nothing posted.
    public function test_case_c_basket_window_flags_and_skips(): void
    {
        $t = CarbonImmutable::now()->subHours(3);
        $this->setOnHand('10.0000');
        $this->movement('11.0000', '10.0000', $t->addMinutes(5));

        $counting = $this->counting(15);
        $item = $this->item($counting, '20.0000', $t);

        $this->fire($counting);

        $this->assertSame('10.0000', StockLevel::where('product_id', $this->product->id)->value('quantity'));
        $this->assertDatabaseMissing('stock_movements', [
            'product_id' => $this->product->id,
            'reason' => MovementReason::CountCorrection->value,
        ]);

        $item->refresh();
        $this->assertContains(CountingItemFlagReason::BasketWindow->value, $item->flag_reasons ?? []);
        $this->assertTrue($item->is_flagged);
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
}
