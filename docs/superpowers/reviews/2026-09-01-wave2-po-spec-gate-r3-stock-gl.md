# Wave-2 PO scenario spec — adversarial gate r3 (condition audit, lens: stock↔GL seam)

**Reviewer:** `stock-gl-interaction-reviewer` (Opus) · **Date:** 2026-09-01 · **Worktree read:** `.worktrees/L-po-flow` (dev `62964e5cc`)
**Under audit:** `02-scenario-matrix.md` rev 3 (154 scenarios) + `01-research.md` rev 3, answering `…-gate-r2-stock-gl.md` (G2-S1-01..10) via `02-gate-r2-response.md`.
**Method:** condition-by-condition. Every "FIXED" claim was checked by opening the cited file at the cited line in this worktree; I did not accept the response table on its own authority, and I did not accept my own r2 text either — one r2 finding of mine turns out to have carried a wrong mechanism, corrected below (G3-S1-04). I ran the W2-WDIL-4 SQL mentally against the live tenant migrations, and re-derived the `[r3]` figures I touched.

## VERDICT: ACCEPT-WITH-CONDITIONS

All ten conditions are materially applied, not reworded. The two that carried the seam risk are genuinely closed: every GR-IR arm now filters `je.status = 'posted'` against a literal I verified matches the stored enum value, and the universal detector is split into three arms that between them see *movement-without-entry*, *free-leg-by-design*, and *received-but-never-moved*. The SQL is executable against the real schema — I checked every column and every literal.

Three residual edits remain, all one-liners, none requiring a re-gate. The one that matters: `01-research.md:354` still carries the **unedited** pre-r3 sentence saying the delivery note's GL entries are "recorded, not asserted" and that LOT-9 asserts "**only** the lot ledger and the aggregate" — two lines below the r3 paragraph that narrows exactly that clause. A runner who reads to the end of §6.7 re-inherits the hole G2-S1-06 closed.

---

## Condition audit — G2-S1-01..10

