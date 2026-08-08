<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Inventory\Application\DTOs\SupplierGoodsReturnLineData;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\SupplierGoodsReturnLineKind;
use App\Modules\Inventory\Domain\Enums\SupplierGoodsReturnNoteStatus;
use App\Modules\Inventory\Domain\Exceptions\BatchTrackedReturnUnsupportedException;
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
 *  BONUS (free) units — you paid nothing for them, so giving them back should
 *  cost nothing, and the quantity decrement's implied value loss has to be handed
 *  back to the surviving units:
 *
 *      issue(q, unitCost: WAC)                     Q -> Q-q, implied value -q*WAC
 *      recordCostAdjustment(+A)                    spread over the Q-q survivors
 *      => WAC' = WAC + A/(Q-q)
 *
 * THE UN-DILUTION IS BOUNDED — do not remove the cap (gate round 1, C-1)
 *
 * The naive A = q*WAC gives WAC' = WAC*Q/(Q-q) = (original value)/(Q-q), i.e.
 * "the same value, fewer units". That is only right while NOTHING ELSE has left
 * since the bonus receipt. Once units have gone, their share of the dilution left
 * with them through COGS; pushing the whole q*WAC onto the survivors counts it
 * twice, and the error grows without bound as the survivor count shrinks. The
 * gate probed it: a mixed paid+bonus note produced 5.238094 against a true
 * 5.000000 (+4.8%), and a return with one survivor produced 9.523808 (+90%).
 *
 * A perpetual WAC ledger cannot retroactively re-cost the units that already
 * left — that residue is a prior-period COGS understatement and belongs to the
 * GL half (c1-bis) and the periodic-vs-perpetual research lane. What this lane
 * CAN do is refuse to let the at-rest cost exceed what the goods actually cost:
 *
 *      C          = the per-unit price paid on the receipt that brought the units
 *                   in (the receipt line's landed_unit_cost, else the PO line's).
 *                   REQUIRED on every bonus line; a line without it is refused.
 *      survivors  = company owned quantity AFTER the exit
 *      desired    = q * WAC                       the value the exiting units carry
 *      headroom   = max(0, survivors * (C - WAC)) what the survivors can absorb
 *                                                 without exceeding what was paid
 *      A          = min(desired, headroom)        <- applied
 *      forgone    = desired - A                   <- recorded on the note line
 *
 * `A = headroom` lands the WAC exactly on C, which is the true cost of the
 * surviving paid units in every probe shape. `max(0, ...)` makes the correction
 * one-directional: a WAC already at or above C is never raised (conservative —
 * it can under-correct, never inflate). `forgone` is the figure c1-bis needs for
 * the P&L leg this lane must not post; it is never silently absorbed, including
 * the survivors == 0 case where the whole amount is forgone and no adjustment
 * movement exists at all.
 *
 * Worked: 20 paid @ 5.000000 + 1 free blends to 100/21 = 4.761904.
 *   - return the free unit with 20 survivors: desired 4.761904, headroom
 *     20*(5.000000-4.761904) = 4.761920 -> A = 4.761904 -> WAC' = 4.999999.
 *     One ulp under C: the missing 0.000016 was truncated away by the blend's
 *     bcdiv at the free receipt, and the model never restores more value than the
 *     exiting units carry. The round trip is value-neutral, not digit-exact.
 *   - same return with 1 survivor: headroom 0.238096 -> A = 0.238096 ->
 *     WAC' = 5.000000 exactly, forgone 4.523808.
 *
 * The issue movement records `unit_cost = WAC` (NOT 0) deliberately: it keeps the
 * movement ledger's value delta agreeing with the implied `Q x WAC` delta, and
 * keeps every existing reader of `unit_cost` behaving as it did before V8.
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
 * One row lock sits between the advisory acquire and the stock-level locks:
 * `DocumentNumberingService` locks the note's `document_sequences` row FOR UPDATE
 * while stamping the number. That is safe because `supplier_return` is a sequence
 * key nothing else in the codebase touches, so the only contenders for that row
 * are other confirms of this same document type — which are already serialized
 * behind the same advisory locks when they share a product, and are independent
 * when they do not. Do NOT add a second sequence key to this method without
 * re-checking that argument.
 *
 * NOT IN SCOPE (recorded, not silently dropped)
 *
 *  - GL. The credit note's journal entry is unchanged in this lane. Both kinds of
 *    line now carry a units-vs-GL residual for c1-bis: bonus lines by `forgone`
 *    (and by the whole `q x WAC` the GL still expenses), ordinary lines by
 *    `q x (invoice price - WAC)` — the GL plug is priced at the INVOICE, the
 *    units relieve at WAC.
 *  - Batch/lot selection. Batch-tracked products are REFUSED on this path
 *    ({@see BatchTrackedReturnUnsupportedException}) rather than silently
 *    desynchronising `inventory_batch_stock` from `stock_levels`.
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
     * Idempotent per supplier credit note, but NOT forgiving: a second call for
     * the same `$supplierCreditNoteId` returns the existing note only when its
     * lines are IDENTICAL to the request. A differing line set is REFUSED rather
     * than silently reusing the old one (gate round 1, I-4) — the caller has
     * already decremented `quantity_invoiced` / `free_quantity_invoiced` for the
     * NEW quantities by the time it reaches here, so confirming the OLD lines
     * would drift the counters from the units with no error anywhere. The partial
     * unique index on `supplier_credit_note_id` is the hard backstop.
     *
     * @param  list<SupplierGoodsReturnLineData>  $lines
     *
     * @throws \DomainException when the note would carry no lines, a line's
     *                          quantity is not strictly positive, a BONUS line has
     *                          no cost ceiling, or an existing note for this credit
     *                          note carries a different line set.
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

        /** @var list<string> $requestedSignatures */
        $requestedSignatures = [];

        foreach ($lines as $line) {
            if (! is_numeric($line->quantity) || bccomp($line->quantity, '0', self::QUANTITY_SCALE) <= 0) {
                throw new \DomainException(sprintf(
                    'Supplier goods-return note line for PO line [%s] has a non-positive quantity (%s).',
                    $line->poLineId,
                    $line->quantity,
                ));
            }

            $requestedSignatures[] = $this->lineSignature(
                $line->poLineId,
                $line->productId,
                $line->variantId,
                $line->kind,
                $line->quantity,
            );

            // A bonus line's un-dilution MUST be bounded (see the class docblock).
            // Without the price actually paid there is no ceiling, and the only
            // alternative is the unbounded model the gate proved wrong — so refuse
            // rather than fall back to it.
            if ($line->kind->requiresWacUndilution()
                && ($line->unitCostCeiling === null || ! is_numeric($line->unitCostCeiling))) {
                throw new \DomainException(sprintf(
                    'Supplier goods-return note bonus line for PO line [%s] has no unit cost ceiling. '
                    .'The WAC un-dilution must be bounded by the price actually paid for the goods; '
                    .'refusing to apply an unbounded correction to the cost at rest.',
                    $line->poLineId,
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
            $requestedSignatures,
        ): SupplierGoodsReturnNote {
            if ($supplierCreditNoteId !== null) {
                $existing = $this->findForCreditNote($supplierCreditNoteId);
                if ($existing !== null) {
                    $this->assertExistingNoteMatches($existing, $requestedSignatures);

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
                    // A fact about the PAST (what the receipt charged), so it is
                    // captured now and frozen — unlike the exit WAC and location,
                    // which are facts about the moment of confirm.
                    'unit_cost_ceiling' => $line->unitCostCeiling,
                    // Resolved on confirm against live stock, not now: a Draft may
                    // sit for days and the hint can go stale.
                    'location_id' => $line->preferredLocationId,
                    'movement_id' => null,
                    'cost_adjustment_movement_id' => null,
                    'wac_undilution_applied' => null,
                    'wac_undilution_forgone' => null,
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
     * @throws BatchTrackedReturnUnsupportedException when any line's product is
     *                                                batch-tracked (refused up-front, before anything moves).
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

            // Refuse batch-tracked products BEFORE the advisory lock, the number
            // stamp or any stock motion, so the refusal costs nothing and the note
            // is left exactly as it was found.
            $this->assertNoBatchTrackedProducts($locked, $productIds);

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
        $applied = null;
        $forgone = null;

        if ($line->kind->requiresWacUndilution()) {
            [$applied, $forgone] = $this->boundedUndilution($line, $unitCost);

            // `applied` can legitimately be zero: no survivor to capitalize
            // against (the note emptied the company's holding), or a WAC already
            // at/above what was paid. Calling recordCostAdjustment with 0 would
            // write a meaningless quantity-0 movement, so skip it — the
            // wac_undilution_* pair is what records the decision.
            if (bccomp($applied, '0', self::COST_SCALE) > 0) {
                // Runs AFTER issue() so the denominator is the POST-exit owned
                // quantity; that is what makes WAC' land exactly on the intended
                // figure rather than approximately.
                $adjustment = $this->wacService->recordCostAdjustment(
                    product: $product,
                    additionalCost: $applied,
                    reason: sprintf('Bonus goods return %s — WAC un-dilution (bounded)', $noteNumber),
                    tenantId: $note->tenant_id,
                    companyId: $note->company_id,
                    reference: $noteNumber,
                    referenceType: StockMovementReferenceType::SupplierGoodsReturnNote->value,
                    referenceId: $note->id,
                );

                $costAdjustmentMovementId = $adjustment?->id;
            }
        }

        $line->forceFill([
            'unit_cost' => $unitCost,
            'location_id' => $locationId,
            'movement_id' => $movement->id,
            'cost_adjustment_movement_id' => $costAdjustmentMovementId,
            'wac_undilution_applied' => $applied,
            'wac_undilution_forgone' => $forgone,
        ])->save();
    }

    /**
     * How much of the value the exiting bonus units carry may be capitalized back
     * onto the survivors, and how much may not.
     *
     * See the class docblock for the derivation and the gate probes this exists to
     * satisfy. In short: `desired = q x WAC` is only fully restorable while nothing
     * has left since the bonus receipt; beyond that it double-counts dilution that
     * already went out through COGS. The bound is the headroom between the current
     * WAC and the price actually PAID for these goods, so the cost at rest can
     * never end up above what the company paid.
     *
     * Called AFTER the issue, so `companyOwnedQuantity` here is the survivor count —
     * the same denominator `recordCostAdjustment` will divide by, which is what
     * makes `applied == headroom` land the WAC exactly on the ceiling.
     *
     * @param  numeric-string  $unitCost  The WAC the units left at.
     * @return array{numeric-string, numeric-string} [applied, forgone]
     */
    private function boundedUndilution(SupplierGoodsReturnNoteLine $line, string $unitCost): array
    {
        /** @var numeric-string $desired */
        $desired = bcmul((string) $line->quantity, $unitCost, self::COST_SCALE);

        /** @var numeric-string $ceiling */
        $ceiling = (string) ($line->unit_cost_ceiling ?? '0');

        // Survivors = what the company still owns AFTER this line's exit, summed
        // across every location and variant row — the same basis
        // WeightedAverageCostService uses, so the two agree by construction.
        /** @var numeric-string $survivors */
        $survivors = $this->ownedQuantityAfterExit($line);

        /** @var numeric-string $headroomPerUnit */
        $headroomPerUnit = bcsub($ceiling, $unitCost, self::COST_SCALE);

        if (bccomp($survivors, '0', self::QUANTITY_SCALE) <= 0
            || bccomp($headroomPerUnit, '0', self::COST_SCALE) <= 0) {
            // Nothing to capitalize against, or the WAC is already at/above what
            // was paid. One-directional by design: never inflate.
            return ['0.'.str_repeat('0', self::COST_SCALE), $desired];
        }

        /** @var numeric-string $headroom */
        $headroom = bcmul($survivors, $headroomPerUnit, self::COST_SCALE);

        /** @var numeric-string $applied */
        $applied = bccomp($desired, $headroom, self::COST_SCALE) <= 0 ? $desired : $headroom;
        /** @var numeric-string $forgone */
        $forgone = bcsub($desired, $applied, self::COST_SCALE);

        return [$applied, $forgone];
    }

    /**
     * Company-owned quantity for the line's product, read AFTER its exit.
     *
     * Mirrors `WeightedAverageCostService::companyOwnedQuantity`'s basis —
     * on-hand across EVERY stock_level row of the product (all locations, all
     * variant rows), because the WAC is company-wide and product-grain. In-transit
     * quantity is deliberately NOT added here: it is part of the WAC denominator,
     * so leaving it out only ever makes the bound TIGHTER (a smaller survivor
     * count means less headroom), which is the safe direction for a cap.
     *
     * @return numeric-string
     */
    private function ownedQuantityAfterExit(SupplierGoodsReturnNoteLine $line): string
    {
        /** @var numeric-string $owned */
        $owned = '0.0000';

        $levels = StockLevel::query()
            ->where('tenant_id', $line->tenant_id)
            ->where('company_id', $line->company_id)
            ->where('product_id', $line->product_id)
            ->get();

        foreach ($levels as $level) {
            $owned = bcadd($owned, (string) $level->quantity, self::QUANTITY_SCALE);
        }

        return $owned;
    }

    /**
     * Refuse a note whose products are batch-tracked (gate round 1, C-2).
     *
     * @param  list<string>  $productIds
     *
     * @throws BatchTrackedReturnUnsupportedException
     */
    private function assertNoBatchTrackedProducts(SupplierGoodsReturnNote $note, array $productIds): void
    {
        /** @var Product|null $batchTracked */
        $batchTracked = Product::query()
            ->where('tenant_id', $note->tenant_id)
            ->where('company_id', $note->company_id)
            ->whereIn('id', $productIds)
            ->where('requires_batch_tracking', true)
            ->first();

        if ($batchTracked !== null) {
            throw new BatchTrackedReturnUnsupportedException($note->id, (string) $batchTracked->id);
        }
    }

    /**
     * Identity of one requested/stored line, for comparing a lingering Draft
     * against a fresh request. Quantity is normalised through bcadd so '2' and
     * '2.0000' compare equal.
     *
     * @param  numeric-string  $quantity
     */
    private function lineSignature(
        string $poLineId,
        string $productId,
        ?string $variantId,
        SupplierGoodsReturnLineKind $kind,
        string $quantity,
    ): string {
        return implode('|', [
            $poLineId,
            $productId,
            $variantId ?? '-',
            $kind->value,
            bcadd($quantity, '0', self::QUANTITY_SCALE),
        ]);
    }

    /**
     * Refuse to reuse an existing note whose lines differ from the request.
     *
     * Takes the request as pre-computed signatures rather than the DTOs: they are
     * built in `createDraft`'s validation loop, which is the one place the
     * quantities have just been proven numeric.
     *
     * @param  list<string>  $requestedSignatures
     *
     * @throws \DomainException
     */
    private function assertExistingNoteMatches(SupplierGoodsReturnNote $existing, array $requestedSignatures): void
    {
        $existing->loadMissing('lines');

        $current = $existing->lines
            ->map(fn (SupplierGoodsReturnNoteLine $line): string => $this->lineSignature(
                $line->po_line_id,
                $line->product_id,
                $line->variant_id,
                $line->kind,
                $line->quantity,
            ))
            ->sort()
            ->values()
            ->all();

        $requested = $requestedSignatures;
        sort($requested);

        if ($current === $requested) {
            return;
        }

        throw new \DomainException(sprintf(
            'Supplier goods-return note [%s] already exists for credit note [%s] but its line set '
            .'does not match this request. Refusing to confirm stale lines: the caller has already '
            .'adjusted the invoiced counters for the requested quantities, so reusing the old lines '
            .'would silently drift the counters from the units. Resolve the existing note first.',
            $existing->id,
            $existing->supplier_credit_note_id ?? 'null',
        ));
    }

    /**
     * Where the units physically leave from.
     *
     * Selection runs on AVAILABLE quantity (`quantity - reserved`), not raw
     * quantity (gate round 1, I-2). `StockAdjustmentService::issue()` refuses
     * against `getAvailableQuantity()`, so picking the largest RAW row could
     * choose a fully reserved location and fail the whole return while another
     * location could have covered it. Reserved units are promised to a customer
     * order; a supplier return does not get to consume them.
     *
     * 1. the line's hint (the goods receipt's destination), IF it can cover the
     *    line — the honest answer when it is available;
     * 2. otherwise the location with the largest AVAILABLE quantity that can cover
     *    the whole line;
     * 3. otherwise the location with the largest AVAILABLE quantity, so
     *    `issue()` raises the canonical InsufficientStockException with real
     *    numbers instead of this method inventing its own error;
     * 4. otherwise REFUSE. The pre-V8 path threw "no stock level is available"
     *    here and so does this one — a return that cannot say where the goods left
     *    from is not a return.
     */
    private function resolveExitLocation(SupplierGoodsReturnNote $note, SupplierGoodsReturnNoteLine $line): string
    {
        /** @var numeric-string $needed */
        $needed = (string) $line->quantity;

        /** @var list<StockLevel> $levels */
        $levels = $this->stockLevelQuery($note, $line)->get()->all();

        /** @var array<int, array{id: string, available: numeric-string}> $candidates */
        $candidates = [];
        foreach ($levels as $level) {
            /** @var numeric-string $available */
            $available = $level->getAvailableQuantity();
            if (bccomp($available, '0', self::QUANTITY_SCALE) <= 0) {
                continue;
            }
            $candidates[] = ['id' => (string) $level->location_id, 'available' => $available];
        }

        usort(
            $candidates,
            static fn (array $a, array $b): int => bccomp($b['available'], $a['available'], self::QUANTITY_SCALE),
        );

        $hint = $line->location_id;
        foreach ($candidates as $candidate) {
            if ($candidate['id'] === $hint && bccomp($candidate['available'], $needed, self::QUANTITY_SCALE) >= 0) {
                return $hint;
            }
        }

        foreach ($candidates as $candidate) {
            if (bccomp($candidate['available'], $needed, self::QUANTITY_SCALE) >= 0) {
                return $candidate['id'];
            }
        }

        $fallback = $candidates[0] ?? null;

        if ($fallback === null) {
            throw new \DomainException(sprintf(
                'Supplier goods-return note [%s] line [%s]: no stock location holds product [%s]; '
                .'cannot return units that are not on hand.',
                $note->id,
                $line->id,
                $line->product_id,
            ));
        }

        return $fallback['id'];
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
