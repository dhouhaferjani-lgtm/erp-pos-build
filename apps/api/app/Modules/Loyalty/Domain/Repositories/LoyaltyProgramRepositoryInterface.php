<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Repositories;

use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use Illuminate\Support\Collection;

/**
 * Repository interface for LoyaltyProgram
 */
interface LoyaltyProgramRepositoryInterface
{
    /**
     * Find program by ID
     */
    public function findById(string $id): ?LoyaltyProgram;

    /**
     * Get all programs for a tenant
     *
     * @return Collection<int, LoyaltyProgram>
     */
    public function findByTenant(string $tenantId): Collection;

    /**
     * Get programs by status for a tenant
     *
     * @return Collection<int, LoyaltyProgram>
     */
    public function findByTenantAndStatus(string $tenantId, ProgramStatus $status): Collection;

    /**
     * Find active programs for a tenant that apply to a specific company
     *
     * @return Collection<int, LoyaltyProgram>
     */
    public function findActiveForCompany(string $tenantId, string $companyId): Collection;

    /**
     * Save program
     */
    public function save(LoyaltyProgram $program): LoyaltyProgram;

    /**
     * Delete program
     */
    public function delete(string $id): bool;
}
