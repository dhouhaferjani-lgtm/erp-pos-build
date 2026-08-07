<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Taxation\Domain\Exceptions\DocumentPeriodLockedException;
use App\Modules\Taxation\Domain\Repositories\VatPeriodRepositoryInterface;
use App\Shared\Contracts\Taxation\DocumentPeriodLockInterface;

/**
 * Refuses a cancellation whose accounting period is no longer OPEN.
 *
 * R2-F1, implementing the second condition of the L2 lane's GL gate ruling 6a
 * (`docs/superpowers/tickets/2026-08-06-l2-gl-vat-declaration-desync.md`).
 *
 * PERIOD RESOLUTION — the document's `document_date`, NOT `now()` and NOT
 * `cancelled_at`. That is the date the ledger and the declaration both key on for
 * this document, verified at the stamp sites:
 *   - AR: `AccountingService::createInvoiceGLEntries()` (declared `:425`, stamps
 *     at `:441`) and `createCreditNoteGLEntries()` (declared `:577`, stamps at
 *     `:593`); likewise `GeneralLedgerService::createFromInvoice()` (`:130`,
 *     stamps at `:146`) and `createFromCreditNote()` (`:214`, stamps at `:230`).
 *     All stamp `entry_date = $document->document_date`.
 *   - AP: `GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry()`
 *     (declared `:1921`) stamps `entry_date = $supplierInvoice->document_date`
 *     at `:1996`. (Do NOT cite `createSupplierInvoiceJournalEntry()` `:684` — it
 *     is dead code and takes a caller-supplied date.)
 *   - Declaration: `EloquentVatDataRepository::aggregateByRateAndDirection()`
 *     filters on `documents.document_date` (`:41`).
 * So `document_date` IS the accounting date, and the period covering it is the
 * one the withdrawal would disturb.
 *
 * ABSENT PERIOD = PERMITTED. No `vat_periods` row covering the date means nothing
 * has ever been closed or filed for that span, so there is nothing to protect.
 * Fail-closed on absence would make every document uncancellable on every tenant
 * that has not begun declaring VAT — which is all of them at launch — and would
 * be a far worse regression than the gap being closed here. This matches the
 * house precedent exactly: `GeneralLedgerService::postEntryNow()` refuses to post
 * into a CLOSED `fiscal_periods` row but deliberately allows an absent one
 * ("absence of configuration is not the same as a deliberately closed period",
 * `FiscalPeriodResolverService::isDateInClosedPeriod()`).
 *
 * RELATIONSHIP TO THE FISCAL-PERIOD GUARD — orthogonal, and NOT a safety net.
 * `fiscal_periods` (Accounting) and `vat_periods` (Taxation) are separate tables
 * with separate lifecycles. The fiscal-period check lives in
 * `GeneralLedgerService::postEntryNow()` (`:3265-3267`) and gates the entry's own
 * `entry_date`. A cancellation reversal is dated `now()`, so one might expect
 * that guard to cover the reversal — IT DOES NOT:
 * `AccountingService::reverseDocumentGl()` writes the reversal with
 * `JournalEntry::create()` directly (`:933-947`) and never routes through
 * `postEntryNow()`, so a REVCAN entry can seal into a CLOSED fiscal period today.
 * That is a PRE-EXISTING hole, out of R2-F1's scope and ticketed for F2:
 * `docs/superpowers/tickets/2026-08-07-cancel-reversal-bypasses-closed-fiscal-period.md`.
 * This guard protects only the period the ORIGINAL document sits in, which
 * nothing checked before R2-F1.
 */
final class VatPeriodCancellationGuard implements DocumentPeriodLockInterface
{
    /**
     * Sales-side types that carry a declaration and a GL entry. Settled by GL
     * gate ruling 6a — NOT part of the c2 ruling.
     *
     * @var list<DocumentType>
     */
    private const SALES_DECLARATION_BEARING_TYPES = [
        DocumentType::Invoice,
        DocumentType::CreditNote,
    ];

    /**
     * Purchase-side types that carry a GL entry (AP / expense / input VAT) — the
     * R-c c2 population.
     *
     * PurchaseOrder and PurchaseQuoteRequest are deliberately ABSENT: they post
     * no journal entry and write no `document_tax_details` row, so locking them
     * would refuse a cancellation with zero fiscal justification (taxation gate
     * I-4). Expense IS present — it is the only purchase-side type the VAT
     * declaration actually reads (`EloquentVatDataRepository:42` restricts to
     * invoice/credit_note/expense).
     *
     * @var list<DocumentType>
     */
    private const PURCHASE_DOCUMENT_TYPES = [
        DocumentType::SupplierInvoice,
        DocumentType::SupplierCreditNote,
        DocumentType::Expense,
    ];

