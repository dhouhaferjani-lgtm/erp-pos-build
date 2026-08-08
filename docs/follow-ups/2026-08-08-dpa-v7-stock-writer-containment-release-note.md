# DPA V7 — stock-writer containment: release note & follow-ups

**Lane:** document-per-action remediation, register V7.
**Plan:** `.superpowers/sdd/HANDOVER-document-per-action-remediation-2026-08-08/plan-v7.md`.

## What changed

The four raw stock writers — `POST /api/v1/stock-movements/{receive,issue,transfer,adjust}` — are
**deleted**. They wrote unjustified signed stock deltas (`reason = NULL`), justified only by a
browser-synthesised label, and `adjust` took an ABSOLUTE `new_quantity` that silently overwrote
anything committed between the browser read and the POST. `GET /api/v1/stock-movements` and the
entry/exit notes are untouched.

Every manual stock mutation now carries its own justifying document:

| Removed | Replacement |
|---|---|
| `POST /stock-movements/adjust` | the new `stock_adjustments` document |
| `POST /stock-movements/transfer` | `POST /stock-transfers` (already shipped) |
| `POST /stock-movements/receive` | `POST /goods-receipts/standalone` when supplier-sourced and priced; otherwise a positive `stock_adjustments` line |
| `POST /stock-movements/issue` | the delivery note when partner-bound, `POST /batches/{uuid}/write-off` when lot-identified; otherwise a negative `stock_adjustments` line |

## Behaviour deltas operators and integrators will notice

1. **Movement-type reclassification.** A manual receive/issue used to write
   `MovementType::Receipt` / `Issue`; through the document it writes `MovementType::Adjustment`.
   Direction is unaffected everywhere it matters — it is derived from the row's signed delta
   (`StockMovement::directionForRow()`, `EntryExitNoteController::directionSql()`,
   `MovementReplayService::signedDelta()`), never from the type. The one **cosmetic** consequence is
   the movement-list filter tabs, which group by `movement_type`: manual entries now appear under
   "Adjustment" rather than "Receipt"/"Issue".

2. **`opening_balance` is gone from the manual reason list**, and the orphaned locale key
   `inventory:stock.reasons.openingBalance` is deleted with it. Selecting it produced a WRONG opening
   balance: it wrote `MovementType::Adjustment` with no WAC basis, no enter-once guard and no GL leg.
   A real opening balance is `MovementType::Opening` via `OpeningBalancePostingService`, reachable
   from the product editor's `opening_qty` / `opening_unit_cost` fields
   (`/inventory/products/{id}/edit#section-inventory`).

3. **Manual entries are no longer landed-cost or GR-backfill candidates.**
   `LinkedCostApplicationService` selects candidates by `movement_type IN ('receipt','issue')` and
   `BackfillGoodsReceiptsCommand` by `'receipt'`. This is desirable — landed cost on a found-stock
   correction is meaningless — but it IS a behaviour delta on a cost-allocation path, so it is stated
   rather than discovered.

4. **`damage` / `write_off` on a batch-tracked product now route to the batch write-off.** The
   adjustment document does not post GL in v1, while `BatchWriteOffService` chains
   `createInventoryWriteOffEntry` (Dr COGS / Cr Inventory). Routing a lot-identified destruction
   through the document would have LOST a journal entry that exists today. Batch-tracked adjustment
   lines are therefore limited to pure quantity corrections.

5. **`entryExitNotes.sourceTypes.stock_transfer` still shows a raw FQCN.** The key is translated in
   en+fr but never resolves, because `StockTransferService` sets `reference_type` to
   `StockTransfer::class` through a post-hoc UPDATE rather than through the document-linkage seam.
   Fixing it is task T14, deliberately NOT run in V7 and registered in the non-seam-writer lane
   (T14a below).

## Deploy prerequisites

- The migration is pure DDL on new tables — no data prerequisite, no seeder dependency, so it is
  **unattended-safe** under `tenants:migrate`.
- **After the permission seeder, run `permission:cache-reset`.** The Spatie permission cache is
  tenant-blind, and V7 adds four permissions (`inventory.adjustments.view|create|post|cancel`).
- No device release: `apps/pos` never called any of the four endpoints.
- No backfill.

## T14a — register T14 in the non-seam-writer lane

**Deliverable is a register entry, not code.** Add the following verbatim alongside the other
non-seam `StockMovement::create` writers, where the morphMap ruling and the backfill live together:

> **T14 — kill `markMovementAsTransfer`'s post-hoc UPDATE.**
> `StockTransferService::markMovementAsTransfer()` UPDATEs a just-written movement to set
> `movement_type`, `reference_type` and `reference_id`. Scope: add
> `?MovementType $movementTypeOverride = null` to `StockAdjustmentService::receive()` / `issue()` /
> `recordMovement()`; add `StockMovementReferenceType::StockTransfer = 'stock_transfer'`; pass both
> from `StockTransferService`'s six seam call sites; delete `markMovementAsTransfer()`; update the
> three literal assertions in `InventoryTransferServiceTest`; backfill existing FQCN rows.
> Payoff: `entryExitNotes.sourceTypes.stock_transfer` (already translated) starts resolving.
> Not run in V7 because the redirect leaves `StockTransferService` byte-identical, so V7 does not
> trigger S0 finding I-5's condition.

## Follow-up tickets opened by this lane

- **`StoreStockTransferRequest` has no decimal ceiling.** `lines.*.quantity` is
  `['required','numeric','min:0.0001']` with no regex, so `POST /stock-transfers` accepts a 5-dp
  quantity today — a rule-19 gap discovered while relocating `IngressPrecisionTest`. Pre-existing;
  deliberately not fixed in this lane, because silently tightening a shipped contract is out of scope.
- **Two rule-19 float breaches on the batch path**, to be fixed together and NOT to collide with the
  lot rules this lane adopts: `BatchStock::getAvailableQuantityAttribute(): float` (two `decimal:4`
  casts subtracted as floats, then `(string)`-cast into `bccomp(...,4)` — at scale 4 with a large
  integer part PHP can emit scientific notation, which `bccomp` rejects outright), and
  `Batch::…(float) $this->batchStock()->sum('quantity')`.
- **`ensureDefaultBatchForImplicitPositiveStock` on the `receive()` path.**
  `ReverseWriteOffService` calls `receive(batchId: null)` and THEN `receiveBatchStock` on the
  original lot, so for a batch-tracked product the helper first tops the DEFAULT lot up to the
  post-update aggregate and Σ lots is inflated. This is why V7 added a NEW delta-based private
  method rather than converting the shared helper — converting it would turn that over-count into a
  double-count.
- **The PostgreSQL immutability trigger** for posted adjustments (deferred, D10).
- **The orphan locale key** `entryExitNotes.sourceTypes.adjustment_batch` (zero `src/` references).
- **GL legs for adjustments** are G1's, and the hand-off has four parts — see plan §5. In particular:
  the contra remap is information-losing (`damage` and `write_off` both invert to
  `adjustment_positive`, and `affectsCOGS()` differs), so G1 must read the ORIGINAL reason through
  `reverses_movement_id`; and `adjustment_negative` is the designated cost-neutral representation of
  internal consumption, NOT `write_off`.
