<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use DomainException;

/**
 * Thrown when a supplier goods-return note would move units of a BATCH-TRACKED
 * product (DPA lane V8, gate round 1 finding C-2).
 *
 * The goods-return path issues aggregate stock through
 * `StockAdjustmentService::issue(batchId: null)`, so `stock_levels.quantity`
 * would drop while `inventory_batch_stock` stayed put — an aggregate-vs-lot
 * divergence with no way to tell later which lot went back to the supplier.
 *
 * Refusing rather than guessing is the house convention on every other
 * batch-aware write:
 *   - `GoodsReceiptService.php` throws "Batch data is required for batch-tracked
 *     product" on the inbound leg;
 *   - `StockTransferService` requires explicit batch allocations before it will
 *     move a batch-tracked line;
 *   - `StockAdjustmentService::ensureDefaultBatchForImplicitPositiveStock` exists
 *     purely to keep the two ledgers in step.
 *
 * It matters most where it is least visible: in a parapharmacy tenant every
 * product is batch-tracked, so a silent skip would corrupt the lot ledger on
 * EVERY supplier return. Note that for ORDINARY return lines this is not even a
 * pre-existing gap being preserved — those lines moved no stock at all before V8,
 * so V8 is what would introduce the desync.
 *
 * Lot selection (FEFO or explicit allocation, mirroring
 * `StockTransferLineBatchAllocation`) is the recorded follow-up that lifts this
 * refusal; `supplier_goods_return_note_lines` already has room to carry it.
 *
 * Extends DomainException so bootstrap/app.php maps it to a 422 BUSINESS_ERROR,
 * matching the other Inventory domain refusals.
 */
final class BatchTrackedReturnUnsupportedException extends DomainException
{
    public function __construct(
        public readonly string $noteId,
        public readonly string $productId,
    ) {
        parent::__construct(sprintf(
            'Supplier goods-return note [%s] cannot move product [%s]: it is batch-tracked, and '
            .'returning units to a supplier without lot selection would decrement aggregate stock '
            .'while leaving the batch ledger untouched. Lot selection for supplier returns is not '
            .'implemented yet; refusing rather than silently desynchronising the two ledgers.',
            $noteId,
            $productId,
        ));
    }
}
