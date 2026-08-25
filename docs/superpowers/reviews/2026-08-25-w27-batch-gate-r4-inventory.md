# W2-7 gate r4 — inventory-costing lens (R3-1 inbound restore + r3 fix round)

**Lane:** `fix/campaign-w27-phantom-default-batch` · worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w27-default-batch` · HEAD `50cc9fe66`
**Reviewer:** inventory-costing-reviewer (r4) · **Date:** 2026-08-25
**Diff reviewed:** `git diff ca25991ee...HEAD` — lane commits `0677382e6` (dev merge + manifest union) and `480f82946` (R3-1/R3-4/R3-5/R3-6/R3-7/R3-9), plus the `50cc9fe66` handback. Everything else in the range arrived through the dev merge and is out of lens.
**Prior rounds:** r1 / r2 / r3 (`2026-08-24-w27-batch-gate-r{1,2,3}-inventory.md`).
**Hygiene:** the lane worktree was left **pristine** — `git status --porcelain` → 0 lines, HEAD unchanged `50cc9fe66`. Every probe and every tamper ran in throwaway `git worktree`s (`…/scratchpad/w27g4` at HEAD, `…/scratchpad/devbase` at dev `df816b701`), both **removed** (`git worktree list` verified). Throwaway PG databases `autoerp_test_w27g4` and `w27g4_clone` created and **dropped**. One test process at a time; the full suite was never run. `--execute` was **never** run against any tenant database; the only tenant contact was a `--dry-run` on the wave-4 tenant, proven write-free afterwards by psql.

# VERDICT: spec ❌ + quality CHANGES-REQUESTED — **merge-blocking: YES**

R3-4, R3-5, R3-6, R3-7 and R3-9 are **closed and verified by execution**, including a tamper that reddens all five refusal/atomicity pins, a live census on the wave-4 tenant that matches my hand query exactly, and independent confirmation that the POS composite arm cannot perturb the sealed payload.

**R3-1 is not closed.** The restore arm resolves a returned unit's origin lot by netting *every* `inventory_batch_movements` leg of that lot and keeping only lots whose net is **negative**. A lot that arrived through a goods receipt carries a **positive** leg for its whole received quantity (`GoodsReceiptService.php:604-611` → `BatchStockService::receiveBatchStock()` → `recordBatchMovement()`), so its net can never go below zero and the lot is **permanently excluded** from the candidate set. On the actual wave-4 first-tenant data every real dated lot has exactly one leg and it is positive. The consequence, measured: the returned units land on a freshly minted `DEFAULT` lot ranked `today + 365` by FEFO — the exact phantom this lane exists to eliminate, re-minted on every return, with the recall trail for short-dated goods destroyed. The lane's three R3-1 tests pass only because their fixture seeds lots with `BatchStock::create` and **no ledger legs at all**.

And the manifest union is stale **again** — `dev` moved to `df816b701` during the fix round (N-6's Document raise landed), so merging the lane's manifest verbatim turns dev's own checker red on `Document`.

---

## 0. What I verified BY EXECUTION

| # | Check | Evidence | Result |
|---|---|---|---|
| 1 | Lane suites, **PostgreSQL 16** (`autoerp_test_w27g4`, 5433) — `BatchTrackedSalesOrderConfirmFefoTest` + `ReceiptStockPolicyTest` + `RepairPhantomDefaultBatchesCommandTest` | one process | **32 passed / 162 assertions** |
| 2 | Same three suites, **sqlite** | one process | **19 passed / 13 skipped / 98 assertions** (matches the handback) |
| 3 | **PROBE 1** — lane fixture shape (lots seeded with no ledger legs): sell 5 → return 5 | my probe, PG | early lot 13 → **18**, Σ lots == stock == 30, **no DEFAULT minted** ✅ |
| 4 | **PROBE 2** — **production shape**: the same two lots seeded through `receiveBatchStock(movementId: …)`, i.e. a goods receipt; sell 5 → return 5 | my probe, PG | early lot stays **13**; a **`DEFAULT` lot is minted at 5.0000** with expiry `today+365`; Σ lots == 30 (reconciles) but the units are re-labelled untracked → **R4-1** |
| 5 | **PROBE 3** — two lots ship 5+5, return 7 | my probe, PG | split **5 → early, 2 → late**, Σ lots == aggregate == 27 ✅ (lane-fixture shape only) |
| 6 | **PROBE 4** — second return with no matching shipment | my probe, PG | early lot **not** credited twice; the 5 lands on `DEFAULT`, Σ lots == 35 == aggregate ✅ |
| 7 | Wave-4 tenant lot shape (read-only psql) | `tenant01a035ba-…` | every real dated lot (`LOT-MAGNÉS-2026B`, `LOT-ARGÂN-2026D`, `LOT-SIRÔP-2026A/C`) has **exactly one leg, positive** (40/20/30/20) → R4-1 fires on day one |
| 8 | **Tamper** — swallow `InsufficientBatchStockException` in `DeliveryNoteService::issueStock()` | throwaway worktree, PG | **all five R3-4/R3-6 pins go red** (`refused atomically`, `short second line rolls back`, `expired lot`, `no lots at all`) + the three R3-1 tests → the pins genuinely bind |
| 9 | **R3-7 census, live** — `inventory:repair-phantom-default-batches --tenant=01a035ba-… --dry-run` | wave-4 tenant | excesses **62.0000 / 4.0000 / 2.0000** on three tuples; `Tuples still drifted: 0`; `Dry run: nothing was written` |
| 10 | The census matches a **hand** query | my psql, same LEFT-JOIN shape | drift `+62 / +4 / +2` on exactly those three tuples, `0` on the other six → **byte-for-byte match** |
| 11 | The dry run really wrote nothing | psql after | `stock_movements WHERE reference_type='batch_ledger_repair'` = **0**; `inventory_batch_movements` still **8**; `inventory_batch_stock` still **13** rows / 410.0000 |
| 12 | **R3-5 sealed-payload independence, re-run myself** | `V3ReceiptHashComputer.php` | **zero** `batch`/`Batch` references in the whole file; `createReceipt()` leaves `current_hash` NULL; the lane's digest-before/after-lot-mutation assertion is green |
| 13 | deptrac ratchet | throwaway worktree | **PASS — TOTAL 182/182**, no boundary regression |
| 14 | DPA baseline | `DocumentPerActionBaselineRatchetTest::repository_violations_match_the_baseline_exactly` | **PASS, 37 assertions — zero new violation keys** |
| 15 | PHPStan L8 (live-DB env), 5 touched production files | | **`[OK] No errors`** |
| 16 | Pint `--test`, 5 production + 3 test files | | **`{"result":"pass"}`** |
| 17 | Rule 19 scan of every added production line | `grep -E '\(float\)|floatval|number_format|Number\('` over `+` lines | **0 hits**; the only rounding is `QuantityScale::round(…, SCALE, HALF_UP/FLOOR)` |
| 18 | Manifest union vs **current** dev | file-set union of `tests/Feature/<group>` between `df816b701` and `50cc9fe66` | Document **union 83** vs lane **80** → **RED after merge** (`feature-lane-manifest-check.php:442` errors when `count($members) > $entry['classes']`) → **R4-2** |
| 19 | Inherited PG reds | same two suites run at HEAD **and** at dev `df816b701` on PG | **identical failure sets** — `ReturnNoteIntegrationTest` 3 (`it rejects/allows/caps … invoiced quantity`), `tests/Unit/POS/ReceiptReturnServiceTest` 11 (`ArgumentCountError`) → **inherited, confirmed** |
| 20 | C-1 blob reachability, recomputed against current dev | `git rev-list 50cc9fe66 --not dev` × `cat-file -e <c>:.deptrac.cache` | **identical 7 commits**, blob `81e058d0d`, **55,666,691 B** |
| 21 | `.deptrac.cache` in HEAD's tree | `git ls-tree -r 50cc9fe66` | **0 hits** ✅ |

---

# FINDINGS

## [CRITICAL] R4-1 — the R3-1 restore arm is **dead on every goods-receipt-sourced lot**. Returns re-mint the `DEFAULT` phantom instead of going back to the lot they left

`apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:419-441` (`outstandingShippedLots()`):

```php
->groupBy('ibm.batch_id')
->havingRaw('SUM(ibm.quantity) < 0')
->orderByRaw('MAX(sm.created_at) DESC')
```

The ceiling is the **net of every leg** the lot has ever had. But the inbound half of the ledger is not empty: `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:605-612` and `:654-661` call `BatchStockService::receiveBatchStock(… movementId: $movement->id)`, which writes a **positive** `inventory_batch_movements` leg (`BatchStockService.php:309-321` → `recordBatchMovement()` at `:436-460`) keyed to a `stock_movements` row that carries `product_id` and `location_id` — so it satisfies the INNER JOIN at `FEFOInventoryService.php:422-424` and is counted.

A lot that received 18 and shipped 5 nets **+13**, not −5. `HAVING SUM < 0` therefore excludes it, and can only ever exclude it: a lot cannot ship more than it received. **Every lot whose arrival was ledger-recorded is permanently invisible to the restore arm.**

Measured, PostgreSQL 16, the lane's own `BuildsDeliveryPolicyFixtures` product, the only difference from the lane's test being that the lots are seeded through `receiveBatchStock()` instead of `BatchStock::create()`:

```
[P2 after DN(5)]     aggregate=25.0000 sumLots=25.0000
    lot LOT-A exp=+30d qty=13.0000        leg batch=1 qty=18.0000 (receipt)
    lot LOT-B exp=+150d qty=12.0000       leg batch=2 qty=12.0000 (receipt)
                                          leg batch=1 qty=-5.0000 (delivery)
