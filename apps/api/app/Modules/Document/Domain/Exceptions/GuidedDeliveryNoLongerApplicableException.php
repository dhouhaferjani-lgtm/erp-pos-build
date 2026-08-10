<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

/**
 * The invoice stopped being eligible for the guided create-delivery-and-post
 * composite BETWEEN the pre-transaction checks and the row lock.
 *
 * ── WHY THIS IS A DISTINCT TYPE (fix round 1, inventory P1-2) ──
 * The composite's eligibility checks ran outside its transaction, so two
 * concurrent submits could both pass them. The loser then applied a STALE
 * payload — clobbering `source_delivery_note_ids` and the T25e audit stamp on a
 * document the winner had already SEALED — confirmed a FRESH draft delivery note
 * (so the only-draft guard could not fire), issued the same goods from stock a
 * SECOND time, and got a 200 back because `post()` idempotently early-returns.
 *
 * The fix takes the row lock as the first statement inside the transaction and
 * re-evaluates on the locked row. This exception is what that re-evaluation
 * raises. It is typed rather than a bare `\DomainException` so the controller can
 * answer with the SAME machine code the pre-transaction check uses
 * (`DELIVERY_CREATION_NOT_APPLICABLE`) — the client sees one refusal, whichever
 * side of the lock detected it — instead of an opaque `OPERATION_FAILED`.
 */
final class GuidedDeliveryNoLongerApplicableException extends \DomainException
{
    public static function becauseTheInvoiceMoved(): self
    {
        return new self(
            'This invoice was posted or delivered by another request while this one was in flight.'
        );
    }
}
