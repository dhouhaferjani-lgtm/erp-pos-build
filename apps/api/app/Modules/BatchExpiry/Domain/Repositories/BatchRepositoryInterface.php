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

    public function getByProduct(string $productId, bool $activeOnly = true): Collection;

    public function getByCompany(string $companyId, array $filters = []): Collection;

    public function create(array $data): Batch;

    public function update(Batch $batch, array $data): bool;

    public function delete(Batch $batch): bool;

    public function markAsExpired(string $batchId): bool;

    public function recall(string $batchId, string $reason): bool;
}
