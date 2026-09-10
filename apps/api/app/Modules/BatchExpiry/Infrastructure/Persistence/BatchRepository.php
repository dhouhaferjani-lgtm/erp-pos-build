<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Infrastructure\Persistence;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Repositories\BatchRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BatchRepository implements BatchRepositoryInterface
{
    public function findById(string $id): ?Batch
    {
        return Batch::find($id);
    }

    public function findByUuid(string $uuid): ?Batch
    {
        return Batch::where('uuid', $uuid)->first();
    }

    /**
     * @param  list<string>|null  $locationIds
     * @param  list<int>  $historicallyVisibleBatchIds
     */
    public function findVisibleByUuid(string $uuid, string $companyId, ?array $locationIds, array $historicallyVisibleBatchIds = []): ?Batch
    {
        $query = Batch::query()->where('uuid', $uuid)->where('company_id', $companyId);
        $this->scopeVisible($query, $locationIds, $historicallyVisibleBatchIds);

        return $query->with(['product', 'batchStock' => fn ($stock) => $locationIds === null ? $stock : $stock->whereIn('location_id', $locationIds)])->first();
    }

    /**
     * @param  Builder<Batch>  $query
     * @param  list<string>|null  $locationIds
     * @param  list<int>  $history
     */
    private function scopeVisible(Builder $query, ?array $locationIds, array $history = []): void
    {
        if ($locationIds !== null) {
            $query->where(fn (Builder $visible) => $visible
                ->whereHas('batchStock', fn (Builder $stock) => $stock->whereIn('location_id', $locationIds)->whereRaw('quantity > ?', ['0']))
                ->orWhereIn('id', $history));
        }
    }

    public function findByBatchNumber(string $companyId, string $productId, string $batchNumber): ?Batch
    {
        return Batch::where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('batch_number', $batchNumber)
            ->first();
    }

    public function findByBatchNumberAndVariant(
        string $companyId,
        string $productId,
        string $batchNumber,
        ?string $variantId,
    ): ?Batch {
        $query = Batch::where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('batch_number', $batchNumber);

        if ($variantId === null) {
            $query->whereNull('variant_id');
        } else {
            $query->where('variant_id', $variantId);
        }

        return $query->first();
    }

    /**
     * @param  list<string>|null  $locationIds
     * @return Collection<int, Batch>
     */
    public function getByProduct(string $tenantId, string $companyId, string $productId, bool $activeOnly = true, ?array $locationIds = null): Collection
    {
        // api.inventory round-2 (Codex Finding 1): lead with tenant+company
        // predicates so a route-supplied productId cannot leak foreign batches.
        $query = Batch::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('product_id', $productId);

        if ($activeOnly) {
            $query->where('is_active', true)
                ->where('is_recalled', false);
        }

        // Eager-load per-location stock so BatchResource emits `batch_stock`.
        // The stock-transfer batch picker (and any consumer of
        // /products/{id}/batch-stock) computes per-source availability from
        // this array; without it every location reads as 0 available and FEFO
        // can never allocate — silently blocking batch-tracked transfers.
        // W4-1 gate r1 — `id` is part of the ranking, not decoration. This list
        // feeds the transfer screen's client-side FEFO split, and the server's
        // canonical split (`StockTransferService::computeFefoSplit`) breaks ties on
        // `id` while `assertAllocationsFollowFefo()` compares per-batch QUANTITIES.
        // Without a deterministic tie-break here, a PARTIAL draw across two
        // equal-rank lots lets the client build a split the server refuses with
        // "Batch allocations must follow FEFO" and the operator cannot satisfy it.
        // Equal DATES were already reachable; undated ties are new, and on the
        // launch tenant they are the common shape.
        // Restricted stock pickers require positive stock; historical visibility belongs to list/detail only.
        $this->scopeVisible($query, $locationIds);

        return $query->with(['batchStock' => fn ($stock) => $locationIds === null ? $stock : $stock->whereIn('location_id', $locationIds)])
            ->orderByRaw('(expiry_date IS NULL) ASC, expiry_date ASC')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>|null  $locationIds
     * @param  list<int>  $historicallyVisibleBatchIds
     * @return Collection<int, Batch>
     */
    public function getByCompany(string $companyId, array $filters = [], ?array $locationIds = null, array $historicallyVisibleBatchIds = []): Collection
    {
        $query = Batch::where('company_id', $companyId);

        if (isset($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['is_expired'])) {
            $query->where('is_expired', $filters['is_expired']);
        }

        if (isset($filters['is_recalled'])) {
            $query->where('is_recalled', $filters['is_recalled']);
        }

        if (isset($filters['expiring_within_days'])) {
            $query->whereBetween('expiry_date', [
                now()->startOfDay(),
                now()->addDays($filters['expiring_within_days'])->endOfDay(),
            ]);
        }

        $this->scopeVisible($query, $locationIds, $historicallyVisibleBatchIds);

        return $query->with(['product', 'batchStock' => fn ($stock) => $locationIds === null ? $stock : $stock->whereIn('location_id', $locationIds)])
            ->orderByRaw('(expiry_date IS NULL) ASC, expiry_date ASC')
            ->orderBy('id')
            ->get();
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Batch
    {
        if (! isset($data['uuid'])) {
            $data['uuid'] = (string) Str::uuid();
        }

        return Batch::create($data);
    }

    /** @param  array<string, mixed>  $data */
    public function update(Batch $batch, array $data): bool
    {
        return $batch->update($data);
    }

    public function delete(Batch $batch): bool
    {
        // Soft deactivate instead of hard delete
        return $batch->update(['is_active' => false]);
    }

    public function markAsExpired(string $batchId): bool
    {
        return Batch::where('id', $batchId)->update(['is_expired' => true]) > 0;
    }

    public function recall(string $batchId, string $reason): bool
    {
        return Batch::where('id', $batchId)->update([
            'is_recalled' => true,
            'recall_reason' => $reason,
            'recalled_at' => now(),
        ]) > 0;
    }
}
