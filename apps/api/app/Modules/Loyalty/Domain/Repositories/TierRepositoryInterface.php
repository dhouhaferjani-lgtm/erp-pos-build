<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Repositories;

use App\Modules\Loyalty\Domain\Entities\Tier;
use Illuminate\Support\Collection;

/**
 * Repository interface for Tier
 */
interface TierRepositoryInterface
{
    /**
     * Find tier by ID
     */
    public function findById(string $id): ?Tier;

    /**
     * Get all tiers for a program ordered by level
     *
     * @return Collection<int, Tier>
     */
    public function findByProgram(string $programId): Collection;

    /**
     * Find tier by program and level
     */
    public function findByProgramAndLevel(string $programId, int $level): ?Tier;

    /**
     * Save tier
     */
    public function save(Tier $tier): Tier;

    /**
     * Delete tier
     */
    public function delete(string $id): bool;
}
