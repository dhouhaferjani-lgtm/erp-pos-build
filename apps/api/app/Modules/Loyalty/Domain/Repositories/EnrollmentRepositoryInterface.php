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