| r2 condition | Status | Verification |
|---|---|---|
| **G2-S1-01** add `AND je.status='posted'` to both GR-IR queries | **APPLIED** | W2-HP-5's `JOIN journal_entries … AND je.status = 'posted'` (matrix `02:152`) and all three WDIL-4 arms (`02:484`, `:495`, arm (c) needs none). Literal verified: `JournalEntryStatus::Posted = 'posted'` (`JournalEntryStatus.php:10`), column is `string` (`2025_11_30_100000_create_journal_entries_table.php:19`) with a CHECK materialised at migrate time from `JournalEntryStatus::cases()` (`2026_08_25_130200_add_enum_check_constraints_to_journal_entries.php:134-136`; its own census docblock spells the set out as `('draft','posted','reversed')` at `:79`). Mechanism re-verified line by line: `'status' => JournalEntryStatus::Draft` at `GeneralLedgerService.php:2099`, inner `DB::transaction` returns at `:2127`/`:2128`, `postSystemGeneratedEntryAndDispatchPostedEventAfterCommit` outside it at `:2134`, swallowed by `PostGrIrOnGoodsReceipt.php:41-53` |
| **G2-S1-02** split out "received but never moved" | **APPLIED** | Arm (c) added verbatim (`02:503-508`); the `movement_id IS NOT NULL` guard survives only in arm (a) where it belongs. `goods_receipt_lines.movement_id` uuid nullable (`2026_07_04_100000_create_goods_receipts_tables.php:48`), `free_movement_id` `:49` |
| **G2-S1-02b** cover `free_movement_id`, free leg = movement AND no JE | **APPLIED, and correct on the code** | Arm (b) (`02:490-499`) asserts `free_movement_id IS NOT NULL` **and** no posted entry keyed on it. Traced both sides: the free leg calls `recordPurchase(landedUnitCost: '0')` (`GoodsReceiptService.php:574`) and writes `free_movement_id` at `:602`/`:702`; the GL side computes `$amount` at `GeneralLedgerService.php:2068` and returns null at `:2070-2072` when it is ≤ 0. So a free unit **must** leave a movement and **must not** leave an entry — the arm asserts exactly that. Fixture exists (`PO-H`, W2-EDGE-7 `02:522`) |
| **G2-S1-03** `allocated_costs` at 6 dp with the real pin | **APPLIED** | `0.000000` (W2-HP-2 `02:140`), `20.000000`/`10.000000` (W2-LAND-1 `02:330`), `30.000000` (LAND-4 `02:333`, LAND-8 `02:338`), each citing the column `2026_05_30_000000_widen_wac_cost_columns_to_scale_6.php:60-63` (verified: `['allocated_costs', 19, 6]` at `:61`, applied by the `ALTER COLUMN … TYPE` at `:79-82`), the cast `DocumentLine.php:156` (verified `'allocated_costs' => 'decimal:6'`), and the **assertion** `LandedCostBcmathTest.php:330-331` (verified `assertSame('20.000000', …)` / `('10.000000', …)`), not the docblock. `assertMoneyEqual` stated at each row |
| **G2-S1-04** pin `location_id` / `partner_id` / no `batch_id` on the FEFO leg | **APPLIED (pins) · rationale APPLIED-WRONGLY** | All three pinned at W2-LOT-9 (`02:237`) and W2-LOT-10 (`02:238`), and generalised at `01:347-350`. Oracles verified: `$location = $line->location ?? $deliveryNote->location` + `continue` (`DeliveryNoteService.php:309-313`), FEFO is the `elseif` (`:388`, `if ($line->batch_id !== null)` at `:380`), `partner_id` required (`CreateDocumentRequest.php:66-70`). **But the stated consequence of omitting the location is wrong** — see G3-S1-04 |
| **G2-S1-05** LOT-6 / LOT-10 un-hedged | **APPLIED** | Both now assert 422 `INVALID_STATUS_TRANSITION` + zero draw + `stock_levels` unchanged (`02:234`, `:238`). Verified: throw `FEFOInventoryService.php:341`, `final class InsufficientBatchStockException extends \DomainException` (`:16`), message `"Insufficient batch stock to fulfill atomic consume. Shortfall: {$shortfall}"` (`:28`), mapped at `DeliveryNoteController.php:595-596`; the `InsufficientStockForFulfilmentException` arm is deliberately first at `:581` (comment `:582-588`) so W2-LOT-10b's distinct `INSUFFICIENT_STOCK` is right (`WeightedAverageCostService.php:457-467`). Rollback contract `DeliveryNoteService.php:376-379` |
| **G2-S1-06** assert the stock exit's GL | **APPLIED-PARTIALLY** | Added at W2-LOT-9's Expected (`02:237`) and `01:352` — but `01:354` still says the opposite, and the assertion carries no key. **G3-S1-01, G3-S1-02** |
| **G2-S1-07** delete W2-LAND-7's refusal hedge | **APPLIED** | Hedge gone; the two-condition reason is stated inline (`02:337`). Re-verified: `consumeReceiptLines` refuses only on a NULL `accrual_unit_cost` (`SupplierInvoicePostingService.php:488-495`) and an over-clear (`:505-514`) |
| **G2-S1-08** mark the three DB-enforced assertions | **APPLIED** | W2-WDIL-1(c) `02:457`, W2-WDIL-2 `02:458`, W2-IDEM-5 `02:408`, each naming its index. Verified: `goods_receipt_lines_movement_id_unique` / `…_free_movement_id_unique` (`2026_07_04_100000_…:61-70`), `uniq_je_source_procurement` `WHERE source_type IN ('supplier_invoice','supplier_credit_note')` (`2026_06_26_120000_…:44-48`) — and the corollary is right: `'goods_receipt'` is **not** in that predicate, so W2-IDEM-2's two GR-IR entries are reachable and the only guard is the application-level `exists()` at `GeneralLedgerService.php:2052` |
| **G2-S1-09** three drifted citations | **APPLIED** | `PurchaseOrderController.php:857-858` = `\DomainException` → 422 `GOODS_RECEIPT_FAILED`, `:859-865` = `\RuntimeException` → 500 `CONFIGURATION_ERROR` (read verbatim); `POST /roles` at `Identity/routes.php:60` with `POST /users` at `:68`; `UserController.php:241-249` with `'company_id' => $companyId` at `:243` and `MembershipRole::Viewer` at `:246`. All three corrected in the matrix |
| **G2-S1-10** variant-aware batch invariant | **APPLIED** | `AND sl.variant_id IS NULL` + `AND pb.variant_id IS NULL` with the grain comment (`02:260-274`); `stock_levels_non_variant` / `stock_levels_with_variant` verified at `2026_06_02_100005_add_variant_id_to_stock_levels.php:52-57` |

