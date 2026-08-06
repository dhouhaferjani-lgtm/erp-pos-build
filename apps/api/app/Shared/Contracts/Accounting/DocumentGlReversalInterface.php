<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Accounting;

use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Accounting\Domain\Exceptions\UnreversibleDocumentGlException;
use App\Modules\Document\Domain\Document;

/**
 * Ask Accounting to reverse a document's GL when the document is withdrawn.
 *
 * W-7 F-6 escalation (c): `DocumentPostingService::cancel()` made no GL call at
 * all, so cancelling a POSTED invoice left its AR debit, its revenue credits and
 * its VAT credit standing in the ledger forever.
 *
 * Called from INSIDE the cancel transaction, deliberately — the L1 lane's gate
 * finding C-1 showed what happens when a GL verdict lands after the document is
 * sealed: the refusal cannot be re-driven and the document is stranded. Here the
 * reversal and the void are one atomic act: if the reversal cannot be written,
 * nothing is cancelled.
 *
 * Module boundaries stay intact: Document depends on this Shared contract, never
 * on `AccountingService`.
 *
 * @see AccountingService::reverseDocumentGl()
 */
interface DocumentGlReversalInterface
{
    /**
     * @return string|null The reversing journal entry's id, or NULL when there was
     *                     nothing to reverse (a document type that posts no GL, a
     *                     document that never reached the ledger, or one already
     *                     reversed).
     *
     * @throws UnreversibleDocumentGlException When the posted legs cannot be
     *                                         mirrored into a balanced entry.
     */
    public function reverseDocumentGl(Document $document): ?string;
}
