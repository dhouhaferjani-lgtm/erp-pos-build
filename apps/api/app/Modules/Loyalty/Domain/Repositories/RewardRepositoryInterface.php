<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Repositories;

use App\Modules\Loyalty\Domain\Entities\Reward;
use Illuminate\Support\Collection;

/**
 * Repository interface for Reward
 */
interface RewardRepositoryInterface
{
    /**
     * Find reward by ID
     */
    public function findById(string $id): ?Reward;

    /**
     * Get all rewards for a program
     *
     * @return Collection<int, Reward>
     */
    public function findByProgram(string $programId): Collection;

    /**
     * Get active rewards for a program
     *
     * @return Collection<int, Reward>
     */
    public function findActiveByProgram(string $programId): Collection;

    /**
     * Get available rewards for a member (considering tier restrictions)
     *
     * @return Collection<int, Reward>
     */
    public function findAvailableForMember(string $programId, ?string $tierId = null): Collection;

    /**
     * Save reward
     */
    public function save(Reward $reward): Reward;

    /**
     * Delete reward
     */
    public function delete(string $id): bool;
}
