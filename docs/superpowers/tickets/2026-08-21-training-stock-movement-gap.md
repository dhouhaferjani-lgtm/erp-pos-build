# Training receipts still move real INVENTORY (and post real COGS)

- **Opened:** 2026-08-21
- **Severity:** P2
- **Owner lane:** inventory-costing (NOT the G-3 treasury lane)
- **Status:** OPEN — deliberately not fixed in the G-3 wave
- **Raised by:** LEDGER gate G-3 (`fix/g3-training-receipt-containment`)

## Summary

G-3 closed the **money** side of training containment: `TreasuryReceiptBridge`,
`TreasuryAccountPaymentBridge` and `PosCoreReceiptProjection::redeemVouchers()`
now all return early on `training_flag = true`.

The **stock** side is still wide open. A training receipt continues to decrement
real inventory and post the resulting COGS journal entry. G-3 must not be read
as "training is contained" — it is contained for cash, AR and vouchers only.

## Evidence

All paths in `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php`
(line numbers as of the G-3 branch, which inserts 18 lines around `:1563`):

- **Call site `:477`** — `applyStockMovementForLines(...)` is called
  unconditionally inside `apply()`'s transaction. Nothing between the receipt
  INSERT (`:452`) and this call tests the training flag; the only conditionals
  in that span are the insert-race bail (`:454-457`) and the v3 rounding branch
  (`:431`).
- **Definition `:1677-1712`** — the sole guard is
  `if ($receiptType === ReceiptType::Return)` (`:1689`), which routes to
  restock; everything else falls through to `decrementStockForLines` (`:1704`).
  `decrementStockForLines` (`:1822`) repeats only that same gate at
  `:1836-1838`. **Neither method's signature even receives the payload or the
  training flag** (`:1678-1687`).
- **`resolveReceiptType()` `:630-637`** — only REFUND/VOID *with a resolved
  original* become `ReceiptType::Return`; `invoice_type_code = 'TRAINING'`
  returns `ReceiptType::Sale` (`:636`). The docblock at `:619` states
  "SALE / TRAINING → Sale" explicitly.

The data to gate on is already on the row: `is_training` / `training_flag` are
persisted at `:399` / `:410`.

**The same file already discriminates on training in four other places**, which
is what makes this an omission rather than a design choice:
`:291-294` (tolerance write-off), `:1577` (voucher redemption — added by G-3),
`:1625` (loyalty earning), `:745-756` (`assertOriginalNotTraining`).

### No upstream containment either

- Device outbox drain does not exclude training chains —
  `apps/pos/src/lib/db/repositories/fiscalEventRepository.ts:108-119` selects
  `WHERE sync_status IN ('pending','failed')` with no `chain_context` filter
  (training events live on `training_operational` / `training_z_session`,
  `FiscalEventEngine.ts:212-213`).
- `OutboxIngestor.php:155` rejects only `isServerOnly()` types, not training.
- `ApplyFiscalEventProjectionJob.php` — zero occurrences of "training".

### The device believes the opposite

`apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:351`:

```
 * - `is_training = 0`: training receipts never move real stock.
```

Enforced device-side at `:360` inside `getUnsyncedReceiptLineBlobs()`, which
feeds the availability selector's pending-sale subtraction. So the device
excludes training from local availability math while the server decrements real
`stock_levels`. **The two sides disagree, and the device's own comment is the
written contract the server violates.**

## Blast radius

Tables written for a training sale:

- `stock_levels` — mutated and saved at `:2023` (decrement) / `:2428` (restock).
- `stock_movements` — created at `:2030` (`MovementType::Issue`,
  `MovementReason::POSSale`, `reference_type = 'pos_receipt'`,
  `is_historical = false`) and `:2435` (`MovementReason::POSReturn`).
- `inventory_countings.late_sales_flags` — appended at `:1785-1795` via
  `flagLateSaleForActiveBlock` (`:1754`), also ungated.
- `journal_entries` / `journal_lines` — every movement is enqueued to the GL
  buffer at `:2553` (`enqueuePosMovement`) and flushed at `:505`. **So this is
  a money consequence too**: a rehearsal posts a real COGS leg.

Services on the path, none of which mention training:
`InventoryGlPostingBuffer` (`:56-93`) → `InventoryGlPostingService::postForExit()`
(`:26`); `ReturnScrapWriteOffService::writeOff()` (called at `:2300` for scrap
disposition — Dr Shrinkage / Cr Inventory); `RestockPolicyResolver`;
`CountingBlockService`.

Costing note: `avg_cost_before/after` stay NULL by design (`:2044-2047`), so WAC
is **not** re-averaged — but `unit_cost` / `total_cost` are written at
`COST_SCALE = 6` (`:161`) and the COGS GL leg still posts.

## Why it was not fixed in the G-3 wave

1. Different lane and different reviewer (inventory-costing / stock↔GL seam).
2. The fix is not a one-line early return: a training receipt must still produce
   its read-model row and its printable receipt, so the guard has to sit
   precisely at the movement boundary without disturbing the projection, and it
   interacts with restock, counting blocks and the GL buffer flush.
3. Reversal question for existing data is open — see below.

## Suggested approach

- Thread the training flag into `applyStockMovementForLines` /
  `decrementStockForLines` (they currently cannot see it) and gate at the
  movement boundary, mirroring the `earnLoyaltyPoints` gate shape.
- Decide the counting-block interaction: should a training sale still raise a
  late-sale flag? Probably not, but that is an owner call.
- Red-first test with a control arm, same shape as
  `tests/Feature/Treasury/TrainingReceiptTreasuryContainmentTest.php`.

## Open questions for the owner

- **Remediation of already-written movements.** Unlike the money side (where the
  census is expected to return zero because device authoring of a training
  SALE_RECEIPT is currently blocked — see the G-3 report), the same blocker
  applies here, so the expected count is also zero. **This must be confirmed per
  tenant before the ticket is closed as "no backfill needed."** Census:

Filters key on the SEALED payload flag, not the mutable `pos_receipts.is_training`
mirror (the `is_training` variant is kept as a commented cross-check — a
divergence between the two is itself a finding):

```sql
SELECT count(*) AS training_stock_movements
FROM stock_movements sm
JOIN pos_receipts r
  ON r.id = sm.reference_id AND sm.reference_type = 'pos_receipt'
JOIN fiscal_events fe ON fe.id = r.fiscal_event_id
WHERE (fe.payload->>'training_flag')::boolean = true;
-- cross-check: drop the fiscal_events join and use r.is_training = true;
```

The **money-side** census (payments / journal_entries / repository_movements /
voucher_ledger) lives in
`2026-08-21-training-latent-surfaces-deposit-and-exchange.md`, § Census.
