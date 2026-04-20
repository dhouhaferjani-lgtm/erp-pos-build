<?php

declare(strict_types=1);

namespace App\Modules\Service\Infrastructure\Persistence;

use App\Modules\Service\Application\Contracts\ServiceRepositoryInterface;
use App\Modules\Service\Domain\Service;

final class EloquentServiceRepository implements ServiceRepositoryInterface
{
    public function findById(string $id): ?Service
    {
        return Service::query()->find($id);
    }

    public function findByIdForTenant(string $id, string $tenantId): ?Service
    {
        return Service::query()
            ->where('tenant_id', $tenantId)
            ->find($id);
    }
}
