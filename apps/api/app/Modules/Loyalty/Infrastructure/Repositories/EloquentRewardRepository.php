<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Infrastructure\Repositories;

use App\Modules\Loyalty\Domain\Entities\Reward;
use App\Modules\Loyalty\Domain\Repositories\RewardRepositoryInterface;
use Illuminate\Support\Collection;

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
