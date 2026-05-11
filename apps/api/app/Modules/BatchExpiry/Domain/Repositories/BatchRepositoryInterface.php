<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Domain\Repositories;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use Illuminate\Support\Collection;

interface BatchRepositoryInterface
{
    public function findById(string $id): ?Batch;

    public function findByUuid(string $uuid): ?Batch;

    public function findByBatchNumber(string $companyId, string $productId, string $batchNumber): ?Batch;

    /** @return Collection<int, Batch> */
    public function getByProduct(string $tenantId, string $companyId, string $productId, bool $activeOnly = true): Collection;

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Batch>
     */
    public function getByCompany(string $companyId, array $filters = []): Collection;

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Batch;

    /** @param  array<string, mixed>  $data */
    public function update(Batch $batch, array $data): bool;

    public function delete(Batch $batch): bool;

    public function markAsExpired(string $batchId): bool;

    public function recall(string $batchId, string $reason): bool;
}
