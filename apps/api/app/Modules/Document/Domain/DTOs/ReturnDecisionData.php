<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\DTOs;

use App\Modules\Document\Domain\Enums\ReturnDecisionMode;
use Carbon\CarbonInterface;

/**
 * The explicit goods decision attached to an invoice cancellation.
 *
 * Plan CF CF-D1 / CF-D5. The whole flow is ONE composite server call rather than
 * three front-end calls: a crash between a cancel, a return-note create and a
 * return-note confirm would leave a cancelled invoice with no return note and no
 * record of what the user chose — which is exactly the silent outcome the owner
 * ruling forbids. So the decision travels with the cancel.
 *
 * `returnedOn` is meaningful only for `AlreadyReturned`; the constructor enforces
 * that rather than trusting the request layer, because this DTO is also the
 * composite's internal contract and the service must not have to re-check.
 */
final class ReturnDecisionData
{
    public function __construct(
        public readonly ReturnDecisionMode $mode,
        public readonly ?CarbonInterface $returnedOn = null,
        public readonly ?string $decidedBy = null,
    ) {
        if ($this->mode === ReturnDecisionMode::AlreadyReturned && $this->returnedOn === null) {
            throw new \InvalidArgumentException(
                'A return decision of already_returned must carry the date the goods came back.'
            );
        }

        if ($this->mode !== ReturnDecisionMode::AlreadyReturned && $this->returnedOn !== null) {
            throw new \InvalidArgumentException(
                'A return date is only meaningful for an already_returned decision; got mode '.$this->mode->value.'.'
            );
        }
    }

    /**
     * The append-only audit record shape (CF-D5).
     *
     * `accepted` distinguishes the decision that took effect from one that was
     * REFUSED as conflicting. A rejected decision is still appended — if nothing were
     * written, the claim that "both decisions are visible to support" would be true
     * of nobody, which is the false-assurance class the fiscal gate flagged.
     *
     * There is deliberately no `superseded` field: conflicts are refused rather than
     * superseding, so no path would ever have set it — a fiscal audit field nothing
     * writes is worse than no field.
     *
     * @return array{mode: string, returned_on: string|null, return_note_id: string|null, decided_by: string|null, decided_at: string, accepted: bool}
     */
    public function toAuditRecord(bool $accepted, ?string $returnNoteId = null): array
    {
        return [
            'mode' => $this->mode->value,
            'returned_on' => $this->returnedOn?->toDateString(),
            'return_note_id' => $returnNoteId,
            'decided_by' => $this->decidedBy,
            'decided_at' => now()->toIso8601String(),
            'accepted' => $accepted,
        ];
    }
}