**WDIL-4 SQL, run mentally against `database/migrations/tenant/**`:** executable on all three arms. `goods_receipts` has `company_id` (`2026_07_04_100000_…:17`) and `status` varchar(20) (`:20`) whose enum is `GoodsReceiptStatus::Posted = 'posted'`; `goods_receipt_lines` has `goods_receipt_id` (`:38`), `product_id` (`:40`), `received_qty` decimal(15,4) (`:42`), `free_qty` (`:43`), `movement_id` (`:48`), `free_movement_id` (`:49`). `journal_entries.source_type` string nullable (`2025_11_30_100000_…:22`), `source_id` **uuid** nullable (`:23`) — type-compatible with the uuid movement ids. The `AND je.status='posted'` sits in the `ON` clause of both LEFT JOINs, which is the correct place; in the `WHERE` it would have silently converted them to inner joins.

---

## Findings

### G3-S1-01 [MAJOR] — `01-research.md:354` re-opens the hole `:352` closes

`01:352` (new in r3) says the run's only stock exit "**must therefore assert that at least one journal entry exists** for the issue movement". `01:354`, two lines later and unedited, says LOT-9/LOT-10 "assert **only** the lot ledger and the aggregate … The delivery note's own COGS movement, **GL entries** and document lifecycle are **recorded, not asserted**." It is even framed as the binding version ("stated so a reviewer can hold us to it").

Two categorical statements, opposite instructions, one section. The matrix row is right (`02:237`), but a runner who reads §6.7 to the end and stops takes the later, absolute sentence.

**Other side of the seam, verified:** the stock side of LOT-9 is fully specified and correct — negative-quantity `Issue` movement at `WeightedAverageCostService.php:470-489` (`'quantity' => bcmul($quantityStr,'-1',4)` at `:478`, `reason => MovementReason::Delivery` at `:477`), lot draw at `DeliveryNoteService.php:388-396`, aggregate at `WeightedAverageCostService.php:492-493`. It is only the value consequence whose instruction is contradictory.

**Fix:** in `01:354`, replace "assert **only** the lot ledger and the aggregate" with "assert the lot ledger, the aggregate **and the existence of the exit entry**", and change "GL entries" to "the COGS **amount**" in the recorded-not-asserted list.

---

### G3-S1-02 [MAJOR] — the new exit-GL assertion has no key, no oracle and no failure semantics

W2-LOT-9 (`02:237`) says "record the `journal_entries` the confirm produces and assert **at least one** entry exists for the issue movement". A runner cannot execute that: nothing in the matrix says what column the entry is keyed on, and W2-HP-5 by contrast spells out both the join and "an EMPTY result FAILS the row".

**Traced, both sides.** The exit posting is fully determined:
- `DeliveryNoteService.php:399-401` enqueues `MovementGlContext(kind: Exit, movementId: $movement->id)`;
- `DeliveryNoteController.php:577` calls `$this->glBuffer->flushIfOutermost()` **inside** the root `DB::transaction`, which posts inline (`InventoryGlPostingBuffer.php:56-92`; `$contained` is false here so it is a bare `$post()` at `:92`);
- `InventoryGlPostingService::postForExit` → `postMovement($ctx, false)` (`:26-29`) → `MovementReason::Delivery` maps to `MovementGlCounterFamily::Cogs` (`MovementReason.php:76-79`) → `SystemAccountPurpose::CostOfGoodsSold` (`InventoryGlPostingService.php:185-187`) → `createInventoryMovementEntry(sourceType: 'inventory_exit', …, postSynchronously: true)` (`:192`, `:206`);
- `GeneralLedgerService.php:5291-5293` posts via `postEntryNow` — **inside** the confirm's root transaction. Unlike GR-IR, nothing swallows it, so a committed Draft is **not** reachable on this path: a 200 confirm implies a `posted` entry, and a throw rolls the movement back with it.
- `('inventory_exit', source_id)` is DB-unique (`uniq_je_source_inventory_movement`, `2026_08_11_000100_…:38-40` over `InventoryGlSourceTypes` `:11-15`), so "exactly one" is enforced and "at least one" is the informative half.
- The two ways it legitimately returns **null** are both the finding, not an excuse: unmapped Inventory/COGS accounts (`InventoryGlPostingService.php:161-169`, logs and returns null) and a non-positive amount (`:173-179`).

