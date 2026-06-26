<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Procurement\Domain\Enums\SupplierCreditNoteReason;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * D1 — Supplier Credit Note posting orchestrator (mirrors C3 in reverse).
 *
 * Posts a Draft supplier credit note in ONE serialized DB transaction:
 *   1. Lock the linked PO lines (FOR UPDATE) — serializes concurrent posts.
 *   2. Idempotency no-op: a reversing entry already exists for this credit note.
 *   3. HARD over-credit guard on the LOCKED state:
 *        - GoodsReturn: returned qty per PO line must be > 0 and ≤ quantity_invoiced
 *          (never drives quantity_invoiced < 0, never credits beyond invoiced).
 *        - PriceAdjustment: the credit HT per PO line must be ≤ the invoiced value
 *          (quantity_invoiced × PO unit_price).
 *   4. Decrement quantity_invoiced per PO line by the returned qty — GoodsReturn ONLY
 *      (reopens the PO line for re-invoicing). PriceAdjustment leaves it untouched.
 *   5. Post the GL reversing entry via the in-transaction system path (atomic with
 *      the lock + decrement).
 *   6. Draft → Posted.
 *
 * The accounting model (legs / plug) lives in
 * GeneralLedgerService::createSupplierCreditNoteEntry. This service owns
 * concurrency, idempotency, and the quantity ledger.
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
     * @throws \DomainException on a missing reason, hard over-credit violations, or
     *                          internal inconsistency (fails loudly, rolls back).
     */
    public function post(Document $creditNote): void
    {
        DB::transaction(function () use ($creditNote): void {
            $creditNote->load('lines');

            $reason = $creditNote->supplier_credit_note_reason;
            if (! $reason instanceof SupplierCreditNoteReason) {
                throw new \DomainException(sprintf(
                    'Supplier credit note [%s] cannot be posted without an explicit reason '
                    .'(SupplierCreditNoteReason::PriceAdjustment | GoodsReturn).',
                    $creditNote->id,
                ));
            }

            // 1. Lock the linked PO lines (serializes concurrent posts sharing a PO line).
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

            $scale = $this->scaleResolver->getScale($creditNote->currency);

            // 3. + 4. Over-credit guard (HARD) and quantity_invoiced reversal.
            if ($reason->decrementsQuantityInvoiced()) {
                $this->guardAndDecrementGoodsReturn($creditNote, $lockedPoLines);
            } else {
                $this->guardPriceAdjustment($creditNote, $lockedPoLines, $scale);
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

            // 5. Post the GL reversing entry (in-transaction system path).
            $this->generalLedgerService->createSupplierCreditNoteEntry(
                $creditNote,
                $ht,
                $recoverableVat,
                $nonRecoverableVat,
            );

            // 6. Draft → Posted.
            $creditNote->status = DocumentStatus::Posted;
            $creditNote->save();
        });
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
        foreach ($this->aggregateReturnedQtyPerPoLine($creditNote) as $sourceLineId => $qty) {
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
     * PriceAdjustment: the credit HT attributable to each PO line must not exceed the
     * value already invoiced for that line (quantity_invoiced × PO unit_price).
     * No quantity_invoiced effect.
     *
     * @param  Collection<int, DocumentLine>  $lockedPoLines
     */
    private function guardPriceAdjustment(Document $creditNote, Collection $lockedPoLines, int $scale): void
    {
        $working = $scale + 1;

        /** @var array<string, numeric-string> $creditHtPerPoLine */
        $creditHtPerPoLine = [];
        foreach ($creditNote->lines as $line) {
            if ($line->source_line_id === null) {
                continue;
            }
            $key = $line->source_line_id;
            /** @var numeric-string $current */
            $current = $creditHtPerPoLine[$key] ?? '0';
            /** @var numeric-string $lineTotal */
            $lineTotal = $line->line_total ?? '0';
            $creditHtPerPoLine[$key] = bcadd($current, $lineTotal, $working);
        }

        foreach ($creditHtPerPoLine as $sourceLineId => $creditHt) {
            $poLine = $this->resolveLockedPoLine($creditNote, $lockedPoLines, $sourceLineId);

            /** @var numeric-string $unitPrice */
            $unitPrice = $poLine->unit_price ?? '0';
            /** @var numeric-string $invoicedValue */
            $invoicedValue = bcmul($poLine->quantity_invoiced, $unitPrice, $working);

            if (bccomp($creditHt, $invoicedValue, $scale) > 0) {
                throw new \DomainException(sprintf(
                    'Supplier credit note [%s] cannot be posted: PO line [%s] over-credit — '
                    .'credit HT %s would exceed the invoiced value %s.',
                    $creditNote->id,
                    $poLine->id,
                    $creditHt,
                    $invoicedValue,
                ));
            }
        }
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
    private function aggregateReturnedQtyPerPoLine(Document $creditNote): array
    {
        /** @var array<string, numeric-string> $agg */
        $agg = [];
        foreach ($creditNote->lines as $line) {
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
