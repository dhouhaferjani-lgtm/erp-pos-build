# W2-7 gate r6 — inventory-costing lens (r5 fix round: R5-1 provenance-first restore, R5-2, R5-6..R5-9)

**Lane:** `fix/campaign-w27-phantom-default-batch` · worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w27-default-batch` · HEAD `63ac36a4f`
**Reviewer:** inventory-costing-reviewer (r6) · **Date:** 2026-08-25
**Diff reviewed:** `git diff c06c8c529...HEAD` — lane commits `804571274` (dev merge + manifest union), `0769a1b18` (R5-1/R5-2/R5-9), `124d0c03b` (R5-6/R5-7/R5-8), `63ac36a4f` (handback §"Fix round r5"). Everything else in the range arrived through the dev merge and is out of lens.
**Prior rounds:** r1–r5 (`2026-08-24-w27-batch-gate-r{1,2,3}-inventory.md`, `2026-08-25-w27-batch-gate-r{4,5}-inventory.md`).
**Scope (as dispatched):** verify R5-1, R5-2, R5-6..R5-9 are closed and that the r5 fixes introduced nothing new of CRITICAL severity. The lane was NOT re-reviewed end to end.
**Hygiene:** the lane worktree is **pristine** — `git status --porcelain` → 0 lines, HEAD unchanged `63ac36a4f`. Every probe and every tamper ran in a throwaway `git worktree` (`…/scratchpad/w27g6`, detached at HEAD, later merged with current `dev`), now **removed** (`git worktree list` verified). Throwaway PG database `autoerp_test_w27g6` (`127.0.0.1:5433`) created and **dropped**. One test process at a time; the full suite was never run. No tenant database was contacted this round; `--execute` was never run anywhere.

# VERDICT: spec ✅ + quality APPROVED — **merge-blocking: NO**

All six findings dispatched to this round are **closed, and closed the way the gate asked** — not by re-shaping the assertion. R5-1 in particular is closed by the exact remedy r5 specified (`$preferredLots` applied first, still capped by outstanding outbound, heuristic and then `DEFAULT` for the remainder), it is red-proofed on **both** channels by tamper, and the lane turned my r5 probe into two committed pins rather than paraphrasing it. R5-2 is red-proofed with the exact `MissingVariantException` I measured last round. R5-6/7/8/9 are mechanically verified.

The r5 changes introduce **nothing of CRITICAL severity**. I measured one real new artefact — a spurious `0.0000` `inventory_batch_movements` leg when the provenance arm drains a lot's ceiling and the return still has quantity left (§R6-1) — but every balance at rest is correct and no `DEFAULT` phantom is minted, so it is ledger noise, not wrong quantity at rest. One structural concern (a second, differently-ordered lock sequence inside `restoreBatchesForReturn()`, §R6-2) is Important but self-limiting: PostgreSQL detects the deadlock and aborts one transaction cleanly.

Two merge-time conditions carry unchanged from r5 and are **parent actions, not lane defects**: `git merge --squash` is mandatory (C-1 blob), and the manifest union must be taken at merge time — `dev` has moved **again**, to `0e407edd7`.

---

## 0. What I verified BY EXECUTION

| # | Check | Evidence | Result |
|---|---|---|---|
| 1 | Lane suites on **PostgreSQL 16** (`autoerp_test_w27g6`, 5433) — `BatchTrackedSalesOrderConfirmFefoTest` + `ReceiptStockPolicyTest` + `RepairPhantomDefaultBatchesCommandTest` + `EnsureDefaultBatchTest` | one process | **OK — 47 tests, 221 assertions** (r5 was 43/207; +4 = the four new pins) |
| 2 | Same four suites on **sqlite** | one process | **47 tests, 140 assertions, 16 skipped** — 31 run. The 3 new skips are `skipUnlessPostgres()` on FEFO consumption (`FOR UPDATE … SKIP LOCKED`), a legitimate reason, not a weakened assertion |
| 3 | `--testdox`, sqlite | | `A return on a variant scoped line…` (R5-2) **runs on sqlite** ✔; the three R5-1 provenance pins are PG-only ↩ |
| 4 | **RED-PROOF 1 — R5-1, both channels.** Suppress the hint on both call sites (`preferredLots: []` in `ReceiptReturnService.php:541` **and** `ReturnNoteService.php:895`) | throwaway worktree, PG | **exactly 2 failures**, one per channel: `test_a_document_return_credits_the_lots_its_own_source_delivery_drew` — *"the lot the SOURCE delivery drew is credited, not the most-recently-shipped one"*; `test_a_pos_return_credits_the_lot_that_receipts_own_line_consumed` — *"the SHORT-DATED lot receipt 1 actually consumed must be credited — the gate measured it left at 0"*. Both new pins **bind** ✅ |
| 5 | **RED-PROOF 2 — R5-2.** Revert `variantId: $line->variant_id` → `null` in `ReturnNoteService::restoreBatchStock()` | throwaway worktree, PG | **1 error**, the exact r5 exception: `MissingVariantException: Product '…' has active variants; batches must be variant-scoped — a variant_id is required.` at `FEFOInventoryService.php:677` ← `:466` ← `ReturnNoteService.php:886`. The pin **binds** ✅ |
| 6 | **MY PROBE — partial return twice, no over-credit** (the gate's item 1, last clause). Lot A (short, 5) + lot B (long, 10); one receipt sells **8** (FEFO → A:5, B:3); return **3**, then return **5** | my own test, PG, untampered code | **lot A = 5.0000, lot B = 10.0000, Σ lots = 15.0000, `DEFAULT` lots = 0** ✅ — no over-credit, no phantom. (Arithmetic check: return 2 hints A `3.1250` / B `1.8750` but A's ceiling has already fallen to `2`, so A is capped at 2 and the 0.8 residue lands back on B through the heuristic. The outstanding-outbound cap, not the hint, is what makes this safe.) |
| 7 | **MY PROBE — zero-quantity leg** (§R6-1). Sell 8 (A:5 + B:3), then call `restoreBatchesForReturn(quantity: '8', preferredLots: [B => '3.0000'])` so provenance drains B's ceiling to 0 with `remaining` still 5 | my own test, PG | `zero-qty legs before=0 **after=1**` · `lotA=5.0000 lotB=10.0000` · `DEFAULT lots=0` — **balances correct, one spurious `0.0000` leg written** |
| 8 | R5-6 — stale docblocks | `git diff` of `0769a1b18` | both rewritten: `FEFOInventoryService.php:349-388` now states the three-step provenance-first policy; `ReturnNoteService.php:844-877` likewise. The dead pointer to `restoreBatchAllocations()` is gone ✅ |
| 9 | R5-7 — dead import | `ReceiptReturnService.php` | `ReceiptLineBatchAllocation` is **live** — used at `:1706` inside `lotProvenanceForLine()` ✅ |
| 10 | R5-8 — last legless fixture | `grep -n 'Batch::create\|BatchStock::create'` over both test files | **3 hits, all inside comments**. `seedLeafLot()` (`ReceiptStockPolicyTest.php:711-748`) now goes `findOrCreateBatch` → real `GoodsReceipt` `StockMovement` → `receiveBatchStock(movementId:)`, identical in shape to the sibling file ✅ |
| 11 | R5-9 — deterministic tiebreak | `FEFOInventoryService.php:558` | `->orderByRaw("{$lastShippedExpr} DESC, ibm.batch_id DESC")` ✅ |
| 12 | **Manifest union vs CURRENT dev `0e407edd7`** | checker run on the real merged tree | **EXIT=0** — `Document` **84**, `Inventory` **114**, `Company` **31**, `Treasury` **120**, `gated_ceiling` **1173** |
| 13 | deptrac ratchet on the **merged** tree | `tools/deptrac-ratchet.php --baseline=deptrac.baseline.json` | **PASS — TOTAL 183/183**, every category `held`, *"no boundary regression against baseline"* ✅ |
| 14 | DPA scanner on the **merged** tree | `DocumentPerActionBaselineRatchetTest` + `DocumentPerActionWriteGuardTest` | 8 tests, **2 failures, both inherited**: `DPA_BASELINE_PROTECTED_BLOB` unset (env, fails closed by design) and dev's **two** `GeneralLedgerService::clearCustomerAdvanceToReceivable::journal_entries::delete` keys. **Zero** keys from this lane; **no stale-key error** ✅ |
| 15 | PHPStan L8 (live-DB env), the 3 touched production files | | **`[OK] No errors`** |
| 16 | Pint `--test`, 3 production + 2 test files | | **`{"result":"pass"}`** |
| 17 | Rule 19 scan of every production line **added by `0769a1b18` + `124d0c03b`** | `grep -E '\(float\)\|floatval\|number_format\|round\(\|\(int\)\|parseFloat'` over `+` lines | **0 float hits.** 3 hits, all safe: `QuantityScale::round(…, FLOOR)` on a ceiling, and two `(int) $…->batch_id` casts — `product_batches.id` is `$table->id()` (bigint autoincrement, `2026_01_05_150000_create_product_batches_table.php:14`), so the cast is on an integer PK, not a decimal property. bcmath literals `4`/`8` carry `// precision-ok:` markers and `ForbidHardcodedBcmathScale` passes |
| 18 | **Actually-CI-enforced surface** — the four `backend-test-pgsql` allowlist classes that traverse this restore path (`BatchChainE2ETest`, `ReturnNoteConfirmSealAndPeriodTest`, `ReceiptReturnRefactorV3Test`, `PosCoreReceiptProjectionRefundDispositionStockTest`) on the **merged** tree | PG | **35 tests, 478 assertions, all green** — no regression on the one gate that runs today |
| 19 | C-1 blob, recomputed against dev `0e407edd7` | `git rev-list 63ac36a4f --not dev` × `cat-file` | **same 7 commits** (`1c3adf33b d33f51089 fb469f423 391a787d1 99454be34 a6543b3a5 6911365a9`), blob `81e058d0d`, **55,666,691 B** — byte-identical to r2/r3/r4/r5 |
| 20 | `.deptrac.cache` in HEAD's tree | `git ls-tree -r 63ac36a4f` | **0 hits** ✅ (it is the *history* that carries it) |
| 21 | Migrations in the lane | `git diff --name-only dev...HEAD -- database/migrations` | **none** — the merge stays migration-free |
| 22 | R5-10 re-verified | `tests/Unit/POS/ReceiptReturnServiceTest` on the merged tree | **11 errors**, `ArgumentCountError: … 11 passed … and exactly 13 expected` — unchanged from r5, still inherited |