**Fix (one line):** "assert `SELECT 1 FROM journal_entries WHERE source_type='inventory_exit' AND source_id = <issue movement id> AND status='posted'` returns a row — **an empty result FAILS the row**; the post is synchronous and in-transaction (`InventoryGlPostingService.php:206`, `GeneralLedgerService.php:5291`), so on a 200 confirm an absent entry means the COGS/Inventory accounts are unmapped (`InventoryGlPostingService.php:161-169`) — which is the F-W2-03 shape on the exit side."

---

### G3-S1-03 [MAJOR] — F-W2-03's remedy is not available: a committed Draft cannot be replayed

`01:238` (F-W2-03) and `01:46` now correctly name the committed-Draft mechanism. Neither says what it costs to repair, and the listener advertises a repair that does not work: *"Ops can replay the `GoodsReceived` event or create the GL entry manually"* (`PostGrIrOnGoodsReceipt.php:44`).

**Code.** `createGoodsReceiptGrIrEntry` opens with an idempotency guard that is **status-blind**:
```php
if (JournalEntry::where('source_type', 'goods_receipt')->where('source_id', $movementId)->exists()) {
    return null;                                   // GeneralLedgerService.php:2052-2054
}
```
A committed Draft satisfies `exists()`. Replaying `GoodsReceived` therefore returns null silently — the movement stays unbooked forever, and the advertised remedy is a no-op. Repair requires flipping the row's status through the posting path or deleting it first. (Contrast `createInventoryMovementEntry`, whose equivalent guard is status-aware and re-posts a Draft: `GeneralLedgerService.php:5224-5229`.)

This matters to the wave because F-W2-03 is the run's P0 and the fix lane will size its remediation from this register line. "Detectable" and "repairable by replay" are not the same claim.

**Fix:** append to F-W2-03: "the repair is **not** a replay — `:2052-2054` early-returns on any entry for that movement regardless of status, so a committed Draft permanently absorbs every retry (the listener's `:44` comment is wrong); the ledger-shaped fix is the status-aware guard `createInventoryMovementEntry` already uses at `:5224-5229`."

---

### G3-S1-04 [MINOR] — the "silent `continue`" rationale for pinning `location_id` is wrong (my r2 error, adopted)

`01:348` and W2-LOT-9's oracle (`02:237`) both say a null location makes the line "silently `continue`" — "no `recordSale`, no lot draw, no `stock_levels` change — and the row fails for a fixture reason that reads exactly like a FEFO defect". I wrote that in r2 and the author adopted it. It is not what the code does.

`DeliveryNoteService::confirm` refuses before `issueStock` is ever reached:
```php
if ($deliveryNote->location_id === null) {
    throw new \DomainException(
        'Delivery note must have a location before confirmation. Document: '.$deliveryNote->document_number
    );                                              // DeliveryNoteService.php:89-93
}
```
mapped to **422 `INVALID_STATUS_TRANSITION`** by `DeliveryNoteController.php:595-596`. Since the header location is guaranteed non-null past that guard, `$line->location ?? $deliveryNote->location` cannot resolve to null and the `continue` at `:311-313` is unreachable from this endpoint. Omitting `location_id` produces a **loud, named refusal**, not a silent green.

The instruction (pin `location_id = MAIN`) is unchanged and still required. Only the justification is wrong — and it is the kind of wrong that costs a triage hour when the tester sees a 422 the spec told them was impossible.

**Fix:** replace the mechanism in `01:348` and the W2-LOT-9 oracle with: "`confirm` refuses a header-less delivery note outright — 422 `INVALID_STATUS_TRANSITION`, *Delivery note must have a location before confirmation* (`DeliveryNoteService.php:89-93` → `DeliveryNoteController.php:595-596`); the `continue` at `:309-313` is unreachable from this endpoint."

---

### G3-S1-05 [MINOR] — W2-LOT-6 and W2-LOT-10 are now the same gesture on the same tuple

