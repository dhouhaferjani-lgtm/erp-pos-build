<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Accounting;

use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Accounting\Domain\Exceptions\UnpostableDocumentGlException;
use App\Modules\Document\Domain\Document;

/**
 * Ask Accounting, BEFORE a document is sealed, whether its GL entry can balance.
 *
 * W-6 D1a / gate finding C-1. The GL entry for an invoice or credit note is
 * written by `InvoicePostedListener`, which `DocumentPostingService` dispatches
 * from `DB::afterCommit(...)` — deliberately, so a listener failure cannot roll
 * back the fiscal chain. That ordering means a refusal raised inside the listener
 * arrives when the document is ALREADY `Posted`, hash-chained and immutable, and
 * `DocumentPostingService::post()` returns early on an already-posted document so
 * the event never re-fires: the document is stranded with no GL at all.
 *
 * This interface moves the verdict — not the posting — ahead of the seal, inside
 * the posting transaction. The listener keeps its own assertion as defence in
 * depth. Module boundaries stay intact: Document depends on this Shared contract,
 * never on `AccountingService`.
 *
 * @see AccountingService::assertDocumentGlIsPostable()
 */
interface DocumentGlPreflightInterface
{
    /**
     * @throws UnpostableDocumentGlException When the document's GL entry could not
     *                                       be balanced. A no-op for document types
     *                                       that post no GL.
     */
    public function assertDocumentGlIsPostable(Document $document): void;
}
