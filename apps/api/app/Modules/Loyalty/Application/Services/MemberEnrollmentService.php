<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Services;

use App\Modules\Loyalty\Application\DTOs\EnrollmentData;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\Transaction;
use App\Modules\Loyalty\Domain\Enums\TransactionType;
use App\Modules\Loyalty\Domain\Events\MemberEnrolledV2;
use App\Modules\Loyalty\Domain\Repositories\EnrollmentRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\LoyaltyMemberRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\LoyaltyProgramRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\TransactionRepositoryInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Application service for member enrollment operations
 */
final readonly class MemberEnrollmentService
{
    public function __construct(
        private EnrollmentRepositoryInterface $enrollmentRepository,
        private LoyaltyMemberRepositoryInterface $memberRepository,
        private LoyaltyProgramRepositoryInterface $programRepository,
        private TransactionRepositoryInterface $transactionRepository,
    ) {}

    /**
     * Enroll a member in a loyalty program
     *
     * @throws InvalidArgumentException if member or program not found, or already enrolled
     */
    public function enroll(string $memberId, string $programId, ?float $welcomeBonus = null): EnrollmentData
    {
        // Validate member exists
        $member = $this->memberRepository->findById($memberId);
        if ($member === null) {
            throw new InvalidArgumentException("Member with ID {$memberId} not found");
        }

        // Validate program exists and is active
        $program = $this->programRepository->findById($programId);
        if ($program === null) {
            throw new InvalidArgumentException("Program with ID {$programId} not found");
        }

        if (! $program->isActive()) {
            throw new InvalidArgumentException("Program {$program->name} is not active");
        }

        // Check if already enrolled
        $existing = $this->enrollmentRepository->findByMemberAndProgram($memberId, $programId);
        if ($existing !== null) {
            throw new InvalidArgumentException('Member is already enrolled in this program');
        }

        return DB::transaction(function () use ($member, $memberId, $programId, $welcomeBonus) {
            // Create enrollment
            $enrollment = new Enrollment([
                'program_id' => $programId,
                'member_id' => $memberId,
                'current_balance' => 0,
                'lifetime_earned' => 0,
                'lifetime_redeemed' => 0,
                'status' => 'ACTIVE',
                'enrolled_at' => now(),
            ]);

            $enrollment = $this->enrollmentRepository->save($enrollment);

            // Apply welcome bonus if provided
            if ($welcomeBonus !== null && $welcomeBonus > 0) {
                $this->applyWelcomeBonus($enrollment, $welcomeBonus);
            }

            // Dispatch event
            event(new MemberEnrolledV2(
                enrollmentId: $enrollment->id,
                tenantId: $member->tenant_id,
                programId: $programId,
                memberId: $memberId,
                enrolledAt: $enrollment->enrolled_at->toIso8601String(),
                customerId: $member->customer_id,
                welcomeBonus: $welcomeBonus,
            ));

            // Reload to get updated balance
            $enrollment = $this->enrollmentRepository->findById($enrollment->id);

            return EnrollmentData::fromModel($enrollment);
        });
    }

    /**
     * Opt a member out of a program
     */
    public function optOut(string $enrollmentId): void
    {
        $enrollment = $this->enrollmentRepository->findById($enrollmentId);

        if ($enrollment === null) {
            throw new InvalidArgumentException("Enrollment with ID {$enrollmentId} not found");
        }

        $enrollment->status = 'OPTED_OUT';
        $this->enrollmentRepository->save($enrollment);
    }

    /**
     * Reactivate an opted-out enrollment
     */
    public function reactivate(string $enrollmentId): void
    {
        $enrollment = $this->enrollmentRepository->findById($enrollmentId);

        if ($enrollment === null) {
            throw new InvalidArgumentException("Enrollment with ID {$enrollmentId} not found");
        }

        $enrollment->status = 'ACTIVE';
        $this->enrollmentRepository->save($enrollment);
    }

    /**
     * Apply welcome bonus to new enrollment
     */
    private function applyWelcomeBonus(Enrollment $enrollment, float $amount): void
    {
        // Create welcome bonus transaction
        $transaction = new Transaction([
            'enrollment_id' => $enrollment->id,
            'transaction_type' => TransactionType::Bonus,
            'amount' => $amount,
            'balance_before' => $enrollment->current_balance,
            'balance_after' => $enrollment->current_balance + $amount,
            'description' => 'Welcome bonus',
            'metadata' => ['bonus_type' => 'welcome'],
        ]);

        $this->transactionRepository->save($transaction);

        // Update enrollment balances
        $enrollment->current_balance += $amount;
        $enrollment->lifetime_earned += $amount;
        $enrollment->last_transaction_at = now();

        $this->enrollmentRepository->save($enrollment);
    }
}
