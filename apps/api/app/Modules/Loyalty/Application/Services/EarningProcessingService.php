<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Services;

use App\Modules\Loyalty\Application\DTOs\TransactionData;
use App\Modules\Loyalty\Domain\Entities\Transaction;
use App\Modules\Loyalty\Domain\Enums\TransactionType;
use App\Modules\Loyalty\Domain\Events\PointsEarnedV2;
use App\Modules\Loyalty\Domain\Repositories\EarningRuleRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\EnrollmentRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\TransactionRepositoryInterface;
use App\Modules\Loyalty\Domain\Services\PointEarningService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Application service for processing point earning transactions
 *
 * Orchestrates the point earning flow using domain services and repositories
 */
final readonly class EarningProcessingService
{
    public function __construct(
        private EnrollmentRepositoryInterface $enrollmentRepository,
        private EarningRuleRepositoryInterface $earningRuleRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private PointEarningService $pointEarningService,
    ) {}

    /**
     * Process point earning for a transaction
     *
     * @param  string  $enrollmentId  Enrollment to earn points for
     * @param  array<string, mixed>  $transactionData  Transaction data with items
     * @param  string  $sourceType  Type of source (e.g., 'order', 'invoice')
     * @param  string  $sourceId  ID of source document
     * @param  string|null  $description  Optional transaction description
     *
     * @throws InvalidArgumentException if enrollment not found or duplicate transaction
     */
    public function earnPoints(
        string $enrollmentId,
        array $transactionData,
        string $sourceType,
        string $sourceId,
        ?string $description = null
    ): TransactionData {
        // Validate enrollment exists
        $enrollment = $this->enrollmentRepository->findById($enrollmentId);
        if ($enrollment === null) {
            throw new InvalidArgumentException("Enrollment with ID {$enrollmentId} not found");
        }

        // Check for duplicate earning (idempotency)
        $existing = $this->transactionRepository->findBySourceDocument($sourceType, $sourceId);
        if ($existing !== null && $existing->transaction_type === TransactionType::Earn) {
            throw new InvalidArgumentException("Points already earned for {$sourceType} {$sourceId}");
        }

        // Get active earning rules for the program
        $rules = $this->earningRuleRepository->findActiveByProgram($enrollment->program_id);
        if ($rules->isEmpty()) {
            // No rules configured - no points earned
            return TransactionData::from([
                'id' => '',
                'enrollment_id' => $enrollmentId,
                'transaction_type' => TransactionType::Earn,
                'amount' => '0',
                'balance_before' => (string) $enrollment->current_balance,
                'balance_after' => (string) $enrollment->current_balance,
                'description' => 'No earning rules configured',
                'metadata' => [
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                ],
                'created_at' => now()->toIso8601String(),
            ]);
        }

        return DB::transaction(function () use ($enrollment, $transactionData, $sourceType, $sourceId, $description, $rules) {
            // Calculate points using domain service
            // Process all rules and sum the points
            $totalPoints = 0.0;

            foreach ($rules as $rule) {
                $pointsAmount = $this->pointEarningService->calculatePoints(
                    $enrollment,
                    $transactionData,
                    $rule
                );

                $totalPoints += $pointsAmount->value;
            }

            // If no points earned, return early (don't create transaction)
            if ($totalPoints <= 0) {
                return TransactionData::from([
                    'id' => '',
                    'enrollment_id' => $enrollment->id,
                    'transaction_type' => TransactionType::Earn,
                    'amount' => '0',
                    'balance_before' => (string) $enrollment->current_balance,
                    'balance_after' => (string) $enrollment->current_balance,
                    'description' => 'Transaction did not qualify for points',
                    'metadata' => [
                        'source_type' => $sourceType,
                        'source_id' => $sourceId,
                    ],
                    'created_at' => now()->toIso8601String(),
                ]);
            }

            $points = $totalPoints;

            // Create earning transaction
            $transaction = new Transaction([
                'enrollment_id' => $enrollment->id,
                'transaction_type' => TransactionType::Earn,
                'amount' => $points,
                'balance_before' => $enrollment->current_balance,
                'balance_after' => $enrollment->current_balance + $points,
                'description' => $description ?? "Earned {$points} points",
                'metadata' => [
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'transaction_data' => $transactionData,
                ],
            ]);

            $transaction = $this->transactionRepository->save($transaction);

            // Update enrollment balances
            $enrollment->current_balance = bcadd($enrollment->current_balance, (string) $points, 3);
            $enrollment->lifetime_earned = bcadd($enrollment->lifetime_earned, (string) $points, 3);
            $enrollment->last_transaction_at = now();

            $enrollment = $this->enrollmentRepository->save($enrollment);

            // Dispatch event
            event(new PointsEarnedV2(
                transactionId: $transaction->id,
                enrollmentId: $enrollment->id,
                memberId: $enrollment->member_id,
                programId: $enrollment->program_id,
                amount: $points,
                sourceType: $sourceType,
                sourceId: $sourceId,
                earnedAt: $transaction->created_at->toIso8601String(),
            ));

            return TransactionData::fromModel($transaction);
        });
    }

    /**
     * Preview points that would be earned for a transaction (without saving)
     *
     * @param  string  $enrollmentId  Enrollment to preview for
     * @param  array<string, mixed>  $transactionData  Transaction data with items
     * @return float Points that would be earned
     */
    public function previewEarning(string $enrollmentId, array $transactionData): float
    {
        $enrollment = $this->enrollmentRepository->findById($enrollmentId);
        if ($enrollment === null) {
            throw new InvalidArgumentException("Enrollment with ID {$enrollmentId} not found");
        }

        $rules = $this->earningRuleRepository->findActiveByProgram($enrollment->program_id);
        if ($rules->isEmpty()) {
            return 0.0;
        }

        // Calculate points for all rules and sum
        $totalPoints = 0.0;

        foreach ($rules as $rule) {
            $pointsAmount = $this->pointEarningService->calculatePoints(
                $enrollment,
                $transactionData,
                $rule
            );

            $totalPoints += $pointsAmount->value;
        }

        return $totalPoints;
    }
}
