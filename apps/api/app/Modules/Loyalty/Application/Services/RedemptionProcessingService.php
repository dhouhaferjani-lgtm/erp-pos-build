<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Services;

use App\Modules\Loyalty\Application\DTOs\TransactionData;
use App\Modules\Loyalty\Domain\Entities\Transaction;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use App\Modules\Loyalty\Domain\Enums\TransactionType;
use App\Modules\Loyalty\Domain\Events\RewardRedeemedV2;
use App\Modules\Loyalty\Domain\Exceptions\EnrollmentNotActiveException;
use App\Modules\Loyalty\Domain\Exceptions\InsufficientPointsException;
use App\Modules\Loyalty\Domain\Repositories\EnrollmentRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\RewardRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\TransactionRepositoryInterface;
use App\Modules\Loyalty\Domain\Services\RewardRedemptionService;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Application service for processing reward redemptions
 *
 * Orchestrates the reward redemption flow using domain services and repositories
 */
final readonly class RedemptionProcessingService
{
    /**
     * Loyalty points are not a currency: `Enrollment::$current_balance` and
     * every `Transaction` balance column are pinned to `decimal:3` by their
     * model casts, so the scale is a property of the domain rather than of a
     * resolved currency. Named here so no bcmath call in this file carries a
     * bare literal.
     */
    private const POINTS_SCALE = 3;

    public function __construct(
        private EnrollmentRepositoryInterface $enrollmentRepository,
        private RewardRepositoryInterface $rewardRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private RewardRedemptionService $rewardRedemptionService,
    ) {}

    /**
     * Redeem a reward for a member.
     *
     * Concurrency contract (Session B lane Q-3):
     *  - the enrollment is loaded INSIDE the transaction under `FOR UPDATE`;
     *  - the debit is a conditional, relative UPDATE against the committed row,
     *    never an absolute value derived from a model read earlier;
     *  - `balance_before` / `balance_after` on the ledger row come from that
     *    locked row;
     *  - a `$redemptionKey` makes the whole call idempotent, backstopped by the
     *    partial unique index `loyalty_txn_redeem_key_unique`.
     *
     * @param  string  $enrollmentId  Enrollment to redeem for
     * @param  string  $rewardId  Reward to redeem
     * @param  string|null  $description  Optional transaction description
     * @param  string|null  $redemptionKey  Client/source-derived idempotency key.
     *                                      Replaying the same key returns the original
     *                                      transaction instead of redeeming again.
     *
     * @throws InvalidArgumentException if enrollment or reward not found
     * @throws EnrollmentNotActiveException if the enrollment is suspended or opted out
     * @throws InsufficientPointsException if the committed balance does not cover the cost
     */
    public function redeemReward(
        string $enrollmentId,
        string $rewardId,
        ?string $description = null,
        ?string $redemptionKey = null,
    ): TransactionData {
        // Validate reward exists and is active. The ENROLLMENT is deliberately
        // NOT read here: it is balance-bearing state and is loaded under a row
        // lock inside the transaction below.
        $reward = $this->rewardRepository->findById($rewardId);
        if ($reward === null) {
            throw new InvalidArgumentException("Reward with ID {$rewardId} not found");
        }

        if (! $reward->is_active) {
            throw new InvalidArgumentException("Reward {$rewardId} is not active");
        }

        // Idempotency fast path: a replay of a key we already honoured returns
        // the original transaction rather than redeeming a second time.
        if ($redemptionKey !== null) {
            $replay = $this->transactionRepository->findRedeemByIdempotencyKey($enrollmentId, $redemptionKey);
            if ($replay !== null) {
                return TransactionData::fromModel($replay);
            }
        }

        try {
            return DB::transaction(function () use ($enrollmentId, $reward, $description, $redemptionKey) {
                // Locked read: this row, and the balance on it, are the authority.
                $enrollment = $this->enrollmentRepository->findByIdForUpdate($enrollmentId);
                if ($enrollment === null) {
                    throw new InvalidArgumentException("Enrollment with ID {$enrollmentId} not found");
                }

                // Mirrors PointAdjustmentService::adjust — an opted-out or
                // suspended member may not move points on any path.
                if ($enrollment->status !== EnrollmentStatus::Active) {
                    throw new EnrollmentNotActiveException('Enrollment must be active to redeem a reward');
                }

                // Use domain service to calculate cost
                $pointsAmount = $this->rewardRedemptionService->calculateCost($reward);
                $pointsRequired = $pointsAmount->value;

                // Check if member is eligible (this includes sufficient points check)
                if (! $this->rewardRedemptionService->isEligible($enrollment, $reward, 0)) {
                    // More specific error message if insufficient points
                    if (bccomp((string) $enrollment->current_balance, $pointsRequired, self::POINTS_SCALE) < 0) {
                        throw new InsufficientPointsException(
                            "Insufficient points. Required: {$pointsRequired}, Available: {$enrollment->current_balance}"
                        );
                    }

                    // Other eligibility issues (tier requirements, date range, quantity, etc.)
                    throw new InvalidArgumentException('Member is not eligible to redeem this reward');
                }

                // Ledger balances are computed from the LOCKED row.
                $balanceBefore = CurrencyScale::bcformatStrict(
                    (string) $enrollment->current_balance,
                    self::POINTS_SCALE,
                );
                $balanceAfter = bcsub($balanceBefore, $pointsRequired, self::POINTS_SCALE);

                // Atomic, conditional, RELATIVE debit. Zero affected rows means a
                // concurrent redemption took the points between the lock and here
                // (or the row moved under a replica/retry) — refuse rather than
                // overwrite with an absolute value.
                $debited = $this->enrollmentRepository->debitForRedemption(
                    $enrollment->id,
                    $pointsRequired,
                    self::POINTS_SCALE,
                );

                if (! $debited) {
                    $committed = $this->enrollmentRepository->findById($enrollment->id);
                    $available = $committed !== null
                        ? (string) $committed->current_balance
                        : $balanceBefore;

                    throw new InsufficientPointsException(
                        "Insufficient points. Required: {$pointsRequired}, Available: {$available}"
                    );
                }

                // Create redemption transaction
                $transaction = new Transaction([
                    'enrollment_id' => $enrollment->id,
                    'transaction_type' => TransactionType::Redeem,
                    'amount' => bcmul($pointsRequired, '-1', self::POINTS_SCALE), // Negative for redemption
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'reward_id' => $reward->id,
                    'redemption_key' => $redemptionKey,
                    'description' => $description ?? "Redeemed reward: {$reward->name}",
                    'metadata' => [
                        'reward_id' => $reward->id,
                        'reward_name' => $reward->name,
                        'reward_type' => $reward->reward_type->value,
                        'points_cost' => $pointsRequired,
                    ],
                    // loyalty_transactions.created_at is NOT NULL with no default and
                    // Transaction::$timestamps is false, so this must be explicit —
                    // exactly as EarningProcessingService and PointAdjustmentService do.
                    'created_at' => now(),
                ]);

                $transaction = $this->transactionRepository->save($transaction);

                // Dispatch event after transaction commits
                DB::afterCommit(function () use ($transaction, $enrollment, $reward, $pointsRequired) {
                    event(new RewardRedeemedV2(
                        transactionId: $transaction->id,
                        enrollmentId: $enrollment->id,
                        memberId: $enrollment->member_id,
                        programId: $enrollment->program_id,
                        rewardId: $reward->id,
                        pointsCost: (float) $pointsRequired, // float at event-display boundary; canonical string used for all fiscal/balance math above
                        redeemedAt: $transaction->created_at->toIso8601String(),
                    ));
                });

                return TransactionData::fromModel($transaction);
            });
        } catch (QueryException $e) {
            // A concurrent replay of the same key lost the race on
            // loyalty_txn_redeem_key_unique. The winner's row is the answer —
            // return it idempotently instead of surfacing a 500.
            if ($redemptionKey !== null && $this->isRedemptionKeyDuplicate($e)) {
                $winner = $this->transactionRepository->findRedeemByIdempotencyKey($enrollmentId, $redemptionKey);
                if ($winner !== null) {
                    return TransactionData::fromModel($winner);
                }
            }

            throw $e;
        }
    }

    /**
     * Did this QueryException come from the redeem idempotency index?
     *
     * Same shape as EarningProcessingService::translateEarnDuplicate: match on
     * SQLSTATE 23505 (PG unique violation) AND our own index name, so an
     * unrelated unique violation is never swallowed as an idempotent replay.
     * SQLite reports 23000 for the same class of violation and names the index
     * in the message, which keeps the phpunit engine honest too.
     *
     * @internal Exposed for unit testing only; reached via redeemReward().
     */
    public function isRedemptionKeyDuplicate(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        return ($sqlState === '23505' || $sqlState === '23000')
            && str_contains($e->getMessage(), 'loyalty_txn_redeem_key_unique');
    }

    /**
     * Check if a reward can be redeemed (without actually redeeming)
     *
     * @param  string  $enrollmentId  Enrollment to check for
     * @param  string  $rewardId  Reward to check
     * @return array{can_redeem: bool, points_required: numeric-string, reason: string|null}
     */
    public function canRedeem(string $enrollmentId, string $rewardId): array
    {
        $enrollment = $this->enrollmentRepository->findById($enrollmentId);
        if ($enrollment === null) {
            return [
                'can_redeem' => false,
                'points_required' => CurrencyScale::bcformatStrict('0', self::POINTS_SCALE),
                'reason' => 'Enrollment not found',
            ];
        }

        $reward = $this->rewardRepository->findById($rewardId);
        if ($reward === null) {
            return [
                'can_redeem' => false,
                'points_required' => CurrencyScale::bcformatStrict('0', self::POINTS_SCALE),
                'reason' => 'Reward not found',
            ];
        }

        if (! $reward->is_active) {
            return [
                'can_redeem' => false,
                'points_required' => CurrencyScale::bcformat((string) $reward->points_cost, self::POINTS_SCALE),
                'reason' => 'Reward is not active',
            ];
        }

        $pointsAmount = $this->rewardRedemptionService->calculateCost($reward);
        $pointsRequired = $pointsAmount->value;

        // Check eligibility using domain service
        $isEligible = $this->rewardRedemptionService->isEligible($enrollment, $reward, 0);

        if (! $isEligible) {
            // Provide more specific reason if possible
            if (bccomp((string) $enrollment->current_balance, $pointsRequired, self::POINTS_SCALE) < 0) {
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