---

## 1. Are R5-1, R5-2, R5-6..R5-9 closed?

**R5-1 — CLOSED, and closed correctly.** `FEFOInventoryService::restoreBatchesForReturn()` (`:395-470`) now takes `array<int, numeric-string> $preferredLots` and runs three arms in the order r5 specified:

```php
$outstanding = $this->outstandingShippedLots($companyId, $productId, $locationId, $variantId);
// 1. Provenance first, each hint capped by the lot's outstanding outbound
foreach ($preferredLots as $batchId => $hinted) { … $ceiling = $outstanding[$batchId] ?? null; if ($ceiling === null) { continue; } … }
// 2. Heuristic for whatever provenance did not cover.
foreach ($outstanding as $batchId => $shipped) { … }
// 3. DEFAULT lot
```

The three properties that matter are all present and I checked each one against the code, not the commit message:

1. **Capped, so a hint can never over-credit.** `$credit` starts at `$hinted` and is lowered to `min($ceiling, $remaining)` (`:428-433`), then `$outstanding[$batchId]` is decremented by exactly what was credited (`:448`). A stale or over-stated hint is silently clipped.
2. **A hint can never reach a forbidden lot.** `$ceiling === null → continue` means the hint must appear in `outstandingShippedLots()`, which already excludes the `DEFAULT` batch number (`:544`) and is scoped by `company_id`, `product_id`, `location_id` **and** `variant_id` (`:537-548`). So a hint naming a batch of the wrong variant, or a `DEFAULT` lot, is dropped rather than credited — the cap is doing real safety work, not just arithmetic.
3. **Provenance is a hint, not a precondition.** Composite leaves and pre-lane sales with no snapshot get `[]` and fall straight to the heuristic — which is what R4-7 actually asked for, and which `test_a_pos_return_without_a_snapshot_still_restores_through_the_heuristic` pins (it stays green under RED-PROOF 1 by design).