After r3, W2-LOT-6 (`02:234`) ends with "run the LOT-9 driver against this product for the full received quantity … 422 `INVALID_STATUS_TRANSITION`, message *Insufficient batch stock…*, zero lots drawn, `stock_levels` unchanged", and W2-LOT-10 (`02:238`) is "`P-LOT-6` after W2-LOT-6's receipt … consume the full received quantity … 422 `INVALID_STATUS_TRANSITION`, same message, no negative row, `stock_levels` unchanged". Identical endpoint, identical payload shape, identical outcome. The two fixes converged (G2-S1-05 on LOT-6, the costing lens's G2-C1-03 reshaping LOT-10 onto the same tuple) and nobody noticed they collapsed.

They differ only in *evidence*: LOT-6 also reads `product_batches.is_expired`, LOT-10 also reads for a negative `inventory_batch_stock` row. That is one API call with two evidence sets, not two scenarios — and if the runner takes them literally, LOT-10 runs a second confirm whose 422 proves nothing new.

**Fix:** say so at LOT-10 — "same call as W2-LOT-6; run once and split the evidence" — or give LOT-10 a distinct shape (e.g. a partial quantity, so the shortfall figure in the message is non-trivial).

---

### G3-S1-06 [MINOR] — two residual citation nits introduced or carried in r3

- W2-WDIL-4's arm-(b) comment cites the zero-amount suppression as `GeneralLedgerService.php:2069-2072`; `:2069` is a blank line — the guard is `:2070-2072` (`$amount` is computed at `:2068`). W2-EDGE-7 (`02:522`) already cites `:2070-2072` correctly, so the two disagree within the same document.
- W2-HP-5's oracle cites `GeneralLedgerService.php:2044-2050` for "`source_id = $movementId`"; `:2044-2050` is the method **signature**. The write is `'source_id' => $movementId` at `:2102`, inside the `JournalEntry::create([…])` that spans `:2093-2103`.

---

## `[r3]` figures I touched, re-derived

| Claim | Re-derivation | Verdict |
|---|---|---|
| `allocated_costs = 20.000000 / 10.000000` (W2-LAND-1) | by-value split `100/150 × 30 = 20.000`, `50/150 × 30 = 10.000` computed at the currency scale (`LandedCostService.php:303-316`), stored in `decimal(19,6)` (`2026_05_30_000000_…:61-62`) with cast `decimal:6` (`DocumentLine.php:156`) ⇒ Postgres pads. Pin asserts `'20.000000'` / `'10.000000'` (`LandedCostBcmathTest.php:330-331`) | **CONFIRMED** |
| `allocated_costs = 30.000000` (W2-LAND-4, W2-LAND-8) · `0.000000` (W2-HP-2) | single-line pool takes the whole `30.000`; a PO with no cost allocates `0` — same 6-dp read-back | **CONFIRMED** |
| **`match_status = price_variance`** on W2-LAND-7 [r3 NEW] | policy defaults `match_mode = 'three_way'` (`2026_06_25_100000_create_procurement_policies_table.php:35`), `variance_tolerance_percent`/`_max_amount` both default `'0'` (`:39`, `:42`). Three-way ⇒ basis is the **receipt-plan weighted** cost, not the PO price: `(5×10.000000 + 5×13.000000)/10 = 11.500000` (`SupplierInvoiceMatcher.php:554-575`). Invoice bills `10.000` ⇒ `priceDiff 1.500`, `extendedVariance = 1.500 × 10 = 15.000` > both zero thresholds ⇒ `PriceVariance` (`:531-535`). Under `block` it throws (`:255-261`) | **CONFIRMED** — and it confirms the S-1 restore (W2-PRICE-7) is genuinely load-bearing for this row, not defensive bookkeeping |
| W2-LAND-7 legs (`Dr 408 115.000`, `Dr 4456 19.000`, `Cr 7585 15.000`, `Cr 401 119.000`) | unchanged from r2, where I derived them from source; the r3 edit only deleted the hedge and added `match_status` | **CONFIRMED (r2)** |

---

## What to fix before merge

Three edits, no re-gate: (1) fix the contradiction at `01-research.md:354` so §6.7 says the exit entry's **existence** is asserted; (2) give W2-LOT-9's exit assertion its key (`source_type='inventory_exit'`, `source_id = <movement id>`, `status='posted'`, empty = FAIL); (3) append the "replay does not repair a committed Draft" clause to F-W2-03. Then optionally: correct the location rationale (G3-S1-04), de-duplicate LOT-6/LOT-10 (G3-S1-05), and the two citation nits (G3-S1-06).
