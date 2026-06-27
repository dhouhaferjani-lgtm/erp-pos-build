<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

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
    ) {}

    /**
     * Post a Draft supplier invoice's GR-IR clearing entry under lock + idempotency.
     *
     * @throws \DomainException on hard match violations, non-positive quantities,
     *                          or internal inconsistency (fails loudly, rolls back).
     */
    public function post(Document $supplierInvoice): void
    {
        DB::transaction(function () use ($supplierInvoice): void {
            // 1. Resolve effective AP policy → enforcement mode.
            $policy = $this->resolver->forCompany($supplierInvoice->company_id);
            $enforcement = $policy->match_enforcement;

            $supplierInvoice->load('lines');

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

            // 4. Recheck the matcher invariant on the LOCKED state. Hard violations
            //    (quantity over-clear / exception) throw regardless of enforcement.
            $this->matcher->assertPostable($supplierInvoice, $enforcement);

            // Capture the economic match status BEFORE incrementing (post-increment the
            // PO line reads as fully invoiced, which would mis-classify the status).
            $matchStatus = $this->matcher->match($supplierInvoice);

            // 5. Positive-net-qty guard — aggregate invoiced qty per PO line must be > 0.
            $aggregateQty = $this->aggregateInvoicedQtyPerPoLine($supplierInvoice);
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

                // Clear 408 at the SAME basis B1 accrued on receipt:
                // landed_unit_cost ?? unit_price (GoodsReceiptService.php:157 →
                // GeneralLedgerService::createGoodsReceiptGrIrEntry). Using raw
                // unit_price here would leave a landed-vs-unit_price residue on 408.
                /** @var numeric-string $accrualUnitCost */
                $accrualUnitCost = $poLine->landed_unit_cost ?? $poLine->unit_price;

                // B3 guard: if the PO line has an immutable receipt-accrual basis
                // recorded (set by GoodsReceiptService), assert the clearing basis
                // equals it. A mismatch means LandedCostService reallocated costs
                // after receipt — the 408 accrual and the clearing would diverge,
                // leaving an irreconcilable residue on account 408.
                if ($poLine->accrual_unit_cost !== null) {
                    if (bccomp($accrualUnitCost, (string) $poLine->accrual_unit_cost, 6) !== 0) {
                        throw new \DomainException(sprintf(
                            'Supplier invoice [%s] cannot be posted: PO line [%s] 408 accrual basis '
                            .'divergence — clearing at %s but receipt accrued at %s. '
                            .'landed_unit_cost was reallocated after receipt; resolve before posting.',
                            $supplierInvoice->id,
                            $poLine->id,
                            $accrualUnitCost,
                            $poLine->accrual_unit_cost,
                        ));
                    }
                }
                /** @var numeric-string $lineAccrual */
                $lineAccrual = bcmul($qty, $accrualUnitCost, $working);
                $accruedHt = bcadd($accruedHt, $lineAccrual, $working);

                /** @var numeric-string $newInvoiced */
                $newInvoiced = bcadd($poLine->quantity_invoiced, $qty, 4);

                // Authoritative over-clear guard at the WRITE boundary: the locked
                // PO row is the source of truth. Holds regardless of match_enforcement
                // and independent of the matcher's separate read.
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
     * Sum invoice line quantities per referenced PO line (scale 4).
     *
     * @return array<string, numeric-string>
     */
    private function aggregateInvoicedQtyPerPoLine(Document $supplierInvoice): array
    {
        /** @var array<string, numeric-string> $agg */
        $agg = [];
        foreach ($supplierInvoice->lines as $line) {
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
