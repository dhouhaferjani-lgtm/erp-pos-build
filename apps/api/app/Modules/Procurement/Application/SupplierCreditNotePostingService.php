<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\DocumentStatusService;
use App\Modules\Inventory\Application\DTOs\SupplierGoodsReturnLineData;
use App\Modules\Inventory\Application\Services\SupplierGoodsReturnNoteService;
use App\Modules\Inventory\Domain\Enums\SupplierGoodsReturnLineKind;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
use App\Modules\Inventory\Domain\Services\ProductCostLock;
use App\Modules\Procurement\Domain\Enums\SupplierCreditNoteReason;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

/**
 * D1 — Supplier Credit Note posting orchestrator (mirrors C3 in reverse).
 *
 * Posts a Draft supplier credit note in ONE serialized DB transaction. The credit
 * note links to a posted supplier invoice via source_document_id, and each
 * credit-note line links to a PO line (source_line_id) that the invoice references.
 *
 *   1. Resolve the reason; resolve + LOCK the linked supplier invoice
 *      (source_document_id) — the anchor for the cumulative read — and LOCK the
 *      linked PO lines (FOR UPDATE).
 *   2. Idempotency no-op: a reversing entry already exists for this credit note.
 *   3. Linkage guard (FIX 1, HARD): EVERY credit-note line must resolve to a PO line
 *      (parent = PurchaseOrder, same company) that the linked invoice references.
 *      Any unlinked/unresolvable/foreign line REJECTS the whole post (no partial GL).
 *   4. Cumulative over-credit guard (FIX 2, HARD): Σ HT of all prior POSTED credit
 *      notes for this invoice + this credit's HT must be ≤ the invoice's actual
 *      posted HT (its subtotal). Cumulative-correct across repeated credit notes and
 *      bounded against the real invoice (handles price variance). The invoice row is
 *      locked, so concurrent credit-note posts serialize on it.
 *   5. GoodsReturn per-line quantity guard (HARD) + decrement quantity_invoiced
 *      (reopens the PO line). PriceAdjustment leaves quantity_invoiced untouched.
 *   5b. Physical units: mint a SUPPLIER GOODS-RETURN NOTE and confirm it, so the
 *      stock exit hangs off its own document (DPA lane V8 — see below).
 *   6. Post the GL reversing entry via the in-transaction system path (atomic).
 *   7. Draft → Posted.
 *
 * balance_due is NOT used for the over-credit bound: it is maintained by a
 * pgsql-only trigger scoped to customer invoices (type='invoice'), and C3 supplier
 * invoice posting never sets it — so it is unreliable for supplier invoices. The
 * cumulative prior-credit-HT-vs-invoice-subtotal mechanism is used instead.
 *
 * The accounting model (legs / plug) lives in
 * GeneralLedgerService::createSupplierCreditNoteEntry.
 *
 * DEFERRED (Phase 2): the "returned goods no longer in stock → expense /
 * purchase price-variance" variant. Phase 1 always credits Inventory.
 *
 * ---------------------------------------------------------------------------
 * DPA lane V8 — the stock exit is a DOCUMENT, not a side effect
 * ---------------------------------------------------------------------------
 *
 * This service used to raw-write stock inline for bonus goods-return lines
 * (`issueBonusReturnStock()`: a `stock_levels` decrement plus an `Issue`
 * `stock_movements` row referencing THIS credit note, with
 * `avg_cost_before == avg_cost_after` asserting the WAC was untouched — which is
 * wrong for zero-cost bonus goods, see SupplierGoodsReturnNoteService). Ordinary
 * goods-return lines, meanwhile, moved no units at all: one reason, two lane
 * behaviours.
 *
 * Now BOTH kinds of line go through one real document, minted and confirmed via
 * `SupplierGoodsReturnNoteService` inside this same transaction. This service no
 * longer touches `stock_levels`, `stock_movements` or `products.cost_price`.
 *
 * AUTO-CONFIRM, and why (decided from the code, not from a preference):
 * the pre-V8 behaviour decremented stock UNCONDITIONALLY at post time, in the
 * same transaction as the GL entry that credits Inventory. The existing contract
 * therefore already asserts the goods are gone when the credit note posts, and
 * leaving the note Draft would put the units and the GL out of step in a way the
 * old code never did. So the note is created AND confirmed here. Letting a user
 * choose (goods not shipped yet → leave it Draft) is the DEFERRED guided-AP-modal
 * lane; this path is the "behind the scenes via the same domain transitions"
 * contract, not a bypass — `createDraft()` then `confirm()` are the real
 * transitions, and the note is a first-class row either way.
 *
 * Lines with no physical product (service lines: `product_id === null`, or a
 * non-physical product) mint NO note line — nothing left the warehouse. A
 * GoodsReturn credit note made up entirely of such lines mints no note at all,
 * which is why the pure-GL tests in SupplierCreditNoteGlTest see none.
 *
 * GL/SUB-LEDGER RECONCILIATION (stock-GL gate P1-1, round 2) — no longer owed.
 * An earlier revision of this lane left the GL legs byte-identical and deferred
 * the value question to c1-bis. That was wrong to ship: the bonus return
 * preserves inventory VALUE via the WAC un-dilution, so expensing the full
 * `q x WAC` made GL disagree with the sub-ledger by `wac_undilution_applied`,
 * permanently and invisibly. `createSupplierCreditNoteEntryWithBonusReturn` now
 * receives BOTH halves — the gross exit and the un-dilution — and books a
 * compensating `Dr Inventory / Cr PurchaseExpenses` pair. c1-bis is ANSWERED for
 * this seam; what remains open is the F-9 cost-basis lane (see the residuals
 * ticket docs/superpowers/tickets/2026-08-22-dpa-v8-residuals.md).
 *
 * LOCK ORDER: the sorted product advisory (`ProductCostLock::acquire`) is taken
 * FIRST, before the document_lines / goods_receipt_lines row locks, matching
 * GoodsReceiptService. See the comment at step 1 in `post()`.
 */
