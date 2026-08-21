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
 *
 * **Parent class (enforcement-P3 M1, deliverable D).** This extends
 * `\InvalidArgumentException` because the GL posting chokepoint
 * (`GeneralLedgerService::sealAndPersistEntry`) historically raised its balance
 * refusal as a BARE `\InvalidArgumentException`. Making this type a REFINEMENT of
 * that class lets the chokepoint raise the house type without breaking any
 * pre-existing `catch (\InvalidArgumentException)` around a GL post, while giving
 * callers a type they can single out and re-throw instead of swallowing.
 * See `docs/handoff/reviews/enforcement-p3/M1-census.md` §5.
 */
final class UnbalancedJournalEntryException extends \InvalidArgumentException
{
    /**
     * The GL posting chokepoint's refusal.
     *
     * The message is BYTE-IDENTICAL to the string the chokepoint raised before
     * the type was normalized — existing assertions on it stay valid.
     *
     * @param  numeric-string  $totalDebit
     * @param  numeric-string  $totalCredit
     */
    public static function forChokepoint(string $totalDebit, string $totalCredit): self
    {
        return new self(sprintf(
            'Cannot post unbalanced journal entry: total debit %s does not equal total credit %s.',
            $totalDebit,
            $totalCredit,
        ));
    }

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
