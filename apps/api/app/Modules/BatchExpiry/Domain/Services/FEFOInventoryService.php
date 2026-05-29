<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Domain\Services;

use App\Modules\BatchExpiry\Application\DTOs\BatchSuggestionDTO;
use App\Modules\BatchExpiry\Application\DTOs\BatchSuggestionResultDTO;
use App\Modules\BatchExpiry\Domain\DTOs\BatchConsumptionResultDTO;
use App\Modules\BatchExpiry\Domain\DTOs\ConsumedBatchDTO;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Events\BatchStockConsumed;
use App\Modules\BatchExpiry\Domain\Exceptions\InsufficientBatchStockException;
use App\Modules\Product\Domain\Product;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class FEFOInventoryService
{
    /**
     * Get batches to fulfill a quantity, ordered by expiry (soonest first).
     * Implements FEFO (First-Expired-First-Out) logic.
     *
     * @param  ?string  $variantId  When set, only batches belonging to that variant
     *                              are considered (product_batches.variant_id = $variantId).
     *                              When null, only product-level batches are considered
     *                              (product_batches.variant_id IS NULL).
     *                              This is a READ-ONLY suggestion; atomic consume is handled
     *                              separately (Task 16b).
     */
    public function suggestBatchesForSale(
        string $productId,
        string $locationId,
        float $quantity,
        bool $includeExpired = false,
        ?string $variantId = null,
    ): BatchSuggestionResultDTO {
        $query = BatchStock::query()
            ->join('product_batches', 'inventory_batch_stock.batch_id', '=', 'product_batches.id')
            ->where('product_batches.product_id', $productId)
            ->where('inventory_batch_stock.location_id', $locationId)
            ->where('inventory_batch_stock.available_quantity', '>', 0)
            ->where('product_batches.is_active', true)
            ->where('product_batches.is_recalled', false)
            ->orderBy('product_batches.expiry_date', 'asc');  // FEFO: earliest expiry first

        // Variant predicate: null → product-level batches only; set → variant batches only.
        if ($variantId === null) {
            $query->whereNull('product_batches.variant_id');
        } else {
            $query->where('product_batches.variant_id', $variantId);
        }

        if (! $includeExpired) {
            $query->where('product_batches.expiry_date', '>=', now()->startOfDay());
        }

        $batchStocks = $query->with('batch')->get();

        $suggestions = [];
        $remaining = $quantity;

        foreach ($batchStocks as $stock) {
            if ($remaining <= 0) {
                break;
            }

            $takeQuantity = min($remaining, $stock->available_quantity);
            $batch = $stock->batch;
            if ($batch === null) {
                continue;
            }

            $suggestions[] = new BatchSuggestionDTO(
                batch: $batch,
                quantity: $takeQuantity,
                expiryDate: $batch->expiry_date,
                expiryStatus: $batch->expiryStatus()
            );

            $remaining -= $takeQuantity;
        }

        return new BatchSuggestionResultDTO(
            suggestions: $suggestions,
            fullyFulfilled: $remaining <= 0,
            shortfall: max(0, $remaining)
        );
    }

    /**
     * Atomically consume batch stock for a quantity, FEFO order, with row locks.
     *
     * Unlike {@see suggestBatchesForSale()} (read-only, lock-free), this method
     * runs inside a transaction and locks each candidate inventory_batch_stock
     * row with `FOR UPDATE ... SKIP LOCKED`. Two concurrent consumers therefore
     * never draw from the same locked row — the second skips it and either finds
     * other stock or reports a shortfall. This closes the concurrency hole where
     * two cashiers received identical suggestions and a sale could shortfall yet
     * still commit.
     *
     * Each consumed batch decrements `quantity` (the GENERATED `available_quantity`
     * recomputes itself) and writes one inventory_batch_movements row keyed to the
     * supplied stock_movements id.
     *
     * @param  string  $tenantId  Owning tenant.
     * @param  string  $productId  Product whose batches are consumed.
     * @param  string  $locationId  Location to consume from.
     * @param  numeric-string  $quantity  Positive quantity to consume (decimal string, 4dp).
     * @param  string  $movementId  FK → stock_movements.id. The caller MUST create
     *                              the stock_movements row first and pass its id.
     * @param  ?string  $variantId  When set, only that variant's batches are consumed;
     *                              when null, only product-level batches (variant_id IS NULL).
     * @param  bool  $strictFulfillment  When true (default), a shortfall throws
     *                                   InsufficientBatchStockException and rolls back the
     *                                   whole pass. When false, partial consumption commits
     *                                   and the remaining quantity is returned as the shortfall.
     *
     * @throws InsufficientBatchStockException When strict and the quantity cannot be fully met.
     */
    public function consumeBatchesAtomically(
        string $tenantId,
        string $productId,
        string $locationId,
        string $quantity,
        string $movementId,
        ?string $variantId = null,
        bool $strictFulfillment = true,
    ): BatchConsumptionResultDTO {
        return DB::transaction(function () use (
            $tenantId,
            $productId,
            $locationId,
            $quantity,
            $movementId,
            $variantId,
            $strictFulfillment,
        ): BatchConsumptionResultDTO {
            $variantPredicate = $variantId !== null ? 'b.variant_id = ?' : 'b.variant_id IS NULL';

            $bindings = [$productId];
            if ($variantId !== null) {
                $bindings[] = $variantId;
            }
            $bindings = array_merge($bindings, [$locationId, now()->toDateString()]);

            // FOR UPDATE OF ibs SKIP LOCKED: lock only the batch-stock rows we are
            // about to draw down, and skip any row another transaction already holds.
            $rows = DB::select("
                SELECT ibs.id AS batch_stock_id, ibs.batch_id, ibs.quantity AS stored_quantity,
                       ibs.available_quantity, b.expiry_date
                FROM inventory_batch_stock AS ibs
                JOIN product_batches AS b ON ibs.batch_id = b.id
                WHERE b.product_id = ? AND {$variantPredicate}
                  AND ibs.location_id = ? AND ibs.available_quantity > 0
                  AND b.is_active = TRUE AND b.is_recalled = FALSE AND b.expiry_date >= ?
                ORDER BY b.expiry_date ASC, b.created_at ASC
                FOR UPDATE OF ibs SKIP LOCKED
            ", $bindings);

            /** @var numeric-string $remaining */
            $remaining = $quantity;
            /** @var array<int, ConsumedBatchDTO> $consumed */
            $consumed = [];

            foreach ($rows as $row) {
                if (bccomp($remaining, '0', 4) <= 0) {
                    break;
                }

                /** @var numeric-string $available */
                $available = (string) $row->available_quantity;

                // Normalize to a 4dp decimal string so every consumed quantity and
                // movement row carries a consistent scale, regardless of caller input.
                /** @var numeric-string $take */
                $take = bcadd(
                    bccomp($available, $remaining, 4) < 0 ? $available : $remaining,
                    '0',
                    4,
                );

                // Decrement quantity at full decimal precision; the GENERATED
                // available_quantity recomputes. We hold a FOR UPDATE lock on this
                // exact row, so the read-modify-write is safe from concurrent writers.
                // bcsub avoids the float cast that decrement() would force.
                /** @var numeric-string $storedQuantity */
                $storedQuantity = (string) $row->stored_quantity;

                /** @var numeric-string $newQuantity */
                $newQuantity = bcsub($storedQuantity, $take, 4);

                DB::table('inventory_batch_stock')
                    ->where('id', $row->batch_stock_id)
                    ->update(['quantity' => $newQuantity]);

                DB::table('inventory_batch_movements')->insert([
                    'tenant_id' => $tenantId,
                    'batch_id' => (int) $row->batch_id,
                    'movement_id' => $movementId,
                    'quantity' => $take,
                    'created_at' => now(),
                ]);

                $consumed[] = new ConsumedBatchDTO(
                    batchId: (int) $row->batch_id,
                    batchStockId: (int) $row->batch_stock_id,
                    quantityConsumed: $take,
                    expiryDate: Carbon::parse($row->expiry_date),
                );

                $remaining = bcsub($remaining, $take, 4);
            }

            // Normalize the shortfall to a consistent 4dp decimal string.
            /** @var numeric-string $shortfall */
            $shortfall = bcadd($remaining, '0', 4);

            if ($strictFulfillment && bccomp($shortfall, '0', 4) > 0) {
                throw new InsufficientBatchStockException($shortfall);
            }

            DB::afterCommit(function () use ($productId, $variantId, $locationId, $consumed): void {
                event(new BatchStockConsumed(
                    productId: $productId,
                    variantId: $variantId,
                    locationId: $locationId,
                    consumed: $consumed,
                ));
            });

            return new BatchConsumptionResultDTO(
                consumed: $consumed,
                shortfall: $shortfall,
            );
        });
    }

    /**
     * Get products expiring within threshold.
     *
     * @return Collection<int, Batch>
     */
    public function getExpiringProducts(
        string $companyId,
        int $daysThreshold = 30,
        ?string $locationId = null
    ): Collection {
        $query = Batch::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('is_recalled', false)
            ->whereBetween('expiry_date', [
                now()->startOfDay(),
                now()->addDays($daysThreshold)->endOfDay(),
            ])
            ->whereHas('batchStock', function (Builder $q) use ($locationId): void {
                $q->whereRaw('available_quantity > 0');
                if ($locationId) {
                    $q->whereRaw('location_id = ?', [$locationId]);
                }
            })
            ->with(['product', 'batchStock'])
            ->orderBy('expiry_date', 'asc');

        return $query->get();
    }

    /**
     * Get all expired batches with remaining stock.
     *
     * @return Collection<int, Batch>
     */
    public function getExpiredBatchesWithStock(string $companyId, ?string $locationId = null): Collection
    {
        $query = Batch::query()
            ->where('company_id', $companyId)
            ->where('expiry_date', '<', now()->startOfDay())
            ->whereHas('batchStock', function (Builder $q) use ($locationId): void {
                $q->whereRaw('quantity > 0');
                if ($locationId) {
                    $q->whereRaw('location_id = ?', [$locationId]);
                }
            })
            ->with(['product', 'batchStock'])
            ->orderBy('expiry_date', 'asc');

        return $query->get();
    }

    /**
     * Get batch stock allocation for a specific batch.
     *
     * @return Collection<int, BatchStock>
     */
    public function getBatchStockByLocation(string $batchId): Collection
    {
        return BatchStock::query()
            ->where('batch_id', $batchId)
            ->with('location')
            ->get();
    }

    /**
     * Check if a product requires batch tracking.
     */
    public function productRequiresBatchTracking(string $productId): bool
    {
        return (bool) (Product::query()
            ->where('id', $productId)
            ->value('requires_batch_tracking') ?? false);
    }

    /**
     * Get total available quantity for a product across all batches.
     */
    public function getTotalAvailableQuantity(string $productId, ?string $locationId = null): float
    {
        $query = BatchStock::query()
            ->join('product_batches', 'inventory_batch_stock.batch_id', '=', 'product_batches.id')
            ->where('product_batches.product_id', $productId)
            ->where('product_batches.is_active', true)
            ->where('product_batches.is_recalled', false)
            ->where('product_batches.expiry_date', '>=', now()->startOfDay());

        if ($locationId) {
            $query->where('inventory_batch_stock.location_id', $locationId);
        }

        return (float) $query->sum('inventory_batch_stock.available_quantity');
    }
}
