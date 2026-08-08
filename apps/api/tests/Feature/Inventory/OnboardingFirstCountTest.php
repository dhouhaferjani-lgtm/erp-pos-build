<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Listeners\ApplyStockAdjustmentsOnCountingCompleted;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Events\InventoryCountingCompleted;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\Services\FirstCountDetector;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task B3 — onboarding first-count opening semantics (cases e, f, g, h).
 *
 * In onboarding mode, the FIRST count of a stock line (no prior supply-side
 * baseline movement) posts the replay-computed adjustment as an opening balance
 * (MovementType::Opening / MovementReason::OpeningBalance) with cost, setting or
 * blending the WAC. Any prior opening / receipt (GRN) / transfer_in makes the
 * count a normal count_correction. POS sales/returns never block opening.
 */
final class OnboardingFirstCountTest extends TestCase
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
            'name' => 'Onboard Tenant',
            'slug' => 'onboard-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Onboard Company',
            'legal_name' => 'Onboard Company LLC',
            'tax_id' => 'ONB-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Onboard User',
            'email' => 'onboard-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-ONB-'.uniqid(),
            'name' => 'Onboard Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
            'onboarding_mode' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'ONB-'.uniqid(),
            'name' => 'Onboard Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '0.000000',
        ]);
    }

    private function priorMovement(
        MovementType $type,
        ?MovementReason $reason,
        ?StockMovementReferenceType $referenceType = null,
    ): void {
        StockMovement::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'movement_type' => $type,
            'reason' => $reason,
            'quantity' => '1.0000',
            'quantity_before' => '0.0000',
            'quantity_after' => '1.0000',
            'reference_type' => $referenceType?->value,
            'reference_id' => $referenceType !== null ? (string) Str::uuid() : null,
            'occurred_at' => CarbonImmutable::now()->subDays(2),
        ]);
    }

    private function fireOnboardingCount(string $finalQty, ?string $openingUnitCost, ?CarbonImmutable $asOf = null): InventoryCountingItem
    {
        // No on-hand row yet — onboarding line starts at zero.
        $t = $asOf ?? CarbonImmutable::now()->subHours(2);

        $counting = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_id' => $this->location->id],
            'counting_number' => 'CNT-ONB-'.uniqid(),
            'status' => CountingStatus::Finalized,
            'ambiguity_window_minutes' => 15,
            'created_by_user_id' => $this->user->id,
        ]);

        $item = InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '0.0000',
            'final_qty' => $finalQty,
            'final_qty_as_of' => $t,
            'opening_unit_cost' => $openingUnitCost,
            'resolution_method' => ItemResolutionMethod::AutoAllMatch,
        ]);

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

        return $item;
    }

    // (e) onboarding first count → opening movement + WAC set to opening cost.
    public function test_case_e_first_count_posts_opening_and_sets_wac(): void
    {
        $this->fireOnboardingCount('10.0000', '3.000000');

        $this->assertSame('10.0000', StockLevel::where('product_id', $this->product->id)->value('quantity'));
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'movement_type' => MovementType::Opening->value,
            'reason' => MovementReason::OpeningBalance->value,
        ]);

        // Prior on-hand ≤ 0 → absolute WAC basis set to the opening cost.
        $this->assertSame('3.000000', Product::whereKey($this->product->id)->value('cost_price'));
    }

    // (e cont.) a second count (opening now exists) → count_correction, NOT opening.
    public function test_case_e_second_count_posts_correction(): void
    {
        // First count (and its opening movement) happens two days ago so it sits
        // well outside the second count's replay + basket windows.
        $this->travelTo(CarbonImmutable::now()->subDays(2));
        $this->fireOnboardingCount('10.0000', '3.000000');
        $this->travelBack();

        // Second count on the same line — a prior opening now exists → correction.
        $this->fireOnboardingCount('12.0000', '3.000000', CarbonImmutable::now());

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'movement_type' => MovementType::Adjustment->value,
            'reason' => MovementReason::CountCorrection->value,
        ]);
        $this->assertSame('12.0000', StockLevel::where('product_id', $this->product->id)->value('quantity'));
    }

    // (f) prior transfer_in → not first → count_correction.
    public function test_case_f_prior_transfer_in_is_not_first(): void
    {
        $this->priorMovement(MovementType::TransferIn, MovementReason::TransferIn);

        $detector = app(FirstCountDetector::class);
        $this->assertFalse($detector->isFirstCount($this->product->id, $this->location->id, null));

        $this->fireOnboardingCount('10.0000', '3.000000');

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'movement_type' => MovementType::Adjustment->value,
            'reason' => MovementReason::CountCorrection->value,
        ]);
        $this->assertDatabaseMissing('stock_movements', [
            'product_id' => $this->product->id,
            'movement_type' => MovementType::Opening->value,
        ]);
    }

    // (g) prior NULL-reason receipt (WAC purchase) → NOT first → count_correction.
    public function test_case_g_prior_null_reason_receipt_is_not_first(): void
    {
        $this->priorMovement(MovementType::Receipt, null);

        $detector = app(FirstCountDetector::class);
        $this->assertFalse($detector->isFirstCount($this->product->id, $this->location->id, null));

        $this->fireOnboardingCount('10.0000', '3.000000');

        $this->assertDatabaseMissing('stock_movements', [
            'product_id' => $this->product->id,
            'movement_type' => MovementType::Opening->value,
        ]);
    }

    // (h) only pos_sale / pos_return history → IS first → opening.
    public function test_case_h_only_pos_history_is_first(): void
    {
        $this->priorMovement(MovementType::Issue, MovementReason::POSSale);
        $this->priorMovement(MovementType::Receipt, MovementReason::POSReturn);

        $detector = app(FirstCountDetector::class);
        $this->assertTrue($detector->isFirstCount($this->product->id, $this->location->id, null));

        $this->fireOnboardingCount('10.0000', '3.000000');

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'movement_type' => MovementType::Opening->value,
            'reason' => MovementReason::OpeningBalance->value,
        ]);
    }

    // ------------------------------------------------------------------------
    // DPA V7 / T15 (plan D2a): the stock_adjustments document replaces the raw
    // manual `receive`, which used to establish the baseline as a NULL-reason
    // receipt. The baseline gains a THREE-column arm so the reclassification is
    // behaviour-preserving forward, and deploy-neutral backward.
    // ------------------------------------------------------------------------

    public function test_a_posted_stock_adjustment_positive_establishes_the_baseline(): void
    {
        $this->priorMovement(
            MovementType::Adjustment,
            MovementReason::AdjustmentPositive,
            StockMovementReferenceType::StockAdjustment,
        );

        $detector = app(FirstCountDetector::class);
        $this->assertFalse($detector->isFirstCount($this->product->id, $this->location->id, null));

        $this->fireOnboardingCount('10.0000', '3.000000');

        // The count posts as a CORRECTION, and the product's cost at rest is
        // untouched — applyOpeningWac() must not have run.
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'movement_type' => MovementType::Adjustment->value,
            'reason' => MovementReason::CountCorrection->value,
        ]);
        $this->assertDatabaseMissing('stock_movements', [
            'product_id' => $this->product->id,
            'movement_type' => MovementType::Opening->value,
        ]);
        $this->assertSame('0.000000', Product::whereKey($this->product->id)->value('cost_price'));
    }

    /**
     * The N-1 regression test: this is the assertion that proves deploy
     * neutrality. Today's shipped modal writes exactly
     * (movement_type='adjustment', reason='adjustment_positive') with
     * `reference_type IS NULL`. A two-column arm would retro-include every such
     * legacy row and silently stop the product's first count from setting WAC.
     */
    public function test_a_legacy_null_reference_type_adjustment_positive_row_is_not_a_baseline(): void
    {
        $this->priorMovement(MovementType::Adjustment, MovementReason::AdjustmentPositive, null);

        $detector = app(FirstCountDetector::class);
        $this->assertTrue(
            $detector->isFirstCount($this->product->id, $this->location->id, null),
            'A legacy manual adjustment (reference_type IS NULL) must NOT establish a baseline.'
        );

        $this->fireOnboardingCount('10.0000', '3.000000');

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'movement_type' => MovementType::Opening->value,
            'reason' => MovementReason::OpeningBalance->value,
        ]);
    }

    public function test_a_negative_stock_adjustment_does_not_establish_the_baseline(): void
    {
        $this->priorMovement(
            MovementType::Adjustment,
            MovementReason::AdjustmentNegative,
            StockMovementReferenceType::StockAdjustment,
        );

        $detector = app(FirstCountDetector::class);
        $this->assertTrue($detector->isFirstCount($this->product->id, $this->location->id, null));
    }

    public function test_count_correction_and_opening_balance_reasoned_adjustments_do_not_establish_the_baseline(): void
    {
        $detector = app(FirstCountDetector::class);

        $this->priorMovement(
            MovementType::Adjustment,
            MovementReason::CountCorrection,
            StockMovementReferenceType::StockAdjustment,
        );
        $this->assertTrue($detector->isFirstCount($this->product->id, $this->location->id, null));

        $this->priorMovement(
            MovementType::Adjustment,
            MovementReason::OpeningBalance,
            StockMovementReferenceType::StockAdjustment,
        );
        $this->assertTrue($detector->isFirstCount($this->product->id, $this->location->id, null));
    }
}
