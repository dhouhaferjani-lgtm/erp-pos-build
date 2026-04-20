<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Persistence;

use App\Modules\Product\Application\Contracts\ProductRepositoryInterface;
use App\Modules\Product\Domain\Product;

final class EloquentProductRepository implements ProductRepositoryInterface
{
    public function findById(string $id): ?Product
    {
        return Product::query()->find($id);
    }

    public function findByIdForTenant(string $id, string $tenantId): ?Product
    {
        return Product::query()
            ->where('tenant_id', $tenantId)
            ->find($id);
    }
}