[P2 after RETURN(5)] aggregate=30.0000 sumLots=30.0000
    lot LOT-A qty=13.0000   <-- NOT credited
    lot LOT-B qty=12.0000
    lot DEFAULT exp=today+365 qty=5.0000  <-- the returned units
```

versus PROBE 1, byte-identical scenario with legless lots: `LOT-A 13 → 18`, no `DEFAULT`.

**This is not hypothetical for tenant #1.** Read-only psql on the wave-4 tenant `01a035ba-592b-72aa-a12a-1e6b2f7e1d06`: every real dated lot carries exactly **one** leg and it is **positive** — `LOT-MAGNÉS-2026B +40`, `LOT-ARGÂN-2026D +20`, `LOT-SIRÔP-2026A +30`, `LOT-SIRÔP-2026C +20`. Every one is excluded. Day one, every customer return on that tenant lands on `DEFAULT`.

Why it matters — this is the lane's own stated defect, restored:
1. **The phantom is re-minted on every return.** `--execute` zeroes the three `DEFAULT` lots (62/4/2); the first return afterwards starts refilling one. The repair becomes a treadmill, exactly as §9-1 of the handback argued the sibling seams would have made it.
2. **Product safety.** The handback's own docblock (`FEFOInventoryService.php:336-343`, `ReturnNoteService.php:736-745`) names the failure: short-dated goods re-labelled as untracked stock ranked `today + 365`. That is precisely what PROBE 2 produces — returned units from a lot expiring in 30 days now sit in a lot FEFO will ship **last**.
3. **Recall trail.** The `inventory_batch_movements` leg written for the credit points at the `DEFAULT` lot, so the three-join recall reconstruction the lane accepts as its residual (§Residuals 2) resolves returned goods to the wrong lot rather than to no lot.

The invariant the gate asked for (`Σ lots == stock_levels`) **does** survive — the DEFAULT fallback reconciles it, so the hard 422 from r3 is genuinely gone. What is not delivered is the headline claim, "the units go back on the lot they LEFT", which the lane asserts in a test (`BatchTrackedSalesOrderConfirmFefoTest.php:343-348`) that is green only because of its fixture.

**Fix.** The ceiling must be *outstanding outbound*, not *net of everything*. Restrict the positive side to legs that are themselves return credits:

```
outstanding(lot) = − [ SUM(ibm.quantity) FILTER (WHERE ibm.quantity < 0)
                     + SUM(ibm.quantity) FILTER (WHERE ibm.quantity > 0
                           AND sm.reason IN ('customer_return','pos_return')) ]
