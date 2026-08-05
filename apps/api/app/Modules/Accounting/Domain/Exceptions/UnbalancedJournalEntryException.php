<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Exceptions;

/**
 * Thrown when a journal entry would be persisted with Sigma(debits) != Sigma(credits).
 *
 * The document-sourced GL paths (`AccountingService::createInvoiceGLEntries()` and
 * `createCreditNoteGLEntries()`) create their entry as `Posted` and seal it into
 * the general-ledger hash chain in one transaction, so an unbalanced entry is
 * immutable the instant it is written — `GeneralLedgerHashService::verifyChain()`
 * checks linkage and hash recomputation but never the balance invariant, so such
 * an entry passes compliance verification while corrupting the trial balance.
 *
 * The auto-posting paths therefore fail CLOSED on imbalance: the exception aborts
 * the surrounding transaction so nothing is persisted and no chain sequence is
 * consumed. The manual route already refuses the same shape at
 * `JournalEntryController::store()` with `UNBALANCED_ENTRY`.
 *
 * W-6 D1a — docs/superpowers/tickets/2026-08-05-w6-finance-gl-defects.md
 */
final class UnbalancedJournalEntryException extends \RuntimeException
{
    /**
     * @param  numeric-string  $totalDebits
     * @param  numeric-string  $totalCredits
     */
    public static function forSourceDocument(
        string $entryType,
        string $documentNumber,
        string $totalDebits,
        string $totalCredits,
    ): self {
        return new self(sprintf(
            'Refusing to post %s GL entry for document %s: debits %s do not equal credits %s. '
            .'An unbalanced entry would be sealed into the immutable general-ledger hash chain.',
            $entryType,
            $documentNumber,
            $totalDebits,
            $totalCredits,
        ));
    }
}
