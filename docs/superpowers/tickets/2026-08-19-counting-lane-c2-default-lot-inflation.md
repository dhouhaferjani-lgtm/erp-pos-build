# T22 — C2 residual disposition: DEFAULT-lot inflation on the counting lane

**Source:** DPA Wave 3D M5, task **T22**. The wave plan's ruling is **RE-TICKET, do not absorb**; the
deliverable for T22 is this file, not code (`plan-wave3.md` T22; brief §"M5 — 3D listener": *"T22 is a
ticket file, not code — the deliverable is the file, citing both source files"*). Registered against
open question **OQ-7**.

**Severity:** P2 — a real FEFO/lot-accounting divergence, but bounded to batch-tracked products
counted through the counting lane, and with no consequence for Wave 3's GL leg (see "Why this does
not block T21").

**Status:** OPEN — deliberately not implemented in Wave 3.

**Owner:** Inventory (counting + batch/FEFO).

## The two source files

1. `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php`
   — `postAdjustmentWithinLock()` at **:905**, whose `bool $deltaBasedDefaultLot` parameter
   (**:921**) selects the lot-reconciliation strategy. `adjustByDelta()` — the `stock_adjustments`
   DOCUMENT path — passes `true` (**:859**) and gets the C2 fix. `adjust()` — the COUNTING path
   driven by `ApplyStockAdjustmentsOnCountingCompleted` — passes `false` (**:729**) and keeps
   TARGET-based reconciliation. The contradiction and its settlement are recorded in the method's own
   docblock at **:886**.

2. `apps/api/tests/Feature/Inventory/InventoryCountingDefaultBatchTest.php`
   — the shipped counting invariant this pins, asserted at **:130-131**: counting a batch-tracked
   line from an aggregate of 5 with zero lots up to 7 must leave the DEFAULT lot holding **7**, not
   the delta of 2. Delta-based reconciliation on the counting path would leave 5 units outside any
   lot.

## The residual

C2 (DEFAULT-lot inflation) is fully closed for the adjustment DOCUMENT — "the only manual route" C2
is actually about. It remains OPEN for the counting lane, because the counting lane's shipped
invariant is target-based: `ensureDefaultBatchForImplicitPositiveStock()` tops the DEFAULT lot up to
the post-update AGGREGATE. On a product that already holds real lots, a positive counting correction
therefore inflates DEFAULT to the whole aggregate, so `Σ lots > aggregate`.

## The ruling: re-ticket, do not absorb

Two independent reasons, both recorded at ruling time:

1. **Changing the counting path to delta-based would break a shipped counting invariant** — the
   assertion at `InventoryCountingDefaultBatchTest.php:130-131`. That is a behaviour change to a
   lane Wave 3 was not chartered to redesign, and it needs its own spec (what SHOULD a count do to
   lots when the counted aggregate disagrees with Σ lots? "top up DEFAULT", "distribute FEFO", and
   "flag for manual lot reconciliation" are all defensible, and the answer is a product decision).

2. **Wave 3's GL leg does not depend on the answer.** T21 derives its direction from
   `quantity_before`/`quantity_after` on the aggregate row (D-5, `StockMovement::directionForRow()`
   / `absoluteDeltaForRow()`), which is correct under BOTH lot strategies. So G2 is unaffected
   whichever way C2 is later resolved, and resolving C2 inside Wave 3 would buy the wave nothing
   while risking a shipped invariant.

## Why this does not block T21

T21 values the count correction on the movement row's own persisted `unit_cost` and posts one
direction-derived shrinkage/gain entry per movement. It never reads lot state. A DEFAULT lot that is
over-topped changes `inventory_batch_stock`, not `stock_levels.quantity` or the movement's
before/after pair, so the journal entry is identical either way.

## Acceptance for the future slice

1. Decide and write down the counting lane's lot-reconciliation semantics for a positive correction
   on a product that already holds real lots — this is a product decision, not an implementation
   detail.
2. Whichever semantics wins, `Σ lots == aggregate` must hold after a counting correction on a
   batch-tracked product. Assert it directly.
3. Re-derive `InventoryCountingDefaultBatchTest.php:130-131` from the new semantics rather than
   deleting it; if the expectation changes, the commit must say why the old one was wrong.
4. Cover the negative direction too: today a negative counting correction on the legacy absolute
   path moves no lot at all, which is the same `Σ lots > aggregate` defect from the other side
   (`StockAdjustmentService.php:989-991`).
5. Run on PostgreSQL.
