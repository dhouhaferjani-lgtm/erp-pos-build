<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * C3 — Supplier Invoice posting orchestrator (GR-IR clearing, option 1).
 *
 * Posts a confirmed supplier invoice in ONE serialized DB transaction:
 *   1. Resolve the company's AP policy → match enforcement.
 *   2. Lock the matched PO lines (FOR UPDATE) — serializes concurrent posts.
 *   3. Idempotency no-op: a clearing entry already exists for this invoice.
 *      Checked under the lock and BEFORE the matcher recheck (a genuine re-post
 *      sees fully-invoiced PO lines, which would otherwise read as an over-clear).
 *   4. Recheck the matcher invariant on the LOCKED state — hard quantity/exception
 *      violations throw regardless of enforcement; warn cannot bypass them.
 *   5. Positive-net-qty guard (authoritative boundary) per PO line.
 *   6. Increment quantity_invoiced per PO line; accrue HT at the PO-cost basis.
 *   7. Post the GL clearing entry via the in-transaction system path (atomic with
 *      the lock + increment).
 *   8. Draft → Posted; persist the match status.
 *
 * The accounting model (legs / plug) lives in
 * GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry. This service owns
 * concurrency, idempotency, and the quantity ledger.
 */
final class SupplierInvoicePostingService
{
    public function __construct(
        private readonly SupplierInvoiceMatcher $matcher,
        private readonly ProcurementPolicyResolver $resolver,
        private readonly GeneralLedgerService $generalLedgerService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly ReceiptLineConsumptionPlanner $receiptPlanner,
    ) {}

