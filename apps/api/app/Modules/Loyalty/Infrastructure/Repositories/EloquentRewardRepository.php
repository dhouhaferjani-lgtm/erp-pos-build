<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Infrastructure\Repositories;

use App\Modules\Loyalty\Domain\Entities\Reward;
use App\Modules\Loyalty\Domain\Repositories\RewardRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent implementation of Reward repository
 */
final readonly class EloquentRewardRepository implements RewardRepositoryInterface
{
    /**
     * Find reward by ID
     */
    public function findById(string $id): ?Reward
    {
        return Reward::find($id);
    }

    /**
     * Determine whether a reward belongs to the given program and tenant.
     */
    public function existsForProgramInTenant(string $id, string $programId, string $tenantId): bool
    {
        return DB::table('loyalty_rewards')
            ->join('loyalty_programs', 'loyalty_programs.id', '=', 'loyalty_rewards.program_id')
            ->where('loyalty_rewards.id', $id)
            ->where('loyalty_rewards.program_id', $programId)
            ->where('loyalty_programs.tenant_id', $tenantId)
            ->exists();
    }

    /**
     * Determine whether a reward belongs to any program in the given tenant.
     */
    public function existsInTenant(string $id, string $tenantId): bool
    {
        return DB::table('loyalty_rewards')
            ->join('loyalty_programs', 'loyalty_programs.id', '=', 'loyalty_rewards.program_id')
            ->where('loyalty_rewards.id', $id)
            ->where('loyalty_programs.tenant_id', $tenantId)
            ->exists();
    }

    /**
     * Get all rewards for a program
     *
     * @return Collection<int, Reward>
     */
    public function findByProgram(string $programId): Collection
    {
        return Reward::where('program_id', $programId)
            ->orderBy('points_cost')
            ->get();
    }

    /**
     * Get active rewards for a program
     *
     * @return Collection<int, Reward>
     */
    public function findActiveByProgram(string $programId): Collection
    {
        return Reward::where('program_id', $programId)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('start_date')
                    ->orWhere('start_date', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->where(function ($query) {
                $query->whereNull('quantity_available')
                    ->orWhere('quantity_available', '>', 0);
            })
            ->orderBy('points_cost')
            ->get();
    }

    /**
     * Get available rewards for a member (considering tier restrictions)
     *
     * @return Collection<int, Reward>
     */
    public function findAvailableForMember(string $programId, ?string $tierId = null): Collection
    {
        $query = Reward::where('program_id', $programId)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('start_date')
                    ->orWhere('start_date', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->where(function ($q) {
                $q->whereNull('quantity_available')
                    ->orWhere('quantity_available', '>', 0);
            });

        // Filter by tier if member has one
        if ($tierId !== null) {
            $query->where(function ($q) use ($tierId) {
                $q->whereNull('tier_ids')
                    ->orWhereJsonContains('tier_ids', $tierId);
            });
        } else {
            // Member has no tier, only show rewards with no tier restriction
            $query->whereNull('tier_ids');
        }

        return $query->orderBy('points_cost')->get();
    }

    /**
     * Save reward
     */
    public function save(Reward $reward): Reward
    {
        $reward->save();

        /** @var Reward $freshReward */
        $freshReward = $reward->fresh();

        return $freshReward;
    }

    /**
     * Delete reward
     */
    public function delete(string $id): bool
    {
        $reward = $this->findById($id);

        if ($reward === null) {
            return false;
        }

        return (bool) $reward->delete();
    }
}
