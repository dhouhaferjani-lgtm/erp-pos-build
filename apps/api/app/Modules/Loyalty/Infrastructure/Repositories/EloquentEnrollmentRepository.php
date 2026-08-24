<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Infrastructure\Repositories;

use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Repositories\EnrollmentRepositoryInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent implementation of Enrollment repository
 */
final readonly class EloquentEnrollmentRepository implements EnrollmentRepositoryInterface
{
    /**
     * Find enrollment by ID
     */
    public function findById(string $id): ?Enrollment
    {
        return Enrollment::find($id);
    }

    /**
     * Find enrollment by ID under a row lock (SELECT ... FOR UPDATE).
     *
     * Caller must already be inside a transaction — outside one the lock is
     * released immediately and buys nothing.
     */
    public function findByIdForUpdate(string $id): ?Enrollment
    {
        return Enrollment::query()
            ->where('id', $id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Conditionally debit an enrollment for a redemption.
     *
     * Fully parameterised raw UPDATE rather than an Eloquent save: the new
     * balance must be derived from the COMMITTED row (`current_balance -
     * :points`), never written as an absolute value computed from a model that
     * may have been read before a concurrent redemption committed. The
     * `current_balance >= :points` predicate is the atomic sufficiency check —
     * zero affected rows means the points were not there and the caller must
     * refuse.
     *
     * `CAST(? AS NUMERIC)` is valid on both PostgreSQL and SQLite, so the same
     * statement serves production and the phpunit :memory: engine.
     *
     * @param  numeric-string  $points  Positive redemption cost
     */
    public function debitForRedemption(string $id, string $points, int $scale): bool
    {
        $now = now();

        $affected = DB::update(
            'UPDATE loyalty_enrollments
                SET current_balance = current_balance - CAST(? AS NUMERIC),
                    lifetime_redeemed = lifetime_redeemed + CAST(? AS NUMERIC),
                    last_transaction_at = ?,
                    updated_at = ?
              WHERE id = ?
                AND current_balance >= CAST(? AS NUMERIC)',
            [
                CurrencyScale::bcformatStrict($points, $scale),
                CurrencyScale::bcformatStrict($points, $scale),
                $now,
                $now,
                $id,
                CurrencyScale::bcformatStrict($points, $scale),
            ],
        );

        return $affected === 1;
    }

    /**
     * Find enrollment by member and program
     */
    public function findByMemberAndProgram(string $memberId, string $programId): ?Enrollment
    {
        return Enrollment::where('member_id', $memberId)
            ->where('program_id', $programId)
            ->first();
    }

    /**
     * Get all enrollments for a member
     *
     * @return Collection<int, Enrollment>
     */
    public function findByMember(string $memberId): Collection
    {
        return Enrollment::where('member_id', $memberId)
            ->with(['program', 'currentTier'])
            ->orderBy('enrolled_at', 'desc')
            ->get();
    }

    /**
     * Get all enrollments for a program
     *
     * @return Collection<int, Enrollment>
     */
    public function findByProgram(string $programId): Collection
    {
        return Enrollment::where('program_id', $programId)
            ->with(['member', 'currentTier'])
            ->orderBy('enrolled_at', 'desc')
            ->get();
    }

    /**
     * Save enrollment
     */
    public function save(Enrollment $enrollment): Enrollment
    {
        $enrollment->save();

        /** @var Enrollment $freshEnrollment */
        $freshEnrollment = $enrollment->fresh();

        return $freshEnrollment;
    }

    /**
     * Delete enrollment
     */
    public function delete(string $id): bool
    {
        $enrollment = $this->findById($id);

        if ($enrollment === null) {
            return false;
        }

        return (bool) $enrollment->delete();
    }
}
