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
use App\Shared\Domain\QuantityScale;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

class FEFOInventoryService
{
    /**
     * Get batches to fulfill a quantity, ordered by expiry (soonest first).
     * Implements FEFO (First-Expired-First-Out) logic.
     *
     * Quantities stay in bcmath decimal strings end-to-end (precision contract):
     * a native-float pass reported false shortfalls of ~1e-16 for requests the
     * lots exactly covered (e.g. 1.1 against [0.7, 0.4]).
     *
     * @param  numeric-string  $quantity  Positive quantity to fulfil (decimal string, 4dp).
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
        string $quantity,
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

        /** @var array<int, BatchSuggestionDTO> $suggestions */
        $suggestions = [];

        // Normalize the request to the canonical quantity scale up front, so every
        // emitted quantity carries the same scale regardless of caller input.
        /** @var numeric-string $remaining */
        $remaining = bcadd($quantity, '0', QuantityScale::SCALE);

        foreach ($batchStocks as $stock) {
            if (bccomp($remaining, '0', QuantityScale::SCALE) <= 0) {
                break;
            }

            $batch = $stock->batch;
            if ($batch === null) {
                continue;
            }

            // Read the GENERATED available_quantity column raw: the model's
            // available_quantity accessor returns a float and would reintroduce
            // representation error before any arithmetic happens.
            /** @var numeric-string $rawAvailable */
            $rawAvailable = (string) $stock->getRawOriginal('available_quantity');

            /** @var numeric-string $available */
            $available = bcadd($rawAvailable, '0', QuantityScale::SCALE);

            /** @var numeric-string $takeQuantity */
            $takeQuantity = bccomp($available, $remaining, QuantityScale::SCALE) < 0
                ? $available
                : $remaining;

            $suggestions[] = new BatchSuggestionDTO(
                batch: $batch,
                quantity: $takeQuantity,
                expiryDate: $batch->expiry_date,
                expiryStatus: $batch->expiryStatus()
            );

            $remaining = bcsub($remaining, $takeQuantity, QuantityScale::SCALE);
        }

        // A take never exceeds the remainder, so $remaining cannot go negative;
        // clamp defensively and emit a canonical scale-4 string either way.
        /** @var numeric-string $shortfall */
        $shortfall = bccomp($remaining, '0', QuantityScale::SCALE) > 0
            ? $remaining
            : bcadd('0', '0', QuantityScale::SCALE);

        return new BatchSuggestionResultDTO(
            suggestions: $suggestions,
            fullyFulfilled: bccomp($shortfall, '0', QuantityScale::SCALE) <= 0,
            shortfall: $shortfall,
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
                if (bccomp($remaining, '0', 4) <= 0) { // precision-ok: batch quantity is decimal(15,4), canonical scale 4
                    break;
                }

                /** @var numeric-string $available */
                $available = (string) $row->available_quantity;

                // Normalize to a 4dp decimal string so every consumed quantity and
                // movement row carries a consistent scale, regardless of caller input.
                // precision-ok: batch quantity is decimal(15,4), canonical scale 4
                $takeSource = bccomp($available, $remaining, 4) < 0 ? $available : $remaining;
                /** @var numeric-string $take */
                $take = bcadd($takeSource, '0', 4); // precision-ok: batch quantity is decimal(15,4), canonical scale 4

                // Decrement quantity at full decimal precision; the GENERATED
                // available_quantity recomputes. We hold a FOR UPDATE lock on this
                // exact row, so the read-modify-write is safe from concurrent writers.
                // bcsub avoids the float cast that decrement() would force.
                /** @var numeric-string $storedQuantity */
                $storedQuantity = (string) $row->stored_quantity;

                /** @var numeric-string $newQuantity */
                $newQuantity = bcsub($storedQuantity, $take, 4); // precision-ok: batch quantity is decimal(15,4), canonical scale 4

                DB::table('inventory_batch_stock')
                    ->where('id', $row->batch_stock_id)
                    ->update(['quantity' => $newQuantity]);

                // Signed ledger: a consume is an ISSUE, so the movement row stores
                // the NEGATIVE magnitude — matching BatchStockService::issueBatchStock()
                // (bcmul($quantity, '-1', 4)). Receipts are positive, issues negative.
                /** @var numeric-string $movementQuantity */
                $movementQuantity = bcmul($take, '-1', 4); // precision-ok: batch quantity is decimal(15,4), canonical scale 4

                DB::table('inventory_batch_movements')->insert([
                    'tenant_id' => $tenantId,
                    'batch_id' => (int) $row->batch_id,
                    'movement_id' => $movementId,
                    'quantity' => $movementQuantity,
                    'created_at' => now(),
                ]);

                // The result DTO reports the POSITIVE magnitude consumed; only the
                // ledger ROW carries the negative sign.
                $consumed[] = new ConsumedBatchDTO(
                    batchId: (int) $row->batch_id,
                    batchStockId: (int) $row->batch_stock_id,
                    quantityConsumed: $take,
                    expiryDate: Carbon::parse($row->expiry_date),
                );

                $remaining = bcsub($remaining, $take, 4); // precision-ok: batch quantity is decimal(15,4), canonical scale 4
            }

            // Normalize the shortfall to a consistent 4dp decimal string.
            /** @var numeric-string $shortfall */
            $shortfall = bcadd($remaining, '0', 4); // precision-ok: batch quantity is decimal(15,4), canonical scale 4

            // precision-ok: batch quantity is decimal(15,4), canonical scale 4
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
            ->with(['product.unitOfMeasure', 'batchStock'])
            ->orderBy('expiry_date', 'asc');

        return $query->get();
    }

    /**
     * Get all expired batches with remaining available (un-reserved) stock.
     *
     * Only lots where `available_quantity > 0` are returned — lots whose
     * entire on-hand quantity is reserved are excluded, as there is nothing
     * free to write off.  Both `quantity` (on-hand total) and
     * `reserved_quantity` are present on each loaded BatchStock row so the
     * caller can display the full picture to the operator.
     *
     * @param  string|list<string>|null  $locationId  A legacy scalar location id,
     *                                                a resolved location-id set, or null for an unscoped service call.
     * @return Collection<int, Batch>
     */
    public function getExpiredBatchesWithStock(string $companyId, string|array|null $locationId = null): Collection
    {
        $locationIds = is_array($locationId) ? $locationId : null;

        $query = Batch::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('is_recalled', false)
            ->where('expiry_date', '<', now()->startOfDay())
            ->whereHas('batchStock', function (Builder $q) use ($locationId, $locationIds): void {
                $q->whereRaw('available_quantity > 0');
                if (is_array($locationIds)) {
                    if ($locationIds === []) {
                        $q->whereRaw('1 = 0');
                    } else {
                        $q->whereIn('location_id', $locationIds);
                    }
                } elseif ($locationId !== null) {
                    $q->whereRaw('location_id = ?', [$locationId]);
                }
            })
            ->with(['product.unitOfMeasure', 'batchStock' => function (Relation $q) use ($locationId, $locationIds): void {
                if (is_array($locationIds)) {
                    if ($locationIds === []) {
                        $q->whereRaw('1 = 0');
                    } else {
                        $q->whereIn('location_id', $locationIds);
                    }
                } elseif ($locationId !== null) {
                    $q->whereRaw('location_id = ?', [$locationId]);
                }
            }])
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
