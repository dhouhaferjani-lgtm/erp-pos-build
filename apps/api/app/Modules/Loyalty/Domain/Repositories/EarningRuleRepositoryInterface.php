<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Repositories;

use App\Modules\Loyalty\Domain\Entities\EarningRule;
use Illuminate\Support\Collection;

/**
 * Repository interface for EarningRule
 */
interface EarningRuleRepositoryInterface
{
    /**
     * Find earning rule by ID
     */
    public function findById(string $id): ?EarningRule;

    /**
     * Get all earning rules for a program ordered by priority
     *
     * @return Collection<int, EarningRule>
     */
    public function findByProgram(string $programId): Collection;

    /**
     * Get active earning rules for a program ordered by priority
     *
     * @return Collection<int, EarningRule>
     */
    public function findActiveByProgram(string $programId): Collection;

    /**
     * Save earning rule
     */
    public function save(EarningRule $rule): EarningRule;

    /**
     * Delete earning rule
     */
    public function delete(string $id): bool;
}