```

`MovementReason::CustomerReturn` / `POSReturn` already exist (`app/Modules/Inventory/Domain/Enums/MovementReason.php:11,31`), and this keeps the anti-double-credit property PROBE 4 verified. **Re-pin with a fixture that seeds the lot through `receiveBatchStock(movementId: …)`** — the current `seedLot()` helper (`BatchTrackedSalesOrderConfirmFefoTest.php:67-90`) cannot discriminate, and its comment at `:58-59` ("exactly as a goods receipt with explicit lots leaves the tuple") is what hid this.

---

## [CRITICAL] R4-2 — the manifest union is stale **again**: merging as-is REVERTS N-6's `Document` ceiling and lands the wrong `gated_ceiling`

`dev` moved after the lane's `0677382e6` merge. Current `dev` = `df816b701`.

| Key | dev `df816b701` | lane `50cc9fe66` | correct at merge |
|---|---|---|---|
| `gated_ceiling` | **1167** | 1168 | **1171** (dev + this lane's 4) |
| `Document.classes` | **82** | 80 | **83** (dev's 82 + `BatchTrackedSalesOrderConfirmFefoTest`) |
| `Inventory.classes` | 111 | **114** | 114 ✅ |
| `Company.classes` | 31 | 31 | 31 ✅ |

Verified by file-set union, not by arithmetic alone:

```
Document  dev=82  lane=80  union=83
Inventory dev=111 lane=114 union=114
Company   dev=31  lane=31  union=31
POS       dev=155 lane=155 union=155
```

`feature-lane-manifest-check.php:442` errors when `count($members) > $entry['classes']`, so a merge that resolves `Document` in the lane's favour lands 83 classes under an 80 ceiling and turns **dev's own checker red by 3**. dev's `Document` note records the deliberate 80 → 82 raise (N-6 `PostingMarkerPrintTest` + `DocumentStatusCheckConstraintParityTest`) — take that entry verbatim and add this lane's one class.

This is the same failure mode as R3-2, one group over. The lane's `EXIT=0` is true in-lane and says nothing about the union. **Re-merge `dev` immediately before the squash and re-derive**; if `dev` moves again before the merge lands, re-derive again — the union must be computed at merge time, not at handback time.

---

## [CRITICAL-CONDITION, carried unchanged] R4-3 — C-1: the 55.6 MB blob is reachable from the same seven commits. The merge MUST be `--squash`

Recomputed today against current dev, byte-identical to r2/r3: blob `81e058d0d`, **55,666,691 bytes**, reachable as `<commit>:.deptrac.cache` from

```
1c3adf33b  d33f51089  fb469f423  391a787d1  99454be34  a6543b3a5  6911365a9
```

HEAD's tree is clean (`git ls-tree -r 50cc9fe66 | grep deptrac.cache` → 0). A `git merge` (ff or `--no-ff`) makes those seven commits ancestors of `dev` and the object travels to `origin` permanently. **`git merge --squash` is mandatory** — record it in the merge ledger.

---

## [IMPORTANT] R4-4 — the restore arm mints `DEFAULT` lots through a raw INSERT that bypasses the variant guard, and it is not variant-scoped while its outbound mirror is

`FEFOInventoryService.php:520-566` (`defaultBatchId()`) inserts into `product_batches` with `DB::table(…)->insertGetId()` at `:543`. The house path, `BatchStockService::findOrCreateBatch()` (`:252-297`, guard at `:263-267`), first runs

```php
if ($variantId === null) {
    $activeVariants = $this->variantLookup->listForProduct($productId, true);
    if ($activeVariants->isNotEmpty()) { throw MissingVariantException::forProduct($productId); }
}
```

— "a variant-bearing product must never receive a product-level batch". The new mint has no such guard and will happily create one.

It gets there because the call site never passes a variant: `ReturnNoteService.php:834-853` (`restoreBatchStock()`) omits `variantId`, so it defaults to `null` and `outstandingShippedLots()` applies `whereNull('pb.variant_id')` (`:429`). The outbound mirror **does** scope it — `DeliveryNoteService.php:348-355` passes `variantId: $line->variant_id`. So on a variant-bearing batch-tracked product the delivery consumes the variant's lots and the return looks for product-level ones, finds none, and mints a forbidden product-level `DEFAULT`.

Mitigating and worth stating in the same breath: `WeightedAverageCostService::recordReturn()` (`:540-548`, lane worktree) has **no** `variantId` parameter either, so the aggregate credit on this path is already product-level — the variant asymmetry is pre-existing above the lot layer. But the guard bypass is new, and a raw insert also skips the model layer entirely. Either thread `$line->variant_id` through (and accept it will not match the product-level aggregate credit until `recordReturn` is fixed), or refuse rather than mint when the product has active variants.

## [IMPORTANT] R4-5 — the whole inbound restore arm is skipped on the default test driver

All three R3-1 tests call `skipUnlessPostgres()` (`BatchTrackedSalesOrderConfirmFefoTest.php:331, 384, 412`). The skip exists because `consumeBatchesAtomically()` uses `FOR UPDATE … SKIP LOCKED`, which SQLite cannot parse — but `restoreBatchesForReturn()` uses a plain `lockForUpdate()` and no PG-only syntax, and `test_a_return_with_no_resolvable_origin_lot_lands_on_the_default_lot_with_a_leg` never confirms a delivery note at all. It is skipped for a reason that does not apply to it. On the sqlite leg (the default `phpunit.xml`, and the driver most reviewers reach for) the entire inbound arm is **unexercised**. Drop the skip on the tests that do not need the DN, and seed the outbound leg directly where one is needed.

## [IMPORTANT] R4-6 — the lane taught the POS composite arm to CONSUME lots; nothing restores them on a composite return (code-read only — I could not execute this one)

`ReceiptReturnService::restoreBatchAllocations()` (`:1641-1651`; the empty-guard is `:1647-1651`) returns early when the original line has no `ReceiptLineBatchAllocation` rows, and the lane's own test pins that a composite leaf writes **none** (`ReceiptStockPolicyTest.php:274-280`). The return loop restores `$originalLine->product_id` (`:486-499`) and the file contains only two `composite` references (`:1013`, `:1261`), neither of which decomposes a combo — so the leaf's lots are never credited back.

I am flagging this as an asymmetry to *state*, not as measured drift: because the aggregate side appears not to decompose either, the leaf's aggregate and its lots may both stay decremented and remain in sync. **I could not verify by execution** — `tests/Unit/POS/ReceiptReturnServiceTest` is 11-of-14 red with `ArgumentCountError` on both drivers (see R4-9), including the two tests that would have answered this (`partial return restores batch stock proportionally`, `full return after partial caps cumulative batch restitution`). Either construct the probe in-lane or record the open question in the residual list; do not let "the POS channel is already correct" stand unqualified.

## [IMPORTANT] R4-7 — two restore policies now exist for the same physical event, and they disagree

Document returns resolve the origin lot from the **movement ledger** (`FEFOInventoryService::restoreBatchesForReturn()`); POS returns resolve it from the **per-line allocation snapshot** (`ReceiptReturnService::restoreBatchAllocations()`), and when the snapshot is absent the POS arm **silently restores nothing** while the document arm falls back to `DEFAULT`. Neither calls the other (grep: no `ReturnNoteService` reference anywhere in `app/Modules/POS`), so there is no double-restore — but a return of the same goods through two channels reconciles the lot ledger in one and drifts it in the other. Name the divergence in the residual list, or converge the POS fallback on the same `DEFAULT` arm.

---

## MINOR

**R4-8** — `FEFOInventoryService.php:437`: `orderByRaw('MAX(sm.created_at) DESC')` computes "most-recently-shipped" as the max over **all** legs of the lot, inbound included, so a lot that received stock yesterday sorts ahead of one that shipped today. Moot while R4-1 keeps the arm dead; wrong once it is fixed. Use `MAX(sm.created_at) FILTER (WHERE ibm.quantity < 0)`.

**R4-9** — `FEFOInventoryService.php:421-434`: the shipment-history query carries no `sm.company_id` (and no tenant) predicate, one round after R3-9 added exactly that scoping to `StockReservationService`'s hold sum for exactly that reason. Redundant under database-per-tenant, free, and consistent.

**R4-10** — `FEFOInventoryService.php:34` / `:37`: `DEFAULT_BATCH_NUMBER` / `DEFAULT_SHELF_LIFE_DAYS` are duplicated from `BatchStockService` (correctly — Domain must not import Application) but nothing pins the two pairs equal. `grep` confirms no test asserts it. One assertion in `EnsureDefaultBatchTest` would stop a silent divergence.

**R4-11** — `BatchTrackedSalesOrderConfirmFefoTest.php:58-59`: *"30 physical units, entirely inside two dated lots, exactly as a goods receipt with explicit lots leaves the tuple"* — this is false (a goods receipt also leaves a positive ledger leg) and it is the premise that made R4-1 invisible for a whole round. Correct the comment when the fixture is fixed.

**R4-12** — inherited red worth a LEDGER row, not a lane block: `tests/Unit/POS/ReceiptReturnServiceTest` fails 11 tests with `ArgumentCountError` (`ReceiptReturnService::__construct()` arity) on **both** drivers, verified identical at dev `df816b701`. Two of the dead tests are the POS lot-restore pins. The claim "the POS channel already restores lots correctly" currently rests on tests that do not execute anywhere.

---

# Answers to the gate's questions

**1 — R3-1 `restoreBatchesForReturn()`.**
*Credits the lots the tuple shipped?* **Only when the lot has no inbound ledger leg.** With a goods-receipt-sourced lot — the production shape, and the shape of every real lot on the wave-4 tenant — the lot is excluded and the credit goes to `DEFAULT` (**R4-1**, PROBE 2).
*Most-recently-shipped first, capped by outstanding outbound net?* The cap is implemented as *net of all legs*, which is the defect; the ordering is `MAX(sm.created_at)` over all legs (R4-8). Within the legless shape the cap behaves: PROBE 3 split a 7-unit return as 5 → early (its full outstanding) + 2 → late, Σ lots == aggregate == 27.
*One `BatchMovement` leg per credit?* **Yes** — written through the model (`FEFOInventoryService.php:507-512`), keyed to the return's own receipt movement, positive; the lane's leg test is green and DPA stays at zero new keys.
*Probes:* sell 5 → return 5 → Σ lots == stock == 30 → deliver 30 **granted** ✅ (PROBE 1, legless shape). Sell 5+5 from two lots → return 7 → correct split ✅. Second return cannot double-credit ✅ (PROBE 4 — the already-credited lot nets to 0 and drops out). Return of a never-lot-attributed sale lands on `DEFAULT` with a leg ✅.
*POS return path — same service or its own?* **Its own**, and with a different resolution policy and a different empty-case behaviour (**R4-7**). No double-restore.

**2 — R3-4 / R3-6 pins.** All five are present and **all five go red** under a single tamper (swallowing `InsufficientBatchStockException` in `DeliveryNoteService::issueStock()`): single-line atomic refusal, multi-line rollback, expired-lot refusal, no-lots refusal, held-lot skip. The policy is now **stated** in `DeliveryNoteService.php:316-338` — expired lots invisible to FEFO with disposal routed to a write-off document, no-lots as a refusal, reserved lots skipped so "FEFO" means earliest expiry *among free lots*, and the atomicity requirement spelled out. That is what r3 asked for. ✅

**3 — R3-5.** Composite-leaf FEFO ✅, empty-allocation gap pinned ✅, short-lot leaf aborts the whole receipt under `PosStockPolicy::Off` ✅ (all three green on PG, all three skipped on sqlite by the same `FOR UPDATE SKIP LOCKED` constraint). *Sealed-payload independence, re-run by me:* `V3ReceiptHashComputer.php` contains **zero** `batch`/`Batch` references — the payload cannot read the lot tables — and `createReceipt()` leaves `current_hash` NULL, so the draw completes before any hash exists. The lane's before/after digest assertion across a lot mutation is green. ✅

**4 — R3-7.** `reportLedgerDrift()` (`RepairPhantomDefaultBatchesCommand.php:490-528`) now iterates `batchTrackedTuples()` (`:540-567`), a `LEFT JOIN` over **every** batch-tracked `(product, variant, location)` tuple of the company, in **both** arms, signed, with a per-direction cause hint. Run read-only on the wave-4 tenant it reports excesses 62/4/2 and `Tuples still drifted: 0`; my independent hand query returns drift `+62 / +4 / +2` on exactly those three tuples and `0.0000` on the other six — an exact match. Post-run psql confirms zero writes (`batch_ledger_repair` movements 0, legs still 8, batch-stock rows still 13). Both new census tests are green on sqlite too, so the `IS NOT DISTINCT FROM` predicate is portable. ✅ *Caveat now that R4-1 is known:* this census is the only thing that will surface the `DEFAULT` lots R4-1 re-mints, so it must stay in the ops runbook after `--execute`.

**5 — Gates.** deptrac **182/182 PASS**; the Domain-tier placement is **legitimate, not laundering** — `restoreBatchesForReturn()` sits beside its outbound mirror `consumeBatchesAtomically()` in the same class and tier, uses the same raw-SQL + row-lock idiom, and the constant duplication (rather than importing the Application service) is the honest way to avoid a `ModuleDomain → ModuleApplication` edge; the only cost is the unpinned mirror (R4-10). DPA **zero new keys** (37 assertions). PHPStan L8 **`[OK]`**; Pint **pass**; rule-19 scan **0 float hits**. Manifest union **WRONG** — recomputed against **current** dev `df816b701`: `Document` must be **83**, `gated_ceiling` **1171**, `Inventory` 114, `Company` 31 (**R4-2**). The two new inherited PG reds are **verified inherited**: `ReturnNoteIntegrationTest` 3 failures and `tests/Unit/POS/ReceiptReturnServiceTest` 11 `ArgumentCountError`s reproduce identically at dev `df816b701` (note: the handback says 14 for the POS file; the run splits 3 + 11 = 14 across the two files).

**6 — Blob / `.deptrac.cache`.** **Unchanged** — same seven commits, blob `81e058d0d`, 55,666,691 B, recomputed against current dev. HEAD's tree carries no `.deptrac.cache`. **Squash-merge instruction stands for the parent (R4-3).**

---

## What to fix before merge

**R4-1** — make the restore ceiling *outstanding outbound* (outbound legs net of prior return credits, via `sm.reason`), and re-pin it with a fixture that seeds lots through `receiveBatchStock(movementId: …)` — the current fixture cannot fail; **R4-2** — re-merge `dev` at merge time and take `Document` = **83**, `gated_ceiling` = **1171**; **R4-3** — `git merge --squash`. Then R4-4 (variant guard bypass), R4-5 (drop the unnecessary sqlite skips), R4-6/R4-7 (POS composite restore + the two-policy divergence, stated or closed); R4-8..R4-12 are one-liners for the same round.
