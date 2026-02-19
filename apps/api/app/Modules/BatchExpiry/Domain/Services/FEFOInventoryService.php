<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Domain\Services;

use App\Modules\BatchExpiry\Application\DTOs\BatchSuggestionDTO;
use App\Modules\BatchExpiry\Application\DTOs\BatchSuggestionResultDTO;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use Illuminate\Support\Collection;

class FEFOInventoryService
{
    /**
     * Get batches to fulfill a quantity, ordered by expiry (soonest first).
     * Implements FEFO (First-Expired-First-Out) logic.
     */
    public function suggestBatchesForSale(
        string $productId,
        string $locationId,
        float $quantity,
        bool $includeExpired = false
    ): BatchSuggestionResultDTO {
        $query = BatchStock::query()
            ->join('product_batches', 'inventory_batch_stock.batch_id', '=', 'product_batches.id')
            ->where('product_batches.product_id', $productId)
            ->where('inventory_batch_stock.location_id', $locationId)
            ->where('inventory_batch_stock.available_quantity', '>', 0)
            ->where('product_batches.is_active', true)
            ->where('product_batches.is_recalled', false)
            ->orderBy('product_batches.expiry_date', 'asc');  // FEFO: earliest expiry first

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

            $suggestions[] = new BatchSuggestionDTO(
                batch: $stock->batch,
                quantity: $takeQuantity,
                expiryDate: $stock->batch->expiry_date,
                expiryStatus: $stock->batch->expiryStatus()
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
     * Get products expiring within threshold.
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
            ->whereHas('batchStock', function ($q) use ($locationId) {
                $q->where('available_quantity', '>', 0);
                if ($locationId) {
                    $q->where('location_id', $locationId);
                }
            })
            ->with(['product', 'batchStock'])
            ->orderBy('expiry_date', 'asc');

        return $query->get();
    }

    /**
     * Get all expired batches with remaining stock.
     */
    public function getExpiredBatchesWithStock(string $companyId, ?string $locationId = null): Collection
    {
        $query = Batch::query()
            ->where('company_id', $companyId)
            ->where('expiry_date', '<', now()->startOfDay())
            ->whereHas('batchStock', function ($q) use ($locationId) {
                $q->where('quantity', '>', 0);
                if ($locationId) {
                    $q->where('location_id', $locationId);
                }
            })
            ->with(['product', 'batchStock'])
            ->orderBy('expiry_date', 'asc');

        return $query->get();
    }

    /**
     * Get batch stock allocation for a specific batch.
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
        return \App\Modules\Product\Domain\Product::query()
            ->where('id', $productId)
            ->value('requires_batch_tracking') ?? false;
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
