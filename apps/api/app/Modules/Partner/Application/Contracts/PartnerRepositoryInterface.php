<?php

declare(strict_types=1);

namespace App\Modules\Partner\Application\Contracts;

use App\Modules\Partner\Domain\Partner;

/**
 * Thin repository contract for cross-module consumers (e.g. Workshop/WorkOrder).
 *
 * Module boundaries forbid direct imports of the Partner Eloquent model; consumers
 * depend on this contract via Laravel's DI container.
 */
interface PartnerRepositoryInterface
{
    /**
     * Find a Partner by its primary key (tenant-agnostic).
     */
    public function findById(string $id): ?Partner;

    /**
     * Find a Partner scoped to a tenant (hardened for multi-tenant boundaries).
     */
    public function findByIdForTenant(string $id, string $tenantId): ?Partner;
}
