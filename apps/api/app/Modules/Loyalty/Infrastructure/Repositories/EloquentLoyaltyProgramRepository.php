<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Infrastructure\Repositories;

use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Repositories\LoyaltyProgramRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Eloquent implementation of LoyaltyProgram repository
 */
final readonly class EloquentLoyaltyProgramRepository implements LoyaltyProgramRepositoryInterface
{
    /**
     * Find program by ID
     */
    public function findById(string $id): ?LoyaltyProgram
    {
        return LoyaltyProgram::find($id);
    }

    /**
     * Get all programs for a tenant
     *
     * @return Collection<int, LoyaltyProgram>
     */
    public function findByTenant(string $tenantId): Collection
    {
        return LoyaltyProgram::where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get programs by status for a tenant
     *
     * @return Collection<int, LoyaltyProgram>
     */
    public function findByTenantAndStatus(string $tenantId, ProgramStatus $status): Collection
    {
        return LoyaltyProgram::where('tenant_id', $tenantId)
            ->where('status', $status)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Find active programs for a tenant that apply to a specific company
     *
     * Programs apply if:
     * - Status is ACTIVE
     * - Within date range (if set)
     * - company_ids is null (applies to all) OR contains the specific company ID
     *
     * @return Collection<int, LoyaltyProgram>
     */
    public function findActiveForCompany(string $tenantId, string $companyId): Collection
    {
        return LoyaltyProgram::where('tenant_id', $tenantId)
            ->where('status', ProgramStatus::Active)
            ->where(function ($query) use ($companyId) {
                $query->whereNull('company_ids')
                    ->orWhereJsonContains('company_ids', $companyId);
            })
            ->where(function ($query) {
                $query->whereNull('start_date')
                    ->orWhere('start_date', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Save program
     */
    public function save(LoyaltyProgram $program): LoyaltyProgram
    {
        $program->save();

        return $program->fresh();
    }

    /**
     * Delete program
     */
    public function delete(string $id): bool
    {
        $program = $this->findById($id);

        if ($program === null) {
            return false;
        }

        return (bool) $program->delete();
    }
}
