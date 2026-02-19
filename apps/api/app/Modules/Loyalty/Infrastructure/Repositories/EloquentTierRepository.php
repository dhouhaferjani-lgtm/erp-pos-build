<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Infrastructure\Repositories;

use App\Modules\Loyalty\Domain\Entities\Tier;
use App\Modules\Loyalty\Domain\Repositories\TierRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Eloquent implementation of Tier repository
 */
final readonly class EloquentTierRepository implements TierRepositoryInterface
{
    /**
     * Find tier by ID
     */
    public function findById(string $id): ?Tier
    {
        return Tier::find($id);
    }

    /**
     * Get all tiers for a program ordered by level
     *
     * @return Collection<int, Tier>
     */
    public function findByProgram(string $programId): Collection
    {
        return Tier::where('program_id', $programId)
            ->orderBy('level')
            ->get();
    }

    /**
     * Find tier by program and level
     */
    public function findByProgramAndLevel(string $programId, int $level): ?Tier
    {
        return Tier::where('program_id', $programId)
            ->where('level', $level)
            ->first();
    }

    /**
     * Save tier
     */
    public function save(Tier $tier): Tier
    {
        $tier->save();

        return $tier->fresh();
    }

    /**
     * Delete tier
     */
    public function delete(string $id): bool
    {
        $tier = $this->findById($id);

        if ($tier === null) {
            return false;
        }

        return (bool) $tier->delete();
    }
}
