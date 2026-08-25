<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Listeners\ApplyStockAdjustmentsOnCountingCompleted;
use App\Modules\Inventory\Application\Services\CountingReconciliationPayloadBuilder;
use App\Modules\Inventory\Application\Services\CountingReplayPreviewService;
use App\Modules\Inventory\Application\Services\InventoryCountingService;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Enums\ReplayPreviewMode;
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

final class PreFinalizeReplayPreviewTest extends TestCase
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

        CarbonImmutable::setTestNow('2026-07-28 12:00:00 UTC');

        $this->tenant = Tenant::create([
            'name' => 'Preview Tenant',
            'slug' => 'preview-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Preview Company',
            'legal_name' => 'Preview Company LLC',
            'tax_id' => 'PRV-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Preview User',
            'email' => 'preview-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-PRV-'.uniqid(),
            'name' => 'Preview Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
            'onboarding_mode' => false,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PRV-'.uniqid(),
            'name' => 'Preview Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '1.000000',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_preview_is_read_only_and_uses_the_auto_resolution_count_instant(): void
    {
        [$counting, $item] = $this->fixture();
        $stockBefore = StockLevel::query()->orderBy('id')->get()->toArray();
        $movementsBefore = StockMovement::query()->orderBy('id')->get()->toArray();
        $itemBefore = $item->fresh()?->getAttributes();

        $preview = app(CountingReplayPreviewService::class)->forItem($counting, $item);

        $this->assertNotNull($preview);
        $this->assertSame('-3.0000', $preview->movementsSinceCount);
        $this->assertSame('17.0000', $preview->expectedNow);
        $this->assertSame('5.0000', $preview->adjustment);
        $this->assertTrue($preview->willAutoPost);
        $this->assertNull($preview->blockedReason);
        $this->assertSame($stockBefore, StockLevel::query()->orderBy('id')->get()->toArray());
        $this->assertSame($movementsBefore, StockMovement::query()->orderBy('id')->get()->toArray());
        $this->assertSame($itemBefore, $item->fresh()?->getAttributes());

        $payload = app(CountingReconciliationPayloadBuilder::class)->transform(
            $item->load(['product', 'location']),
            $counting,
        );
        $this->assertSame([
            'mode' => 'timestamp_replay',
            'movements_since_count' => '-3.0000',
            'expected_now' => '17.0000',
            'adjustment' => '5.0000',
            'will_auto_post' => true,
            'blocked_reason' => null,
        ], $payload['replay_preview'] ?? null);
        $this->assertNull($this->product->unit_id);
        $this->assertSame(4, $payload['product']['quantity_decimals'] ?? null);
    }

    public function test_preview_matches_the_replay_adjustment_that_finalize_posts(): void
    {
        [$counting, $item] = $this->fixture();
        $preview = app(CountingReplayPreviewService::class)->forItem($counting, $item);
        $this->assertNotNull($preview);

        Event::fake([InventoryCountingCompleted::class]);
        app(InventoryCountingService::class)->finalize($counting, $this->user);
        $this->assertSame(CountingStatus::Finalized, $counting->fresh()?->status);
        $this->assertSame(
            '2026-07-28T10:00:00+00:00',
            $item->fresh()?->final_qty_as_of?->toIso8601String(),
        );

        app(CompanyContext::class)->clear();
        app(ApplyStockAdjustmentsOnCountingCompleted::class)->handle(
            new InventoryCountingCompleted(
                countingId: $counting->id,
                tenantId: $this->tenant->id,
                companyId: $this->company->id,
                locationId: $this->location->id,
                countingNumber: (string) $counting->counting_number,
                itemsCount: 1,
                totalVariance: '0.0000',
                completedBy: $this->user->id,
                completedAt: now()->toIso8601String(),
            )
        );

        $posted = StockMovement::query()
            ->where('reason', MovementReason::CountCorrection)
            ->latest('created_at')
            ->firstOrFail();
        $appliedItem = $item->fresh();

        $this->assertSame($preview->adjustment, (string) $posted->quantity);
        $this->assertSame($preview->expectedNow, (string) $posted->quantity_after);
        $this->assertSame($preview->expectedNow, $appliedItem->expected_qty_at_apply);
        $this->assertSame($preview->movementsSinceCount, $appliedItem->replay_audit['replayedDelta'] ?? null);
    }

    public function test_legacy_delta_line_still_exposes_the_adjustment_finalize_will_post(): void
    {
        [$counting, $item] = $this->fixture();
        $item->count_1_at_estimate = null;
        $item->save();

        $preview = app(CountingReplayPreviewService::class)->forItem($counting, $item);

        $this->assertNotNull($preview);
        $this->assertSame(ReplayPreviewMode::LegacyDelta, $preview->mode);
        $this->assertNull($preview->movementsSinceCount);
        $this->assertSame('17.0000', $preview->expectedNow);
        $this->assertSame('5.0000', $preview->adjustment);
        $this->assertTrue($preview->willAutoPost);
    }

    /**
     * 🚨 Campaign W4-6 rewrote this sentinel. `basket_window` is an ANNOTATION,
     * not a veto — so the preview must promise an auto-post, name no blocking
     * reason, and the apply must both stamp the reason and post the correction.
     * The preview promising a skip was half of why an operator could read
     * "finalized successfully" over an unapplied two-unit shrinkage.
     */
    public function test_basket_window_preview_promises_the_post_it_will_make(): void
    {
        [$counting, $item] = $this->fixture();
        $this->movement('12.0000', '11.0000', CarbonImmutable::parse('2026-07-28 10:05:00 UTC'));

        $preview = app(CountingReplayPreviewService::class)->forItem($counting, $item);
        $this->assertNotNull($preview);
        $this->assertTrue($preview->willAutoPost);
        $this->assertNull($preview->blockedReason);

        $counting->status = CountingStatus::Finalized;
        $counting->finalized_at = now();
        $counting->save();
        $item->final_qty_as_of = $item->count_1_at_estimate;
        $item->save();

        app(CompanyContext::class)->clear();
        app(ApplyStockAdjustmentsOnCountingCompleted::class)->handle(
            new InventoryCountingCompleted(
                countingId: $counting->id,
                tenantId: $this->tenant->id,
                companyId: $this->company->id,
                locationId: $this->location->id,
                countingNumber: (string) $counting->counting_number,
                itemsCount: 1,
                totalVariance: '0.0000',
                completedBy: $this->user->id,
                completedAt: now()->toIso8601String(),
            )
        );

        $applied = $item->fresh();
        $this->assertNotNull($applied);
        $this->assertContains('basket_window', $applied->flag_reasons ?? []);

        $posted = StockMovement::query()
            ->where('product_id', $item->product_id)
            ->where('reason', MovementReason::CountCorrection)
            ->sole();
        $this->assertSame($preview->adjustment, (string) $posted->quantity);
        $this->assertSame($preview->expectedNow, (string) $posted->quantity_after);
    }

    public function test_negative_at_apply_preview_matches_the_flag_stamped_at_apply(): void
    {
        [$counting, $item] = $this->fixture();
        $item->count_1_qty = '1.0000';
        $item->final_qty = '1.0000';
        $item->save();

        $this->assertPreviewAndApplyBlockedBy($counting, $item, 'negative_at_apply');
    }

    public function test_pending_opening_cost_preview_matches_the_flag_stamped_at_apply(): void
    {
        [$counting, $item] = $this->fixture();
        $this->location->onboarding_mode = true;
        $this->location->save();
        $this->product->cost_price = '0.000000';
        $this->product->save();

        $this->assertPreviewAndApplyBlockedBy($counting, $item, 'pending_opening_cost');
    }

    /** @return array{InventoryCounting, InventoryCountingItem} */
    private function fixture(): array
    {
        $countedAt = CarbonImmutable::parse('2026-07-28 10:00:00 UTC');

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => '12.0000',
            'reserved' => '0.0000',
        ]);

        StockMovement::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'movement_type' => MovementType::Issue,
            'quantity' => '-3.0000',
            'quantity_before' => '15.0000',
            'quantity_after' => '12.0000',
            'occurred_at' => $countedAt->addHour(),
        ]);

        $counting = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_id' => $this->location->id],
            'counting_number' => 'CP'.uniqid(), // <= 20 chars: inventory_countings.counting_number is varchar(20)
            'status' => CountingStatus::PendingReview,
            'ambiguity_window_minutes' => 15,
            'created_by_user_id' => $this->user->id,
        ]);

        $item = InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '15.0000',
            'count_1_qty' => '20.0000',
            'count_1_at_estimate' => $countedAt,
            'final_qty' => '20.0000',
            'resolution_method' => ItemResolutionMethod::AutoAllMatch,
        ]);

        return [$counting, $item];
    }

    /**
     * @param  numeric-string  $before
     * @param  numeric-string  $after
     */
    private function movement(string $before, string $after, CarbonImmutable $occurredAt): StockMovement
    {
        return StockMovement::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'movement_type' => MovementType::Issue,
            'quantity' => bcsub($after, $before, 4),
            'quantity_before' => $before,
            'quantity_after' => $after,
            'occurred_at' => $occurredAt,
        ]);
    }

    private function assertPreviewAndApplyBlockedBy(
        InventoryCounting $counting,
        InventoryCountingItem $item,
        string $expectedReason,
    ): void {
        $preview = app(CountingReplayPreviewService::class)->forItem($counting, $item);
        $this->assertNotNull($preview);
        $this->assertFalse($preview->willAutoPost);
        $this->assertSame($expectedReason, $preview->blockedReason);

        $counting->status = CountingStatus::Finalized;
        $counting->finalized_at = now();
        $counting->save();
        $item->final_qty_as_of = $item->count_1_at_estimate;
        $item->save();

        app(CompanyContext::class)->clear();
        app(ApplyStockAdjustmentsOnCountingCompleted::class)->handle(
            new InventoryCountingCompleted(
                countingId: $counting->id,
                tenantId: $this->tenant->id,
                companyId: $this->company->id,
                locationId: $this->location->id,
                countingNumber: (string) $counting->counting_number,
                itemsCount: 1,
                totalVariance: '0.0000',
                completedBy: $this->user->id,
                completedAt: now()->toIso8601String(),
            )
        );

        $applied = $item->fresh();
        $this->assertNotNull($applied);
        $this->assertContains($expectedReason, $applied->flag_reasons ?? []);
        $this->assertDatabaseMissing('stock_movements', [
            'product_id' => $item->product_id,
            'reason' => MovementReason::CountCorrection->value,
        ]);
    }
}