Both feeders are sound. POS (`ReceiptReturnService::lotProvenanceForLine()`, `:1704-1735`) reads the line's `ReceiptLineBatchAllocation` rows and nets them by prior returns proportionally; `bcdiv` **truncates**, so `alreadyCredited` is under-stated and the hint is slightly over-stated — the safe direction, since over-statement is clipped by the ceiling and under-statement would strand quantity on `DEFAULT`. DN/RN (`ReturnNoteService::lotProvenanceForReturnLine()`, `:912-953`) resolves the issue legs of the originating delivery from `stock_movements.reference_id`, scoped by `company_id`, and returns `[]` when nothing resolves.

Red-proofed on both channels (§0 #4). My r5 probe is now the committed pin, verbatim, with two dated lots and two receipts — the shape the old one-lot pin could not discriminate.

**R5-2 — CLOSED.** `ReturnNoteService::restoreBatchStock()` now takes the `DocumentLine` and passes `variantId: $line->variant_id` (`:894`), restoring symmetry with `DeliveryNoteService::issueStock()`. Red-proofed with the exact exception (§0 #5), and the pin **runs on sqlite**, so it is enforced wherever `tests/Feature/Document` runs — not only on PG.

**R5-6 / R5-7 / R5-8 / R5-9 — all CLOSED**, verified mechanically (§0 #8-11). R5-8 is the one worth calling out: `seedLeafLot()` was rewritten to mint through the real ledger, and the two assertions that had used `->sole()` over the product's movements were re-scoped (`Issue` for the sale, `POSReturn` for the credit) rather than deleted — the honest fix.

**R5-3 — resolved, but re-derive at merge time (§3).** **R5-4** carries unchanged as the parent's merge instruction. **R5-5 / R5-10** are residuals, restated in §4.

## 2. Did the r5 changes introduce anything CRITICAL?

**No.** I looked specifically at the four surfaces where a provenance hint could corrupt quantity at rest, and each is closed by the cap: over-credit (clipped by `$ceiling`), wrong-variant lot (absent from `$outstanding` → dropped), `DEFAULT` lot reached via hint (excluded by `outstandingShippedLots`), and negative `$outstanding` (impossible, `$credit ≤ $ceiling`). My partial-return probe (§0 #6) confirms the double-return case lands exactly right. No float, no scale downgrade, no WAC arithmetic touched, no new movement whose sign contradicts its reason.

Two new findings, neither blocking, plus two inherited-but-now-load-bearing notes:

### [MINOR] R6-1 — the heuristic loop has no zero guard, so a drained provenance lot gets a spurious `0.0000` ledger leg

`FEFOInventoryService.php:446-458`. The heuristic loop entered after the provenance arm has no `$shipped <= 0` check:

```php
foreach ($outstanding as $batchId => $shipped) {
    if (bccomp($remaining, '0', QuantityScale::SCALE) <= 0) { break; }
    $credit = bccomp($shipped, $remaining, QuantityScale::SCALE) < 0 ? $shipped : $remaining;
    $this->creditLot($tenantId, $batchId, $locationId, $credit, $movementId);   // <-- $credit may be '0.0000'
```

When the provenance arm drove a lot's `$outstanding` to exactly `0.0000` (`:448`) and the return still has `$remaining > 0`, that lot is re-visited with `$shipped = '0.0000'`, `$credit` resolves to `'0.0000'`, and `creditLot()` (`:586-640`) has **no zero guard either** — it updates `inventory_batch_stock` by `+0` and inserts an `inventory_batch_movements` row with `quantity = 0`.

Measured (§0 #7): **1** zero-quantity leg written; lot A `5.0000`, lot B `10.0000`, `DEFAULT` lots `0`. So **no balance is wrong and no phantom is minted** — this is ledger noise. It matters because `inventory_batch_movements` is the recall/traceability substrate: a `0.0000` credit row reads as "this return touched this lot" when it did not, and `BatchTraceabilityController`-style reconstructions have to learn to ignore it. Reachable whenever a tuple is returned through both channels, or whenever a ceiling clips a hint.

**Fix (one line):** `if (bccomp($shipped, '0', QuantityScale::SCALE) <= 0) { continue; }` at the top of the heuristic loop body. A matching guard in `creditLot()` would be belt-and-braces.

### [IMPORTANT] R6-2 — the provenance arm adds a SECOND, differently-ordered lock sequence inside one method

`creditLot()` takes a `lockForUpdate()` on the lot's `inventory_batch_stock` row (`:594-598`). Before r5 there was exactly one iteration order in `restoreBatchesForReturn()` — `outstandingShippedLots()`'s `MAX(last shipped) DESC, batch_id DESC` — which is a pure function of the tuple's data, so two concurrent returns of the same tuple locked the same rows in the same order.

r5 adds a first pass over `$preferredLots` (`:417-450`), whose order is the order `ReceiptLineBatchAllocation::where(…)->get()` returns (`ReceiptReturnService.php:1706` — **no `orderBy`**) or the ungrouped `->get()` in `ReturnNoteService.php:930-936`. That is per-receipt-line, driver-defined, and typically FEFO-ascending — i.e. roughly the **reverse** of the heuristic order that follows it in the same method. Two concurrent returns that split differently between the two arms can therefore acquire the same two lot locks in opposite orders.

Not Critical: both callers run inside a DB transaction, PostgreSQL detects the cycle and aborts one side with a deadlock error, and the return rolls back atomically — the operator sees a failure, not corruption. Not merge-blocking for tenant #1 (single terminal; it needs two simultaneous returns of the same product at the same location). **I did not execute this** — it is a structural reading of the two iteration orders, stated as such.

**Fix:** `ksort($preferredLots)` before the provenance loop and `ksort($outstanding)`-equivalent ordering for the credit pass (batch id ascending everywhere), keeping `outstandingShippedLots()`'s most-recently-shipped order only as the *selection* order. Or merge the two arms into one pass over a canonically-ordered list.

### [IMPORTANT] R6-3 — the four new provenance pins run in PARKED CI lanes only, so the R5-1 fix has no green gate

`test_a_pos_return_credits_the_lot_that_receipts_own_line_consumed`, `…without_a_snapshot…`, and `test_a_document_return_credits_the_lots_its_own_source_delivery_drew` are `skipUnlessPostgres()` (legitimately — FEFO consumption uses `FOR UPDATE … SKIP LOCKED`). Their PG homes are `feature-lane-pos` and `feature-lane-documents` (`.github/workflows/ci.yml:1275`, `:1749`), both gated on `vars.SELF_HOSTED_RUNNER_READY == 'true'` — **off**, which the manifest checker itself reports (*"70 group(s) / 1173 class(es) are laned but not yet running"*). Neither class is in the `backend-test-pgsql` allowlist (`ci.yml:983`), and the cheap `backend-test` job runs only `--testsuite=Unit` plus a few architecture files — the whole-`tests/Feature` sqlite sweep is `workflow_dispatch`-only (`ci.yml:477`).

Net: the CRITICAL that r5 caught is fixed and proven **by hand**, and nothing in CI can catch its return. The structure is inherited (F-2 parking), **not lane-introduced** — which is why this is not blocking. But the escape hatch exists and is being used in this very merge range: dev's own W2-6/Q-11 lanes appended `ExpensePostTest|ResolveLineEntryCodeCostRedactionTest|PurchaseOrderUnpricedLineConfirmTest` to that same allowlist. Appending `BatchTrackedSalesOrderConfirmFefoTest|ReceiptStockPolicyTest` is a one-line change with the same precedent, and the manifest checker already validates filter anchoring. Strongly recommended in the merge commit or the next lane.

### [MINOR] R6-4 — the provenance source column is `decimal(10,3)`, one scale short of the quantity floor

`pos_receipt_line_batch_allocations.quantity` is `$table->decimal('quantity', 10, 3)` (`database/migrations/tenant/2026_02_19_000001_create_pos_receipt_line_batch_allocations_table.php:18`) — a **quantity** column at scale 3, where rule 19's floor is `decimal(N,4)`. Inherited (2026-02-19, long before this lane), but r5 makes it **load-bearing**: it is now a `numeric-string` quantity source feeding `restoreBatchesForReturn()`. Harmless today — a sub-milli-unit truncation only under-states the hint, and the heuristic credits the residue to the same lots (my probe confirms no `DEFAULT` leakage) — but it should be on the precision-contract backlog now that a costing path reads it.

## 3. Merge manifest — recomputed on the REAL merged tree against CURRENT dev

`dev` has moved again since r5: **`0e407edd799d43a518fe8d1e6b47fd3293055c2a`** (*"register+handoff: [Session B] Q-12 merged (524e2f477)"*), which raised only `Treasury` 119 → 120. I merged `63ac36a4f` with that dev in a throwaway worktree — `apps/api/tests/feature-lane-manifest.json` **auto-merged cleanly** (different hunks) — and ran `php apps/api/tools/feature-lane-manifest-check.php` on the result:

```
tests/Feature lane manifest OK — 1413 Feature classes in 74 groups; every group has a disposition;
every declared lane is present in ci.yml; every --filter entry is anchored and uniquely matched
against 1807 test classes across all suites.
EXIT=0
```

**Exact values for the squash commit:**

| Key | dev `0e407edd7` | lane `63ac36a4f` | **at merge** |
|---|---|---|---|
| `gated_ceiling` | 1169 | 1173 | **1173** |
| `groups.Document.classes` | 83 | 84 | **84** |
| `groups.Inventory.classes` | 111 | 114 | **114** |
| `groups.Company.classes` | 31 | 31 | **31** |
| `groups.Treasury.classes` | **120** | 119 | **120** — take **dev's** entry, note and all (Q-12 `TreasuryOrphanCensusCommandTest`) |

The lane's values need no adjustment this round; the only resolution required is to keep dev's `Treasury: 120` + its note. `Document`'s note must keep dev's W2-6 `82 → 83` paragraph verbatim with this lane's `83 → 84` (`BatchTrackedSalesOrderConfirmFefoTest`) appended — which is what `804571274` already wrote. **These are merge-time values and dev has now moved under this lane four rounds running: re-derive if it moves again before the squash lands.**

**deptrac:** ratchet **PASS, TOTAL 183/183** on the merged tree, every category `held`.
**DPA:** only dev's **two** inherited `GeneralLedgerService::clearCustomerAdvanceToReceivable::journal_entries::delete` keys, plus the env-unset anti-growth failure. **Zero** keys from this lane, no stale-key error.
**C-1 blob:** unchanged — blob `81e058d0d`, **55,666,691 B**, reachable as `<commit>:.deptrac.cache` from `1c3adf33b d33f51089 fb469f423 391a787d1 99454be34 a6543b3a5 6911365a9`. `git ls-tree -r 63ac36a4f | grep deptrac.cache` → **0**, so HEAD's tree is clean; it is the *history*. **`git merge --squash` is mandatory** and must be recorded in the merge ledger.
**Migrations:** none in the lane.

## 4. Residuals for the LEDGER row — one line each

1. **P1 — a returned composite never re-enters stock at all.** A composite `pos_receipt_lines` row carries `product_id = NULL`, so neither the aggregate credit nor the lot credit runs for the leaf; leaf aggregate and leaf lots stay in sync (no drift, no phantom), the aggregate half predates this lane. **Unreachable on tenant #1** (`composite_items` = 0, NULL-`product_id` receipt lines = 0, measured r5) — must close before the first tenant sells a combo, and the fix must decompose the aggregate credit, the GL and the disposition together.
2. **R5-10 — `tests/Unit/POS/ReceiptReturnServiceTest` is 11 `ArgumentCountError`s** (11 args passed, 13 expected), inherited and re-verified this round; two of the dead tests were the POS lot-restore pins, so that channel's unit coverage is gone and rests entirely on the lane's PG feature pins.
3. **R6-3 — the four new provenance pins run only in PARKED CI lanes** (`feature-lane-pos` / `feature-lane-documents`, `SELF_HOSTED_RUNNER_READY` off) and are absent from the `backend-test-pgsql` allowlist, so the R5-1 CRITICAL fix has **no green CI gate**; appending the two class names to that allowlist has direct precedent in this merge range.
4. **R6-2 — `restoreBatchesForReturn()` now holds two differently-ordered lock sequences** (provenance = allocation-row order, heuristic = most-recently-shipped); concurrent returns of the same tuple can deadlock, PG aborts cleanly, not reachable on a single-terminal tenant. Fix = canonical batch-id ordering.
5. **R6-1 — a spurious `0.0000` `inventory_batch_movements` leg** is written for any lot the provenance arm drains while quantity remains; balances correct, recall trail noisy. Fix = one `continue` guard.
6. **R6-4 — `pos_receipt_line_batch_allocations.quantity` is `decimal(10,3)`**, one scale below the rule-19 quantity floor, and r5 made it a load-bearing costing input.
7. **`WeightedAverageCostService::recordReturn()` still has no `variantId`** — the aggregate credit on the return path stays product-level while the lot restore is now variant-scoped; predates this lane.
8. **Document-channel provenance is per-DOCUMENT, not per-line** — a delivery note has no allocation snapshot, so a multi-line delivery of the same product resolves at the delivery's grain (self-documented at `ReturnNoteService.php:855-863`); better than the tuple-wide heuristic, weaker than the POS snapshot.
9. **C-1 — the 55.6 MB `.deptrac.cache` blob lives in seven lane commits**; `git merge --squash` is the mitigation and is mandatory.

---

## Merge instruction for the parent

```
git merge --squash fix/campaign-w27-phantom-default-batch      # --squash is MANDATORY (C-1 / R5-4)
```

`apps/api/tests/feature-lane-manifest.json` should auto-merge; verify it lands on:

```
"gated_ceiling": 1173
"groups"."Document"."classes": 84     # dev's W2-6 82->83 note VERBATIM + this lane's 83 -> 84
"groups"."Inventory"."classes": 114
"groups"."Company"."classes": 31      # dev verbatim
"groups"."Treasury"."classes": 120    # dev verbatim, WITH its Q-12 note
```

then re-run `php apps/api/tools/feature-lane-manifest-check.php` on the merged tree and require **EXIT=0**. If `dev` moves again before the squash lands, **re-derive** — these are merge-time values.

## What to fix before merge

Nothing in the lane. Squash-merge it, take dev's `Treasury: 120` entry, and re-run the manifest checker on the merged tree; then book R6-1 (one-line zero guard), R6-2 (canonical lock order) and R6-3 (put the two provenance pins in the `backend-test-pgsql` allowlist so the fix has a gate that can go red) as the follow-up round, and carry residuals 1, 2 and 6-9 onto the LEDGER row.
