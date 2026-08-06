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
 * catch it. Such a document needs the accountant's forward correcting entry first.
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
            'Document %s cannot be cancelled: its journal entry %s is out of balance '
            .'(debits %s, credits %s), so reversing it would seal a second unbalanced '
            .'entry into the chain. Post a correcting entry first.',
            $documentNumber,
            $entryNumber,
            $totalDebits,
            $totalCredits,
        ));
    }
}
