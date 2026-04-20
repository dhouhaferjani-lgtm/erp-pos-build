<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Contracts;

use App\Modules\Product\Domain\Product;

/**
 * Thin repository contract for cross-module consumers (e.g. Workshop/WorkOrder).
 *
 * Module boundaries forbid direct imports of the Product Eloquent model; consumers
 * depend on this contract via Laravel's DI container.
 */
interface ProductRepositoryInterface
{
    /**
     * Find a Product by its primary key (tenant-agnostic).
     */
    public function findById(string $id): ?Product;

    /**
     * Find a Product scoped to a tenant.
     */
    public function findByIdForTenant(string $id, string $tenantId): ?Product;
}