    public function __construct(
        private readonly VatPeriodRepositoryInterface $periodRepository,
    ) {}

    public function assertCancellationPeriodIsOpen(Document $document): void
    {
        if (! $this->refusalAppliesTo($document->type)) {
            return;
        }

        $lockedPeriod = $this->periodRepository->findLockedPeriodCoveringDate(
            $document->company_id,
            $document->document_date,
        );

        if ($lockedPeriod === null) {
            return;
        }

        throw DocumentPeriodLockedException::forDocument(
            $document->document_number,
            $lockedPeriod->label,
            $lockedPeriod->status,
        );
    }

    /**
     * ===== R-c c2 SEAM — REVERSIBLE, DO NOT INLINE =====
     *
     * SCOPE OF THE "DEFAULT FOR ALL DOCUMENT TYPES" INSTRUCTION (taxation gate
     * I-4, ruling adopted): the round-2 plan's intent was DECLARATION AND LEDGER
     * PROTECTION, not literal totality. Applying the lock to types that post no
     * journal entry and write no `document_tax_details` row (Quote, SalesOrder,
     * DeliveryNote, ReturnNote, PurchaseOrder, PurchaseQuoteRequest, Income)
     * protects nothing, and would make such a document PERMANENTLY uncancellable
     * the moment a lane wires its cancellation through
     * `DocumentPostingService::cancel()` — a fiscal refusal with no fiscal
     * justification. The population is therefore narrowed to the types that
     * actually reach the ledger or the declaration.
     *
     * Ruling R-c c2 (`docs/superpowers/tickets/2026-08-07-round2-rulings-record.md`)
     * is still OPEN: for a PURCHASE document whose period is CLOSED/FILED, the
     * expert may rule either "refuse the cancel" (the mirror of the AR refusal) or
     * "reverse in the current period" (let the cancel through; the AP mirror F2
     * will add is dated `now()` like every other reversal).
     *
     * F1 ships REFUSE as the default, explicitly flagged reversible. The
     * justification is AP and trial-balance integrity, NOT output-VAT symmetry:
     * a supplier invoice's input VAT is not in the declaration at all today
     * (`EloquentVatDataRepository:42` reads invoice/credit_note/expense only —
     * `supplier_invoice` appears nowhere in Taxation). What a supplier invoice
     * DOES carry is a GL entry dated `document_date`
     * (`createSupplierInvoiceGrIrClearingEntry`), so withdrawing it inside a
     * period whose books are closed is a ledger-integrity problem regardless of
     * VAT — and `Expense`, which IS declared, sits in the same branch. Refusing
     * is also the forward-compatible default: if F2/F3 bring supplier invoices
     * into the declaration, no behaviour has to change.
     *
     * TO FLIP (if c2 returns "reverse-in-current-period"): change the
     * `return true;` inside the purchase branch below to `return false;` and flip
     * the two purchase-document expectations in
     * `tests/Feature/Document/CancelRefusedOnNonOpenVatPeriodTest.php`. No other
     * call site, contract, error code or migration moves.
     *
     * !! DO NOT FLIP BEFORE F2's AP MIRROR IS MERGED (GL gate I-4) !! Flipping
     * TODAY yields a cancel with NO GL REVERSAL AT ALL:
     * `AccountingService::reverseDocumentGl()` returns `null` for any type other
     * than Invoice/CreditNote (`:833`), and `SupplierInvoice` takes
     * `DocumentPostingService::cancel()`'s non-fiscal branch — so the GR-IR / AP /
     * expense legs would simply stand forever. The refusal is currently the ONLY
     * thing preventing that. Flip only once F2 has wired the AP reversal.
     */
    private function refusalAppliesTo(DocumentType $type): bool
    {
        if (in_array($type, self::PURCHASE_DOCUMENT_TYPES, true)) {
            // R-c c2 DEFAULT (pending ruling) — refuse. See the flip warning above.
            return true;
        }

        // Sales side: settled by GL gate ruling 6a, NOT part of the c2 ruling.
        // Every other type posts nothing and is deliberately NOT locked.
        return in_array($type, self::SALES_DECLARATION_BEARING_TYPES, true);
    }
}
