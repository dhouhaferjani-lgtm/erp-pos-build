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

        $fixtureBatches = Batch::query()
            ->where('batch_number', 'like', 'DEMO-FEFO-%')
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

        $batchIds = $fixtureBatches->pluck('id');
        $batchCount = $fixtureBatches->count();
        $batchStockCount = BatchStock::query()
            ->whereIn('batch_id', $batchIds)
            ->where('location_id', $warehouse->id)
            ->count();

        $this->seed(DemoPharmacySeeder::class);

        $this->assertSame(
            $batchCount,
            Batch::query()->where('batch_number', 'like', 'DEMO-FEFO-%')->count(),
        );
        $this->assertSame(
            $batchStockCount,
            BatchStock::query()
                ->whereIn('batch_id', $batchIds)
                ->where('location_id', $warehouse->id)
                ->count(),
        );
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