final class SupplierCreditNotePostingService
{
    public function __construct(
        private readonly GeneralLedgerService $generalLedgerService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly SupplierGoodsReturnNoteService $goodsReturnNoteService,
        private readonly ProductCostLock $costLock,
        private readonly DocumentStatusService $documentStatus,
    ) {}

    /**
     * Post a Draft supplier credit note's reversing entry under lock + idempotency.
     *
     * @throws \DomainException on a missing reason, broken invoice linkage, unlinked
     *                          lines, over-credit (per-line or cumulative), or
     *                          internal inconsistency (fails loudly, rolls back).
     */
    public function post(Document $creditNote, ?string $actorId = null): void
    {
        DB::transaction(function () use ($creditNote, $actorId): void {
            // HIGH (concurrency fix): reload the credit-note row under FOR UPDATE so every
            // guard in this transaction (type, status, reason) runs against the current,
            // locked DB state rather than the caller-provided (potentially stale) snapshot.
            // Two concurrent posts of the same Draft credit note serialise here: the second
            // blocks until the first commits, then re-reads Posted + JE-exists and takes the
            // idempotent no-op path below. The caller-provided model is discarded; $creditNote
            // is reassigned to the locked, freshly-loaded instance for the rest of the method.
            $creditNote = Document::query()
                ->whereKey($creditNote->id)
                ->lockForUpdate()
                ->firstOrFail();
            $creditNote->load('lines');

            // 0a. Input document guard: this service only posts supplier credit notes.
            if ($creditNote->type !== DocumentType::SupplierCreditNote) {
                throw new \DomainException(sprintf(
                    'Document [%s] cannot be posted as a supplier credit note: its type is [%s].',
                    $creditNote->id,
                    $creditNote->type->value,
                ));
            }

            $reason = $creditNote->supplier_credit_note_reason;
            if (! $reason instanceof SupplierCreditNoteReason) {
                throw new \DomainException(sprintf(
                    'Supplier credit note [%s] cannot be posted without an explicit reason '
                    .'(SupplierCreditNoteReason::PriceAdjustment | GoodsReturn).',
                    $creditNote->id,
                ));
            }

            // 0b. Status guard — preserve the idempotent retry. A Posted credit note is a
            //     no-op IFF its reversing entry already exists; a Posted-without-entry or any
            //     non-Draft/non-Posted state (e.g. Cancelled) is rejected. Only Draft proceeds.
            if ($creditNote->status === DocumentStatus::Posted) {
                $entryExists = JournalEntry::query()
                    ->where('source_type', 'supplier_credit_note')
                    ->where('source_id', $creditNote->id)
                    ->exists();
                if ($entryExists) {
                    return;
                }
                throw new \DomainException(sprintf(
                    'Supplier credit note [%s] is marked Posted but has no reversing entry '
                    .'(inconsistent state); refusing to post.',
                    $creditNote->id,
                ));
            }
            if ($creditNote->status !== DocumentStatus::Draft) {
                throw new \DomainException(sprintf(
                    'Supplier credit note [%s] cannot be posted: status is [%s], expected Draft.',
                    $creditNote->id,
                    $creditNote->status->value,
                ));
            }

            // 1. Lock ORDER (stock-GL gate P2-4). The product advisory locks come
            //    FIRST, in one sorted acquire, BEFORE any document_lines /
            //    goods_receipt_lines row lock — matching GoodsReceiptService's
            //    advisory-before-the-seam order.
            //
            //    Before this hoist the order was inverted: this method took the PO
            //    line + receipt line row locks, and only later did
            //    SupplierGoodsReturnNoteService::confirm() reach
            //    recordCostAdjustment() and take the per-product advisory. A
            //    goods-receipt post for an overlapping product takes them the
            //    other way round (advisory first, then its row locks), so two
            //    concurrent transactions could hold what the other needed — a
            //    textbook AB-BA deadlock, and one that only appears under
            //    concurrency, on PG, on products that both lanes touch.
            //
            //    The product ids come from an UNLOCKED read: they are only needed
            //    to choose which advisory keys to take, the advisory itself is what
            //    serialises, and every quantity/cost decision below is made from
            //    the LOCKED re-read inside the closure. Reading them unlocked is
            //    therefore safe and is what lets the advisory come first at all.
            /** @var list<string> $poLineIds */
            $poLineIds = $creditNote->lines
                ->pluck('source_line_id')
                ->reject(static fn ($id): bool => $id === null)
                ->map(static fn (mixed $id): string => (string) $id)
                ->unique()
                ->values()
                ->all();

            /** @var list<string> $productIds */
            $productIds = DocumentLine::query()
                ->whereIn('id', $poLineIds)
                ->pluck('product_id')
                ->filter()
                ->unique()
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();

            $this->costLock->acquire(
                $creditNote->tenant_id,
                $creditNote->company_id,
                $productIds,
                function () use ($creditNote, $reason, $poLineIds, $actorId): void {
                    $this->postLocked($creditNote, $reason, $poLineIds, $actorId);
                },
            );
        });
    }

