<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Services;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Loyalty\Application\DTOs\TransactionData;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\Transaction;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use App\Modules\Loyalty\Domain\Enums\TransactionType;
use App\Modules\Loyalty\Domain\Events\LoyaltyAdjusted;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class PointAdjustmentService
{
    public function __construct(
        private CompanyContext $companyContext,
    ) {}

    /**
     * Adjust points for an enrollment (credit or debit).
     *
     * @param  numeric-string  $points  Positive for credit, negative for debit
     * @param  string  $reason  Reason for the adjustment
     *
     * @throws InvalidArgumentException if debit exceeds current balance or enrollment is not active
     */
    public function adjust(Enrollment $enrollment, string $points, string $reason, User $adjustedBy): TransactionData
    {
        if ($enrollment->status !== EnrollmentStatus::Active) {
            throw new InvalidArgumentException('Enrollment must be active to adjust points');
        }

        // Validate debit doesn't exceed balance
        if (bccomp($points, '0', 3) < 0) {
            $absPoints = bcmul($points, '-1', 3);
            if (bccomp($absPoints, $enrollment->current_balance, 3) > 0) {
                throw new InvalidArgumentException('Debit amount exceeds current balance');
            }
        }

        return DB::transaction(function () use ($enrollment, $points, $reason, $adjustedBy): TransactionData {
            $company = $this->companyContext->requireCompany();
            $balanceBefore = $enrollment->current_balance;
            $balanceAfter = bcadd($balanceBefore, $points, 3);

            $transaction = new Transaction([
                'enrollment_id' => $enrollment->id,
                'transaction_type' => TransactionType::Adjust,
                'amount' => $points,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => $reason,
                'metadata' => [
                    'adjusted_by' => $adjustedBy->id,
                    'adjustment_type' => bccomp($points, '0', 3) >= 0 ? 'credit' : 'debit',
                ],
                'created_by' => $adjustedBy->id,
                'created_at' => now(),
            ]);

            $transaction->save();

            // Update enrollment balance
            $enrollment->current_balance = $balanceAfter;
            $enrollment->last_transaction_at = now();

            // Update lifetime counters
            if (bccomp($points, '0', 3) > 0) {
                $enrollment->lifetime_earned = bcadd($enrollment->lifetime_earned, $points, 3);
            }

            $enrollment->save();

            // Calculate monetary value (1:1 for now, can be configurable)
            $monetaryValue = $points;
            $program = $enrollment->program;

            DB::afterCommit(function () use ($transaction, $enrollment, $adjustedBy, $company, $points, $monetaryValue, $program, $balanceBefore, $balanceAfter, $reason) {
                event(new LoyaltyAdjusted(
                    adjustmentId: $transaction->id,
                    enrollmentId: $enrollment->id,
                    tenantId: $adjustedBy->tenant_id,
                    companyId: $company->id,
                    partnerId: $enrollment->member_id,
                    programId: $enrollment->program_id,
                    adjustmentType: bccomp($points, '0', 3) >= 0 ? 'credit' : 'debit',
                    points: $points,
                    monetaryValue: $monetaryValue,
                    currency: $program->currency ?? 'EUR',
                    previousBalance: $balanceBefore,
                    newBalance: $balanceAfter,
                    adjustedBy: $adjustedBy->id,
                    adjustedAt: now()->toIso8601String(),
                    reason: $reason,
                ));
            });

            return TransactionData::fromModel($transaction);
        });
    }
}
