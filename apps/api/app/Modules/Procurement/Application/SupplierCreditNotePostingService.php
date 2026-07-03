<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Procurement\Domain\Enums\SupplierCreditNoteReason;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Database\Eloquent\Collection;
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
 */
final class SupplierCreditNotePostingService
{
    public function __construct(
        private readonly GeneralLedgerService $generalLedgerService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * Post a Draft supplier credit note's reversing entry under lock + idempotency.
     *
     * @throws \DomainException on a missing reason, broken invoice linkage, unlinked
     *                          lines, over-credit (per-line or cumulative), or
     *                          internal inconsistency (fails loudly, rolls back).
     */
    public function post(Document $creditNote): void
    {
        DB::transaction(function () use ($creditNote): void {
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

            // 1a. Resolve + lock the linked supplier invoice (the cumulative anchor).
            $supplierInvoice = $this->resolveAndLockLinkedInvoice($creditNote);

            // 1b. Lock the linked PO lines (serializes concurrent posts sharing a PO line).
            $poLineIds = $creditNote->lines
                ->pluck('source_line_id')
                ->reject(static fn ($id): bool => $id === null)
                ->unique()
                ->values()
                ->all();

            /** @var Collection<int, DocumentLine> $lockedPoLines */
            $lockedPoLines = DocumentLine::query()
                ->whereIn('id', $poLineIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

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

            // 5. GoodsReturn per-line quantity guard + decrement (PriceAdjustment: no-op).
            if ($reason->decrementsQuantityInvoiced()) {
                $this->guardAndDecrementGoodsReturn($creditNote, $lockedPoLines);
                $bonusReturnInventoryValue = $this->guardDecrementAndIssueBonusGoodsReturn($creditNote, $lockedPoLines);
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
                );
            } else {
                $this->generalLedgerService->createSupplierCreditNoteEntry(
                    $creditNote,
                    $ht,
                    $recoverableVat,
                    $nonRecoverableVat,
                );
            }

            // 7. Draft → Posted.
            $creditNote->status = DocumentStatus::Posted;
            $creditNote->save();
        });
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

        /** @var \Illuminate\Support\Collection<int, numeric-string|null> $priorSubtotals */
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
     * @param  Collection<int, DocumentLine>  $lockedPoLines
     */
    private function guardAndDecrementGoodsReturn(Document $creditNote, Collection $lockedPoLines): void
    {
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

            $poLine->quantity_invoiced = $newInvoiced;
            $poLine->save();
        }
    }

    /**
     * Bonus GoodsReturn: returned free qty must be > 0 and ≤ free_quantity_invoiced;
     * decrement only the free counter and issue stock at the current diluted WAC.
     *
     * @param  Collection<int, DocumentLine>  $lockedPoLines
     * @return numeric-string
     */
    private function guardDecrementAndIssueBonusGoodsReturn(Document $creditNote, Collection $lockedPoLines): string
    {
        /** @var numeric-string $inventoryValue */
        $inventoryValue = '0.000000';

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

            $poLine->free_quantity_invoiced = $newFreeInvoiced;
            $poLine->save();

            $inventoryValue = bcadd(
                $inventoryValue,
                $this->issueBonusReturnStock($creditNote, $poLine, $qty),
                6,
            );
        }

        return $inventoryValue;
    }

    /**
     * @param  numeric-string  $qty
     * @return numeric-string
     */
    private function issueBonusReturnStock(Document $creditNote, DocumentLine $poLine, string $qty): string
    {
        $productId = $poLine->product_id;
        if ($productId === null) {
            throw new \DomainException(sprintf(
                'Supplier credit note [%s] cannot return bonus stock for PO line [%s]: no product_id is attached.',
                $creditNote->id,
                $poLine->id,
            ));
        }

        /** @var Product $product */
        $product = Product::query()
            ->whereKey($productId)
            ->where('tenant_id', $creditNote->tenant_id)
            ->where('company_id', $creditNote->company_id)
            ->lockForUpdate()
            ->firstOrFail();

        $stockLevelQuery = StockLevel::query()
            ->where('tenant_id', $creditNote->tenant_id)
            ->where('company_id', $creditNote->company_id)
            ->where('product_id', $productId)
            ->where('quantity', '>', '0');

        if ($poLine->variant_id === null) {
            $stockLevelQuery->whereNull('variant_id');
        } else {
            $stockLevelQuery->where('variant_id', $poLine->variant_id);
        }

        /** @var StockLevel|null $stockLevel */
        $stockLevel = $stockLevelQuery
            ->orderByDesc('quantity')
            ->lockForUpdate()
            ->first();

        if ($stockLevel === null) {
            throw new \DomainException(sprintf(
                'Supplier credit note [%s] cannot return bonus stock for PO line [%s]: no stock level is available.',
                $creditNote->id,
                $poLine->id,
            ));
        }

        /** @var numeric-string $quantityBefore */
        $quantityBefore = $stockLevel->quantity;
        /** @var numeric-string $quantityAfter */
        $quantityAfter = bcsub($quantityBefore, $qty, 4);

        if (bccomp($quantityAfter, '0', 4) < 0) {
            throw new \DomainException(sprintf(
                'Supplier credit note [%s] cannot return %s bonus units for PO line [%s]: stock would go negative from %s.',
                $creditNote->id,
                $qty,
                $poLine->id,
                $quantityBefore,
            ));
        }

        $rawUnitCost = $product->cost_price;
        if (! is_numeric($rawUnitCost)) {
            throw new \DomainException(sprintf(
                'Supplier credit note [%s] cannot return bonus stock for PO line [%s]: product [%s] has a non-numeric WAC cost.',
                $creditNote->id,
                $poLine->id,
                $productId,
            ));
        }

        $unitCost = bcadd($rawUnitCost, '0', 6);
        /** @var numeric-string $totalCost */
        $totalCost = bcmul($qty, $unitCost, 6);

        $stockLevel->quantity = $quantityAfter;
        $stockLevel->save();

        StockMovement::create([
            'tenant_id' => $creditNote->tenant_id,
            'company_id' => $creditNote->company_id,
            'product_id' => $productId,
            'variant_id' => $poLine->variant_id,
            'location_id' => $stockLevel->location_id,
            'movement_type' => MovementType::Issue,
            'quantity' => bcmul($qty, '-1', 4),
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'avg_cost_before' => $unitCost,
            'avg_cost_after' => $unitCost,
            'reference' => $creditNote->document_number,
            'reference_type' => Document::class,
            'reference_id' => $creditNote->id,
        ]);

        return $totalCost;
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
