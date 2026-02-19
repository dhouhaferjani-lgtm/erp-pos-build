<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Services;

use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\Tier;
use App\Modules\Loyalty\Domain\Enums\QualificationType;
use Illuminate\Support\Collection;

final readonly class TierEvaluationService
{
    /**
     * Determine appropriate tier for member
     *
     * @param  Collection<int, Tier>  $programTiers
     * @param  array<string, mixed>  $memberStats  (lifetime_spend, points_earned, visit_count)
     */
    public function calculateTier(
        Enrollment $enrollment,
        Collection $programTiers,
        array $memberStats
    ): ?Tier {
        // Sort tiers by level in descending order to find highest qualifying tier
        $sortedTiers = $programTiers->sortByDesc('level');

        // Iterate through tiers to find highest one member qualifies for
        foreach ($sortedTiers as $tier) {
            // Manual tiers cannot be automatically calculated
            if ($tier->qualification_type === QualificationType::Manual) {
                continue;
            }

            if ($this->qualifiesForTier($tier, $memberStats)) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * Check if member qualifies for a specific tier
     *
     * @param  array<string, mixed>  $memberStats
     */
    public function qualifiesForTier(
        Tier $tier,
        array $memberStats,
        ?int $qualificationPeriodMonths = null
    ): bool {
        $threshold = (float) $tier->qualification_threshold;

        return match ($tier->qualification_type) {
            QualificationType::Spend => (float) ($memberStats['lifetime_spend'] ?? 0.0) >= $threshold,
            QualificationType::PointsEarned => (int) ($memberStats['points_earned'] ?? 0) >= $threshold,
            QualificationType::Visits => (int) ($memberStats['visit_count'] ?? 0) >= $threshold,
            QualificationType::Manual => false, // Manual tiers must be explicitly assigned
        };
    }

    /**
     * Determine if tier change should trigger
     */
    public function shouldChangeTier(
        ?Tier $currentTier,
        Tier $calculatedTier
    ): bool {
        // If no current tier (new member), change is required
        if ($currentTier === null) {
            return true;
        }

        // Compare levels - change needed if different
        return $currentTier->level !== $calculatedTier->level;
    }

    /**
     * Check if tier change is upgrade or downgrade
     */
    public function isUpgrade(?Tier $currentTier, Tier $newTier): bool
    {
        $currentLevel = $currentTier !== null ? $currentTier->level : 0;

        return $currentLevel < $newTier->level;
    }

    /**
     * Apply tier multiplier to points
     */
    public function applyTierMultiplier(float $basePoints, Tier $tier): float
    {
        return $basePoints * (float) $tier->earning_multiplier;
    }
}
