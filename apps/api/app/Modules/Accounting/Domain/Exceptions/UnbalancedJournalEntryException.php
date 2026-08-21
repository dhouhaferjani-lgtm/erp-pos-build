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
 * `CreditNoteController::post()` depends on it concretely, catching this at `:406`
 * to render a structured `500 CONFIGURATION_ERROR`.
 *
 * enforcement-P3 M1 briefly re-parented this to `\InvalidArgumentException` so the
 * GL chokepoint could share the type, and that was WRONG (round 1, findings 3/4):
 * it turned that structured 500 into a generic `500 INTERNAL_ERROR`, and made the
 * type a `\LogicException` exposed to the `catch (\InvalidArgumentException)` blocks
 * that render 400/422 — precisely what "never a 422" exists to prevent. Reverted.
 *
 * **This type covers the POST-SEAL, document-sourced refusal only.** The GL posting
 * chokepoint raises its own sibling,
 * {@see UnbalancedJournalEntryPostException} — deliberately a separate class,
 * because the two refusals need opposite catch semantics and one shared parent
 * cannot serve both without breaking a live contract in one direction or the other.
 * See `docs/handoff/reviews/enforcement-p3/M1-census.md` §5.
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
