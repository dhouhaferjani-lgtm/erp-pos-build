<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Inventory\Application\DTOs\SupplierGoodsReturnLineData;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\SupplierGoodsReturnLineKind;
use App\Modules\Inventory\Domain\Enums\SupplierGoodsReturnNoteStatus;
use App\Modules\Inventory\Domain\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Domain\Services\ProductCostLock;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\SupplierGoodsReturnNote;
use App\Modules\Inventory\Domain\SupplierGoodsReturnNoteLine;
use App\Modules\Product\Domain\Product;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Supplier goods-return note lifecycle (DPA lane V8).
 *
 * THE VIOLATION THIS REPLACES
 *
 * `SupplierCreditNotePostingService::issueBonusReturnStock()` used to raw-write a
 * `stock_levels` decrement and an `Issue` `stock_movements` row inline, on a MONEY
 * document's lifecycle, and stamp `avg_cost_before = avg_cost_after = WAC` —
 * asserting that giving back a ZERO-COST bonus unit leaves the weighted average
 * cost untouched. It does not. Free units enter through
 * `GoodsReceiptService` -> `recordPurchase(landedUnitCost: '0')`, which DILUTES
 * the WAC. Handing one back must UN-dilute it, and the old row asserted the exact
 * opposite in a column drift detectors read. Meanwhile ORDINARY return lines moved
 * no units at all: one credit-note reason, two lane behaviours.
 *
 * THE COSTING MODEL (this is the load-bearing part — read it before editing)
 *
 * Inventory value is implied by `company owned quantity x product.cost_price`.
 * Decrementing quantity by q therefore always destroys q x WAC of implied value,
 * whatever unit cost the movement records. So:
 *
 *  ORDINARY (paid) units — value SHOULD fall by q x WAC. `issue(unitCost: WAC)`
 *  is the whole story; the WAC itself does not move. Correct by construction.
 *
 *  BONUS (free) units — value should NOT fall at all: you paid nothing for them,
 *  so giving them back costs nothing. The quantity decrement's implied
 *  value loss has to be handed back to the surviving units:
 *
 *      issue(q, unitCost: WAC)                     Q -> Q-q, implied value -q*WAC
 *      recordCostAdjustment(+q*WAC)                spread over the Q-q survivors
 *      => WAC' = WAC + q*WAC/(Q-q) = WAC*Q/(Q-q) = (original value)/(Q-q)
 *
 *  which is exactly "the same value, fewer units" — the WAC RISES to what the
 *  paid units actually cost. Worked example: 20 paid @ 5.000 + 1 free blends to
 *  100/21 = 4.761904; returning the free unit gives 4.761904 + 4.761904/20 =
 *  4.999999 over 20 units.
 *
 *  The issue movement records `unit_cost = WAC` (NOT 0) deliberately: it keeps the
 *  movement ledger's value delta (-q*WAC then +q*WAC = 0) agreeing with the
 *  implied `Q x WAC` delta, and keeps every existing reader of `unit_cost`
 *  behaving as it did before V8.
 *
 * WHY THESE TWO PRIMITIVES
 *
 * `StockAdjustmentService::issue()` is the S0-seam-aware, cost-locked single
 * writer of an outbound movement, and it is the only one that threads document
 * linkage (`referenceType`/`referenceId`) onto the row. `recordCostAdjustment()`
 * is the WAC engine's documented entry point for "supplier rebates, duty
 * adjustments, write-downs, or any other WAC-affecting event that does not change
 * on-hand quantity". Nothing here writes `stock_levels` or `products.cost_price`
 * directly.
 *
 * LOCK ORDER
 *
 * The per-product advisory lock is taken FIRST, for every product in the note, in
 * sorted order, before any row lock — so the canonical
 * `advisory -> stock_level rows -> product row` order holds across the whole
 * confirm. `issue()` deliberately takes no advisory lock of its own (it is a pure
 * decrement); `recordCostAdjustment()` re-acquires the same transaction-scoped
 * advisory lock, which is re-entrant.
 *
 * NOT IN SCOPE (recorded, not silently dropped)
 *
 *  - GL. The credit note's journal entry is unchanged in this lane; the
 *    Dr PurchaseExpenses / Cr Inventory legs a bonus return still posts are now
 *    inconsistent with the units (which preserve value) and are c1-bis's to fix.
 *  - Batch/lot selection. The old raw path ignored `inventory_batch_stock`
 *    entirely and so does this one (`batchId: null`) — not a regression, but a
 *    batch-tracked product's lot ledger will not follow the return.
 *  - Cancelling a CONFIRMED note (needs its own reversing document).
 */
final class SupplierGoodsReturnNoteService
{
    /**
     * Canonical stock quantity scale — matches `decimal(15,4)` on stock_levels /
     * stock_movements and the `decimal:4` cast on the note line.
     */
    private const int QUANTITY_SCALE = 4;

