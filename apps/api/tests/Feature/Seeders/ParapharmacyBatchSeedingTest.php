<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Product;
use Database\Seeders\ParapharmacySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Para-pharmacy launch E2E — Task 2.1.
 *
 * The seeder flags ~70% of products `requires_batch_tracking = true` and gives
 * them stock, but historically seeded no `product_batches` / `inventory_batch_stock`
 * rows. A batch-tracked product with stock but zero selectable lots silently
 * blocks PO goods-receipt and stock-transfer flows (no lot to pick).
 *
 * These tests pin the invariant: every batch-tracked product that holds stock
 * must have lots whose per-location quantity reconciles EXACTLY to its
 * StockLevel, with FEFO expiries — and non-batch-tracked products must NOT get
 * lots (else the UI would wrongly demand lot selection for them).
 */
final class ParapharmacyBatchSeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_batch_tracked_product_has_lots_summing_to_stock(): void
    {
        $this->seed(ParapharmacySeeder::class);

        $batchTrackedIds = Product::query()
            ->where('requires_batch_tracking', true)
            ->pluck('id');

        $this->assertNotEmpty(
            $batchTrackedIds,
            'Seeder must produce batch-tracked products to reconcile against',
        );

        // Every stock row that belongs to a batch-tracked product must be
        // backed by lots summing to exactly that StockLevel quantity.
        $stockLevels = StockLevel::query()
            ->whereIn('product_id', $batchTrackedIds)
            ->where('quantity', '>', 0)
            ->get();

        $this->assertNotEmpty(
            $stockLevels,
            'Batch-tracked products must hold stock for the reconciliation to be meaningful',
        );

        foreach ($stockLevels as $stock) {
            $lotSum = $this->lotSumForProductAtLocation(
                (string) $stock->product_id,
                $stock->variant_id,
                (string) $stock->location_id,
            );

            $expected = bcadd((string) $stock->quantity, '0', 4);

            $this->assertSame(
                0,
                bccomp($expected, $lotSum, 4),
                "lots must reconcile to stock for product {$stock->product_id}"
                ." @ location {$stock->location_id} (expected {$expected}, got {$lotSum})",
            );
        }
    }

    public function test_batch_tracked_stock_has_at_least_one_fefo_lot(): void
    {
        $this->seed(ParapharmacySeeder::class);

        $batchTrackedIds = Product::query()
            ->where('requires_batch_tracking', true)
            ->pluck('id');

        $stocked = StockLevel::query()
            ->whereIn('product_id', $batchTrackedIds)
            ->where('quantity', '>', 0)
            ->get();

        $this->assertNotEmpty($stocked);

        foreach ($stocked as $stock) {
            $lots = Batch::query()
                ->where('product_id', $stock->product_id)
                ->when(
                    $stock->variant_id === null,
                    fn ($q) => $q->whereNull('variant_id'),
                    fn ($q) => $q->where('variant_id', $stock->variant_id),
                )
                ->get();

            $this->assertNotEmpty(
                $lots,
                "batch-tracked product {$stock->product_id} with stock must have at least one lot",
            );

            // Every lot carries a future expiry date (FEFO selection needs it).
            foreach ($lots as $lot) {
                $this->assertTrue(
                    $lot->expiry_date->isFuture(),
                    "lot {$lot->batch_number} must carry a future expiry for FEFO",
                );
            }
        }
    }

    public function test_non_batch_tracked_products_get_no_lots(): void
    {
        $this->seed(ParapharmacySeeder::class);

        $nonBatchTrackedIds = Product::query()
            ->where('requires_batch_tracking', false)
            ->pluck('id');

        $this->assertSame(
            0,
            Batch::query()->whereIn('product_id', $nonBatchTrackedIds)->count(),
            'products without batch tracking must not be given lots',
        );
    }

    /**
     * Sum inventory_batch_stock quantity (scale 4) across all lots of a given
     * product (+ variant) at a single location.
     *
     * @return numeric-string
     */
    private function lotSumForProductAtLocation(
        string $productId,
        ?string $variantId,
        string $locationId,
    ): string {
        $batchIds = Batch::query()
            ->where('product_id', $productId)
            ->when(
                $variantId === null,
                fn ($q) => $q->whereNull('variant_id'),
                fn ($q) => $q->where('variant_id', $variantId),
            )
            ->pluck('id');

        return BatchStock::query()
            ->whereIn('batch_id', $batchIds)
            ->where('location_id', $locationId)
            ->get()
            ->reduce(
                static fn (string $carry, BatchStock $bs): string => bcadd($carry, (string) $bs->quantity, 4),
                '0',
            );
    }
}
