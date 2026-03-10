<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Infrastructure\Repositories;

use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Repositories\EarningRuleRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Eloquent implementation of EarningRule repository
 */
final readonly class EloquentEarningRuleRepository implements EarningRuleRepositoryInterface
{
    /**
     * Find earning rule by ID
     */
    public function findById(string $id): ?EarningRule
    {
        return EarningRule::find($id);
    }

    /**
     * Get all earning rules for a program ordered by priority
     *
     * @return Collection<int, EarningRule>
     */
    public function findByProgram(string $programId): Collection
    {
        return EarningRule::where('program_id', $programId)
            ->orderBy('priority')
            ->get();
    }

    /**
     * Get active earning rules for a program ordered by priority
     *
     * @return Collection<int, EarningRule>
     */
    public function findActiveByProgram(string $programId): Collection
    {
        return EarningRule::where('program_id', $programId)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('start_date')
                    ->orWhere('start_date', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->orderBy('priority')
            ->get();
    }

    /**
     * Save earning rule
     */
    public function save(EarningRule $rule): EarningRule
    {
        $rule->save();

        /** @var EarningRule $freshRule */
        $freshRule = $rule->fresh();

        return $freshRule;
    }

    /**
     * Delete earning rule
     */
    public function delete(string $id): bool
    {
        $rule = $this->findById($id);

        if ($rule === null) {
            return false;
        }

        return (bool) $rule->delete();
    }
}
