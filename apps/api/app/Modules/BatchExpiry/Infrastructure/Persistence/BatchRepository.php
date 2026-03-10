<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Infrastructure\Persistence;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Repositories\BatchRepositoryInterface;
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

    public function findByBatchNumber(string $companyId, string $productId, string $batchNumber): ?Batch
    {
        return Batch::where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('batch_number', $batchNumber)
            ->first();
    }

    /** @return Collection<int, Batch> */
    public function getByProduct(string $productId, bool $activeOnly = true): Collection
    {
        $query = Batch::where('product_id', $productId);

        if ($activeOnly) {
            $query->where('is_active', true)
                ->where('is_recalled', false);
        }

        return $query->orderBy('expiry_date', 'asc')->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Batch>
     */
    public function getByCompany(string $companyId, array $filters = []): Collection
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

        return $query->with(['product', 'batchStock'])->orderBy('expiry_date', 'asc')->get();
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
