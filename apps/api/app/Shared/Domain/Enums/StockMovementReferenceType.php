<?php

declare(strict_types=1);

namespace App\Shared\Domain\Enums;

/**
 * Canonical vocabulary for `stock_movements.reference_type` — the morph type of
 * the document that justifies a stock movement (document-per-action, DPA S0).
 *
 * WHY AN ENUM, AND WHY HERE
 *
 * `reference_type` is a type column, so CLAUDE rule 9 (enums for all status/type
 * columns) applies. Two bare `?string` parameters would leave the vocabulary
 * unrepresentable in the type system, and the column already carries three
 * coexisting conventions written by pre-DPA code — fully-qualified class names
 * (`StockTransfer::class`), short class names (`'Document'`) and snake codes
 * (`'pos_receipt'`). Every adopting lane picking its own string is how that
 * happened; this enum is the fix going forward.
 *
 * It lives in `Shared\Domain\Enums` (alongside VarianceDirection, which Inventory
 * defines and POS consumes) rather than in `Inventory\Domain\Enums` precisely so
 * the adopting lanes in Procurement and POS can name a reference type WITHOUT
 * importing an Inventory model class — CLAUDE rule 6 forbids direct cross-module
 * model imports, and the FQCN convention (`referenceType: InventoryCounting::class`)
 * would have required exactly that.
 *
 * ADDING A CASE
 *
 * Cases are added by the lane that adopts the seam, one at a time. Deliberately
 * NOT enumerated here: the pre-existing writers that bypass
 * StockAdjustmentService entirely (POS projections/services, StockTransferService's
 * post-hoc UPDATE, OpeningBalancePostingService). Those are recorded at the
 * program level and belong to their own lanes — minting cases for them now would
 * bless strings this seam does not actually write.
 */
enum StockMovementReferenceType: string
{
    /**
     * A row in the unified `documents` table (goods receipt, delivery note,
     * return note, supplier credit note, …).
     *
     * The backing value is the SHORT class name, not the FQCN, because existing
     * readers query it literally — `LinkedCostApplicationService` and
     * `BackfillGoodsReceiptsCommand` both do `->where('reference_type', 'Document')`,
     * and it is what every WeightedAverageCostService caller already writes.
     * Changing it would silently break those lookups.
     */
    case Document = 'Document';

    /**
     * An `inventory_countings` row — the counting document whose finalize posted
     * the movement (count correction or onboarding opening).
     */
    case InventoryCounting = 'inventory_counting';

    /**
     * A `pos_receipts` RETURN receipt whose line carried `disposition = scrap`
     * — the document that justifies DESTROYING the returned goods (DPA V10).
     *
     * The return receipt itself only justifies the RE-ENTRY leg (`+qty`,
     * `MovementReason::POSReturn`, still written raw by the POS return/refund
     * paths — their own lane). The scrap leg is a second, economically distinct
     * act (`−qty`, `MovementReason::WriteOff`, cost-bearing, GL-posted), so it
     * gets its own reference type rather than sharing the re-entry leg's.
     *
     * The backing value is the string the pre-DPA raw write-off already wrote
     * (`ReceiptReturnService::writeOffReturnedStock()`); it is preserved
     * verbatim so historical rows and this seam share one vocabulary and no
     * existing reader has to be migrated.
     */
    case PosReceiptReturnScrap = 'pos_receipt_return_scrap';

    /**
     * A `stock_adjustments` row — the manual stock-correction document that
     * replaced the four raw `POST /stock-movements/*` writers (DPA V7, D2).
     *
     * Load-bearing beyond display: `FirstCountDetector`'s supply-side baseline
     * conjoins this value (D2a), which is what keeps the baseline extension
     * forward-only — every legacy manual `adjustment_positive` row carries
     * `reference_type IS NULL`, because today's `adjust()` passes no reference
     * type and `recordMovement()` persists `$referenceType?->value`.
     */
    case StockAdjustment = 'stock_adjustment';

    /**
     * A `supplier_goods_return_notes` row — the document that justifies units
     * going back to a supplier (DPA lane V8). Both the Issue movement and, for
     * bonus lines, the quantity-neutral WAC un-dilution adjustment carry it.
     *
     * Before V8 these movements were stamped `Document` + the supplier CREDIT
     * NOTE's id: an AP money document standing in for a stock document. This
     * case is what replaces that.
     */
    case SupplierGoodsReturnNote = 'supplier_goods_return_note';

    /**
     * A run of `inventory:repair-phantom-default-batches` — the maintenance
     * operation that recomputes a phantom `DEFAULT` lot down to its untracked
     * remainder (campaign W2-7).
     *
     * `reference_id` is the RUN id, not a document id: the repair is a ledger
     * reconciliation with no document behind it, and grouping every lot the run
     * corrected under one id is what lets an operator pull the whole batch back
     * out of `stock_movements`. The movement it stamps carries `quantity = 0`
     * with `quantity_before === quantity_after`, because the AGGREGATE on-hand
     * quantity is not changing — only the double-booked lot ledger is.
     *
     * The backing value is the string the lane's first draft wrote as a raw
     * constant, preserved verbatim so nothing has to be migrated.
     */
    case BatchLedgerRepair = 'batch_ledger_repair';
}
