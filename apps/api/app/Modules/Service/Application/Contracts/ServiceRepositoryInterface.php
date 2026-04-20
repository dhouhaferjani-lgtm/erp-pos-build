<?php

declare(strict_types=1);

namespace App\Modules\Service\Application\Contracts;

use App\Modules\Service\Domain\Service;

/**
 * Thin repository contract for cross-module consumers (e.g. Workshop/WorkOrder).
 *
 * Module boundaries forbid direct imports of the Service Eloquent model; consumers
 * depend on this contract via Laravel's DI container.
 */
interface ServiceRepositoryInterface
{
    /**
     * Find a Service by its primary key (tenant-agnostic).
     */
    public function findById(string $id): ?Service;

    /**
     * Find a Service scoped to a tenant.
     */
    public function findByIdForTenant(string $id, string $tenantId): ?Service;
}
