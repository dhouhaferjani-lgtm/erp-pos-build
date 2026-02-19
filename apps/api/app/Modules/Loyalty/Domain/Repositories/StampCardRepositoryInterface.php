<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Repositories;

use App\Modules\Loyalty\Domain\Entities\MemberStampCard;
use App\Modules\Loyalty\Domain\Entities\StampCardDefinition;
use Illuminate\Support\Collection;

/**
 * Repository interface for Stamp Cards (both definitions and member cards)
 */
interface StampCardRepositoryInterface
{
    /**
     * Find stamp card definition by ID
     */
    public function findDefinitionById(string $id): ?StampCardDefinition;

    /**
     * Get all stamp card definitions for a program
     *
     * @return Collection<int, StampCardDefinition>
     */
    public function findDefinitionsByProgram(string $programId): Collection;

    /**
     * Find member stamp card by ID
     */
    public function findMemberCardById(string $id): ?MemberStampCard;

    /**
     * Get member's stamp cards for an enrollment
     *
     * @return Collection<int, MemberStampCard>
     */
    public function findMemberCardsByEnrollment(string $enrollmentId): Collection;

    /**
     * Get active (uncompleted, unexpired) member card for definition
     */
    public function findActiveMemberCard(string $enrollmentId, string $cardDefinitionId): ?MemberStampCard;

    /**
     * Save stamp card definition
     */
    public function saveDefinition(StampCardDefinition $definition): StampCardDefinition;

    /**
     * Save member stamp card
     */
    public function saveMemberCard(MemberStampCard $card): MemberStampCard;

    /**
     * Delete stamp card definition
     */
    public function deleteDefinition(string $id): bool;

    /**
     * Delete member stamp card
     */
    public function deleteMemberCard(string $id): bool;
}
