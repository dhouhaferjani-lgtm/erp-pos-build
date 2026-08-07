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
 * this document: `AccountingService::createInvoiceGLEntries()` and
 * `GeneralLedgerService::createSupplierInvoiceEntry()` both stamp
 * `entry_date = $document->document_date`, and
 * `EloquentVatDataRepository::aggregateByRateAndDirection()` filters the
 * declaration on `documents.document_date`. So `document_date` IS the accounting
 * date, and the period covering it is exactly the declaration the withdrawal
 * would disturb.
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
 * NOT a duplicate of that fiscal-period guard, which is orthogonal: it gates the
 * entry's OWN `entry_date`, and a cancellation reversal is dated `now()`. So it
 * protects the CURRENT period while this guard protects the period the ORIGINAL
 * document sits in — the one whose declaration the withdrawal would disturb, and
 * the one nothing checked before R2-F1.
 */
final class VatPeriodCancellationGuard implements DocumentPeriodLockInterface
{
    /**
     * Purchase-side document types — the R-c c2 population.
     *
     * @var list<DocumentType>
     */
    private const PURCHASE_DOCUMENT_TYPES = [
        DocumentType::PurchaseOrder,
        DocumentType::PurchaseQuoteRequest,
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
     * Ruling R-c c2 (`docs/superpowers/tickets/2026-08-07-round2-rulings-record.md`)
     * is still OPEN: for a PURCHASE document whose period is CLOSED/FILED, the
     * expert may rule either "refuse the cancel" (the mirror of the AR refusal) or
     * "reverse in the current period" (let the cancel through; the AP/input-VAT
     * mirror F2 will add is dated `now()` like every other reversal).
     *
     * Per the round-2 plan, F1 ships the DEFAULT — refusal EVERYWHERE, sales and
     * purchase alike — because a supplier invoice's deductible input VAT sits in
     * the very same filed declaration as the output VAT, so withdrawing it after
     * filing has the identical retroactive problem. The default is explicitly
     * flagged reversible.
     *
     * If c2 comes back as "reverse-in-current-period", the ONLY change is the
     * `return true;` inside the purchase branch below, which becomes
     * `return false;` — plus flipping the two purchase-document expectations in
     * `tests/Feature/Document/CancelRefusedOnNonOpenVatPeriodTest.php`. No other
     * call site, contract, error code or migration moves.
     */
    private function refusalAppliesTo(DocumentType $type): bool
    {
        if (in_array($type, self::PURCHASE_DOCUMENT_TYPES, true)) {
            // R-c c2 DEFAULT (pending ruling) — refuse, exactly like the sales
            // side. Flip this single line to `return false;` to adopt the
            // "reverse-in-current-period" branch.
            return true;
        }

        // Sales side: settled by GL gate ruling 6a, NOT part of the c2 ruling.
        return true;
    }
}
