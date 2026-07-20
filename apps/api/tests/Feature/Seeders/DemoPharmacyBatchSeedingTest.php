<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Product;
use Database\Seeders\DemoPharmacySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Tunisia multi-branch demo (warehouse + 4 POS shops) must satisfy the
 * batch-stock invariant at every location. Shops use reconciled DEFAULT lots;
 * selected warehouse products use deterministic multi-batch FEFO fixtures so
 * browser demos exercise a real split instead of a single synthetic lot.
 *
 * Reseeding against a demo tenant that has already been USED (transfers
 * consumed fixture lots, stock dropped below the eligibility threshold) must
 * keep every product's aggregate warehouse batch stock equal to its
 * StockLevel — stale fixture lots must never dangle on top of a refreshed
 * DEFAULT lot (Wave D review Round 11, Important finding).
 */
final class DemoPharmacyBatchSeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_tracked_shop_stock_has_reconciled_default_lots(): void
    {
        $this->seed(DemoPharmacySeeder::class);

        $batchTrackedIds = Product::query()
            ->where('requires_batch_tracking', true)
            ->pluck('id');

        $shopIds = Location::query()
            ->where('type', LocationType::Shop)
            ->pluck('id');
        $this->assertNotEmpty($shopIds, 'demo must provision POS shops');

        // Batch-tracked stock sitting at the SHOPS — the location class that the
        // parent batch pass did not yet cover when it ran.
        $shopStock = StockLevel::query()
            ->whereIn('product_id', $batchTrackedIds)
            ->whereIn('location_id', $shopIds)
            ->where('quantity', '>', 0)
            ->get();

        $this->assertNotEmpty($shopStock, 'demo shops must hold batch-tracked stock to reconcile');

        foreach ($shopStock as $stock) {
            $lotSum = BatchStock::query()
                ->where('location_id', $stock->location_id)
                ->whereIn('batch_id', Batch::query()
                    ->where('product_id', $stock->product_id)
                    ->where('batch_number', BatchStockService::DEFAULT_BATCH_NUMBER)
                    ->when(
                        $stock->variant_id === null,
                        fn ($q) => $q->whereNull('variant_id'),
                        fn ($q) => $q->where('variant_id', $stock->variant_id),
                    )
                    ->pluck('id'))
                ->get()
                ->reduce(
                    static fn (string $carry, BatchStock $bs): string => bcadd($carry, (string) $bs->quantity, 4),
                    '0',
                );

            $this->assertSame(
                0,
                bccomp((string) $stock->quantity, $lotSum, 4),
                "shop lot must reconcile to stock for product {$stock->product_id} @ location {$stock->location_id}",
            );
        }
    }

    public function test_warehouse_has_idempotent_multi_batch_fefo_fixtures(): void
    {
        $this->seed(DemoPharmacySeeder::class);

        $warehouse = Location::query()
            ->where('code', 'WH-01')
            ->firstOrFail();

        $this->assertWarehouseFefoFixtureShape($warehouse);
        $this->assertFixtureProductsReconcileToStockLevel($warehouse);

        $batchIds = Batch::query()
            ->where('batch_number', 'like', 'DEMO-FEFO-%')
            ->pluck('id')
            ->sort()
            ->values();
        $this->assertCount(12, $batchIds);
        $batchStockCount = BatchStock::query()
            ->whereIn('batch_id', $batchIds)
            ->where('location_id', $warehouse->id)
            ->count();

        $this->seed(DemoPharmacySeeder::class);

        // A clean reseed must reuse the same rows — no new lots minted …
        $this->assertSame(
            $batchIds->all(),
            Batch::query()
                ->where('batch_number', 'like', 'DEMO-FEFO-%')
                ->pluck('id')
                ->sort()
                ->values()
                ->all(),
        );
        $this->assertSame(
            $batchStockCount,
            BatchStock::query()
                ->whereIn('batch_id', $batchIds)
                ->where('location_id', $warehouse->id)
                ->count(),
        );

        // … and the canonical 3/4/remainder split must still reconcile, not
        // just the row counts (a reseed that drifted quantities would
        // otherwise pass).
        $this->assertWarehouseFefoFixtureShape($warehouse);
        $this->assertFixtureProductsReconcileToStockLevel($warehouse);
    }

    public function test_reseed_after_demo_usage_reconciles_and_degrades_consumed_fixture(): void
    {
        $this->seed(DemoPharmacySeeder::class);

        $warehouse = Location::query()
            ->where('code', 'WH-01')
            ->firstOrFail();

        // Simulate live demo usage: a FEFO transfer drained lot A and part of
        // lot B of the first fixture product, dropping its warehouse stock
        // below the 9.0000 fixture-eligibility threshold.
        $consumedProductId = Batch::query()
            ->where('batch_number', 'like', 'DEMO-FEFO-%')
            ->orderBy('product_id')
            ->value('product_id');
        $this->assertIsString($consumedProductId);

        $consumedBatches = Batch::query()
            ->where('product_id', $consumedProductId)
            ->where('batch_number', 'like', 'DEMO-FEFO-%')
            ->orderBy('expiry_date')
            ->get();
        $this->assertCount(3, $consumedBatches);

        StockLevel::query()
            ->where('product_id', $consumedProductId)
            ->whereNull('variant_id')
            ->where('location_id', $warehouse->id)
            ->update(['quantity' => '5.0000']);

        $consumedLotQuantities = ['0.0000', '2.0000', '3.0000'];
        foreach ($consumedBatches as $index => $batch) {
            BatchStock::query()
                ->where('batch_id', $batch->id)
                ->where('location_id', $warehouse->id)
                ->update(['quantity' => $consumedLotQuantities[$index]]);
        }

        $this->seed(DemoPharmacySeeder::class);

        // The consumed product degrades to a reconciled single DEFAULT lot:
        // its fixture lots are zeroed, never left dangling on top of the
        // DEFAULT lot the earlier reconcile pass refreshed to StockLevel.
        foreach ($consumedBatches as $batch) {
            $this->assertSame(
                '0.0000',
                (string) BatchStock::query()
                    ->where('batch_id', $batch->id)
                    ->where('location_id', $warehouse->id)
                    ->value('quantity'),
                "stale fixture lot {$batch->batch_number} must be zeroed on reseed",
            );
        }

        $defaultBatch = Batch::query()
            ->where('product_id', $consumedProductId)
            ->whereNull('variant_id')
            ->where('batch_number', BatchStockService::DEFAULT_BATCH_NUMBER)
            ->firstOrFail();
        $this->assertSame(
            '5.0000',
            (string) BatchStock::query()
                ->where('batch_id', $defaultBatch->id)
                ->where('location_id', $warehouse->id)
                ->value('quantity'),
        );

        // A replacement product keeps the fixture set at 4 products × 3 lots …
        $this->assertWarehouseFefoFixtureShape($warehouse);

        // … and every product that ever carried a fixture still reconciles
        // aggregate warehouse batch stock to StockLevel (the Round 11 failure
        // mode was aggregate > StockLevel via re-keyed lots).
        $this->assertFixtureProductsReconcileToStockLevel($warehouse);
    }

    /**
     * The live fixture set: exactly 4 products × 3 sellable dated lots with
     * positive warehouse stock, FEFO-ordered expiries, the canonical
     * 3/4/positive-remainder split summing exactly to StockLevel, and a
     * zeroed DEFAULT lot.
     */
    private function assertWarehouseFefoFixtureShape(Location $warehouse): void
    {
        $liveFixtureBatchIds = BatchStock::query()
            ->where('location_id', $warehouse->id)
            ->where('quantity', '>', 0)
            ->whereIn('batch_id', Batch::query()
                ->where('batch_number', 'like', 'DEMO-FEFO-%')
                ->pluck('id'))
            ->pluck('batch_id');

        $fixtureBatches = Batch::query()
            ->whereIn('id', $liveFixtureBatchIds)
            ->orderBy('product_id')
            ->orderBy('expiry_date')
            ->get();

        $this->assertCount(12, $fixtureBatches);

        $fixtureProductIds = $fixtureBatches
            ->pluck('product_id')
            ->unique()
            ->values();
        $this->assertCount(4, $fixtureProductIds);

        foreach ($fixtureProductIds as $productId) {
            $productBatches = $fixtureBatches
                ->where('product_id', $productId)
                ->sortBy('expiry_date')
                ->values();

            $this->assertCount(3, $productBatches);
            $firstBatch = $productBatches->get(0);
            $secondBatch = $productBatches->get(1);
            $thirdBatch = $productBatches->get(2);
            $this->assertInstanceOf(Batch::class, $firstBatch);
            $this->assertInstanceOf(Batch::class, $secondBatch);
            $this->assertInstanceOf(Batch::class, $thirdBatch);
            $this->assertTrue($firstBatch->expiry_date->isBefore($secondBatch->expiry_date));
            $this->assertTrue($secondBatch->expiry_date->isBefore($thirdBatch->expiry_date));

            foreach ($productBatches as $batch) {
                $this->assertTrue($batch->is_active);
                $this->assertFalse($batch->is_expired);
                $this->assertFalse($batch->is_recalled);
                $this->assertTrue($batch->canBeSold());
            }

            $stockLevel = StockLevel::query()
                ->where('product_id', $productId)
                ->whereNull('variant_id')
                ->where('location_id', $warehouse->id)
                ->firstOrFail();

            $quantities = [
                $this->warehouseBatchQuantity($firstBatch, $warehouse),
                $this->warehouseBatchQuantity($secondBatch, $warehouse),
                $this->warehouseBatchQuantity($thirdBatch, $warehouse),
            ];

            $this->assertSame('3.0000', $quantities[0]);
            $this->assertSame('4.0000', $quantities[1]);
            $this->assertSame(1, bccomp($quantities[2], '0.0000', 4));

            $fixtureTotal = bcadd(
                bcadd($quantities[0], $quantities[1], 4),
                $quantities[2],
                4,
            );
            $this->assertSame(0, bccomp((string) $stockLevel->quantity, $fixtureTotal, 4));

            $defaultBatch = Batch::query()
                ->where('product_id', $productId)
                ->whereNull('variant_id')
                ->where('batch_number', BatchStockService::DEFAULT_BATCH_NUMBER)
                ->firstOrFail();
            $this->assertSame(
                '0.0000',
                (string) BatchStock::query()
                    ->where('batch_id', $defaultBatch->id)
                    ->where('location_id', $warehouse->id)
                    ->value('quantity'),
            );
        }
    }

    /**
     * Every product that ever carried a DEMO-FEFO lot (live or stale) must
     * have its aggregate warehouse batch stock — DEFAULT + fixture + any
     * other lots — equal its warehouse StockLevel at scale 4.
     */
    private function assertFixtureProductsReconcileToStockLevel(Location $warehouse): void
    {
        $everFixturedProductIds = Batch::query()
            ->where('batch_number', 'like', 'DEMO-FEFO-%')
            ->pluck('product_id')
            ->unique()
            ->values();
        $this->assertNotEmpty($everFixturedProductIds);

        foreach ($everFixturedProductIds as $productId) {
            $stockLevel = StockLevel::query()
                ->where('product_id', $productId)
                ->whereNull('variant_id')
                ->where('location_id', $warehouse->id)
                ->firstOrFail();

            $lotSum = BatchStock::query()
                ->where('location_id', $warehouse->id)
                ->whereIn('batch_id', Batch::query()
                    ->where('product_id', $productId)
                    ->whereNull('variant_id')
                    ->pluck('id'))
                ->get()
                ->reduce(
                    static fn (string $carry, BatchStock $bs): string => bcadd($carry, (string) $bs->quantity, 4),
                    '0',
                );

            $this->assertSame(
                0,
                bccomp((string) $stockLevel->quantity, $lotSum, 4),
                "aggregate warehouse batch stock must equal StockLevel for product {$productId}",
            );
        }
    }

    /** @return numeric-string */
    private function warehouseBatchQuantity(Batch $batch, Location $warehouse): string
    {
        $quantity = (string) BatchStock::query()
            ->where('batch_id', $batch->id)
            ->where('location_id', $warehouse->id)
            ->value('quantity');

        if (! is_numeric($quantity)) {
            $this->fail("Warehouse quantity for batch {$batch->batch_number} must be numeric.");
        }

        return $quantity;
    }
}
