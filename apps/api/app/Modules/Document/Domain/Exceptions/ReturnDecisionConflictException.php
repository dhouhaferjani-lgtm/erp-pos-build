<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

use App\Modules\Document\Domain\DTOs\ReturnDecisionData;
use DomainException;

/**
 * A different goods decision has already been recorded for this cancellation.
 *
 * Plan CF CF-D5. Carries the REJECTED decision because of the commit-then-refuse
 * mechanism the fiscal gate required (N2-I1): the conflict is detected inside the
 * composite's single `DB::transaction` while the invoice row is held FOR UPDATE, and
 * throwing out of that transaction ROLLS BACK anything appended inside it. So the
 * append cannot happen where the conflict is found.
 *
 * The owner of the fix is `RefundService::cancelInvoice()`, the transaction owner:
 *   1. detect under the step-0 lock and throw this, carrying the rejected decision;
 *      the outer transaction rolls back, which is correct — nothing else on this path
 *      was written;
 *   2. CATCH it around the `DB::transaction(...)` call, open a NEW short transaction
 *      that re-acquires the invoice FOR UPDATE and RE-READS `payload` (the rollback
 *      discarded the first read; re-reading is what stops two concurrent conflicting
 *      replays from losing an append), append the record with `accepted: false`, and
 *      commit;
 *   3. RE-THROW, so the client still gets its typed 422.
 *
 * `DB::afterCommit` cannot be used — it does not fire on rollback.
 *
 * The append is BEST-EFFORT. If the short transaction fails (deadlock, lock timeout,
 * anything), the failure is logged and this exception is re-thrown ANYWAY. Letting the
 * plumbing exception replace the re-throw would turn a typed
 * `RETURN_DECISION_ALREADY_RECORDED` 422 into a 500 and leave the client unable to
 * tell "your decision conflicts" from "the server broke". The audit record is the
 * nice-to-have; the correct refusal is the contract.
 */
final class ReturnDecisionConflictException extends DomainException
{
    public const CODE = 'RETURN_DECISION_ALREADY_RECORDED';

    /**
     * @param  array<string, mixed>  $existingDecision  The accepted decision already on record.
     */
    public function __construct(
        public readonly string $invoiceId,
        public readonly string $invoiceNumber,
        public readonly ReturnDecisionData $rejectedDecision,
        public readonly array $existingDecision,
    ) {
        parent::__construct(
            "A goods decision has already been recorded for the cancellation of invoice {$invoiceNumber} "
            .'and cannot be changed. Open the linked return note to see what was recorded.'
        );
    }

    public function existingReturnNoteId(): ?string
    {
        $id = $this->existingDecision['return_note_id'] ?? null;

        return is_string($id) ? $id : null;
    }
}
