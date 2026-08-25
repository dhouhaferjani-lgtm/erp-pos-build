# W2-7 gate r3 — inventory-costing lens (+ fiscal-exposure check on the POS arm)

**Lane:** `fix/campaign-w27-phantom-default-batch` · worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w27-default-batch` · HEAD `ca25991ee`
**Reviewer:** inventory-costing-reviewer (r3) · **Date:** 2026-08-24
**Diff reviewed:** `git diff 1818ac42f...HEAD` — lane commits `a78b5c334` (C-2 + C-3 + W2-7 overwrite shape), `eca305416` (W4-5 DN channel + POS composite), `ca25991ee` (handback). Everything else in that range arrived through the `cd1e35323` dev merge and is out of lens.
**Prior rounds:** `2026-08-24-w27-batch-gate-r1-inventory.md`, `2026-08-24-w27-batch-gate-r2-inventory.md`.
**Hygiene:** lane worktree left **pristine** (`git status --porcelain` → 0 lines, HEAD unchanged `ca25991ee`). All tampering done in a throwaway `git worktree` (`…/scratchpad/w27g3`, hard-linked vendor, **removed**; `git worktree list` verified). Throwaway PG DB `autoerp_test_w27g3` (127.0.0.1:5433) created and **dropped**. Single test process throughout; the full suite was never run. `--execute` was **never** run against any tenant database.

# VERDICT: spec ❌ (W4-5 half) + quality CHANGES-REQUESTED

The **W2-7 half is done**: C-2 and C-3 are closed and I re-verified both by execution, including a read-only dry run on the campaign tenant that now prints `Tuples still drifted: 2`. The **wave-4 overwrite shape** is pinned with wave 4's own numbers and is red against `dev`. The **W4-5 DN channel works** on the happy path and refuses atomically on the short path.

But W4-5's own stated invariant — *"`Σ lots == stock_levels.quantity` after the sale"* (`BatchTrackedSalesOrderConfirmFefoTest.php:290-294`) — **does not survive a customer return**, and the failure is not cosmetic: I made the very next delivery of stock the ERP says is on hand get **refused with a 422**. The lane taught the outbound document channel to move lots and left the inbound document channel lot-blind. That is a new, self-compounding, opposite-direction drift class shipped into the vertical this lane exists to protect, on the eve of first-tenant trading. It must be closed in-lane or the DN arm must be held.

---

## 0. What I verified BY EXECUTION

| # | Check | Command / evidence | Result |
|---|---|---|---|
| 1 | Lane DN suite, **PostgreSQL 16** | `artisan test -c phpunit-pgsql.xml tests/Feature/Document/BatchTrackedSalesOrderConfirmFefoTest.php` | **5 passed / 29 assertions** |
| 2 | Lane + neighbour suites, **sqlite** | `BatchTrackedSalesOrderConfirmFefo`, `InvoiceDeliveryNoteConfirmation`, `StandaloneInvoiceGuidedDelivery`, `MissingStockLevelConfirmRefusal`, `ImplicitReservationFefoLot`, `RepairPhantomDefaultBatchesCommand`, `SiblingSeamsUntrackedRemainder` | **59 passed / 3 skipped / 288 assertions** |
| 3 | Batch-adjacent regression, sqlite | `DemoPharmacySeederSalesInvoices`, `ReplenishmentActions`, `ComboReceipt`, `ReceiptStockPolicy`, `BatchChainE2E`, `ReceiptBatchAllocation` | **25 passed / 5 skipped / 150 assertions** |
| 4 | **C-2 on the campaign tenant** (`01a034af-…`), read-only dry run | `inventory:repair-phantom-default-batches --tenant=… --dry-run` | `WOULD REMAIN DRIFTED 1.0000` **on both tuples** + cause line; summary `Tuples still drifted: 2`; `Dry run: nothing was written` |
| 5 | Dry run really wrote nothing | psql on the tenant DB after | `stock_movements WHERE reference_type='batch_ledger_repair'` = **0**; `inventory_batch_movements` = **10** (unchanged); lots 8/9 still 29.0000 / 9.0000 |
| 6 | **PROBE A** — sell 5, then a `ReturnNote` of 5 (document channel) | my probe, PG | `after DN: aggregate=25.0000 Σlots=25.0000` → `after RETURN: aggregate=30.0000 Σlots=25.0000`, `untrackedRemainderAt=5.0000` |
| 7 | **PROBE A2** — same, then deliver the 30 the aggregate says exist | my probe, PG | **`InsufficientBatchStockException … Shortfall: 5.0000`** — a hard 422 on stock the ERP reports as on hand |
| 8 | **PROBE B** — aggregate 30, lots 10, deliver 20 | my probe, PG | **REFUSED** typed; `aggregate=30.0000`, `stock_movements=0`, document still `draft` → **atomic, no half-issue** ✅ |
| 9 | **PROBE C** — all stock in an **expired** lot, deliver 5 | my probe, PG | **REFUSED** `Shortfall: 5.0000` (aggregate 30 untouched) — behaviour change vs base |
| 10 | **PROBE D** — foreign hold of 18 on the earliest lot, deliver 5 | my probe, PG | **GRANTED**, consumed the **later** lot (early lot still 18.0000) — FEFO yields to `reserved_quantity` |
| 11 | **PROBE E** — batch-tracked product, aggregate 30, **no lots at all** | my probe, PG | **REFUSED** `Shortfall: 5.0000` |
| 12 | **PROBE F** — WAC after a lot-consuming DN | my probe, PG | `cost_price` 60.000000 → **60.000000**; movement `unit_cost=60.000000`, `total_cost=300.000000` — WAC untouched |
| 13 | **POS composite arm** (no lane test exists — I wrote one) | combo ×3, leaf 2/unit, batch-tracked leaf, lots 60 + 40 | `aggregate=94.0000 sumlots=94.0000 batch_legs=1 allocations=0` — **the new arm works**; snapshots absent by design |
| 14 | Red-proof, W4-5 DN | `DeliveryNoteService.php` checked out at `a78b5c334` | **3 failed / 2 passed** — all three W4-5 tests genuinely red-first |
| 15 | Red-proof, C-2 + C-3 | `RepairPhantomDefaultBatchesCommand.php` + `StockReservationService.php` at `1818ac42f` | **3 failed / 3** — both C-3 tests fail with the exact `Available: 0.0000, Requested: 5.0000` I measured in r2 |
| 16 | Red-proof, wave-4 overwrite shape | `BatchStockService.php` + `StockReservationService.php` at **dev `b339a8211`** | overwrite test **FAILS**; its companion *"neither raised nor silently shrunk"* is **green** on dev → no-regression pin, label it as such |
| 17 | sqlite genuinely rejects the FEFO SQL | raw PDO probe of the `FOR UPDATE OF ibs SKIP LOCKED` statement | `SQLITE REJECTED: near "FOR": syntax error` → the skip guard is **honest**, not a masked assertion |
| 18 | PHPStan L8 (live-DB env), 4 touched production files | | **`[OK] No errors`** |
| 19 | Pint `--test`, 4 production files + touched test dirs | | **`{"result":"pass"}`** |
| 20 | Rule 19 scan of every added production line | `git diff 1818ac42f...HEAD -- apps/api/app \| grep -E '^\+.*(\(float\)\|floatval\|parseFloat\|number_format\|Number\()'` | **0 hits** |
| 21 | `feature-lane-manifest-check.php` | | **EXIT=0** — 1404 Feature classes / 74 groups |
| 22 | `deptrac-ratchet.php` | | **PASS 182/182**, tree still clean |
| 23 | DPA baseline | `DocumentPerActionBaselineRatchetTest::repository_violations_match_the_baseline_exactly` | **PASS, 37 assertions — zero new violation keys** |
| 24 | C-1 blob reachability, recomputed | `git rev-list ca25991ee --not b339a8211` × `cat-file -e <c>:.deptrac.cache` | **identical 7 commits**, same blob `81e058d0d`, **55,666,691 B** |
| 25 | `.deptrac.cache` in HEAD's tree | `git ls-tree -r HEAD --name-only \| grep deptrac.cache` | **absent** ✅ |

---

# FINDINGS

## [CRITICAL] R3-1 — outbound now moves lots; inbound still does not. A sale-then-return leaves `Σ lots` BELOW `stock_levels`, and the next delivery is refused on stock the ERP says exists

`apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php:320-330` now consumes FEFO lots for a DN line with no `batch_id`. Its mirror, `apps/api/app/Modules/Document/Domain/Services/ReturnNoteService.php:723-731`, calls `wacService->recordReturn()` and **nothing else** — `grep -n 'batch\|Batch' ReturnNoteService.php` returns exactly **one** hit, a comment at `:172` about batch-fetching products. The customer-return channel on documents has no lot leg at all.

Before this lane both document arms were lot-blind, so the drift was one-directional (lots *overstate*, which is the W2-7/W4-5 symptom). This lane fixes one arm only, which creates the **opposite** drift and a hard failure:

```
[A]  after DN(5):      aggregate=25.0000   Σlots=25.0000
[A]  after RETURN(5):  aggregate=30.0000   Σlots=25.0000     <-- lots now UNDERSTATE
[A]  untrackedRemainderAt = 5.0000
[P2] then deliver 30:  InsufficientBatchStockException :: Shortfall: 5.0000   <-- 422, refused
```

(my probes, PostgreSQL 16, `autoerp_test_w27g3`; fixture = the lane's own `BuildsDeliveryPolicyFixtures` product with `requires_batch_tracking = true`, lots 18 + 12, aggregate 30.)

Two compounding consequences, both bad for the parapharmacy vertical:

1. **Hard refusal.** Every returned unit permanently shaves the lot ledger. Once cumulative returns exceed the free lot balance, `POST /api/v1/delivery-notes/{id}/confirm` returns `422 INSUFFICIENT_BATCH_STOCK` (`bootstrap/app.php:595-607`) while the stock screen shows the goods on hand. Nobody in the shop can diagnose that.
2. **Phantom regeneration.** `untrackedRemainderAt` now reports **5.0000** where the true untracked remainder is zero, so the next sibling seam (`StockAdjustmentService::receive`/`::adjust`, the counting listener, the `requires_batch_tracking` backfill, `StockReservationService:380`) will top a `DEFAULT` lot up to 5 via `ensureDefaultBatchForUntrackedRemainder()` — **re-minting the exact phantom this lane exists to eliminate**, and re-labelling returned goods from a short-dated lot as untracked stock with a `today + 365` FEFO rank. For a parapharmacy that is a product-safety defect, not just a ledger one.

The POS channel does not have this hole: `ReceiptReturnService::restoreBatchAllocations()` (`:1641-1706`) restores lots proportionally from `ReceiptLineBatchAllocation`. The document channel has no equivalent.

**Fix (in-lane).** Give `ReturnNoteService::receiveStockBack()` the symmetric arm, keyed to the same movement as the aggregate credit:
* preferred — resolve the source delivery's issue movements, read their `inventory_batch_movements` legs, and credit back proportionally (the `restoreBatchAllocations` shape, one leg per lot, `movement_id` = the new return movement);
* minimum viable — when the source lot cannot be resolved, credit `BatchStockService::ensureDefaultBatchForUntrackedRemainder()` **with its own `inventory_batch_movements` leg**, so `Σ lots` reconciles and the units are visibly untracked rather than silently missing.
Pin it with the exact probe above: sell 5 → return 5 → `Σ lots == stock_levels == 30`, then deliver 30 and assert it is **granted**.

*If the fix is deferred, the DN arm (`DeliveryNoteService.php:320-330`) must be held out of this merge* — a one-directional lot ledger that overstates is survivable for a week; one that hard-refuses deliveries on day one is not.

---

## [CRITICAL] R3-2 — the manifest union is stale against current `dev`: merging as-is REVERTS Session B's `Company` ceiling and lands the wrong `gated_ceiling`

Recomputed from both refs today (`apps/api/tests/feature-lane-manifest.json`):

| Key | dev `b339a8211` | lane `ca25991ee` | correct at merge |
|---|---|---|---|
| `gated_ceiling` | **1164** | 1167 | **1168** (dev + 4) |
| `Company.classes` | **31** | **30** | **31** (take dev verbatim) |
| `Document.classes` | 79 | **80** | 80 ✅ |
| `Inventory.classes` | 111 | **114** | 114 ✅ |

`dev` moved after the lane's `cd1e35323` dev merge: `8791eac5c` *"merge-prep: manifest Company 30→31 / gated 1163→1164 (Q-10, at merge)"* is on `dev` and **not** on the lane (`git log --oneline b339a8211 --not ca25991ee -- apps/api/tests/feature-lane-manifest.json`). The lane's file still carries dev's *pre-Q-10* `Company` entry, so a merge that resolves in the lane's favour drops `FiscalPeriodReopenEndpointTest` below the ceiling and **turns dev's own `feature-lane-manifest-check.php` red**. The handback's gate table ("EXIT=0 — no ceiling change") is true in-lane and irrelevant to the union — this is exactly the "re-derive as dev + 4 if dev moves" condition r2 left open.

**Fix:** `git merge dev` into the lane (or resolve at squash time), take dev's `Company` entry **verbatim** including its note, set `gated_ceiling` to **1168**, and re-run the checker.

---

## [CRITICAL-CONDITION, carried unchanged] R3-3 — C-1: the 55.6 MB blob is still reachable from the same seven commits. The merge MUST be a squash

Recomputed today, byte-identical to r2: blob `81e058d0d`, **55,666,691 bytes**, reachable as `<commit>:.deptrac.cache` from

```
1c3adf33b  d33f51089  fb469f423  391a787d1  99454be34  a6543b3a5  6911365a9
```

HEAD's tree is clean of it (`git ls-tree -r HEAD` → 0 hits) and `/.deptrac.cache` is ignored (`.gitignore:53`), but a `git merge` (ff **or** `--no-ff`) makes those seven commits ancestors of `dev` and the object travels to `origin` on the next promotion, permanently. Only `git merge --squash` (or a history rewrite) prevents it. The handback records this as the plan (§C-1); it is a hard blocker, not a preference. Record the choice in the merge ledger.

---

## [IMPORTANT] R3-4 — three shapes that used to ship now hard-refuse, none of them pinned or documented

Strict fulfilment is the right policy (it mirrors the POS FU-1 note at `ReceiptCreationService.php:1391-1401`), but `consumeBatchesAtomically()`'s candidate predicate (`FEFOInventoryService.php:226-231`) is narrower than "has lot stock", and the lane ships three new refusals silently:

* **expired lots** — `b.expiry_date >= <today>`: stock sitting in a lot that passed expiry is invisible to FEFO. PROBE C: aggregate 30 entirely in an expired lot, deliver 5 → **refused**. Arguably correct (do not ship expired goods) but it is a *policy decision* being made by a `WHERE` clause, with an English-only message and no owner sign-off.
* **no lots at all** — PROBE E: aggregate 30, zero lots → **refused**. `GoodsReceiptService.php:523-524` does force batch data for batch-tracked products, so the common inbound path is safe; but any tuple whose stock arrived by a path that did not mint a lot is now undeliverable.
* **reserved lots** — PROBE D: a foreign hold of 18 on the earliest lot makes the DN silently ship the **later** lot instead. Not wrong, but it means FEFO order is not what the docblock promises whenever `reserved_quantity > 0`, and the shipped lot is decided by hold state.

**Fix:** pin all three with tests (they are cheap — PROBE C/D/E are three fixtures), and state the expired-lot policy explicitly in `DeliveryNoteService::issueStock()`'s comment block, which currently says only "cannot be drawn from real lots".

---

## [IMPORTANT] R3-5 — the POS composite arm is NEW production code with ZERO tests

`apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:1259-1274`. `git diff a78b5c334 eca305416 -- apps/api/tests/` touches exactly one file, `BatchTrackedSalesOrderConfirmFefoTest.php` — nothing covers the composite leaf. Every combo test in `tests/Feature/POS/ComboReceiptTest.php` is `markTestSkipped` (`:63`, `:240`), and `ReceiptStockPolicyTest::composite leaf deduction respects policy` uses a non-batch-tracked leaf, so the new branch is dead in CI on both drivers.

I wrote the missing test myself and the arm **works** (`aggregate=94.0000 sumlots=94.0000 batch_legs=1 allocations=0`), so this is a coverage defect, not a correctness one — but it violates rule 2 and it means nobody will notice when it breaks. Three assertions are owed, all PG-only:
1. a batch-tracked leaf consumes FEFO and `Σ lots == stock_levels` (my probe verbatim);
2. `pos_receipt_line_batch_allocations` is **empty** for the leaf — pin the known gap so residual 3 cannot be quietly forgotten;
3. a short-lot leaf **aborts the whole receipt** (no `pos_receipts` row, no `stock_movements`, terminal `current_sequence` unadvanced).

## [IMPORTANT] R3-6 — the DN strict refusal is correct and atomic, and nothing pins it

PROBE B proves the good behaviour precisely: `aggregate=30.0000`, `stock_movements=0`, document still `draft`. That is the single most valuable property of the W4-5 change (it is what stops a half-issued delivery) and the lane's two new tests only cover fully-covered quantities (5 of 30, 25 of 30). One test, PG-only: lots 10 against aggregate 30, deliver 20, assert `InsufficientBatchStockException`, `stock_levels` unchanged, zero `stock_movements`, status still `Draft`. Add the multi-line variant too (line 1 fulfillable, line 2 short → **both** roll back), because `issueStock()` loops and `recordSale()` has already written line 1's movement by then.

## [IMPORTANT] R3-7 — C-2's dry run is honest but still structurally blind to most drift, and W4-5 moves the drift outside its window

`apps/api/app/Console/Commands/RepairPhantomDefaultBatchesCommand.php:182-187` — `if (bccomp($excess, '0', SCALE) <= 0) { continue; }` sits **above** the new prediction at `:214-215`, and the whole loop iterates `defaultLotRows()` (`:300-320`), i.e. tuples that *have* a `DEFAULT` lot. So a tuple is reported only when it is **both** drifted **and** already carrying a phantom. After R3-1, the new negative-drift class lives precisely in tuples with a healthy or absent `DEFAULT` lot — the census will report `Tuples still drifted: 0` on a tenant that is bleeding lot quantity on every return.

The handback's owner section already publishes the correct census as a raw SQL query. Make the command run it: hoist the drift read above the `excess` guard (it is a pure read of two sums) and emit `DRIFTED <n>` for any tuple where `Σ lots ≠ stock_levels`, phantom or not.

Secondary, minor: `$predictedDrift = ledgerDriftFor(...) − $excess` (`:214-215`) assumes the repair removes the **whole** excess, but `repairLot()` floors at remaining `reserved_quantity` and reports `lotsPartiallyReduced` (`:250-257`). On such a lot the prediction is optimistic. One clause of docblock, or clamp the prediction by the reservation floor.

## [IMPORTANT] R3-8 — reserve-lot ≠ consume-lot is now measurable, and the shipped lot appears nowhere on the document

The lane pins non-propagation deliberately (`BatchTrackedSalesOrderConfirmFefoTest.php:343-345`, `assertNull($deliveryNote->lines->first()?->batch_id)`), and I confirmed the divergence is real rather than theoretical: PROBE D reserved nothing, but with a foreign hold on the earliest lot the DN consumed the **later** lot. The same divergence occurs whenever a goods receipt lands an earlier-expiry lot between SO confirm and DN confirm.

Ledger-wise this is fine (R3-1 aside). Traceability-wise: `document_lines.batch_id` stays `NULL`, so a printed delivery note shows no lot number, and the only customer↔lot trail is `inventory_batch_movements.movement_id → stock_movements.reference_id → documents.partner_id`. That chain does work — I confirmed the leg is keyed to the issue movement (`BatchTrackedSalesOrderConfirmFefoTest.php:297-308`) — but it is a three-join reconstruction, not a document. For a recall in a parapharmacy that distinction matters. Either write the consumed lots back onto the DN line/payload, or say explicitly in the residual list that recall lookup is SQL-only today.

---

## MINOR

**R3-9** — `StockReservationService.php:175-183`: the active-reservation sum now carries `whereNull('variant_id')` (C-3, correct) but still has **no `company_id` / `tenant_id` predicate**, unlike the `stock_levels` lookup three lines above it (`:174`). Harmless under database-per-tenant; add it so the two halves of one comparison are scoped identically.

**R3-10** — `FEFOInventoryService.php:221-231`: on sqlite this raises a raw `SQLSTATE[HY000] … near "FOR": syntax error` (verified). Any future author adding a DN or combo test for a batch-tracked product on the default driver gets an opaque SQL error instead of "PostgreSQL required". A two-line driver guard throwing a descriptive exception would save a debugging session. Do **not** make it silently skip consumption — that would ship wrong data.

**R3-11** — `RepairPhantomDefaultBatchesCommand.php:69-77`: r2 MINOR 4 and MINOR 5 are **not** fixed, and the handback's "documented in the class docblock" is inaccurate. It still says the excess is *"recomputed under the lock"* (only the `DEFAULT` lot's row is locked; `phantomExcessFor()` → `aggregateQuantityFor()` at `:426-443` and the sibling `SUM` are unlocked) and still gives the wrong no-cycle reason (*"it locks exactly one batch-stock row"* — `reserve()` can touch two). The paragraph is now **additionally** stale: `consumeBatchesAtomically()` is a new batch-stock locker reachable from DN confirm and POS composite, in `expiry ASC` order — the opposite of `repairLot()`'s DEFAULT-first order. *The no-deadlock conclusion still holds*, and for a reason worth writing down: `consumeBatchesAtomically` uses `SKIP LOCKED`, so it never waits, and every one of the three paths takes the `stock_levels` row before any `inventory_batch_stock` row. Say **that**.

**R3-12** — `ImplicitReservationFefoLotTest.php:617-619` still carries the retracted sentence *"issuance stays FEFO (`FEFOInventoryService::consumeBatchesAtomically`)"*, so the handback's claim that r2 MINOR 6 "is gone — that docblock was rewritten wholesale" is wrong (a different docblock was rewritten). Harmless outcome, because W4-5 has now made the sentence **true** for the DN path — but correct the handback so the record stays trustworthy.

**R3-13** — `ReceiptCreationService.php:1272`: `productRequiresBatchTracking($line->component_id)` is one extra query per leaf per receipt, inside the receipt transaction. Negligible for combos of 2–3 components; note it before someone ships a 30-line recipe.

---

# Answers to the gate's questions

**1 — C-2 / C-3 / variant probe.**
*C-2 CLOSED, verified on the campaign tenant.* The prediction is `bcsub(ledgerDriftFor(), $excess)` at `:214-215`, computed above the `! $execute` branch; `ledgerDriftFor()` (`:485-507`) is `Σ lots − aggregate`, variant-scoped, and a pure read. The read-only dry run now prints `WOULD REMAIN DRIFTED 1.0000` on **both** wave-2 tuples plus the cause line, and the summary counter reads `Tuples still drifted: 2` — matching my r2 psql census exactly (two `issue`/`delivery` movements of −1.0000). The counter is honest and not double-counted (the dry-run arm `continue`s before the execute arm). Post-run psql confirms zero writes. **Caveat R3-7**: the prediction only runs for tuples that already carry a phantom `DEFAULT` lot, which is the wrong window after W4-5.
*C-3 CLOSED.* `StockReservationService.php:174` and `:180` both carry `whereNull('variant_id')`, matching `resolveDefaultBatchIdForImplicitReservation()` and `WeightedAverageCostService`'s canonical lock. My r2 probe is now the lane's own test and is **red against `1818ac42f`** with the exact message I measured (`Available: 0.0000, Requested: 5.0000`); the sibling-hold test is red the same way. Both green at HEAD, on sqlite and PG. Residual R3-9 (no company scope on the sum).

**2 — Overwrite shape, and "never shrink".**
Pinned with wave 4's numbers: real lots 30 + 20, `DEFAULT` already 15, aggregate 65 → the existing lot is left **exactly at 15**, `Σ lots` = 65 not 115 (`ImplicitReservationFefoLotTest.php:344-376`). Red against **current dev** — dev raises it to the full aggregate. **Leaving the shrink to the repair command is the right call**: reducing a lot is a stock quantity change and needs its own justifying movement, and doing it as a side effect of an order confirm is precisely the class of write this campaign is remediating. Two corrections to how it is framed, though. (a) The companion test *"neither raised nor silently shrunk"* is **green on dev** — it is a no-regression pin, not red-first evidence; label it. (b) The operational consequence for tenant #1 is concrete and belongs in the ops list, not in a policy paragraph: the campaign tenant carries phantom `DEFAULT` lots of **29.0000** and **9.0000** today. Until `--execute` runs, those 38 phantom units are *FEFO-consumable* — after W4-5 a delivery that outruns the real lots will draw from a lot literally numbered `DEFAULT` with a `today + 365` expiry, so the recall trail for those units is wrong from the first sale; `untrackedRemainderAt` stays clamped at 0 so every sibling seam stops backing genuinely untracked stock; and once returns start, R3-1's negative drift overlays the positive one and the census is measuring two defects at once. **Run `--execute` on the campaign tenant before day-one trading, not after.**

**3 — W4-5 DN channel.**
*Σ lots == stock after issue:* **YES** — 25/25 on the single-lot shape, 5/5 on the spill shape, one `inventory_batch_movements` leg per lot keyed to the issue movement, `-5.0000` signed. *Σ lots == stock after a return:* **NO — see R3-1.** *Expiry-aware order:* yes, `ORDER BY b.expiry_date ASC, b.created_at ASC` (`FEFOInventoryService.php:229`), verified by the spill test and by PROBE D's yield-to-reservation behaviour. *Partial availability refuses rather than half-issuing:* **YES, verified atomic** (PROBE B: typed exception, aggregate untouched, zero movements, document still draft) — but unpinned (R3-6). *WAC/COGS unchanged in amount:* **YES** — `WeightedAverageCostService.php` is not in the diff, no running-average / `workingScale()` / `unit_cost` / `total_cost` arithmetic is touched anywhere in the lane, and PROBE F confirms `cost_price` 60.000000 → 60.000000 with `unit_cost=60.000000`, `total_cost=300.000000` on the lot-consuming delivery. *Reserved lot == consumed lot:* **NO**, deliberately and now measurably (R3-8). *sqlite skip honest:* **YES** — sqlite genuinely cannot parse the statement (raw PDO probe), the guard mirrors `BatchChainE2ETest`'s, and no existing sqlite test silently loses coverage (I ran the six candidate suites). *Lock order / deadlock:* **deadlock-free.** All three lot-touching paths take the `stock_levels` row first — `reserve()` (`:174`), `recordSale()` (`WeightedAverageCostService.php:411-417`), `decrementStock()` (`ReceiptCreationService.php:898-909`) — and `consumeBatchesAtomically()` uses `SKIP LOCKED`, so it never waits on a row another transaction holds. The DEFAULT-first order of `reserve()`/`repairLot()` versus the expiry-ASC order of `consumeBatchesAtomically()` is an inversion that would matter if either waited; neither does. The class docblock still gives the wrong reason (R3-11).

**4 — POS composite arm, fiscal exposure.**
*Does it touch the sealed payload or hash inputs?* **NO.** The change captures `decrementStock()`'s return value and calls `consumeBatchesAtomically()`; it writes only `inventory_batch_stock` and `inventory_batch_movements`. It does not touch `pos_receipts`, `pos_receipt_lines`, VAT aggregates, totals, `previous_hash` or `chain_sequence`. *Same transaction as the seal, or after commit?* **Neither — it is inside the receipt-CREATION transaction, before the seal**, at `:1266` within the `DB::transaction` opened at `:137`; `createReceipt` returns a `pending_seal` receipt with `fiscal_hash = null` (`:513`, `:563`, `:587`) and the seal happens later in `ReceiptFinalizationService::finalize()`. That is **consistent with the direct-line path**: `allocateBatches()` is called at `:676` in the same loop, and `BatchChainE2ETest:232-249` pins it — after a 4-unit POS sale, `stock_levels` 6.0000 and batch stock 6.0000, asserted post-`createReceipt`. *Failure mode if lots are short at seal time:* `InsufficientBatchStockException` (a `\DomainException`, i.e. `\LogicException`) propagates out of `createReceipt`, rolling back the entire creation transaction — no receipt row, no `stock_movements`, no terminal `current_sequence` advance (`:643-644`), and `DB::afterCommit`'s `ReceiptDrafted` never fires. `ReceiptController::store` catches only `\RuntimeException` / `\InvalidArgumentException`, so it falls through to the global renderer → **`422 INSUFFICIENT_BATCH_STOCK`** with `details.shortfall` (`bootstrap/app.php:595-607`). Correct and clean. Two notes: (i) the refusal is **policy-blind by design** — a `pos_stock_policy` of `Warn`/`Off` relaxes only the aggregate check, so a combo that used to sell into negative stock now fails; that is the documented FU-1 asymmetry, but it is new for combos and unpinned (R3-5). (ii) `ExchangeService.php:233` calls `createReceipt` **after** `processReturn()` has already sealed the return half inside the same transaction (`:116`), so a short-lot combo leaf rolls back a sealed return receipt — no committed chain gap, but a new trigger for that path.
*Scope note the owner should hear:* the live device-authored POS lane is `PosCoreReceiptProjection::applyStockMovementForLines` (`:1689`), and that projection has **no lot handling at all** (`composite_item_id => null` at `:1296`; zero `consumeBatches`/`allocateBatches`/`inventory_batch_stock` references in the file). W4-5's POS work therefore covers `ReceiptController` / `OrderToReceiptService` / `ExchangeService` only. Device receipts keep overstating lots exactly as wave 4 measured. Not a lane defect — but the handback should say it, because "POS channel — already correct" reads as fleet-wide and is not.

**5 — Red-proof / PG / manifest / deptrac / DPA.**
Three (in fact **eight**) new tests independently red-proofed by me: the three W4-5 DN tests against `DeliveryNoteService.php@a78b5c334`; the two C-3 tests and the C-2 dry-run test against `StockReservationService.php`/`RepairPhantomDefaultBatchesCommand.php@1818ac42f`; the overwrite-shape test against `BatchStockService.php`+`StockReservationService.php@dev b339a8211`. Its companion is green on dev — a no-regression pin (R3-12 territory; label it). PG leg green (5/29). Manifest checker `EXIT=0` in-lane but the **union is wrong** (R3-2). Deptrac **PASS 182/182**, tree clean. DPA **PASS, 37 assertions, zero new keys**. PHPStan `[OK]`, Pint pass, rule-19 scan zero hits.

**6 — Blob list / `.deptrac.cache`.**
**Unchanged.** Same seven commits, same blob `81e058d0d`, same 55,666,691 bytes, recomputed against current dev. HEAD's tree contains no `.deptrac.cache`. C-1 stands as a hard merge-strategy blocker (R3-3).

---

## What to fix before merge

**R3-1** — give `ReturnNoteService::receiveStockBack()` the lot leg the DN channel now expects (or hold the DN arm); **R3-2** — re-merge `dev` and re-derive `gated_ceiling` = **1168** with `Company` = **31** taken verbatim; **R3-3** — squash-merge so blob `81e058d0d` never reaches `origin`. Then the three coverage debts (**R3-4/R3-5/R3-6**) and **R3-7**'s census widening; R3-8..R3-13 are doc/scope one-liners for the same round.
