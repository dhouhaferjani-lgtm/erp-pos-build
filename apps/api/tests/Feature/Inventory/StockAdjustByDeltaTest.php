<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Exceptions\InsufficientBatchStockException;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Events\StockMovementRecorded;
use App\Modules\Inventory\Domain\Events\StockMovementRecordedV2;
use App\Modules\Inventory\Domain\Exceptions\AdjustmentExceedsAvailableException;
use App\Modules\Inventory\Domain\Exceptions\StockMovedSinceAuthoringException;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * DPA V7 / T2 — the delta-native writer `StockAdjustmentService::adjustByDelta()`.
 *
 * Drives every assertion all the way to the persisted movement / stock_level /
 * BatchStock state rather than to a return value, because the defects this
 * method exists to close (the lost-update race and the two-directional lot
 * desync) are only visible at rest.
 */
final class StockAdjustByDeltaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Product $product;

    private StockAdjustmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Delta Tenant',
            'slug' => 'delta-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Delta Co',
            'legal_name' => 'Delta Co LLC',
            'tax_id' => 'TAX-DELTA',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Delta User',
            'email' => 'delta@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'DL-01',
            'name' => 'Delta Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'DL-PROD-001',
            'name' => 'Delta Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'requires_batch_tracking' => false,
        ]);

        $this->service = app(StockAdjustmentService::class);
    }

    // -------------------------------------------------------- the delta itself

    public function test_a_positive_delta_is_applied_to_whatever_the_row_holds(): void
    {
        $this->seedStock('10.0000');

        // Someone else commits +5 AFTER the operator authored a +2.
        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '5.0000',
            reference: 'INTERLEAVED',
            userId: $this->user->id,
        );

        $movement = $this->service->adjustByDelta(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            deltaQuantity: '2.0000',
            reference: 'ADJ-2026-0001',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentPositive,
        );

        // The interleaved receive SURVIVES — this is the whole point.
        $this->assertSame('17.0000', (string) $this->level()->quantity);
        $this->assertSame('15.0000', (string) $movement->quantity_before);
        $this->assertSame('17.0000', (string) $movement->quantity_after);
        $this->assertSame('2.0000', (string) $movement->quantity);
        $this->assertSame(MovementType::Adjustment, $movement->movement_type);
    }

    public function test_a_negative_delta_is_applied_and_persisted_signed(): void
    {
        $this->seedStock('10.0000');

        $movement = $this->service->adjustByDelta(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            deltaQuantity: '-3.5000',
            reference: 'ADJ-2026-0002',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentNegative,
        );

        $this->assertSame('6.5000', (string) $this->level()->quantity);
        $this->assertSame('-3.5000', (string) $movement->quantity);
    }

    public function test_a_zero_delta_is_refused(): void
    {
        $this->seedStock('10.0000');

        $this->expectException(InvalidArgumentException::class);

        $this->service->adjustByDelta(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            deltaQuantity: '0.0000',
            reference: 'ADJ-ZERO',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentPositive,
        );
    }

    public function test_costs_stay_null_because_v1_posts_no_gl(): void
    {
        $this->seedStock('10.0000');

        $movement = $this->service->adjustByDelta(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            deltaQuantity: '1.0000',
            reference: 'ADJ-COST',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentPositive,
        );

        $this->assertNull($movement->fresh()?->unit_cost);
        $this->assertNull($movement->fresh()?->total_cost);
    }

    // ------------------------------------------------------------- staleness

    public function test_an_observed_before_mismatch_is_refused_with_both_values(): void
    {
        $this->seedStock('10.0000');
        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '5.0000',
            reference: 'INTERLEAVED',
            userId: $this->user->id,
        );

        try {
            $this->service->adjustByDelta(
                productId: $this->product->id,
                locationId: $this->warehouse->id,
                deltaQuantity: '2.0000',
                reference: 'ADJ-STALE',
                userId: $this->user->id,
                reasonCode: MovementReason::AdjustmentPositive,
                observedBefore: '10.0000',
            );
            $this->fail('Expected StockMovedSinceAuthoringException.');
        } catch (StockMovedSinceAuthoringException $e) {
            $this->assertSame('10.0000', $e->observedBefore);
            $this->assertSame('15.0000', $e->quantityBefore);
            $this->assertSame($this->product->id, $e->productId);
            $this->assertNull($e->batchUuid);
        }

        // Nothing was written.
        $this->assertSame('15.0000', (string) $this->level()->quantity);
        $this->assertSame(0, StockMovement::where('reference', 'ADJ-STALE')->count());
    }

    public function test_acknowledge_stale_bypasses_the_staleness_guard(): void
    {
        $this->seedStock('10.0000');
        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '5.0000',
            reference: 'INTERLEAVED',
            userId: $this->user->id,
        );

        $movement = $this->service->adjustByDelta(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            deltaQuantity: '2.0000',
            reference: 'ADJ-ACK',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentPositive,
            observedBefore: '10.0000',
            acknowledgeStale: true,
        );

        // The DELTA is applied to the fresh baseline — never the stale absolute.
        $this->assertSame('17.0000', (string) $this->level()->quantity);
        $this->assertSame('15.0000', (string) $movement->quantity_before);
    }

    public function test_a_matching_observed_before_passes(): void
    {
        $this->seedStock('10.0000');

        $this->service->adjustByDelta(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            deltaQuantity: '2.0000',
            reference: 'ADJ-FRESH',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentPositive,
            observedBefore: '10.0000',
        );

        $this->assertSame('12.0000', (string) $this->level()->quantity);
    }

    // ------------------------------------- D1a: the reserved-aware boundary

    public function test_a_negative_delta_beyond_available_is_refused(): void
    {
        $level = $this->seedStock('5.0000');
        $level->update(['reserved' => '3.0000']);

        try {
            $this->service->adjustByDelta(
                productId: $this->product->id,
                locationId: $this->warehouse->id,
                deltaQuantity: '-4.0000',
                reference: 'ADJ-RESERVED',
                userId: $this->user->id,
                reasonCode: MovementReason::AdjustmentNegative,
            );
            $this->fail('Expected AdjustmentExceedsAvailableException.');
        } catch (AdjustmentExceedsAvailableException $e) {
            $this->assertSame('5.0000', $e->quantityBefore);
            $this->assertSame('3.0000', $e->reserved);
            $this->assertSame('2.0000', $e->available);
            $this->assertSame('-4.0000', $e->deltaQuantity);
        }

        $this->assertSame('5.0000', (string) $this->level()->quantity);
    }

    public function test_a_negative_delta_within_available_succeeds(): void
    {
        $level = $this->seedStock('5.0000');
        $level->update(['reserved' => '3.0000']);

        $this->service->adjustByDelta(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            deltaQuantity: '-2.0000',
            reference: 'ADJ-WITHIN',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentNegative,
        );

        $this->assertSame('3.0000', (string) $this->level()->quantity);
        $this->assertSame('0.0000', $this->level()->getAvailableQuantity());
    }

    public function test_ignore_reservations_overrides_the_boundary_and_leaves_available_negative(): void
    {
        $level = $this->seedStock('5.0000');
        $level->update(['reserved' => '3.0000']);

        $this->service->adjustByDelta(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            deltaQuantity: '-4.0000',
            reference: 'ADJ-OVERRIDE',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentNegative,
            ignoreReservations: true,
        );

        // The TRUTHFUL state: the stock is gone and the orders are still
        // promised, so `available` is negative and every downstream reader can
        // see it.
        $this->assertSame('1.0000', (string) $this->level()->quantity);
        $this->assertSame('-2.0000', $this->level()->getAvailableQuantity());
    }

    public function test_a_positive_delta_skips_the_guard_even_when_on_hand_is_already_negative(): void
    {
        // POS oversell paths write stock_levels directly, so a negative on-hand
        // is reachable and MUST stay correctable upward.
        $level = $this->seedStock('0.0000');
        $level->update(['quantity' => '-4.0000']);

        $this->service->adjustByDelta(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            deltaQuantity: '6.0000',
            reference: 'ADJ-RECOVER',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentPositive,
        );

        $this->assertSame('2.0000', (string) $this->level()->quantity);
    }

    // ------------------------------------------------- D8: reversal linkage

    public function test_reverses_movement_id_persists_and_a_second_reversal_is_refused(): void
    {
        $this->seedStock('10.0000');

        $original = $this->service->adjustByDelta(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            deltaQuantity: '4.0000',
            reference: 'ADJ-ORIGINAL',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentPositive,
        );

        $contra = $this->service->adjustByDelta(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            deltaQuantity: '-4.0000',
            reference: 'ADJ-CONTRA',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentNegative,
            reversesMovementId: $original->id,
        );

        $this->assertSame($original->id, $contra->fresh()?->reverses_movement_id);

        // stock_movements_reverses_movement_id_unique is a partial unique — a
        // second contra against the same movement must not be writable.
        $this->expectException(QueryException::class);
        $this->service->adjustByDelta(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            deltaQuantity: '-1.0000',
            reference: 'ADJ-CONTRA-2',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentNegative,
            reversesMovementId: $original->id,
        );
    }

    // ------------------------------------------------------ D1b: lot motion

    public function test_a_batch_tracked_positive_with_a_lot_moves_the_lot_and_the_aggregate_together(): void
    {
        $product = $this->batchTrackedProduct();
        $this->seedStock('100.0000', $product);
        $lot = $this->seedLot($product, 'LOT-A', '100.0000');

        $movement = $this->service->adjustByDelta(
            productId: $product->id,
            locationId: $this->warehouse->id,
            deltaQuantity: '5.0000',
            reference: 'ADJ-LOT-IN',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentPositive,
            batchId: $lot->id,
        );

        $this->assertSame('105.0000', (string) $this->level($product)->quantity);
        $this->assertSame('105.0000', $this->lotQuantity($lot));
        $this->assertSame('105.0000', $this->totalLotQuantity($product));

        // ensureDefaultBatch must NOT have run: no DEFAULT lot was minted.
        $this->assertSame(0, Batch::where('product_id', $product->id)->where('batch_number', 'DEFAULT')->count());

        // The lot movement is linked to the stock movement (a NOT-NULL FK on
        // inventory_batch_movements), unlike ensureDefaultBatch's movement-free path.
        $this->assertDatabaseHas('inventory_batch_movements', [
            'batch_id' => $lot->id,
            'movement_id' => $movement->id,
        ]);
    }

    public function test_a_batch_tracked_negative_with_a_lot_decrements_that_lot(): void
    {
        $product = $this->batchTrackedProduct();
        $this->seedStock('100.0000', $product);
        $lot = $this->seedLot($product, 'LOT-A', '100.0000');

        $this->service->adjustByDelta(
            productId: $product->id,
            locationId: $this->warehouse->id,
            deltaQuantity: '-10.0000',
            reference: 'ADJ-LOT-OUT',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentNegative,
            batchId: $lot->id,
        );

        $this->assertSame('90.0000', (string) $this->level($product)->quantity);
        $this->assertSame('90.0000', $this->lotQuantity($lot));
        $this->assertSame('90.0000', $this->totalLotQuantity($product));
    }

    public function test_a_lot_insufficient_negative_throws_with_the_shortfall(): void
    {
        $product = $this->batchTrackedProduct();
        $this->seedStock('100.0000', $product);
        // Magnitudes deliberately kept small and bcmath-safe: BatchStock's
        // available_quantity accessor returns a FLOAT, so a large integer part
        // can reach bccomp as scientific notation (plan §5 ticket, re-review N-4).
        $lot = $this->seedLot($product, 'LOT-A', '4.0000');

        try {
            $this->service->adjustByDelta(
                productId: $product->id,
                locationId: $this->warehouse->id,
                deltaQuantity: '-6.0000',
                reference: 'ADJ-LOT-SHORT',
                userId: $this->user->id,
                reasonCode: MovementReason::AdjustmentNegative,
                batchId: $lot->id,
            );
            $this->fail('Expected InsufficientBatchStockException.');
        } catch (InsufficientBatchStockException $e) {
            $this->assertSame('2.0000', $e->shortfall);
        }
    }

    /**
     * The C2 case T1 pinned as broken, INVERTED: a positive line with no lot on a
     * batch-tracked product tops the DEFAULT lot by the DELTA, so Σ lots equals
     * the aggregate even when a real lot already holds stock. Before V7 the
     * target-based helper inflated DEFAULT to the whole aggregate (Σ 205 vs 105).
     */
    public function test_a_batch_tracked_positive_with_no_lot_tops_the_default_lot_by_the_delta(): void
    {
        $product = $this->batchTrackedProduct();
        $this->seedStock('100.0000', $product);
        $realLot = $this->seedLot($product, 'LOT-REAL', '100.0000');

        $this->service->adjustByDelta(
            productId: $product->id,
            locationId: $this->warehouse->id,
            deltaQuantity: '5.0000',
            reference: 'ADJ-DEFAULT',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentPositive,
        );

        $this->assertSame('105.0000', (string) $this->level($product)->quantity);
        $this->assertSame('100.0000', $this->lotQuantity($realLot));

        $default = Batch::query()
            ->where('product_id', $product->id)
            ->where('batch_number', 'DEFAULT')
            ->firstOrFail();

        $this->assertSame('5.0000', $this->lotQuantity($default));
        $this->assertSame(
            (string) $this->level($product)->quantity,
            $this->totalLotQuantity($product),
            'Sigma BatchStock must equal the aggregate.'
        );
    }

    public function test_a_non_batch_tracked_positive_with_no_lot_mints_nothing(): void
    {
        $this->seedStock('10.0000');

        $this->service->adjustByDelta(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            deltaQuantity: '5.0000',
            reference: 'ADJ-NOLOT',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentPositive,
        );

        $this->assertSame(0, Batch::where('product_id', $this->product->id)->count());
    }

    // ---------------------------------------------------------- D2b / events

    public function test_an_adjustment_movement_is_not_a_landed_cost_candidate(): void
    {
        $this->seedStock('10.0000');

        $this->service->adjustByDelta(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            deltaQuantity: '5.0000',
            reference: 'ADJ-LANDED',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentPositive,
        );

        // LinkedCostApplicationService selects candidates by
        // movement_type = 'receipt' / 'issue'; BackfillGoodsReceiptsCommand by
        // 'receipt'. A manual entry through the document is neither — desirable
        // (landed cost on a found-stock correction is meaningless), but a real
        // behaviour delta on a cost-allocation path, so it is asserted.
        $this->assertSame(0, StockMovement::query()
            ->where('product_id', $this->product->id)
            ->whereIn('movement_type', [MovementType::Receipt->value, MovementType::Issue->value])
            ->count());
        $this->assertSame(1, StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('movement_type', MovementType::Adjustment->value)
            ->count());
    }

    public function test_the_dispatched_events_match_adjust_for_the_same_net_effect(): void
    {
        $this->seedStock('10.0000');
        $second = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'DL-PROD-002',
            'name' => 'Delta Product Two',
            'type' => ProductType::Part,
            'is_active' => true,
            'requires_batch_tracking' => false,
        ]);
        $this->seedStock('10.0000', $second);

        Event::fake([StockMovementRecorded::class, StockMovementRecordedV2::class]);

        $this->service->adjust(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            newQuantity: '13.0000',
            reason: 'SAME',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentPositive,
        );
        $this->service->adjustByDelta(
            productId: $second->id,
            locationId: $this->warehouse->id,
            deltaQuantity: '3.0000',
            reference: 'SAME',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentPositive,
        );

        $payloads = [];
        Event::assertDispatched(StockMovementRecorded::class, function (StockMovementRecorded $event) use (&$payloads): bool {
            $payloads[] = [
                'movementType' => $event->movementType,
                'quantity' => $event->quantity,
                'unitCost' => $event->unitCost,
                'totalCost' => $event->totalCost,
                'newStockLevel' => $event->newStockLevel,
                'reference' => $event->reference,
                'referenceType' => $event->referenceType,
                'referenceId' => $event->referenceId,
            ];

            return true;
        });

        $this->assertCount(2, $payloads);
        $this->assertSame($payloads[0], $payloads[1], 'adjust() and adjustByDelta() must emit the same V1 payload for the same net effect.');

        $v2 = [];
        Event::assertDispatched(StockMovementRecordedV2::class, function (StockMovementRecordedV2 $event) use (&$v2): bool {
            $v2[] = [
                'movementType' => $event->movementType,
                'quantity' => $event->quantity,
                'newStockLevel' => $event->newStockLevel,
                'variantId' => $event->variantId,
                'reference' => $event->reference,
            ];

            return true;
        });
        $this->assertCount(2, $v2);
        $this->assertSame($v2[0], $v2[1]);
    }

    public function test_document_linkage_is_persisted_and_read_back_off_the_row(): void
    {
        $this->seedStock('10.0000');
        $adjustmentId = (string) Str::uuid();

        $movement = $this->service->adjustByDelta(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            deltaQuantity: '1.0000',
            reference: 'ADJ-2026-0007',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentPositive,
            referenceType: StockMovementReferenceType::StockAdjustment,
            referenceId: $adjustmentId,
        );

        $fresh = $movement->fresh();
        $this->assertSame('stock_adjustment', $fresh?->reference_type);
        $this->assertSame($adjustmentId, $fresh?->reference_id);
    }

    // ------------------------------------------------------------- fixtures

    private function batchTrackedProduct(): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'DL-LOT-001',
            'name' => 'Delta Lot Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'requires_batch_tracking' => true,
            'default_shelf_life_days' => 180,
        ]);
    }

    private function seedStock(string $quantity, ?Product $product = null): StockLevel
    {
        return StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => ($product ?? $this->product)->id,
            'location_id' => $this->warehouse->id,
            'quantity' => $quantity,
            'reserved' => '0.0000',
        ]);
    }

    private function seedLot(Product $product, string $batchNumber, string $quantity): Batch
    {
        $batch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'batch_number' => $batchNumber,
            'expiry_date' => now()->addYear()->toDateString(),
            'is_active' => true,
        ]);

        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $batch->id,
            'location_id' => $this->warehouse->id,
            'quantity' => $quantity,
            'reserved_quantity' => '0.0000',
        ]);

        return $batch;
    }

    private function level(?Product $product = null): StockLevel
    {
        return StockLevel::query()
            ->where('product_id', ($product ?? $this->product)->id)
            ->where('location_id', $this->warehouse->id)
            ->firstOrFail();
    }

    private function lotQuantity(Batch $batch): string
    {
        return (string) BatchStock::query()
            ->where('batch_id', $batch->id)
            ->where('location_id', $this->warehouse->id)
            ->firstOrFail()
            ->quantity;
    }

    private function totalLotQuantity(Product $product): string
    {
        $total = '0.0000';

        $rows = BatchStock::query()
            ->whereIn('batch_id', Batch::query()->where('product_id', $product->id)->pluck('id'))
            ->where('location_id', $this->warehouse->id)
            ->get();

        foreach ($rows as $row) {
            $total = bcadd($total, (string) $row->quantity, 4); // precision-ok: batch quantity is decimal(15,4), canonical scale 4
        }

        return $total;
    }
}
