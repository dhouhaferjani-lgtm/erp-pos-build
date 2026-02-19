<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Infrastructure\Repositories;

use App\Modules\Loyalty\Domain\Entities\MemberStampCard;
use App\Modules\Loyalty\Domain\Entities\StampCardDefinition;
use App\Modules\Loyalty\Domain\Repositories\StampCardRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Eloquent implementation of StampCard repository
 */
final readonly class EloquentStampCardRepository implements StampCardRepositoryInterface
{
    /**
     * Find stamp card definition by ID
     */
    public function findDefinitionById(string $id): ?StampCardDefinition
    {
        return StampCardDefinition::find($id);
    }

    /**
     * Get all stamp card definitions for a program
     *
     * @return Collection<int, StampCardDefinition>
     */
    public function findDefinitionsByProgram(string $programId): Collection
    {
        return StampCardDefinition::where('program_id', $programId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Find member stamp card by ID
     */
    public function findMemberCardById(string $id): ?MemberStampCard
    {
        return MemberStampCard::find($id);
    }

    /**
     * Get member's stamp cards for an enrollment
     *
     * @return Collection<int, MemberStampCard>
     */
    public function findMemberCardsByEnrollment(string $enrollmentId): Collection
    {
        return MemberStampCard::where('enrollment_id', $enrollmentId)
            ->with('cardDefinition')
            ->orderBy('started_at', 'desc')
            ->get();
    }

    /**
     * Get active (uncompleted, unexpired) member card for definition
     */
    public function findActiveMemberCard(string $enrollmentId, string $cardDefinitionId): ?MemberStampCard
    {
        return MemberStampCard::where('enrollment_id', $enrollmentId)
            ->where('card_definition_id', $cardDefinitionId)
            ->whereNull('completed_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    /**
     * Save stamp card definition
     */
    public function saveDefinition(StampCardDefinition $definition): StampCardDefinition
    {
        $definition->save();

        return $definition->fresh();
    }

    /**
     * Save member stamp card
     */
    public function saveMemberCard(MemberStampCard $card): MemberStampCard
    {
        $card->save();

        return $card->fresh();
    }

    /**
     * Delete stamp card definition
     */
    public function deleteDefinition(string $id): bool
    {
        $definition = $this->findDefinitionById($id);

        if ($definition === null) {
            return false;
        }

        return (bool) $definition->delete();
    }

    /**
     * Delete member stamp card
     */
    public function deleteMemberCard(string $id): bool
    {
        $card = $this->findMemberCardById($id);

        if ($card === null) {
            return false;
        }

        return (bool) $card->delete();
    }
}
