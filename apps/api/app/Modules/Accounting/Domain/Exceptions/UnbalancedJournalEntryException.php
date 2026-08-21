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
 * **The `\RuntimeException` parent is LOAD-BEARING — do not re-parent it.**
 * An unbalanced entry must surface as an unmapped 500 + alert and must NEVER be
 * reportable as a client validation error; see the contract note at
 * `AccountingService::assertLegsBalance()` and `UnpostableDocumentGlException`.
 * enforcement-P3 M1 briefly re-parented this to `\InvalidArgumentException` (so the
 * GL chokepoint, which historically raised a BARE `\InvalidArgumentException`, could
 * adopt the house type without breaking existing catchers) and that was WRONG on
 * two counts, both caught at review (round 1, findings 3 and 4):
 *   1. `\InvalidArgumentException` is a `\LogicException`, so the ~30
 *      `catch (\InvalidArgumentException)` blocks in `app/` that render 400/422
 *      `VALIDATION_ERROR` responses became latent downgrade paths for a fiscal
 *      refusal — exactly what the "never a 422" contract exists to prevent.
 *   2. It silently changed a live API contract: `CreditNoteController::post()`'s
 *      `catch (\RuntimeException)` stopped matching, turning a
 *      `500 CONFIGURATION_ERROR` (with detail) into a generic `500 INTERNAL_ERROR`.
 * The parent was restored. The chokepoint still raises this type — see
 * `GeneralLedgerService::sealAndPersistEntry` — which is the actual goal of
 * deliverable D and needs no hierarchy change at all.
 * See `docs/handoff/reviews/enforcement-p3/M1-census.md` §5.
 */
final class UnbalancedJournalEntryException extends \RuntimeException
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
