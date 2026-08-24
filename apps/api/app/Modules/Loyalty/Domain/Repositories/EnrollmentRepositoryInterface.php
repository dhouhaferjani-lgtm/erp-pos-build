<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Repositories;

use App\Modules\Loyalty\Domain\Entities\Enrollment;
use Illuminate\Support\Collection;

/**
 * Repository interface for Enrollment
 */
interface EnrollmentRepositoryInterface
{
    /**
     * Find enrollment by ID
     */
    public function findById(string $id): ?Enrollment;

    /**
     * Find enrollment by ID under a row lock (SELECT ... FOR UPDATE).
     *
     * MUST be called inside an open transaction. Every balance-mutating path
     * reads through this, never through findById(): an unlocked read is what
     * let two concurrent redemptions each compute their new balance from the
     * same stale snapshot.
     */
    public function findByIdForUpdate(string $id): ?Enrollment;

    /**
     * Conditionally debit an enrollment for a redemption.
     *
     * Issues a single relative UPDATE — `SET current_balance = current_balance
     * - :points ... WHERE id = :id AND current_balance >= :points` — so the
     * committed row, not an in-memory model, is the authority on whether the
     * points are there. Also advances lifetime_redeemed and last_transaction_at
     * in the same statement.
     *
     * @param  numeric-string  $points  Positive redemption cost
     * @return bool true when exactly one row was debited; false when the
     *              committed balance did not cover the cost (caller refuses)
     */
    public function debitForRedemption(string $id, string $points, int $scale): bool;

    /**
     * Find enrollment by member and program
     */
    public function findByMemberAndProgram(string $memberId, string $programId): ?Enrollment;

    /**
     * Get all enrollments for a member
     *
     * @return Collection<int, Enrollment>
     */
    public function findByMember(string $memberId): Collection;

    /**
     * Get all enrollments for a program
     *
     * @return Collection<int, Enrollment>
     */
    public function findByProgram(string $programId): Collection;

    /**
     * Save enrollment
     */
    public function save(Enrollment $enrollment): Enrollment;

    /**
     * Delete enrollment
     */
    public function delete(string $id): bool;
}
