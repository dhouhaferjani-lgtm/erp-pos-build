<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Domain\Repositories;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use Illuminate\Support\Collection;

interface BatchRepositoryInterface
{
    public function findById(int $id): ?Batch;

    public function findByUuid(string $uuid): ?Batch;

    public function findByBatchNumber(int $companyId, int $productId, string $batchNumber): ?Batch;

    public function getByProduct(int $productId, bool $activeOnly = true): Collection;

    public function getByCompany(int $companyId, array $filters = []): Collection;

    public function create(array $data): Batch;

    public function update(Batch $batch, array $data): bool;

    public function delete(Batch $batch): bool;

    public function markAsExpired(int $batchId): bool;

    public function recall(int $batchId, string $reason): bool;
}