    /**
     * Internal at-rest cost precision, mirroring
     * WeightedAverageCostService::COST_SCALE.
     */
    private const int COST_SCALE = 6;

    /**
     * `document_sequences` key + prefix for the note number (SGR-YYYY-NNNN),
     * mirroring GoodsReceiptService's `goods_receipt` / `GRN`.
     *
     * MUST fit `document_sequences.type varchar(20)` (2025_11_30_080002:16). The
     * obvious `supplier_goods_return_note` (26 chars) is green on the SQLite test
     * runner and throws SQLSTATE[22001] on every confirm against PostgreSQL — a
     * live PG run caught it. 15 chars, with room to spare.
     */
    private const string SEQUENCE_KEY = 'supplier_return';

    private const string SEQUENCE_PREFIX = 'SGR';

    public function __construct(
        private readonly StockAdjustmentService $stockAdjustmentService,
        private readonly WeightedAverageCostService $wacService,
        private readonly ProductCostLock $costLock,
        private readonly DocumentNumberingService $numberingService,
    ) {}

    /**
     * Mint a Draft note. NOTHING moves here — a Draft states intent only.
     *
     * Idempotent per supplier credit note: a second call for the same
     * `$supplierCreditNoteId` returns the existing note untouched rather than
     * minting a second document (the partial unique index on
     * `supplier_credit_note_id` is the hard backstop).
     *
     * @param  list<SupplierGoodsReturnLineData>  $lines
     *
     * @throws \DomainException when the note would carry no lines, or a line's
     *                          quantity is not strictly positive.
     */
    public function createDraft(
        string $tenantId,
        string $companyId,
        ?string $partnerId,
        ?string $supplierCreditNoteId,
        ?string $reference,
        array $lines,
        ?string $actorId = null,
    ): SupplierGoodsReturnNote {
        if ($lines === []) {
            throw new \DomainException(
                'A supplier goods-return note must carry at least one line; refusing to mint an empty document.'
            );
        }

        foreach ($lines as $line) {
            if (! is_numeric($line->quantity) || bccomp($line->quantity, '0', self::QUANTITY_SCALE) <= 0) {
                throw new \DomainException(sprintf(
                    'Supplier goods-return note line for PO line [%s] has a non-positive quantity (%s).',
                    $line->poLineId,
                    $line->quantity,
                ));
            }
        }

        return DB::transaction(function () use (
            $tenantId,
            $companyId,
            $partnerId,
            $supplierCreditNoteId,
            $reference,
            $lines,
            $actorId,
        ): SupplierGoodsReturnNote {
            if ($supplierCreditNoteId !== null) {
                $existing = $this->findForCreditNote($supplierCreditNoteId);
                if ($existing !== null) {
                    return $existing;
                }
            }

            $note = SupplierGoodsReturnNote::create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'supplier_credit_note_id' => $supplierCreditNoteId,
                'partner_id' => $partnerId,
                'note_number' => null,
                'status' => SupplierGoodsReturnNoteStatus::Draft,
                'location_id' => null,
                'reference' => $reference,
                'returned_at' => null,
                'created_by' => $actorId,
                'confirmed_by' => null,
                'notes' => null,
            ]);

            foreach ($lines as $line) {
                SupplierGoodsReturnNoteLine::create([
                    'tenant_id' => $tenantId,
                    'company_id' => $companyId,
                    'supplier_goods_return_note_id' => $note->id,
                    'po_line_id' => $line->poLineId,
                    'goods_receipt_id' => $line->goodsReceiptId,
                    'goods_receipt_line_id' => $line->goodsReceiptLineId,
                    'product_id' => $line->productId,
                    'variant_id' => $line->variantId,
                    'kind' => $line->kind,
                    'quantity' => $line->quantity,
                    'unit_cost' => null,
                    // Resolved on confirm against live stock, not now: a Draft may
                    // sit for days and the hint can go stale.
                    'location_id' => $line->preferredLocationId,
                    'movement_id' => null,
                    'cost_adjustment_movement_id' => null,
                ]);
            }

            /** @var SupplierGoodsReturnNote $fresh */
            $fresh = $note->fresh(['lines']);

            return $fresh;
        });
    }

    /**
     * Confirm the note: the units leave, once, through the S0 seam.
     *
     * Idempotent — a note that is already Confirmed is returned untouched (its
     * movements exist; re-issuing would double-decrement).
     *
     * @throws \DomainException when no stock-bearing location can be resolved for
     *                          a line, or a product's WAC is non-numeric.
     * @throws InsufficientStockException
     *                                    when the resolved location cannot cover the line
     *                                    (rolls the whole confirm back — the note stays Draft).
     */
    public function confirm(SupplierGoodsReturnNote $note, ?string $actorId = null): SupplierGoodsReturnNote
    {
        return DB::transaction(function () use ($note, $actorId): SupplierGoodsReturnNote {
            /** @var SupplierGoodsReturnNote $locked */
            $locked = SupplierGoodsReturnNote::query()
                ->whereKey($note->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === SupplierGoodsReturnNoteStatus::Confirmed) {
                /** @var SupplierGoodsReturnNote $fresh */
                $fresh = $locked->fresh(['lines']);

                return $fresh;
            }

            if (! $locked->status->canBeConfirmed()) {
                throw new \DomainException(sprintf(
                    'Supplier goods-return note [%s] cannot be confirmed: status is [%s].',
                    $locked->id,
                    $locked->status->value,
                ));
            }

            $locked->load('lines');

            /** @var list<string> $productIds */
            $productIds = $locked->lines
                ->pluck('product_id')
                ->unique()
                ->values()
                ->all();

            return $this->costLock->acquire(
                $locked->tenant_id,
                $locked->company_id,
                $productIds,
                function () use ($locked, $actorId): SupplierGoodsReturnNote {
                    $noteNumber = $this->numberingService->generateForKey(
                        $locked->tenant_id,
                        $locked->company_id,
                        self::SEQUENCE_KEY,
                        self::SEQUENCE_PREFIX,
                    );
                    $locked->forceFill([
                        'note_number' => $noteNumber,
                        'status' => SupplierGoodsReturnNoteStatus::Confirmed,
                        'returned_at' => now(),
                        'confirmed_by' => $actorId ?? $locked->confirmed_by,
                    ])->save();

                    $firstLocationId = null;

                    /** @var SupplierGoodsReturnNoteLine $line */
                    foreach ($locked->lines as $line) {
                        $this->issueLine($locked, $line, $noteNumber, $actorId);
                        $firstLocationId ??= $line->location_id;
                    }

                    if ($locked->location_id === null && $firstLocationId !== null) {
                        $locked->forceFill(['location_id' => $firstLocationId])->save();
                    }

                    /** @var SupplierGoodsReturnNote $fresh */
                    $fresh = $locked->fresh(['lines']);

                    return $fresh;
                },
            );
        });
    }

    /**
     * The confirmed note's Bonus-line inventory value, at COST_SCALE.
     *
     * This is the figure the supplier credit note's GL hook consumes
     * (`createSupplierCreditNoteEntryWithBonusReturn`). It is computed from the
     * note's own stamped `unit_cost` x `quantity`, which reproduces the pre-V8
     * `bcmul($qty, $product->cost_price, 6)` exactly — the GL model is unchanged
     * in this lane by design (see the class docblock).
     *
     * @return numeric-string
     */
    public function bonusInventoryValue(SupplierGoodsReturnNote $note): string
    {
        $note->loadMissing('lines');

        /** @var numeric-string $value */
        $value = '0.000000';

        /** @var SupplierGoodsReturnNoteLine $line */
        foreach ($note->lines as $line) {
            if ($line->kind !== SupplierGoodsReturnLineKind::Bonus) {
                continue;
            }

            /** @var numeric-string $unitCost */
            $unitCost = (string) ($line->unit_cost ?? '0');
            $value = bcadd($value, bcmul((string) $line->quantity, $unitCost, self::COST_SCALE), self::COST_SCALE);
        }

        return $value;
    }

    public function findForCreditNote(string $supplierCreditNoteId): ?SupplierGoodsReturnNote
    {
        /** @var SupplierGoodsReturnNote|null $note */
        $note = SupplierGoodsReturnNote::query()
            ->where('supplier_credit_note_id', $supplierCreditNoteId)
            ->with('lines')
            ->first();

        return $note;
    }

    /**
     * Issue one line's units and, for a bonus line, un-dilute the WAC.
     */
    private function issueLine(
        SupplierGoodsReturnNote $note,
        SupplierGoodsReturnNoteLine $line,
        string $noteNumber,
        ?string $actorId,
    ): void {
        // Read UNLOCKED on purpose. issue() row-locks the stock_level and
        // recordCostAdjustment() locks the product row itself, both in the
        // canonical advisory -> stock_level -> product order. Taking a product row
        // lock here would invert that order and deadlock a concurrent recompute —
        // the same reasoning GoodsReceiptService::processReceiptLines documents at
        // its own unlocked Product::find().
        /** @var Product|null $product */
        $product = Product::query()
            ->where('tenant_id', $note->tenant_id)
            ->where('company_id', $note->company_id)
            ->find($line->product_id);

        if ($product === null) {
            throw new \DomainException(sprintf(
                'Supplier goods-return note [%s] line [%s]: product [%s] does not belong to this company.',
                $note->id,
                $line->id,
                $line->product_id,
            ));
        }

        $rawUnitCost = $product->cost_price;
        if (! is_numeric($rawUnitCost)) {
            throw new \DomainException(sprintf(
                'Supplier goods-return note [%s] line [%s]: product [%s] has a non-numeric WAC cost.',
                $note->id,
                $line->id,
                $line->product_id,
            ));
        }

        /** @var numeric-string $unitCost */
        $unitCost = bcadd((string) $rawUnitCost, '0', self::COST_SCALE);

        $locationId = $this->resolveExitLocation($note, $line);

        $movement = $this->stockAdjustmentService->issue(
            productId: $line->product_id,
            locationId: $locationId,
            quantity: (string) $line->quantity,
            reference: $noteNumber,
            userId: $actorId,
            batchId: null,
            expectedCompanyId: $note->company_id,
            variantId: $line->variant_id,
            reason: MovementReason::SupplierReturn,
            unitCost: $unitCost,
            referenceType: StockMovementReferenceType::SupplierGoodsReturnNote,
            referenceId: $note->id,
        );

        $costAdjustmentMovementId = null;

        if ($line->kind->requiresWacUndilution()) {
            // Restore the implied value the quantity decrement destroyed, spread
            // over the units that survive it. Run AFTER issue() so the
            // denominator is the post-exit owned quantity — that is what makes
            // WAC' = value/(Q-q) exact rather than approximate.
            //
            // Returns null when nothing is left to capitalize against (the note
            // emptied the company's holding of this product): the WAC of an empty
            // bucket is undefined, and the WAC engine documents that no-op. The
            // next receipt re-establishes the cost basis.
            /** @var numeric-string $undilutionValue */
            $undilutionValue = bcmul((string) $line->quantity, $unitCost, self::COST_SCALE);

            $adjustment = $this->wacService->recordCostAdjustment(
                product: $product,
                additionalCost: $undilutionValue,
                reason: sprintf('Bonus goods return %s — WAC un-dilution', $noteNumber),
                tenantId: $note->tenant_id,
                companyId: $note->company_id,
                reference: $noteNumber,
                referenceType: StockMovementReferenceType::SupplierGoodsReturnNote->value,
                referenceId: $note->id,
            );

            $costAdjustmentMovementId = $adjustment?->id;
        }

        $line->forceFill([
            'unit_cost' => $unitCost,
            'location_id' => $locationId,
            'movement_id' => $movement->id,
            'cost_adjustment_movement_id' => $costAdjustmentMovementId,
        ])->save();
    }

    /**
     * Where the units physically leave from.
     *
     * 1. the line's hint (the goods receipt's destination), IF it still holds
     *    stock — the honest answer when it is available;
     * 2. otherwise the company's largest stock-bearing location for this
     *    product/variant. This is byte-for-byte the pre-V8 raw path's choice
     *    (`orderByDesc('quantity')->first()` over rows with `quantity > 0`), so
     *    legacy data keeps behaving as it did;
     * 3. otherwise REFUSE. The old path threw "no stock level is available" here
     *    and so does this one — a return that cannot say where the goods left from
     *    is not a return.
     */
    private function resolveExitLocation(SupplierGoodsReturnNote $note, SupplierGoodsReturnNoteLine $line): string
    {
        $hint = $line->location_id;
        if ($hint !== null && $this->locationHoldsStock($note, $line, $hint)) {
            return $hint;
        }

        /** @var StockLevel|null $fallback */
        $fallback = $this->stockLevelQuery($note, $line)
            ->where('quantity', '>', '0')
            ->orderByDesc('quantity')
            ->first();

        if ($fallback === null) {
            throw new \DomainException(sprintf(
                'Supplier goods-return note [%s] line [%s]: no stock location holds product [%s]; '
                .'cannot return units that are not on hand.',
                $note->id,
                $line->id,
                $line->product_id,
            ));
        }

        return (string) $fallback->location_id;
    }

    private function locationHoldsStock(
        SupplierGoodsReturnNote $note,
        SupplierGoodsReturnNoteLine $line,
        string $locationId,
    ): bool {
        return $this->stockLevelQuery($note, $line)
            ->where('location_id', $locationId)
            ->where('quantity', '>', '0')
            ->exists();
    }

    /**
     * @return Builder<StockLevel>
     */
    private function stockLevelQuery(
        SupplierGoodsReturnNote $note,
        SupplierGoodsReturnNoteLine $line,
    ): Builder {
        $query = StockLevel::query()
            ->where('tenant_id', $note->tenant_id)
            ->where('company_id', $note->company_id)
            ->where('product_id', $line->product_id);

        if ($line->variant_id === null) {
            $query->whereNull('variant_id');
        } else {
            $query->where('variant_id', $line->variant_id);
        }

        return $query;
    }
}