    /**
     * Post a Draft supplier invoice's GR-IR clearing entry under lock + idempotency.
     *
     * @throws \DomainException on hard match violations, non-positive quantities,
     *                          or internal inconsistency (fails loudly, rolls back).
     */
    public function post(Document $supplierInvoice, ?string $actorId = null): void
    {
        DB::transaction(function () use ($supplierInvoice, $actorId): void {
            // 1. Resolve effective AP policy → enforcement mode.
            $policy = $this->resolver->forCompany($supplierInvoice->company_id);
            $enforcement = $policy->match_enforcement;

            $supplierInvoice->load('lines');
            /** @var array<string, mixed> $payload */
            $payload = $supplierInvoice->payload ?? [];
            $supplierInvoicePayload = is_array($payload['supplier_invoice'] ?? null) ? $payload['supplier_invoice'] : [];
            if (($supplierInvoicePayload['pending_receipt'] ?? false) === true) {
                throw new \DomainException('PENDING_RECEIPT_UNLINKED');
            }
            $this->assertInvoiceFirstApproval($supplierInvoice, $actorId);

            // 2. Lock the matched PO lines (serializes concurrent posts sharing a PO line).
            $poLineIds = $supplierInvoice->lines
                ->pluck('source_line_id')
                ->reject(static fn ($id): bool => $id === null)
                ->unique()
                ->values()
                ->all();

            /** @var Collection<int, DocumentLine> $lockedPoLines */
            $lockedPoLines = DocumentLine::query()
                ->whereIn('id', $poLineIds)
                ->whereHas('document', fn ($q) => $q->whereRaw('company_id = ?', [$supplierInvoice->company_id]))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var Collection<int, GoodsReceiptLine> $lockedReceiptLines */
            $lockedReceiptLines = GoodsReceiptLine::query()
                ->postedReceipts()
                ->where('company_id', $supplierInvoice->company_id)
                ->whereIn('po_line_id', $poLineIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $receiptLinesByPoLine = $lockedReceiptLines->groupBy('po_line_id');

            $poDocumentIds = $lockedPoLines
                ->pluck('document_id')
                ->unique()
                ->values()
                ->all();

            /** @var Collection<int, Document> $lockedPoDocuments */
            $lockedPoDocuments = Document::query()
                ->whereIn('id', $poDocumentIds)
                ->where('company_id', $supplierInvoice->company_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // 3. Idempotency no-op: a clearing entry already exists for this invoice.
            $alreadyPosted = JournalEntry::query()
                ->where('source_type', 'supplier_invoice')
                ->where('source_id', $supplierInvoice->id)
                ->where('company_id', $supplierInvoice->company_id)
                ->exists();
            if ($alreadyPosted) {
                return;
            }

            $this->assertLineParentsShareInvoiceHeader($supplierInvoice, $lockedPoLines, $lockedPoDocuments);

            // 4. Recheck the matcher invariant on the LOCKED state. Hard violations
            //    (quantity over-clear / exception) throw regardless of enforcement.
            $this->matcher->assertPostable($supplierInvoice, $enforcement);

            // Capture the economic match status BEFORE incrementing (post-increment the
            // PO line reads as fully invoiced, which would mis-classify the status).
            $matchStatus = $this->matcher->match($supplierInvoice);

            // 5. Positive-net-qty guard — aggregate invoiced qty per PO line must be > 0.
            $aggregateQty = $this->aggregateInvoicedQtyPerPoLine($supplierInvoice);
            $aggregateBonusQty = $this->aggregateBonusQtyPerPoLine($supplierInvoice);
            foreach ($aggregateQty as $sourceLineId => $qty) {
                if (bccomp($qty, '0', 4) <= 0) {
                    throw new \DomainException(sprintf(
                        'Supplier invoice [%s] cannot be posted: PO line [%s] has a non-positive aggregate invoiced quantity (%s).',
                        $supplierInvoice->id,
                        $sourceLineId,
                        $qty,
                    ));
                }
            }

            $scale = $this->scaleResolver->getScale($supplierInvoice->currency);
            $working = $scale + 4;

            // 6. Increment quantity_invoiced per PO line and accumulate accrued HT
            //    (Σ invoiced_qty × PO unit_price) — the SAME basis B1 accrued to 408.
            /** @var numeric-string $accruedHt */
            $accruedHt = '0';
            foreach ($aggregateQty as $sourceLineId => $qty) {
                /** @var DocumentLine|null $poLine */
                $poLine = $lockedPoLines->get($sourceLineId);
                if ($poLine === null) {
                    // assertPostable already validated every referenced PO line; defensive.
                    throw new \DomainException(sprintf(
                        'Supplier invoice [%s]: locked PO line [%s] not found.',
                        $supplierInvoice->id,
                        $sourceLineId,
                    ));
                }

                /** @var Collection<int, GoodsReceiptLine> $receiptLines */
                $receiptLines = $receiptLinesByPoLine->get($sourceLineId, new Collection);

                if ($receiptLines->isNotEmpty()) {
                    /** @var numeric-string $lineAccrual */
                    $lineAccrual = $this->consumeReceiptLines(
                        $supplierInvoice,
                        $sourceLineId,
                        $qty,
                        $receiptLines,
                        $working,
                    );
                    $accruedHt = bcadd($accruedHt, $lineAccrual, $working);

                    /** @var numeric-string $newInvoiced */
                    $newInvoiced = CurrencyScale::bcformatStrict((string) GoodsReceiptLine::query()
                        ->postedReceipts()
                        ->where('po_line_id', $sourceLineId)
                        ->sum('quantity_invoiced'), 4);
                } else {
                    // Backfill-less compatibility path: no receipt lines exist, so clear
                    // at the legacy PO-line basis exactly as pre-ledger posting did.
                    /** @var numeric-string $accrualUnitCost */
                    $accrualUnitCost = $poLine->accrual_unit_cost ?? $poLine->landed_unit_cost ?? $poLine->unit_price;
                    /** @var numeric-string $lineAccrual */
                    $lineAccrual = bcmul($qty, $accrualUnitCost, $working);
                    $accruedHt = bcadd($accruedHt, $lineAccrual, $working);
                    /** @var numeric-string $newInvoiced */
                    $newInvoiced = bcadd($poLine->quantity_invoiced, $qty, 4);
                }

                if (bccomp($newInvoiced, $poLine->quantity_received, 4) > 0) {
                    throw new \DomainException(sprintf(
                        'Supplier invoice [%s] cannot be posted: PO line [%s] over-clear — '
                        .'invoiced %s would exceed received %s.',
                        $supplierInvoice->id,
                        $poLine->id,
                        $newInvoiced,
                        $poLine->quantity_received,
                    ));
                }

                $poLine->quantity_invoiced = $newInvoiced;
                $poLine->save();
            }

            foreach ($aggregateBonusQty as $sourceLineId => $qty) {
                /** @var DocumentLine|null $poLine */
                $poLine = $lockedPoLines->get($sourceLineId);
                if ($poLine === null) {
                    throw new \DomainException(sprintf(
                        'Supplier invoice [%s]: locked PO line [%s] not found for bonus quantity.',
                        $supplierInvoice->id,
                        $sourceLineId,
                    ));
                }

                /** @var Collection<int, GoodsReceiptLine> $receiptLines */
                $receiptLines = $receiptLinesByPoLine->get($sourceLineId, new Collection);

                if ($receiptLines->isNotEmpty()) {
                    $this->consumeFreeReceiptLines($supplierInvoice, $sourceLineId, $qty, $receiptLines);

                    /** @var numeric-string $newFreeInvoiced */
                    $newFreeInvoiced = CurrencyScale::bcformatStrict((string) GoodsReceiptLine::query()
                        ->postedReceipts()
                        ->where('po_line_id', $sourceLineId)
                        ->sum('free_quantity_invoiced'), 4);
                } else {
                    /** @var numeric-string $newFreeInvoiced */
                    $newFreeInvoiced = bcadd((string) ($poLine->free_quantity_invoiced ?? '0'), $qty, 4);
                }

                if (bccomp($newFreeInvoiced, (string) ($poLine->free_quantity_received ?? '0'), 4) > 0) {
                    throw new \DomainException(sprintf(
                        'Supplier invoice [%s] cannot be posted: PO line [%s] bonus over-clear — '
                        .'free invoiced %s would exceed free received %s.',
                        $supplierInvoice->id,
                        $poLine->id,
                        $newFreeInvoiced,
                        $poLine->free_quantity_received,
                    ));
                }

                $poLine->free_quantity_invoiced = $newFreeInvoiced;
                $poLine->save();
            }

            // Tax components from the invoice lines.
            /** @var numeric-string $recoverableVat */
            $recoverableVat = '0';
            /** @var numeric-string $nonRecoverableVat */
            $nonRecoverableVat = '0';
            foreach ($supplierInvoice->lines as $line) {
                /** @var numeric-string $rec */
                $rec = $line->recoverable_tax_amount ?? '0';
                /** @var numeric-string $nonRec */
                $nonRec = $line->non_recoverable_tax_amount ?? '0';
                $recoverableVat = bcadd($recoverableVat, $rec, $working);
                $nonRecoverableVat = bcadd($nonRecoverableVat, $nonRec, $working);
            }

            /** @var numeric-string $billedHt */
            $billedHt = $supplierInvoice->subtotal ?? '0';
            /** @var numeric-string $timbre */
            $timbre = $supplierInvoice->stamp_duty_amount ?? '0';

            // 7. Post the GL clearing entry (in-transaction system path).
            $this->generalLedgerService->createSupplierInvoiceGrIrClearingEntry(
                $supplierInvoice,
                $accruedHt,
                $billedHt,
                $recoverableVat,
                $nonRecoverableVat,
                $timbre,
            );

            // 8. Draft → Posted; initialize payable ceiling; persist the match status.
            // balance_due is set to total here so PaymentController has an authoritative
            // ceiling (deferred B2 credit-note decrement will subtract from this).
            $supplierInvoice->balance_due = $supplierInvoice->total;
            $supplierInvoice->status = DocumentStatus::Posted;
            $supplierInvoice->match_status = $matchStatus;
            $supplierInvoice->save();
        });
    }

    /**
     * @param  Collection<int, GoodsReceiptLine>  $receiptLines
     * @return numeric-string
     */
    private function consumeReceiptLines(
        Document $supplierInvoice,
        string $poLineId,
        string $qtyToConsume,
        Collection $receiptLines,
        int $workingScale,
    ): string {
        /** @var numeric-string $remaining */
        $remaining = CurrencyScale::bcformatStrict($qtyToConsume, 4);
        /** @var numeric-string $accrued */
        $accrued = '0';

        /** @var GoodsReceiptLine $line */
        foreach ($receiptLines->sortBy([
            ['created_at', 'asc'],
            ['id', 'asc'],
        ]) as $line) {
            if (bccomp($remaining, '0', 4) <= 0) {
                break;
            }

            /** @var numeric-string $matchable */
            $matchable = $this->receiptPlanner->matchableQty($line);
            if (bccomp($matchable, '0', 4) <= 0) {
                continue;
            }

            /** @var numeric-string $sliceQty */
            $sliceQty = bccomp($matchable, $remaining, 4) > 0 ? $remaining : $matchable;

            $basisRaw = $line->getAttribute('accrual_unit_cost');
            if ($basisRaw === null) {
                throw new \DomainException(sprintf(
                    'Supplier invoice [%s] cannot be posted: receipt line [%s] has no accrual basis.',
                    $supplierInvoice->id,
                    $line->id,
                ));
            }

            /** @var numeric-string $basis */
            $basis = CurrencyScale::bcformatStrict((string) $basisRaw, 6);
            $accrued = bcadd($accrued, bcmul($sliceQty, $basis, $workingScale), $workingScale);

            /** @var numeric-string $newReceiptInvoiced */
            $newReceiptInvoiced = bcadd((string) $line->quantity_invoiced, $sliceQty, 4);
            /** @var numeric-string $receiptWindow */
            $receiptWindow = (string) $line->received_qty;
            if (bccomp($newReceiptInvoiced, $receiptWindow, 4) > 0) {
                throw new \DomainException(sprintf(
                    'Supplier invoice [%s] cannot be posted: receipt line [%s] over-clear — '
                    .'invoiced %s would exceed received %s.',
                    $supplierInvoice->id,
                    $line->id,
                    $newReceiptInvoiced,
                    $receiptWindow,
                ));
            }

            $line->quantity_invoiced = $newReceiptInvoiced;
            $line->save();

            $remaining = bcsub($remaining, $sliceQty, 4);
        }

        if (bccomp($remaining, '0', 4) > 0) {
            throw new \DomainException(sprintf(
                'Supplier invoice [%s] cannot be posted: PO line [%s] has insufficient receipt-line quantity; remaining %s.',
                $supplierInvoice->id,
                $poLineId,
                $remaining,
            ));
        }

        return $accrued;
    }

    /**
     * @param  Collection<int, GoodsReceiptLine>  $receiptLines
     */
    private function consumeFreeReceiptLines(
        Document $supplierInvoice,
        string $poLineId,
        string $qtyToConsume,
        Collection $receiptLines,
    ): void {
        /** @var numeric-string $remaining */
        $remaining = CurrencyScale::bcformatStrict($qtyToConsume, 4);

        /** @var GoodsReceiptLine $line */
        foreach ($receiptLines->sortBy([
            ['created_at', 'asc'],
            ['id', 'asc'],
        ]) as $line) {
            if (bccomp($remaining, '0', 4) <= 0) {
                break;
            }

            /** @var numeric-string $matchable */
            $matchable = $this->receiptPlanner->freeMatchableQty($line);
            if (bccomp($matchable, '0', 4) <= 0) {
                continue;
            }

            /** @var numeric-string $sliceQty */
            $sliceQty = bccomp($matchable, $remaining, 4) > 0 ? $remaining : $matchable;

            /** @var numeric-string $newFreeInvoiced */
            $newFreeInvoiced = bcadd((string) ($line->free_quantity_invoiced ?? '0'), $sliceQty, 4);
            if (bccomp($newFreeInvoiced, (string) $line->free_qty, 4) > 0) {
                throw new \DomainException(sprintf(
                    'Supplier invoice [%s] cannot be posted: receipt line [%s] bonus over-clear — '
                    .'free invoiced %s would exceed free received %s.',
                    $supplierInvoice->id,
                    $line->id,
                    $newFreeInvoiced,
                    $line->free_qty,
                ));
            }

            $line->free_quantity_invoiced = $newFreeInvoiced;
            $line->save();

            $remaining = bcsub($remaining, $sliceQty, 4);
        }

        if (bccomp($remaining, '0', 4) > 0) {
            throw new \DomainException(sprintf(
                'Supplier invoice [%s] cannot be posted: PO line [%s] has insufficient receipt-line free quantity; remaining %s.',
                $supplierInvoice->id,
                $poLineId,
                $remaining,
            ));
        }
    }

    /**
     * Sum invoice line quantities per referenced PO line (scale 4).
     *
     * @return array<string, numeric-string>
     */
    private function aggregateInvoicedQtyPerPoLine(Document $supplierInvoice): array
    {
        /** @var array<string, numeric-string> $agg */
        $agg = [];
        foreach ($supplierInvoice->lines as $line) {
            if ((bool) ($line->is_bonus_line ?? false)) {
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

    /**
     * @param  Collection<int, DocumentLine>  $lockedPoLines
     * @param  Collection<int, Document>  $lockedPoDocuments
     */
    private function assertLineParentsShareInvoiceHeader(
        Document $supplierInvoice,
        Collection $lockedPoLines,
        Collection $lockedPoDocuments,
    ): void {
        /** @var DocumentLine $poLine */
        foreach ($lockedPoLines as $poLine) {
            /** @var Document|null $parent */
            $parent = $lockedPoDocuments->get($poLine->document_id);
            if (
                $parent === null
                || $parent->type !== DocumentType::PurchaseOrder
                || $parent->company_id !== $supplierInvoice->company_id
                || $parent->partner_id !== $supplierInvoice->partner_id
                || $parent->currency !== $supplierInvoice->currency
            ) {
                throw new \DomainException(sprintf(
                    'Supplier invoice [%s] cannot be posted: PO line [%s] parent does not share company, partner, and currency.',
                    $supplierInvoice->id,
                    $poLine->id,
                ));
            }
        }
    }

    /**
     * Sum explicit remise-en-nature quantities per referenced PO line (scale 4).
     *
     * @return array<string, numeric-string>
     */
    private function aggregateBonusQtyPerPoLine(Document $supplierInvoice): array
    {
        /** @var array<string, numeric-string> $agg */
        $agg = [];
        foreach ($supplierInvoice->lines as $line) {
            if (! (bool) ($line->is_bonus_line ?? false)) {
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

    private function assertInvoiceFirstApproval(Document $supplierInvoice, ?string $actorId): void
    {
        $policy = $this->resolver->forCompany($supplierInvoice->company_id);
        if (! $policy->requiresInvoiceFirstApproval() || ! $this->chainIncludesInvoiceFirstAutoPo($supplierInvoice)) {
            return;
        }

        if ($actorId === null) {
            throw new \DomainException('INVOICE_FIRST_APPROVAL_REQUIRED');
        }

        $actor = User::query()->find($actorId);
        if (! $actor instanceof User) {
            throw new \DomainException('INVOICE_FIRST_APPROVAL_REQUIRED');
        }

        try {
            $hasApprovalPermission = $actor->hasPermissionTo('supplier-invoices.approve-invoice-first', 'sanctum');
        } catch (PermissionDoesNotExist) {
            $hasApprovalPermission = false;
        }

        if (! $hasApprovalPermission) {
            throw new \DomainException('INVOICE_FIRST_APPROVAL_REQUIRED');
        }
    }

    private function chainIncludesInvoiceFirstAutoPo(Document $supplierInvoice): bool
    {
        /** @var array<string, mixed> $payload */
        $payload = $supplierInvoice->payload ?? [];
        $supplierInvoicePayload = is_array($payload['supplier_invoice'] ?? null) ? $payload['supplier_invoice'] : [];
        /** @var list<string> $sourceDocumentIds */
        $sourceDocumentIds = array_values(array_filter(
            $supplierInvoicePayload['source_document_ids'] ?? [],
            static fn (mixed $id): bool => is_string($id),
        ));

        if ($supplierInvoice->source_document_id !== null) {
            $sourceDocumentIds[] = $supplierInvoice->source_document_id;
        }
        $sourceDocumentIds = array_values(array_unique($sourceDocumentIds));
        if ($sourceDocumentIds === []) {
            return false;
        }

        /** @var Collection<int, Document> $purchaseOrders */
        $purchaseOrders = Document::query()
            ->whereIn('id', $sourceDocumentIds)
            ->where('company_id', $supplierInvoice->company_id)
            ->where('type', DocumentType::PurchaseOrder)
            ->whereJsonContainsKey('payload->auto_generated')
            ->get();

        foreach ($purchaseOrders as $purchaseOrder) {
            /** @var array<string, mixed> $poPayload */
            $poPayload = $purchaseOrder->payload ?? [];
            $autoGenerated = is_array($poPayload['auto_generated'] ?? null) ? $poPayload['auto_generated'] : [];
            if (($autoGenerated['source'] ?? null) === 'invoice_first') {
                return true;
            }
        }

        return false;
    }
}