    /**
     * The body of `post()`, running INSIDE the sorted product-advisory acquire.
     *
     * Extracted for readability and to keep the diff surface small: `acquire()`
     * is the last statement in `post()`, so any statement added after it later
     * would run OUTSIDE the advisory, and a named method makes that boundary
     * obvious where a 120-line inline closure would not.
     *
     * NOT for control-flow reasons. An earlier revision of this docblock claimed
     * a `return` inside an inline closure would "silently fall through" to the
     * rest of the transaction; that is false PHP semantics — a `return` exits the
     * closure exactly as it exits this method, and `acquire()` returning is the
     * end of `post()` either way. Corrected rather than deleted so the wrong
     * reasoning is not re-derived by the next reader (gate round 3, P3).
     *
     * @param  list<string>  $poLineIds
     */
    private function postLocked(
        Document $creditNote,
        SupplierCreditNoteReason $reason,
        array $poLineIds,
        ?string $actorId,
    ): void {
        // 1a. Resolve + lock the linked supplier invoice (the cumulative anchor).
        $supplierInvoice = $this->resolveAndLockLinkedInvoice($creditNote);

        // 1b. Lock the linked PO lines (serializes concurrent posts sharing a PO line).
        /** @var Collection<int, DocumentLine> $lockedPoLines */
        $lockedPoLines = DocumentLine::query()
            ->whereIn('id', $poLineIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        /** @var Collection<int, GoodsReceiptLine> $lockedReceiptLines */
        $lockedReceiptLines = GoodsReceiptLine::query()
            ->postedReceipts()
            ->where('company_id', $creditNote->company_id)
            ->whereIn('po_line_id', $poLineIds)
            ->lockForUpdate()
            ->orderBy('id')
            ->get();
        $receiptLinesByPoLine = $lockedReceiptLines->groupBy('po_line_id');

        // 2. Idempotency no-op: a reversing entry already exists for this credit note.
        $alreadyPosted = JournalEntry::query()
            ->where('source_type', 'supplier_credit_note')
            ->where('source_id', $creditNote->id)
            ->exists();
        if ($alreadyPosted) {
            return;
        }

        // 3. Linkage guard (FIX 1): every line resolves to a PO line of this invoice.
        $this->assertAllLinesLinkedToInvoicePoLines($creditNote, $supplierInvoice, $lockedPoLines);

        $scale = $this->scaleResolver->getScale($creditNote->currency);

        // 4. Cumulative over-credit guard (FIX 2): bound against the invoice's actual HT.
        $this->assertCumulativeHtWithinInvoice($creditNote, $supplierInvoice, $scale);

        /** @var numeric-string $bonusReturnInventoryValue */
        $bonusReturnInventoryValue = '0.000000';
        /** @var numeric-string $bonusReturnUndilutionApplied */
        $bonusReturnUndilutionApplied = '0.000000';

        // 5. GoodsReturn per-line quantity guard + decrement (PriceAdjustment: no-op).
        if ($reason->decrementsQuantityInvoiced()) {
            /** @var list<SupplierGoodsReturnLineData> $returnLines */
            $returnLines = [
                ...$this->guardAndDecrementGoodsReturn($creditNote, $lockedPoLines, $receiptLinesByPoLine),
                ...$this->guardAndDecrementBonusGoodsReturn($creditNote, $lockedPoLines, $receiptLinesByPoLine),
            ];

            // 5b. The units leave through their OWN document, in this same
            //     transaction (DPA V8). A GoodsReturn made up only of service
            //     lines produces no physical lines and therefore no note.
            if ($returnLines !== []) {
                $note = $this->goodsReturnNoteService->confirm(
                    $this->goodsReturnNoteService->createDraft(
                        tenantId: $creditNote->tenant_id,
                        companyId: $creditNote->company_id,
                        partnerId: $creditNote->partner_id,
                        supplierCreditNoteId: $creditNote->id,
                        reference: $creditNote->document_number,
                        lines: $returnLines,
                        actorId: $actorId,
                    ),
                    $actorId,
                );

                // The GL figures come off the note's own stamped costs, which
                // reproduce the pre-V8 `qty x product.cost_price` at scale 6.
                // BOTH halves of the sub-ledger movement are needed: the gross
                // exit AND the un-dilution that put value back (gate P1-1).
                $bonusReturnInventoryValue = $this->goodsReturnNoteService->bonusInventoryValue($note);
                $bonusReturnUndilutionApplied = $this->goodsReturnNoteService->bonusUndilutionApplied($note);
            }
        }

        // Tax components from the credit-note lines.
        /** @var numeric-string $recoverableVat */
        $recoverableVat = '0';
        /** @var numeric-string $nonRecoverableVat */
        $nonRecoverableVat = '0';
        foreach ($creditNote->lines as $line) {
            /** @var numeric-string $rec */
            $rec = $line->recoverable_tax_amount ?? '0';
            /** @var numeric-string $nonRec */
            $nonRec = $line->non_recoverable_tax_amount ?? '0';
            $recoverableVat = bcadd($recoverableVat, $rec, $scale + 1);
            $nonRecoverableVat = bcadd($nonRecoverableVat, $nonRec, $scale + 1);
        }

        /** @var numeric-string $ht */
        $ht = $creditNote->subtotal ?? '0';

        // 6. Post the GL reversing entry (in-transaction system path).
        if (bccomp($bonusReturnInventoryValue, '0', 6) > 0) {
            $this->generalLedgerService->createSupplierCreditNoteEntryWithBonusReturn(
                $creditNote,
                $ht,
                $recoverableVat,
                $nonRecoverableVat,
                $bonusReturnInventoryValue,
                $bonusReturnUndilutionApplied,
            );
        } else {
            $this->generalLedgerService->createSupplierCreditNoteEntry(
                $creditNote,
                $ht,
                $recoverableVat,
                $nonRecoverableVat,
            );
        }

        // 7. Draft → Posted, through the single write path (N-6 fix round r1,
        // fiscal gate F-6).
        $this->documentStatus->transition($creditNote, DocumentStatus::Posted);
    }

    /**
     * Resolve + lock (FOR UPDATE) the supplier invoice the credit note reverses.
     *
     * The credit note MUST link, via source_document_id, to a supplier invoice that
     * is (a) of the same company, (b) the SAME supplier/partner — so a credit for
     * supplier A cannot reduce A's payable against B's invoice ceiling (FIX 1), and
     * (c) POSTED — a credit reverses a posted liability, not a Draft/Cancelled one
     * (FIX 2). Any mismatch rejects the post.
     */
    private function resolveAndLockLinkedInvoice(Document $creditNote): Document
    {
        $invoiceId = $creditNote->source_document_id;
        if ($invoiceId === null) {
            throw new \DomainException(sprintf(
                'Supplier credit note [%s] cannot be posted: it must link to a supplier invoice '
                .'via source_document_id.',
                $creditNote->id,
            ));
        }

        /** @var Document|null $supplierInvoice */
        $supplierInvoice = Document::query()
            ->whereKey($invoiceId)
            ->lockForUpdate()
            ->first();

        if (
            $supplierInvoice === null
            || $supplierInvoice->type !== DocumentType::SupplierInvoice
            || $supplierInvoice->company_id !== $creditNote->company_id
        ) {
            throw new \DomainException(sprintf(
                'Supplier credit note [%s] cannot be posted: source_document_id [%s] does not '
                .'reference a supplier invoice of this company.',
                $creditNote->id,
                $invoiceId,
            ));
        }

        // FIX 1 (cross-supplier) — the linked invoice must belong to the SAME supplier.
        if ($supplierInvoice->partner_id !== $creditNote->partner_id) {
            throw new \DomainException(sprintf(
                'Supplier credit note [%s] (supplier [%s]) cannot be posted against invoice [%s] '
                .'belonging to a different supplier [%s].',
                $creditNote->id,
                $creditNote->partner_id ?? 'null',
                $invoiceId,
                $supplierInvoice->partner_id ?? 'null',
            ));
        }

        // FIX 2 (non-posted invoice) — a credit reverses a POSTED liability only.
        if ($supplierInvoice->status !== DocumentStatus::Posted) {
            throw new \DomainException(sprintf(
                'Supplier credit note [%s] cannot be posted: linked supplier invoice [%s] is not '
                .'Posted (status [%s]).',
                $creditNote->id,
                $invoiceId,
                $supplierInvoice->status->value,
            ));
        }

        $supplierInvoice->load('lines');

        return $supplierInvoice;
    }

    /**
     * FIX 1 — every credit-note line must resolve to a lockable PO line that the
     * linked supplier invoice references (validated like the C2 matcher: the PO
     * line's parent is a PurchaseOrder of the same company). Any unlinked,
     * unresolvable, or foreign line rejects the WHOLE post (no partial GL).
     *
     * @param  Collection<int, DocumentLine>  $lockedPoLines
     */
    private function assertAllLinesLinkedToInvoicePoLines(
        Document $creditNote,
        Document $supplierInvoice,
        Collection $lockedPoLines,
    ): void {
        // The set of PO lines the linked invoice actually references.
        $invoicePoLineIds = $supplierInvoice->lines
            ->pluck('source_line_id')
            ->reject(static fn ($id): bool => $id === null)
            ->unique()
            ->all();
        /** @var array<string, true> $invoicePoLineSet */
        $invoicePoLineSet = array_fill_keys($invoicePoLineIds, true);

        foreach ($creditNote->lines as $line) {
            $sourceLineId = $line->source_line_id;

            if ($sourceLineId === null) {
                throw new \DomainException(sprintf(
                    'Supplier credit note [%s] cannot be posted: line [%s] is unlinked '
                    .'(no source_line_id). Every line must reference a PO line of the linked invoice.',
                    $creditNote->id,
                    $line->id,
                ));
            }

            if (! isset($invoicePoLineSet[$sourceLineId])) {
                throw new \DomainException(sprintf(
                    'Supplier credit note [%s] cannot be posted: line [%s] references PO line [%s], '
                    .'which is not part of the linked supplier invoice [%s].',
                    $creditNote->id,
                    $line->id,
                    $sourceLineId,
                    $supplierInvoice->id,
                ));
            }

            // Resolve the locked PO line and validate its parent (C2-style).
            $poLine = $this->resolveLockedPoLine($creditNote, $lockedPoLines, $sourceLineId);
            $parent = $poLine->document;
            if (
                $parent->type !== DocumentType::PurchaseOrder
                || $parent->company_id !== $creditNote->company_id
            ) {
                throw new \DomainException(sprintf(
                    'Supplier credit note [%s] cannot be posted: PO line [%s] does not belong to a '
                    .'purchase order of this company.',
                    $creditNote->id,
                    $sourceLineId,
                ));
            }
        }
    }

    /**
     * FIX 2 — cumulative over-credit guard. The sum of HT across all PRIOR posted
     * supplier credit notes linked to the same invoice, plus this credit's HT, must
     * not exceed the invoice's actual posted HT (its subtotal). Reads run under the
     * invoice-row lock taken in resolveAndLockLinkedInvoice(), so concurrent posts
     * serialize.
     */
    private function assertCumulativeHtWithinInvoice(Document $creditNote, Document $supplierInvoice, int $scale): void
    {
        /** @var numeric-string $currentHt */
        $currentHt = $creditNote->subtotal ?? '0';

        /** @var SupportCollection<int, numeric-string|null> $priorSubtotals */
        $priorSubtotals = Document::query()
            ->where('type', DocumentType::SupplierCreditNote)
            ->where('source_document_id', $supplierInvoice->id)
            ->where('status', DocumentStatus::Posted)
            ->whereKeyNot($creditNote->id)
            ->pluck('subtotal');

        /** @var numeric-string $priorHt */
        $priorHt = '0';
        foreach ($priorSubtotals as $subtotal) {
            /** @var numeric-string $s */
            $s = $subtotal ?? '0';
            $priorHt = bcadd($priorHt, $s, $scale);
        }

        $cumulativeHt = bcadd($priorHt, $currentHt, $scale);

        /** @var numeric-string $invoiceHt */
        $invoiceHt = $supplierInvoice->subtotal ?? '0';

        if (bccomp($cumulativeHt, $invoiceHt, $scale) > 0) {
            throw new \DomainException(sprintf(
                'Supplier credit note [%s] cannot be posted: cumulative credited HT %s '
                .'(prior %s + current %s) would exceed the invoice [%s] HT %s.',
                $creditNote->id,
                $cumulativeHt,
                $priorHt,
                $currentHt,
                $supplierInvoice->id,
                $invoiceHt,
            ));
        }
    }

    /**
     * GoodsReturn: returned qty per PO line must be > 0 and ≤ quantity_invoiced;
     * decrement quantity_invoiced (reopens the line). Authoritative WRITE-boundary
     * guard on the LOCKED PO row.
     *
     * DPA V8: also emits the goods-return note line for each PHYSICAL PO line, so
     * paid returns move units through the same document bonus returns do. Before
     * V8 this path moved no stock at all while the GL already credited Inventory
     * for it — the units side was simply missing.
     *
     * @param  Collection<int, DocumentLine>  $lockedPoLines
     * @param  SupportCollection<int|string, Collection<int, GoodsReceiptLine>>  $receiptLinesByPoLine
     * @return list<SupplierGoodsReturnLineData>
     */
    private function guardAndDecrementGoodsReturn(Document $creditNote, Collection $lockedPoLines, SupportCollection $receiptLinesByPoLine): array
    {
        /** @var list<SupplierGoodsReturnLineData> $returnLines */
        $returnLines = [];

        foreach ($this->aggregateReturnedQtyPerPoLine($creditNote, false) as $sourceLineId => $qty) {
            $poLine = $this->resolveLockedPoLine($creditNote, $lockedPoLines, $sourceLineId);

            if (bccomp($qty, '0', 4) <= 0) {
                throw new \DomainException(sprintf(
                    'Supplier credit note [%s] cannot be posted: PO line [%s] has a non-positive returned quantity (%s).',
                    $creditNote->id,
                    $sourceLineId,
                    $qty,
                ));
            }

            /** @var Collection<int, GoodsReceiptLine> $receiptLines */
            $receiptLines = $receiptLinesByPoLine->get($sourceLineId, new Collection);

            /** @var list<array{line: GoodsReceiptLine, qty: numeric-string}> $allocation */
            $allocation = [];

            if ($receiptLines->isNotEmpty()) {
                $allocation = $this->decrementReceiptLineInvoiced($creditNote, $sourceLineId, $qty, $receiptLines, false);

                /** @var numeric-string $newInvoiced */
                $newInvoiced = $this->receiptLedgerSum($sourceLineId, 'quantity_invoiced');
            } else {
                /** @var numeric-string $newInvoiced */
                $newInvoiced = bcsub($poLine->quantity_invoiced, $qty, 4);

                if (bccomp($newInvoiced, '0', 4) < 0) {
                    throw new \DomainException(sprintf(
                        'Supplier credit note [%s] cannot be posted: PO line [%s] over-credit — '
                        .'returning %s would exceed invoiced %s (quantity_invoiced cannot go negative).',
                        $creditNote->id,
                        $poLine->id,
                        $qty,
                        $poLine->quantity_invoiced,
                    ));
                }
            }

            $poLine->quantity_invoiced = $newInvoiced;
            $poLine->save();

            foreach ($this->returnNoteLines(
                $creditNote,
                $poLine,
                $qty,
                SupplierGoodsReturnLineKind::Ordinary,
                $allocation,
                requireProduct: false,
            ) as $line) {
                $returnLines[] = $line;
            }
        }

        return $returnLines;
    }

    /**
     * Walk the posted receipt lines newest-first, decrementing the invoiced
     * counter, and RETURN the allocation it consumed.
     *
     * The return value is what makes requirement 1's "goods receipt(s)" linkage
     * honest (gate round 1, I-6): a return can span several receipt lines, each
     * with its OWN landed cost, so the goods-return note gets one line per slice
     * rather than a single line pointing at whichever receipt happened to be
     * newest. Deriving the allocation from the very loop that mutates the counters
     * — instead of re-deriving it alongside — is what keeps the note and the
     * counters from ever disagreeing about which receipt they mean.
     *
     * @param  Collection<int, GoodsReceiptLine>  $receiptLines
     * @return list<array{line: GoodsReceiptLine, qty: numeric-string}>
     */
    private function decrementReceiptLineInvoiced(
        Document $creditNote,
        string $poLineId,
        string $qtyToReverse,
        Collection $receiptLines,
        bool $free,
    ): array {
        /** @var list<array{line: GoodsReceiptLine, qty: numeric-string}> $allocation */
        $allocation = [];

        /** @var numeric-string $remaining */
        $remaining = CurrencyScale::bcformatStrict($qtyToReverse, 4);

        /** @var GoodsReceiptLine $line */
        foreach ($receiptLines->sortBy([
            ['created_at', 'desc'],
            ['id', 'desc'],
        ]) as $line) {
            if (bccomp($remaining, '0', 4) <= 0) {
                break;
            }

            /** @var numeric-string $current */
            $current = $free
                ? (string) ($line->free_quantity_invoiced ?? '0')
                : (string) $line->quantity_invoiced;
            if (bccomp($current, '0', 4) <= 0) {
                continue;
            }

            /** @var numeric-string $sliceQty */
            $sliceQty = bccomp($current, $remaining, 4) > 0 ? $remaining : $current;
            /** @var numeric-string $newInvoiced */
            $newInvoiced = bcsub($current, $sliceQty, 4);

            if ($free) {
                $line->free_quantity_invoiced = $newInvoiced;
            } else {
                $line->quantity_invoiced = $newInvoiced;
            }
            $line->save();

            $allocation[] = ['line' => $line, 'qty' => $sliceQty];

            $remaining = bcsub($remaining, $sliceQty, 4);
        }

        if (bccomp($remaining, '0', 4) > 0) {
            throw new \DomainException(sprintf(
                'Supplier credit note [%s] cannot be posted: PO line [%s] over-credit — '
                .'returning %s would exceed receipt-line %s quantity.',
                $creditNote->id,
                $poLineId,
                $qtyToReverse,
                $free ? 'free invoiced' : 'invoiced',
            ));
        }

        return $allocation;
    }

    /**
     * @return numeric-string
     */
    private function receiptLedgerSum(string $poLineId, string $column): string
    {
        return CurrencyScale::bcformatStrict((string) GoodsReceiptLine::query()
            ->postedReceipts()
            ->where('po_line_id', $poLineId)
            ->sum($column), 4);
    }

    /**
     * Bonus GoodsReturn: returned free qty must be > 0 and ≤ free_quantity_invoiced;
     * decrement only the free counter.
     *
     * DPA V8: this method no longer moves stock. It emits a goods-return note line
     * instead, and the note's confirm issues the units AND un-dilutes the WAC the
     * free units diluted on entry (which the old inline write got wrong).
     *
     * @param  Collection<int, DocumentLine>  $lockedPoLines
     * @param  SupportCollection<int|string, Collection<int, GoodsReceiptLine>>  $receiptLinesByPoLine
     * @return list<SupplierGoodsReturnLineData>
     */
    private function guardAndDecrementBonusGoodsReturn(Document $creditNote, Collection $lockedPoLines, SupportCollection $receiptLinesByPoLine): array
    {
        /** @var list<SupplierGoodsReturnLineData> $returnLines */
        $returnLines = [];

        foreach ($this->aggregateReturnedQtyPerPoLine($creditNote, true) as $sourceLineId => $qty) {
            $poLine = $this->resolveLockedPoLine($creditNote, $lockedPoLines, $sourceLineId);

            if (bccomp($qty, '0', 4) <= 0) {
                throw new \DomainException(sprintf(
                    'Supplier credit note [%s] cannot be posted: PO line [%s] has a non-positive returned bonus quantity (%s).',
                    $creditNote->id,
                    $sourceLineId,
                    $qty,
                ));
            }

            /** @var Collection<int, GoodsReceiptLine> $receiptLines */
            $receiptLines = $receiptLinesByPoLine->get($sourceLineId, new Collection);

            /** @var list<array{line: GoodsReceiptLine, qty: numeric-string}> $allocation */
            $allocation = [];

            if ($receiptLines->isNotEmpty()) {
                $allocation = $this->decrementReceiptLineInvoiced($creditNote, $sourceLineId, $qty, $receiptLines, true);

                /** @var numeric-string $newFreeInvoiced */
                $newFreeInvoiced = $this->receiptLedgerSum($sourceLineId, 'free_quantity_invoiced');
            } else {
                /** @var numeric-string $freeInvoiced */
                $freeInvoiced = $poLine->free_quantity_invoiced ?? '0.0000';
                /** @var numeric-string $newFreeInvoiced */
                $newFreeInvoiced = bcsub($freeInvoiced, $qty, 4);

                if (bccomp($newFreeInvoiced, '0', 4) < 0) {
                    throw new \DomainException(sprintf(
                        'Supplier credit note [%s] cannot be posted: PO line [%s] over-credit — '
                        .'returning %s bonus units would exceed invoiced free quantity %s.',
                        $creditNote->id,
                        $poLine->id,
                        $qty,
                        $freeInvoiced,
                    ));
                }
            }

            $poLine->free_quantity_invoiced = $newFreeInvoiced;
            $poLine->save();

            foreach ($this->returnNoteLines(
                $creditNote,
                $poLine,
                $qty,
                SupplierGoodsReturnLineKind::Bonus,
                $allocation,
                // A FREE unit of nothing is an inconsistency, and the pre-V8 path
                // threw here ("no product_id is attached"). Preserved.
                requireProduct: true,
            ) as $line) {
                $returnLines[] = $line;
            }
        }

        return $returnLines;
    }

    /**
     * Build the goods-return note lines for a PO line — ONE PER RECEIPT LINE the
     * counter decrement actually consumed — or an empty list when nothing physical
     * left the warehouse.
     *
     * Splitting per receipt line (gate round 1, I-6) does two things a single
     * newest-receipt link could not: it makes requirement 1's "goods receipt(s)"
     * linkage true for a return that spans several receipts, and it lets each
     * slice carry the landed cost of the receipt IT came from — which is the
     * ceiling the bonus un-dilution is bounded by (C-1). The allocation comes from
     * `decrementReceiptLineInvoiced` itself, so the note and the counters can
     * never disagree about which receipt they mean.
     *
     * With no posted receipt lines (the legacy PO-line path) there is one slice
     * for the whole quantity, ceilinged on the PO line's own landed cost.
     *
     * Non-physical lines (no `product_id`, or a service product) are SKIPPED
     * rather than refused — that is the same choice GoodsReceiptService makes on
     * the inbound leg, and refusing would break every price-style credit note
     * raised against a service line. `requireProduct` re-imposes the pre-V8 hard
     * failure on the bonus path, where a missing product really is inconsistent.
     *
     * @param  numeric-string  $qty
     * @param  list<array{line: GoodsReceiptLine, qty: numeric-string}>  $allocation
     * @return list<SupplierGoodsReturnLineData>
     */
    private function returnNoteLines(
        Document $creditNote,
        DocumentLine $poLine,
        string $qty,
        SupplierGoodsReturnLineKind $kind,
        array $allocation,
        bool $requireProduct,
    ): array {
        $productId = $poLine->product_id;

        if ($productId === null) {
            if ($requireProduct) {
                throw new \DomainException(sprintf(
                    'Supplier credit note [%s] cannot return bonus stock for PO line [%s]: no product_id is attached.',
                    $creditNote->id,
                    $poLine->id,
                ));
            }

            return [];
        }

        /** @var Product|null $product */
        $product = Product::query()
            ->whereKey($productId)
            ->where('tenant_id', $creditNote->tenant_id)
            ->where('company_id', $creditNote->company_id)
            ->first();

        if ($product === null) {
            throw new \DomainException(sprintf(
                'Supplier credit note [%s] cannot return stock for PO line [%s]: product [%s] does not '
                .'belong to this company.',
                $creditNote->id,
                $poLine->id,
                $productId,
            ));
        }

        if (! $product->isPhysical()) {
            if ($requireProduct) {
                throw new \DomainException(sprintf(
                    'Supplier credit note [%s] cannot return bonus stock for PO line [%s]: product [%s] '
                    .'is not a physical product.',
                    $creditNote->id,
                    $poLine->id,
                    $productId,
                ));
            }

            return [];
        }

        if ($allocation === []) {
            return [new SupplierGoodsReturnLineData(
                poLineId: (string) $poLine->id,
                productId: (string) $productId,
                variantId: $poLine->variant_id,
                kind: $kind,
                quantity: $qty,
                unitCostCeiling: $this->poLineCostCeiling($creditNote, $poLine, $kind),
                goodsReceiptId: null,
                goodsReceiptLineId: null,
                preferredLocationId: $poLine->location_id,
            )];
        }

        /** @var list<SupplierGoodsReturnLineData> $lines */
        $lines = [];

        foreach ($allocation as $slice) {
            $receiptLine = $slice['line'];

            $lines[] = new SupplierGoodsReturnLineData(
                poLineId: (string) $poLine->id,
                productId: (string) $productId,
                variantId: $poLine->variant_id,
                kind: $kind,
                quantity: $slice['qty'],
                unitCostCeiling: $this->receiptLineCostCeiling($creditNote, $poLine, $receiptLine, $kind),
                goodsReceiptId: $receiptLine->goods_receipt_id,
                goodsReceiptLineId: $receiptLine->id,
                preferredLocationId: $receiptLine->goodsReceipt->location_id ?? $poLine->location_id,
            );
        }

        return $lines;
    }

    /**
     * The price actually PAID per unit on the receipt these units came in on.
     *
     * This is the ceiling the bonus WAC un-dilution may never push
     * `products.cost_price` above (gate round 1, C-1). `landed_unit_cost` is the
     * receipt's own capitalized cost including allocated additional costs — the
     * exact figure `GoodsReceiptService` fed into `recordPurchase` for the PAID
     * units of this very receipt, which is what makes it the right bound for
     * un-diluting that receipt's free units.
     *
     * NOTE it is the PAID unit cost, never `effective_unit_cost` (which is the
     * landed cost already spread across paid + free, i.e. the diluted figure this
     * lane exists to correct).
     *
     * @return numeric-string|null
     */
    private function receiptLineCostCeiling(
        Document $creditNote,
        DocumentLine $poLine,
        GoodsReceiptLine $receiptLine,
        SupplierGoodsReturnLineKind $kind,
    ): ?string {
        $raw = $receiptLine->landed_unit_cost ?? $receiptLine->received_unit_price;

        if ($raw !== null && bccomp($raw, '0', 6) > 0) {
            return bcadd($raw, '0', 6);
        }

        return $this->poLineCostCeiling($creditNote, $poLine, $kind);
    }

    /**
     * Ceiling fallback from the PO line itself, for the legacy path where no
     * posted receipt line exists (or carries no landed cost).
     *
     * A BONUS line with no resolvable ceiling is REFUSED here rather than passed
     * on: the note service would refuse it anyway, and failing at the PO line says
     * WHICH line is unpriceable.
     *
     * @return numeric-string|null
     */
    private function poLineCostCeiling(
        Document $creditNote,
        DocumentLine $poLine,
        SupplierGoodsReturnLineKind $kind,
    ): ?string {
        // `unit_price` is non-nullable on a document line, so `$raw` is always a
        // numeric string here; only its SIGN needs checking. A zero/negative
        // landed cost or price means the receipt recorded no price to bound by.
        $raw = $poLine->landed_unit_cost ?? $poLine->unit_price;

        if (bccomp($raw, '0', 6) > 0) {
            return bcadd($raw, '0', 6);
        }

        if ($kind->requiresWacUndilution()) {
            throw new \DomainException(sprintf(
                'Supplier credit note [%s] cannot return bonus stock for PO line [%s]: no positive '
                .'landed cost or unit price is recorded, so the WAC un-dilution has no ceiling to be '
                .'bounded by. Refusing to apply an unbounded correction to the cost at rest.',
                $creditNote->id,
                $poLine->id,
            ));
        }

        return null;
    }

    /**
     * @param  Collection<int, DocumentLine>  $lockedPoLines
     */
    private function resolveLockedPoLine(Document $creditNote, Collection $lockedPoLines, string $sourceLineId): DocumentLine
    {
        /** @var DocumentLine|null $poLine */
        $poLine = $lockedPoLines->get($sourceLineId);
        if ($poLine === null) {
            throw new \DomainException(sprintf(
                'Supplier credit note [%s]: linked PO line [%s] not found.',
                $creditNote->id,
                $sourceLineId,
            ));
        }

        return $poLine;
    }

    /**
     * Sum credit-note line quantities per referenced PO line (scale 4).
     *
     * @return array<string, numeric-string>
     */
    private function aggregateReturnedQtyPerPoLine(Document $creditNote, bool $bonusLines): array
    {
        /** @var array<string, numeric-string> $agg */
        $agg = [];
        foreach ($creditNote->lines as $line) {
            if ($line->is_bonus_line !== $bonusLines) {
                continue;
            }
            if ($line->source_line_id === null) {
                continue;
            }
            $key = $line->source_line_id;
            /** @var numeric-string $current */
            $current = $agg[$key] ?? '0.0000';
            $agg[$key] = bcadd($current, $line->quantity, 4);
        }

        return $agg;
    }
}
