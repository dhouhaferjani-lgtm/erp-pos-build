# Stock-adjustment documents need one accounting outcome for every line

**Severity:** HIGH — known document-per-action violation (D-20).
**Owner:** Inventory + Accounting architecture owner.
**Deadline:** immediate follow-on after Wave 3, before broad production use of
non-batch `stock_adjustments` as an operator correction lane.

## Problem

A single posted `stock_adjustments` document currently has two accounting
outcomes selected by `products.requires_batch_tracking`:

- batch-tracked damage/write-off lines route through `BatchWriteOffService` and
  post a `batch_write_off` journal entry;
- non-batch lines route through `StockAdjustmentService::adjustByDelta()` and
  move inventory with no GL leg.

Wave 3D threads `unit_cost` onto the latter movement so its value is observable,
but deliberately excludes the stock-adjustment document path from the Wave 3C
buffer. `MovementReason::requiresGLEntry()` is not the control; the no-GL result
is a wiring decision. D-b and D-e therefore exclude
`reference_type = 'stock_adjustment'` until this ticket closes.

## Required resolution

Choose the adjustment gain/loss purposes with Accounting, route the non-batch
document writer into the inventory-GL seam at a real root tail, and prove one
document cannot mix posted and unposted accounting outcomes. Revisit both D-b
and D-e exclusions only in the same change. Preserve the posted document and
movement audit chains; remediation uses compensating entries, never deletion.

