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
 * default-batch invariant at EVERY location — not just the warehouse. The
 * shops' front-of-house stock is seeded after the parent's batch pass, so this
 * pins that the seeder reconciles a DEFAULT lot for batch-tracked products at
 * the shops too (otherwise PO/transfer/lot selection silently blocks there).
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
}
