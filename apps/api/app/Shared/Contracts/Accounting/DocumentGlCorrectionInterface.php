<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Accounting;

use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Accounting\Domain\Exceptions\UnpostableCorrectingEntryException;
use App\Modules\Document\Domain\Document;

/**
 * Ask Accounting to post a correcting-entry DOCUMENT to the general ledger
 * (R2-F4).
 *
 * Owner ruling c4 (`docs/superpowers/tickets/2026-08-07-round2-rulings-record.md`):
 * "corrections are DOCUMENTS, always. A correction requires creating a
 * correcting document LINKED to the original document it refers to
 * (`source_document_id`). No free-floating manual JEs as the correction
 * mechanism." This contract is how the Document module hands such a document to
 * Accounting without ever touching `AccountingService`, `Account`,
 * `JournalEntry` or the chart of accounts itself (rule 6).
 *
 * THE INVARIANT — what distinguishes this from a manual journal entry, and what
 * makes it worth being a document: a correcting entry may post only if, after
 * its legs are added, the TARGET document's whole ledger footprint balances
 * (its own posted entry plus every correction already applied to it). A
 * self-balancing correction on a broken target is refused, because it repairs
 * nothing while adding another entry to an immutable chain.
 *
 * That is also precisely what re-opens the dead end
 * `docs/superpowers/tickets/2026-08-06-l2-correcting-entry-escape-hatch.md`
 * describes: `reverseDocumentGl()` counts a document's correcting entries in the
 * same aggregate, so once a correction has rebalanced the target, the
 * cancellation it was refusing becomes possible again.
 *
 * @see AccountingService::postCorrectingEntryGl()
 */
interface DocumentGlCorrectionInterface
{
    /**
     * The NON-writing verdict, for a pre-flight or a read model.
     *
     * Same relationship to {@see self::postCorrectingEntryGl()} as
     * `DocumentGlPreflightInterface::assertDocumentGlIsPostable()` has to the
     * posting paths: it answers "would this post?" without writing anything, so
     * a caller can refuse cleanly before touching the document's status.
     *
     * @throws UnpostableCorrectingEntryException
     */
    public function assertCorrectingEntryIsPostable(Document $correctingEntry): void;

    /**
     * Post the correcting entry, sealed into the journal hash chain like every
     * other entry, dated `now()` — never back-dated onto the target's period.
     *
     * @return string The correcting journal entry's id.
     *
     * @throws UnpostableCorrectingEntryException
     */
    public function postCorrectingEntryGl(Document $correctingEntry): string;
}
