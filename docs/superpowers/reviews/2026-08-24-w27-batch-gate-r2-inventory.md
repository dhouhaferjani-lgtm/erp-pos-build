# W2-7 gate r2 — inventory-costing lens (adversarial)

**Lane:** `fix/campaign-w27-phantom-default-batch` · worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w27-default-batch` · HEAD `1818ac42f`
**Reviewer:** inventory-costing-reviewer (r2) · **Date:** 2026-08-24
**Diff reviewed:** `git diff 6911365a9...HEAD` (fix-round commits `cdeff450c`, `c6aad0f58`, `62dc466a0`, `1818ac42f`)
**r1:** `docs/superpowers/reviews/2026-08-24-w27-batch-gate-r1-inventory.md`
**Lane worktree left pristine** (`git status --porcelain` → 0 lines, HEAD unchanged at `1818ac42f`). All tampering was done in a throwaway `git worktree` (`…/scratchpad/w27g2`, removed; `git worktree list` verified). Throwaway PG DB `autoerp_test_w27g2` created and **dropped**. `--execute` was **never** run against any tenant database.

# VERDICT: spec ✅ + quality APPROVED-WITH-CONDITIONS

Both r1 CRITICALs are **closed and verified by execution**. The tuple-level hold is genuinely one number applied in both branches, it refuses the mirror oversell, it still grants what the stock covers, and it holds under a **real two-process race on PostgreSQL**. Findings 3–13 are addressed, and I independently red-proofed five of the six new tests against the pre-fix files. Three conditions remain before merge (C-1 is a merge-strategy hard blocker; C-2 is a correctness-of-evidence defect in the command's own dry-run; C-3 is a new false-refusal surface this lane opens).

---

## 0. What I verified BY EXECUTION

| Check | Command / evidence | Result |
|---|---|---|
| Lane suites, sqlite | `phpunit ImplicitReservationFefoLotTest SiblingSeamsUntrackedRemainderTest RepairPhantomDefaultBatchesCommandTest BatchTrackedSalesOrderConfirmFefoTest MissingStockLevelConfirmRefusalTest` | **OK (34 tests, 168 assertions)** |
| Same set, **PostgreSQL 16** (`autoerp_test_w27g2`, 127.0.0.1:5433) | same paths, `DB_CONNECTION=pgsql` | **OK (34 tests, 168 assertions)** |
| Pre-existing seam suites | `StockReservationDefaultBatchTest`, `ExpireStockReservationsCommandTest`, `StockAdjustmentDefaultBatchTest`, `InventoryCountingDefaultBatchTest`, `EnsureDefaultBatchTest`, `FEFOSuggestionPrecisionTest` | **OK (24 tests, 95 assertions)** |
| **CRITICAL 1 probe (my own, not the lane's tests)** — 18+12 lots, agg 30 | reserve 25 → aggregate hold; then reserve 12 | **REFUSED** `InsufficientStockForFulfilmentException` "Available: 5.0000, Requested: 12.0000" |
| Same, on **`dev`** (`905d3fa7c`) with only `StockReservationService.php`+`BatchStockService.php` checked out at dev | identical probe | **GRANTED** on lot — 37 held against 30. Regression is real and is closed. |
| 25 + 5 vs 30 | | **GRANTED on the FEFO lot** (`batch_id` = earliest-expiry lot), activeSum 30 |
| Original double-confirm (30 then 30 on one 30-unit lot) | | **REFUSED**, activeSum 30 |
| Untracked (non-batch) product unaffected | 10-of-10 then 1 → refused; 6-of-10 → granted | **unchanged vs dev** |
| **Concurrent two-confirm race, PostgreSQL, two OS processes, spin-synchronised start** | 20 + 20 vs 30 | `W1 GRANTED` / `W2 REFUSED (Available 10.0000)`; final `active=20.0000, on_hand=30.0000` |
| **Concurrent MIXED race** (aggregate-spill vs lot) | 25 + 12 vs 30 | `LOT12 GRANTED (batch 58)` / `AGG25 REFUSED (Available 18.0000)`; final `active=12.0000` — the `stock_levels` row lock serialises both branches |
| Red-proof, CRITICAL 1 | `StockReservationService.php` at `6911365a9` | `test_a_lot_booked_confirm_cannot_oversell_…` **FAILS** ("exception … is not thrown") |
| Red-proof, findings 3/5/8/9/11 | `StockAdjustmentService`+`ReverseWriteOffService`+`RepairPhantomDefaultBatchesCommand` at `6911365a9` | **5 tests / 5 failures** — every one genuinely red-first |
| `.deptrac.cache` untracked + ignored | `git ls-files` → 0 hits; `git check-ignore -v` → `.gitignore:53:/.deptrac.cache` | ✅ |
| Ratchet no longer dirties the tree | `php apps/api/tools/deptrac-ratchet.php` then `git status --porcelain` | **PASS 182/182**, tree still clean |
| `feature-lane-manifest-check.php` | | **EXIT=0** — 1403 Feature classes / 74 groups |
| Manifest union vs **current** dev `905d3fa7c` | computed from both refs | dev `1163 / Document 79 / Inventory 111` → lane `1167 / 80 / 114`. **Only those two groups differ; every other group byte-identical.** Union arithmetic exact. No ceiling change claimed this round — correct, every new test landed in an existing class. |
| PHPStan L8, 8 touched production files (live-DB env) | | **`[OK] No errors`** |
| Pint `--test`, 8 production files + both test dirs | | **`{"result":"pass"}`** |
| Rule 19 scan of the added lines | `git diff … \| grep -E '^\+.*(\(float\)\|floatval\|parseFloat\|number_format\|Number\()'` | **0 hits** |
| DPA baseline | `DocumentPerActionBaselineRatchetTest::repository_violations_match_the_baseline_exactly` | **PASS, 37 assertions — zero new violation keys** |
| Aggregate-shaped `ensureDefaultBatch(` callers (re-run myself, whole repo, non-vendor, non-test) | | **ZERO.** Six callers of the remainder helper: `StockReservationService:380`, `StockAdjustmentService:1760`, `ProductController:928`, `OpeningBalancePostingService:166`, `ParapharmacySeeder:1389`, `BatchStockService:230`. Fifth seam closed. |
| **Campaign / wave-2 tenant dry-run** (`01a034af-94ea-713d-8ce0-462216bf6ab5`), read-only | `inventory:repair-phantom-default-batches --dry-run` | lot 8 **29.0000**, lot 9 **9.0000**, total **38.0000**, `Lots skipped … 0`, `Tuples still drifted … 0`, "nothing was written", EXIT=0 |
| Dry run wrote nothing | post-run psql | `stock_movements WHERE reference_type='batch_ledger_repair'` = **0**; `inventory_batch_movements` = **10** (unchanged); lot 8 = 29.0000, lot 9 = 9.0000 |
| WAC / valuation untouched | `git diff --name-status` | `WeightedAverageCostService.php` **not in the diff**; no `unit_cost`/`avg_cost`/running-average arithmetic modified anywhere in the lane; the repair movement carries `quantity = 0` with `quantity_before === quantity_after` |
| Document-per-action | code + DPA scanner | every quantity change still carries its own movement; the repair's lot write is the batch leg of its own `stock_movements` row; zero new DPA keys |

---

## FINDINGS

### ✅ CLOSED — r1 CRITICAL 1 (mirror oversell)

`apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php:153-190`. The hold is computed **once, before the branch**, as `max(stock_levels.reserved, Σ active StockReservation.quantity)`; `stock_levels` is locked FIRST (`:153-157`) and the per-lot check survives as a second, narrower condition inside `if ($batchId !== null)` (`:197-215`). Verified refused/granted/raced exactly as specified, on sqlite and PG, sequentially and under a real two-process race. Lock order is `stock_levels → inventory_batch_stock` in `reserve()`, matching the resolver at `:364-370`.

### ✅ CLOSED (in the tree) — r1 CRITICAL 2 (55.6 MB artifact) — but see C-1

Untracked in `cdeff450c`; `/.deptrac.cache` added at `.gitignore:53`; the ratchet no longer dirties the worktree (verified by running it).

---

### [CRITICAL-CONDITION] C-1 — the 55.6 MB blob is still reachable from 7 commits on this branch: the merge MUST be a squash

Blob `81e058d0d`, **55,666,691 bytes**, reachable from `<commit>:.deptrac.cache` in exactly these commits:

```
1c3adf33b  d33f51089  fb469f423  391a787d1  99454be34  a6543b3a5  6911365a9
```

Removing it from HEAD's *tree* keeps it out of `dev`'s working tree, but a `git merge` (ff **or** `--no-ff`) makes those seven commits ancestors of `dev`, so the object travels with the branch and is pushed to `origin` on the next promotion — permanently. Only a **squash merge** (or a history rewrite of the branch) makes it unreachable. The handback names this as an owner merge-strategy call (§Residual 5); it is not optional if the 55 MB must not enter `origin`.

**Fix:** merge this lane with `git merge --squash` (or rebase-drop `1c3adf33b`'s cache blob) and record the choice in the merge ledger.

---

### [IMPORTANT] C-2 — the DRY RUN, the command's whole reason to exist, still reports "drifted 0" on a tenant that IS drifted

`apps/api/app/Console/Commands/RepairPhantomDefaultBatchesCommand.php:195-197` — `if (! $execute) { continue; }` sits **above** the `ledgerDriftFor()` block at `:224-238`, so residual-drift detection runs only under `--execute`. The operator's evidence step therefore prints `Tuples still drifted after correction: 0` unconditionally.

**Proven on the campaign tenant** (`tenant01a034af-…`, my read-only run today):

```
Total phantom quantity: 38.0000
Lots skipped (excess changed under the lock): 0
Tuples still drifted after correction: 0      <-- FALSE
```

Independent psql census of the same tenant:

```
 id |      sku        |  batch_number   | quantity | stock_level
  8 | CREM-BEBE_200   | DEFAULT         |  29.0000 |   29.0000
  1 | CREM-BEBE_200   | LOT-CRÈME-2026A |  30.0000 |   29.0000   <- +1 drift survives the repair
  9 | SERU-ANTIAGE_30 | DEFAULT         |   9.0000 |    9.0000
  7 | SERU-ANTIAGE_30 | LOT-SÉRUM-2026D |  10.0000 |    9.0000   <- +1 drift survives the repair
```

and the cause, from `stock_movements` on that tenant:

```
 movement_type | reason   | quantity | before | after |      sku
 issue         | delivery |  -1.0000 |  30.00 | 29.00 | CREM-BEBE_200
 issue         | delivery |  -1.0000 |  10.00 |  9.00 | SERU-ANTIAGE_30
```

So after `--execute` **both** tuples will emit `RESIDUAL DRIFT 1.0000` — information the operator needed *before* deciding to execute, not after. r1 finding 9 is half-fixed: the honesty landed on the write arm, not on the evidence arm.

**Fix:** compute `ledgerDriftFor()` in the census arm too (it is a pure read) and print it as "would remain drifted" alongside the excess line, or hoist the drift block above the `! $execute` continue and word it accordingly. Extend `test_residual_ledger_drift_is_reported_rather_than_read_as_reconciled` to assert it on the `--dry-run` transcript.

---

### [IMPORTANT] C-3 — the new tuple guard reads a VARIANT-BLIND `stock_levels` row, and now gates the lot branch too — a new false-refusal surface

`StockReservationService.php:153-157` looks up `stock_levels` by `product_id + location_id + company_id` with **no `variant_id` predicate** and `->first()`. Two disagreements inside one call chain:

- the resolver 200 lines below uses `->whereNull('variant_id')` (`:368`);
- the codebase's canonical `stock_levels` lock is variant-scoped — `WeightedAverageCostService.php:174-175` (`where('variant_id', …)` / `whereNull('variant_id')`).

On `dev` this blind lookup was reached only by the aggregate branch, and never by a batch-tracked product (the phantom DEFAULT lot always resolved). This lane hoists it in front of **both** branches, so it now gates every lot-booked reservation.

**Proven by execution** (my probe, lane HEAD vs `dev`, same fixture — one 5-unit dated lot, `stock_levels` product-level row 5.0000, plus one empty variant row):

```
variant row inserted FIRST, then the product-level row → reserve 5.0000
   lane HEAD : REFUSED  InsufficientStockForFulfilmentException "Available: 0.0000, Requested: 5.0000"
   dev       : GRANTED  batch_id = 14
product-level row inserted first → both GRANT
```

The outcome depends on physical row order, i.e. it is arbitrary. Two further shapes:

```
variant rows ONLY, no product-level row, lot holds 5 → reserve 3  : GRANTED with batch_id = NULL
   (the resolver's whereNull finds nothing → aggregate branch → the guard reads a VARIANT row's quantity)
two variant rows 5 + 5, reserve 4 at product level               : GRANTED against ONE row's 5,
   while `activeHold` (:160-168, no variant predicate) sums reservations across BOTH variants
```

**Scoped honestly:** latent today — a census of every local tenant DB returns `variant_rows = 0` in all 12, and the wave-2 tenant has 5 product-level rows / 0 variant rows. It becomes a hard "insufficient stock" on sales-order confirm the day variant stock lands.

**Fix (in-lane, 2 lines):** add `->whereNull('variant_id')` at `:153-157` and the same predicate to the `StockReservation` sum at `:160-168`, so the guard is self-consistent with `:368` and with WAC. Note that this makes the variant-rows-only shape refuse — which is the correct refusal for a product-level reservation, and threading `variantId` through `reserve()` is the (separate) lane.

---

### [MINOR] 4 — "recomputed under the row lock" overstates what is actually locked

`RepairPhantomDefaultBatchesCommand.php:330-348` locks **only** the DEFAULT lot's `inventory_batch_stock` row. The recomputation then calls `phantomExcessFor()` → `aggregateQuantityFor()` (`:426-443`, plain `->value('quantity')`, no lock) and `trackedLotQuantityAt()` (an unlocked `SUM` over the sibling lots). So a concurrent receipt or adjustment between the recompute and the write is still possible; the SKIP narrows the window from *census→write* to *recompute→write*, it does not eliminate it. The class docblock (`:68-77`) should say that rather than implying the correction is computed from a fully locked state.

### [MINOR] 5 — the lock-order docblock's stated reason is wrong (the conclusion is right)

`RepairPhantomDefaultBatchesCommand.php:70-73` says "No cycle exists against `reserve()` (it locks exactly one batch-stock row)". `reserve()` can touch **two**: `ensureDefaultBatch()` UPDATEs the DEFAULT row (`BatchStockService.php:104-106`) when the remainder is positive, and then `lockForUpdate()`s the FEFO row (`StockReservationService.php:199-202`). The no-cycle conclusion still holds — both `reserve()` and `repairLot()` take the DEFAULT row *before* the real lot — but state the real reason, because the wrong one invites a future reordering.

### [MINOR] 6 — the retracted FEFO-issuance claim survives verbatim in a test docblock

`apps/api/tests/Feature/Inventory/ImplicitReservationFefoLotTest.php:418-420` still reads *"The aggregate branch still enforces `stock_levels` availability, and issuance stays FEFO (`FEFOInventoryService::consumeBatchesAtomically`)."* — the exact sentence finding 7 removed from the service. Delete or correct it; otherwise the false justification is still discoverable in the lane.

### [MINOR] 7 — one row of the handback's red-evidence table is overstated

`test_the_tuple_level_hold_still_grants_what_the_stock_genuinely_covers` is **green** against the immediately-preceding commit `6911365a9` in my run (`F.` = 1 failure of 2). It is a no-regression assertion, which is entirely legitimate — but the handback's "Red evidence" block lists it as failing with "Failed asserting that 1 is identical to 2". Correct the handback so the red-first record stays trustworthy.

### [MINOR] 8 — finding 12's fix mixes scopes for a variant opening line

`OpeningBalancePostingService.php:166-176` passes `aggregateQuantity: $quantityAfter` — derived from a **variant-blind** `stock_levels` lookup at `:106-109` (no `variant_id`, no `company_id`) — together with `variantId: $line->variantId`, which scopes the subtracted lot total to one variant. Product-level (product-grain) aggregate minus variant-scoped lots can over-seed the DEFAULT lot. For the non-variant opening (every opening in every local tenant today) the change is a strict improvement over passing `$line->quantity`. Same latent class as C-3; fix them together.

### [MINOR] 9 — undocumented, unpinned behaviour change on the explicit-`batchId` path

An explicit-batch reservation on a tuple with **no `stock_levels` row** now refuses (`InsufficientStockForFulfilmentException`); on `dev` it was granted (probe: lot 10, no aggregate row, reserve 4 → `dev` GRANTED batch 9, lane REFUSED). Likewise an explicit-batch reservation whose lot covers the request but whose aggregate does not (lot 10 / agg 2 / request 4) now refuses. Both refusals are the **correct** semantics — the aggregate is the on-hand truth — and a census shows **0** batch-stock rows without a matching `stock_levels` row in any local tenant DB. But it changes the contract for `reserveWithFEFO()` and `StockReservationController`, it is not pinned by a test, and the handback does not mention it. Add a one-line pin and a sentence.

### [MINOR] 10 — the new SUM guard converts a silent coercion into a throw on the confirm path

`BatchStockService.php:155-163` now throws `\RuntimeException` when `SUM(inventory_batch_stock.quantity)` is not a plain decimal. Verified safe today: Laravel's `Builder::sum()` returns int `0` (not `null`) for an empty aggregate, so the no-real-lots case does not hit the regex — confirmed green across the 24-test pre-existing seam sweep and on PG. Recorded only so nobody "simplifies" the `?: 0` behaviour it silently depends on.

---

## The residual the owner must see before the first tenant sells (not a lane defect — a launch fact)

The lane now **pins as intended behaviour** (`BatchTrackedSalesOrderConfirmFefoTest::test_delivery_note_confirm_releases_the_lot_hold_without_issuing_from_that_lot`) that a delivery-note line with no `batch_id` moves the aggregate 30 → 25 while **neither lot moves**, leaving `Σ lots = 30` against `stock_levels.quantity = 25` and `untrackedRemainderAt() = 0.0000`.

That is not hypothetical: it is exactly what produced the wave-2 tenant's surviving drift (two `delivery` movements of −1.0000 each, lots untouched — psql evidence above). Consequences for the parapharmacy vertical this lane exists to serve: after the first delivery the lot ledger permanently overstates on-hand, dated lots never deplete so FEFO ranks stale lots forever, the DEFAULT lot stops backing genuinely untracked stock (clamped at 0), and `inventory:repair-phantom-default-batches` will report growing `RESIDUAL DRIFT` on every re-run. The lane names it a follow-up (Residuals 2 and 3) — it needs to be a **dated, owner-visible lane before first sale**, not a bullet, and C-2 is what makes the operator see it at dry-run time.

---

## Answers to the gate's questions

**1 — CRITICAL 1.** Hold = `max(stock_levels.reserved, Σ active reservations)` computed once at `:159-175`, before the branch; `stock_levels` locked first at `:153-157`. All five probes verified by execution on sqlite **and** PG: 25→12 refused, 25→5 granted on the FEFO lot, original double-confirm refused, untracked unaffected. Concurrency: no harness existed, so I built one — two OS processes with a spin-synchronised start against PG; both the same-branch race (20+20) and the mixed aggregate-vs-lot race (25+12) produced exactly one grant. Lock order `stock_levels → inventory_batch_stock` is consistent with the resolver and with `repairLot()`'s DEFAULT-before-real-lot order; no cycle against DN confirm / POS decrement / adjustments (all reach `stock_levels` first via `lockStockLevel()` / `recordSale()`).

**2 — CRITICAL 2.** Gone from the index, `/.deptrac.cache` ignored (`.gitignore:53`), ratchet run leaves the tree clean. Blob `81e058d0d` (55,666,691 B) remains reachable from `1c3adf33b`, `d33f51089`, `fb469f423`, `391a787d1`, `99454be34`, `a6543b3a5`, `6911365a9` → **squash merge required** (C-1).

**3 — findings 3–13.** 3 ✅ (`aggregateQuantityFor()`, red-proofed with a discriminating sibling-variant row at 99). 4 ⚠ (recompute + SKIP counter land and are red-proofed; the "under the lock" claim overstates — MINOR 4). 5 ✅ (`StockMovementReferenceType::BatchLedgerRepair`, `->value` at the write, `en`/`fr`/`ar` labels; resolves through `EntryExitNoteController:289-291` instead of leaking a raw string). 6 ✅ (fifth seam `ParapharmacySeeder:1389`; my independent grep returns **zero** aggregate-shaped callers). 7 ✅ in the service, ⚠ stale copy survives in a test docblock (MINOR 6); the DN behaviour is now pinned honestly and the pin is accurate. 8 ✅ (`receive(creditsLotItself:)`, 30-vs-30 probe green, red-proofed). 9 ⚠ (execute arm yes, **dry-run arm no** — C-2). 10 ✅. 11 ✅ (red-proofed: "Output 'Repair run id' was printed"). 12 ✅ with MINOR 8. 13 ✅ documented, with MINOR 5 on the stated reason.

**4 — WAC / DPA.** `WeightedAverageCostService.php` is not in the diff; no running-average, `workingScale()`, `unit_cost` or `total_cost` arithmetic is touched by any path in this lane. The repair's justifying movement is `quantity = 0.0000` with `quantity_before === quantity_after` (now variant-scoped), so it cannot perturb WAC or the COGS coverage checker. Every quantity change in the lane still carries its own movement; DPA baseline passes with zero new keys.

**5 — red-proof / PG / manifest / deptrac.** Five of the six new tests independently red-proofed by me against the pre-fix files (5/5 failures); the sixth is a no-regression test (MINOR 7). PG leg 34/34. Manifest EXIT=0, no ceiling change claimed, union against **current** dev `905d3fa7c` = `1163 + 4` → **pin at merge: `gated_ceiling` 1167, `Document` 80, `Inventory` 114** (re-derive as `dev + 4` if dev moves). Deptrac **PASS 182/182**.

**6 — campaign dry-run.** 38.0000 total (29 + 9), `Lots skipped 0`, `Tuples still drifted 0`, "nothing was written"; post-run psql confirms 0 repair movements, `inventory_batch_movements` still 10, lots unchanged. The `drifted 0` line is false — see C-2.

---

## What to fix before merge

**C-1** squash-merge (or rewrite) so blob `81e058d0d` never enters `origin`; **C-2** report residual ledger drift in the `--dry-run` arm (`RepairPhantomDefaultBatchesCommand.php:195-197`) and assert it on the dry-run transcript; **C-3** add `whereNull('variant_id')` to the tuple lookup and the reservation sum at `StockReservationService.php:153-168`. MINOR 4–10 are one-liners/doc corrections; land them in the same round.
