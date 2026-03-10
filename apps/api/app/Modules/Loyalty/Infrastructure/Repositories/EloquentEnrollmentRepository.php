<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Infrastructure\Repositories;

use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Repositories\EnrollmentRepositoryInterface;
use Illuminate\Support\Collection;

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
