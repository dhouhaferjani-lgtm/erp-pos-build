<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Services;

use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\Tier;
use App\Modules\Loyalty\Domain\Events\TierDowngradedV2;
use App\Modules\Loyalty\Domain\Events\TierUpgradedV2;
use App\Modules\Loyalty\Domain\Repositories\EnrollmentRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\TierRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\TransactionRepositoryInterface;
use App\Modules\Loyalty\Domain\Services\TierEvaluationService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Application service for managing member tier changes
 *
 * Orchestrates tier evaluation and tier changes (upgrades/downgrades)
 */
final readonly class TierManagementService
{
    public function __construct(
        private EnrollmentRepositoryInterface $enrollmentRepository,
        private TierRepositoryInterface $tierRepository,
        /** @phpstan-ignore-next-line Property is injected for future use */
        private TransactionRepositoryInterface $transactionRepository,
        private TierEvaluationService $tierEvaluationService,
    ) {}

    /**
     * Evaluate and apply tier changes for a member
     *
     * @param  string  $enrollmentId  Enrollment to evaluate
     * @param  array<string, mixed>  $memberStats  Member statistics (lifetime_spend, points_earned, visit_count)
     * @return array{tier_changed: bool, previous_tier_id: string|null, new_tier_id: string|null, is_upgrade: bool}
     */
    public function evaluateAndApplyTierChange(string $enrollmentId, array $memberStats): array
    {
        // Validate enrollment exists
        $enrollment = $this->enrollmentRepository->findById($enrollmentId);
        if ($enrollment === null) {
            throw new InvalidArgumentException("Enrollment with ID {$enrollmentId} not found");
        }

        // Get all tiers for the program
        $programTiers = $this->tierRepository->findByProgram($enrollment->program_id);

        if ($programTiers->isEmpty()) {
            return [
                'tier_changed' => false,
                'previous_tier_id' => $enrollment->current_tier_id,
                'new_tier_id' => null,
                'is_upgrade' => false,
            ];
        }

        return DB::transaction(function () use ($enrollment, $programTiers, $memberStats) {
            // Calculate appropriate tier using domain service
            $calculatedTier = $this->tierEvaluationService->calculateTier(
                $enrollment,
                $programTiers,
                $memberStats
            );

            // No tier calculated (member doesn't qualify for any automatic tier)
            if ($calculatedTier === null) {
                return [
                    'tier_changed' => false,
                    'previous_tier_id' => $enrollment->current_tier_id,
                    'new_tier_id' => null,
                    'is_upgrade' => false,
                ];
            }

            // Get current tier
            $currentTier = null;
            if ($enrollment->current_tier_id !== null) {
                $currentTier = $this->tierRepository->findById($enrollment->current_tier_id);
            }

            // Check if tier change is needed
            if (! $this->tierEvaluationService->shouldChangeTier($currentTier, $calculatedTier)) {
                return [
                    'tier_changed' => false,
                    'previous_tier_id' => $enrollment->current_tier_id,
                    'new_tier_id' => $enrollment->current_tier_id,
                    'is_upgrade' => false,
                ];
            }

            // Determine if upgrade or downgrade
            $isUpgrade = $this->tierEvaluationService->isUpgrade($currentTier, $calculatedTier);

            $previousTierId = $enrollment->current_tier_id;

            // Apply tier change
            $enrollment->current_tier_id = $calculatedTier->id;
            $enrollment->tier_changed_at = now();

            $enrollment = $this->enrollmentRepository->save($enrollment);

            // Dispatch appropriate event after transaction commits
            if ($isUpgrade) {
                DB::afterCommit(function () use ($enrollment, $previousTierId, $calculatedTier) {
                    event(new TierUpgradedV2(
                        enrollmentId: $enrollment->id,
                        memberId: $enrollment->member_id,
                        programId: $enrollment->program_id,
                        previousTierId: $previousTierId,
                        newTierId: $calculatedTier->id,
                        newTierName: $calculatedTier->name,
                        upgradedAt: $enrollment->tier_changed_at?->toIso8601String() ?? now()->toIso8601String(),
                    ));
                });
            } else {
                DB::afterCommit(function () use ($enrollment, $previousTierId, $calculatedTier) {
                    event(new TierDowngradedV2(
                        enrollmentId: $enrollment->id,
                        memberId: $enrollment->member_id,
                        programId: $enrollment->program_id,
                        previousTierId: $previousTierId,
                        newTierId: $calculatedTier->id,
                        newTierName: $calculatedTier->name,
                        downgradedAt: $enrollment->tier_changed_at?->toIso8601String() ?? now()->toIso8601String(),
                    ));
                });
            }

            return [
                'tier_changed' => true,
                'previous_tier_id' => $previousTierId,
                'new_tier_id' => $calculatedTier->id,
                'is_upgrade' => $isUpgrade,
            ];
        });
    }

    /**
     * Check what tier a member qualifies for without applying changes
     *
     * @param  string  $enrollmentId  Enrollment to check
     * @param  array<string, mixed>  $memberStats  Member statistics
     * @return array{qualifies_for_tier: bool, tier_id: string|null, tier_name: string|null, is_upgrade: bool}
     */
    public function checkTierQualification(string $enrollmentId, array $memberStats): array
    {
        $enrollment = $this->enrollmentRepository->findById($enrollmentId);
        if ($enrollment === null) {
            throw new InvalidArgumentException("Enrollment with ID {$enrollmentId} not found");
        }

        $programTiers = $this->tierRepository->findByProgram($enrollment->program_id);

        if ($programTiers->isEmpty()) {
            return [
                'qualifies_for_tier' => false,
                'tier_id' => null,
                'tier_name' => null,
                'is_upgrade' => false,
            ];
        }

        // Calculate appropriate tier
        $calculatedTier = $this->tierEvaluationService->calculateTier(
            $enrollment,
            $programTiers,
            $memberStats
        );

        if ($calculatedTier === null) {
            return [
                'qualifies_for_tier' => false,
                'tier_id' => null,
                'tier_name' => null,
                'is_upgrade' => false,
            ];
        }

        // Get current tier
        $currentTier = null;
        if ($enrollment->current_tier_id !== null) {
            $currentTier = $this->tierRepository->findById($enrollment->current_tier_id);
        }

        // Determine if upgrade
        $isUpgrade = $this->tierEvaluationService->isUpgrade($currentTier, $calculatedTier);

        return [
            'qualifies_for_tier' => true,
            'tier_id' => $calculatedTier->id,
            'tier_name' => $calculatedTier->name,
            'is_upgrade' => $isUpgrade,
        ];
    }

    /**
     * Manually assign a tier to a member (for manual qualification types)
     *
     * @param  string  $enrollmentId  Enrollment to update
     * @param  string  $tierId  Tier to assign
     * @return array{tier_changed: bool, previous_tier_id: string|null, new_tier_id: string, is_upgrade: bool}
     */
    public function assignTierManually(string $enrollmentId, string $tierId): array
    {
        $enrollment = $this->enrollmentRepository->findById($enrollmentId);
        if ($enrollment === null) {
            throw new InvalidArgumentException("Enrollment with ID {$enrollmentId} not found");
        }

        $tier = $this->tierRepository->findById($tierId);
        if ($tier === null) {
            throw new InvalidArgumentException("Tier with ID {$tierId} not found");
        }

        // Verify tier belongs to the same program
        if ($tier->program_id !== $enrollment->program_id) {
            throw new InvalidArgumentException("Tier does not belong to the enrollment's program");
        }

        return DB::transaction(function () use ($enrollment, $tier) {
            // Get current tier
            $currentTier = null;
            if ($enrollment->current_tier_id !== null) {
                $currentTier = $this->tierRepository->findById($enrollment->current_tier_id);
            }

            // Check if same tier
            if ($enrollment->current_tier_id === $tier->id) {
                return [
                    'tier_changed' => false,
                    'previous_tier_id' => $enrollment->current_tier_id,
                    'new_tier_id' => $tier->id,
                    'is_upgrade' => false,
                ];
            }

            // Determine if upgrade or downgrade
            $isUpgrade = $this->tierEvaluationService->isUpgrade($currentTier, $tier);

            $previousTierId = $enrollment->current_tier_id;

            // Apply tier change
            $enrollment->current_tier_id = $tier->id;
            $enrollment->tier_changed_at = now();

            $enrollment = $this->enrollmentRepository->save($enrollment);

            // Dispatch appropriate event after transaction commits
            if ($isUpgrade) {
                DB::afterCommit(function () use ($enrollment, $previousTierId, $tier) {
                    event(new TierUpgradedV2(
                        enrollmentId: $enrollment->id,
                        memberId: $enrollment->member_id,
                        programId: $enrollment->program_id,
                        previousTierId: $previousTierId,
                        newTierId: $tier->id,
                        newTierName: $tier->name,
                        upgradedAt: $enrollment->tier_changed_at?->toIso8601String() ?? now()->toIso8601String(),
                    ));
                });
            } else {
                DB::afterCommit(function () use ($enrollment, $previousTierId, $tier) {
                    event(new TierDowngradedV2(
                        enrollmentId: $enrollment->id,
                        memberId: $enrollment->member_id,
                        programId: $enrollment->program_id,
                        previousTierId: $previousTierId,
                        newTierId: $tier->id,
                        newTierName: $tier->name,
                        downgradedAt: $enrollment->tier_changed_at?->toIso8601String() ?? now()->toIso8601String(),
                    ));
                });
            }

            return [
                'tier_changed' => true,
                'previous_tier_id' => $previousTierId,
                'new_tier_id' => $tier->id,
                'is_upgrade' => $isUpgrade,
            ];
        });
    }
}
