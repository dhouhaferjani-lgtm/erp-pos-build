<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Services;

use App\Modules\Loyalty\Application\DTOs\TransactionData;
use App\Modules\Loyalty\Domain\Entities\Transaction;
use App\Modules\Loyalty\Domain\Enums\TransactionType;
use App\Modules\Loyalty\Domain\Events\RewardRedeemedV2;
use App\Modules\Loyalty\Domain\Repositories\EnrollmentRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\RewardRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\TransactionRepositoryInterface;
use App\Modules\Loyalty\Domain\Services\RewardRedemptionService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Application service for processing reward redemptions
 *
 * Orchestrates the reward redemption flow using domain services and repositories
 */
final readonly class RedemptionProcessingService
{
    public function __construct(
        private EnrollmentRepositoryInterface $enrollmentRepository,
        private RewardRepositoryInterface $rewardRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private RewardRedemptionService $rewardRedemptionService,
    ) {}

    /**
     * Redeem a reward for a member
     *
     * @param  string  $enrollmentId  Enrollment to redeem for
     * @param  string  $rewardId  Reward to redeem
     * @param  string|null  $description  Optional transaction description
     *
     * @throws InvalidArgumentException if enrollment or reward not found, or insufficient points
     */
    public function redeemReward(
        string $enrollmentId,
        string $rewardId,
        ?string $description = null
    ): TransactionData {
        // Validate enrollment exists
        $enrollment = $this->enrollmentRepository->findById($enrollmentId);
        if ($enrollment === null) {
            throw new InvalidArgumentException("Enrollment with ID {$enrollmentId} not found");
        }

        // Validate reward exists and is active
        $reward = $this->rewardRepository->findById($rewardId);
        if ($reward === null) {
            throw new InvalidArgumentException("Reward with ID {$rewardId} not found");
        }

        if (! $reward->is_active) {
            throw new InvalidArgumentException("Reward {$rewardId} is not active");
        }

        return DB::transaction(function () use ($enrollment, $reward, $description) {
            // Use domain service to calculate cost
            $pointsAmount = $this->rewardRedemptionService->calculateCost($reward);
            $pointsRequired = $pointsAmount->value;

            // Check if member is eligible (this includes sufficient points check)
            if (! $this->rewardRedemptionService->isEligible($enrollment, $reward, 0)) {
                // More specific error message if insufficient points
                if ($enrollment->current_balance < $pointsRequired) {
                    throw new InvalidArgumentException(
                        "Insufficient points. Required: {$pointsRequired}, Available: {$enrollment->current_balance}"
                    );
                }

                // Other eligibility issues (tier requirements, date range, quantity, etc.)
                throw new InvalidArgumentException('Member is not eligible to redeem this reward');
            }

            // Create redemption transaction
            $transaction = new Transaction([
                'enrollment_id' => $enrollment->id,
                'transaction_type' => TransactionType::Redeem,
                'amount' => -$pointsRequired, // Negative for redemption
                'balance_before' => $enrollment->current_balance,
                'balance_after' => $enrollment->current_balance - $pointsRequired,
                'reward_id' => $reward->id,
                'description' => $description ?? "Redeemed reward: {$reward->name}",
                'metadata' => [
                    'reward_id' => $reward->id,
                    'reward_name' => $reward->name,
                    'reward_type' => $reward->reward_type->value,
                    'points_cost' => $pointsRequired,
                ],
            ]);

            $transaction = $this->transactionRepository->save($transaction);

            // Update enrollment balances
            $enrollment->current_balance = bcsub($enrollment->current_balance, (string) $pointsRequired, 3);
            $enrollment->lifetime_redeemed = bcadd($enrollment->lifetime_redeemed, (string) $pointsRequired, 3);
            $enrollment->last_transaction_at = now();

            $enrollment = $this->enrollmentRepository->save($enrollment);

            // Dispatch event
            event(new RewardRedeemedV2(
                transactionId: $transaction->id,
                enrollmentId: $enrollment->id,
                memberId: $enrollment->member_id,
                programId: $enrollment->program_id,
                rewardId: $reward->id,
                pointsCost: $pointsRequired,
                redeemedAt: $transaction->created_at->toIso8601String(),
            ));

            return TransactionData::fromModel($transaction);
        });
    }

    /**
     * Check if a reward can be redeemed (without actually redeeming)
     *
     * @param  string  $enrollmentId  Enrollment to check for
     * @param  string  $rewardId  Reward to check
     * @return array{can_redeem: bool, points_required: float, reason: string|null}
     */
    public function canRedeem(string $enrollmentId, string $rewardId): array
    {
        $enrollment = $this->enrollmentRepository->findById($enrollmentId);
        if ($enrollment === null) {
            return [
                'can_redeem' => false,
                'points_required' => 0.0,
                'reason' => 'Enrollment not found',
            ];
        }

        $reward = $this->rewardRepository->findById($rewardId);
        if ($reward === null) {
            return [
                'can_redeem' => false,
                'points_required' => 0.0,
                'reason' => 'Reward not found',
            ];
        }

        if (! $reward->is_active) {
            return [
                'can_redeem' => false,
                'points_required' => (float) $reward->points_cost,
                'reason' => 'Reward is not active',
            ];
        }

        $pointsAmount = $this->rewardRedemptionService->calculateCost($reward);
        $pointsRequired = $pointsAmount->value;

        // Check eligibility using domain service
        $isEligible = $this->rewardRedemptionService->isEligible($enrollment, $reward, 0);

        if (! $isEligible) {
            // Provide more specific reason if possible
            if ($enrollment->current_balance < $pointsRequired) {
                return [
                    'can_redeem' => false,
                    'points_required' => $pointsRequired,
                    'reason' => "Insufficient points (need {$pointsRequired}, have {$enrollment->current_balance})",
                ];
            }

            // Other eligibility issues
            return [
                'can_redeem' => false,
                'points_required' => $pointsRequired,
                'reason' => 'Member does not meet eligibility requirements (tier, date range, or quantity limits)',
            ];
        }

        return [
            'can_redeem' => true,
            'points_required' => $pointsRequired,
            'reason' => null,
        ];
    }
}
