<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

use DomainException;

/**
 * A goods-bearing return decision was made against an invoice with nothing left to
 * return.
 *
 * Plan CF CF-D7 / fiscal gate N-I3. This is the ENFORCEMENT half of CF-D6, and the
 * fiscal gate accepted CF-D6 only because it exists: the modal disables options 1 and
 * 2 when no confirmed delivery note backs the invoice, but **a disabled radio is
 * affordance, not a safety property**. A direct API call or a stale client bypasses
 * the UI entirely, so the server refuses independently — and refuses in a TYPED way,
 * rather than falling into a generic `min:1` validation accident that tells the client
 * nothing about why.
 *
 * Raised IN THE SERVICE, before the return note is built. The composite path does not
 * pass through `CreateDocumentRequest` at all (T2 moved the create body into
 * `ReturnNoteService::createDraft()`), so there is no validation layer for this
 * refusal to precede — `lines` `required|min:1` and `lines.*.quantity` `gt:0` still
 * govern the standalone `POST /return-notes` route, but they are not the composite's
 * guard.
 */
final class ReturnNothingDeliveredException extends DomainException
{
    public const CODE = 'RETURN_NOTHING_DELIVERED';

    public function __construct(
        public readonly string $invoiceId,
        public readonly string $invoiceNumber,
    ) {
        parent::__construct(
            "Nothing has been delivered against invoice {$invoiceNumber} that is still available to return, "
            .'so no goods can come back. Record the cancellation without a return instead.'
        );
    }
}
