<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Exceptions;

use DomainException;

/**
 * Thrown when a withdrawn document's posted GL cannot be mirrored into a BALANCED
 * reversing entry.
 *
 * A mirror of a balanced entry is balanced by construction, so this can only fire
 * on an original that was already out of balance — the ledger carries exactly one
 * such entry (the campaign's own `MTP-DOC-06` probe, W-6 D1b, 19.000 stranded on
 * `4457`), sealed before the L1 lane closed the path that minted it.
 *
 * Refusing is the fail-closed choice: mirroring an unbalanced entry would seal a
 * SECOND unbalanced entry into the chain, which is exactly what W-6 D1a's guard
 * exists to prevent, and `GeneralLedgerHashService::verifyChain()` would never
 * catch it.
 *
 * HISTORY, and why the advice in the message changed twice. The message
 * originally said "Post a correcting entry first" — impossible advice at the
 * time: `reverseDocumentGl()`'s balance count matched only
 * `source_type = 'Document' AND source_id = $document->id`, and the only
 * manual-entry writer, `JournalEntryController::store()`, hard-codes
 * `source_type = 'manual'` (`:100-102`), so a correcting entry created through
 * the only available endpoint could NEVER enter that predicate. The GL gate
 * therefore replaced it with "contact support", which was honest but a dead end.
 *
 * R2-F4 (owner ruling c4) built the missing remedy: a correcting-entry DOCUMENT
 * linked to the original via `source_document_id`, whose legs
 * `reverseDocumentGl()` now counts through
 * `AccountingService::documentLedgerFootprint()`. The advice is self-service
 * again — and this time it is true, pinned by
 * `CorrectingEntryUnblocksCancellationTest`.
 * docs/superpowers/tickets/2026-08-06-l2-correcting-entry-escape-hatch.md
 * docs/superpowers/tickets/2026-08-07-round2-rulings-record.md (R-c c4)
 *
 * Extending `DomainException` makes it a 422 `BUSINESS_ERROR` via
 * `bootstrap/app.php`, and because the reversal runs INSIDE the cancel
 * transaction the refusal rolls the cancel back with it — nothing is stranded
 * (contrast the L1 gate's finding C-1).
 *
 * docs/superpowers/tickets/2026-08-03-w7-cross-cutting-findings.md (F-6)
 */
final class UnreversibleDocumentGlException extends DomainException
{
    /**
     * @param  numeric-string  $totalDebits
     * @param  numeric-string  $totalCredits
     */
    public static function forUnbalancedOriginal(
        string $documentNumber,
        string $entryNumber,
        string $totalDebits,
        string $totalCredits,
    ): self {
        return new self(sprintf(
            'Document %s cannot be cancelled: its ledger entries (starting with %s) are out of '
            .'balance (debits %s, credits %s), so reversing them would seal a second unbalanced '
            .'entry into the chain. Create a CORRECTING ENTRY against this document for the '
            .'difference of %s, post it, and then retry the cancellation.',
            $documentNumber,
            $entryNumber,
            $totalDebits,
            $totalCredits,
            bcsub(
                bccomp($totalDebits, $totalCredits, 6) > 0 ? $totalDebits : $totalCredits,
                bccomp($totalDebits, $totalCredits, 6) > 0 ? $totalCredits : $totalDebits,
                3,
            ),
        ));
    }
}
