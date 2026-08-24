# W2-7 gate r1 — inventory-costing lens (adversarial)

**Lane:** `fix/campaign-w27-phantom-default-batch` · worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w27-default-batch` · HEAD `6911365a9`
**Reviewer:** inventory-costing-reviewer (r1) · **Date:** 2026-08-24
**Diff reviewed:** `git diff dev...HEAD` (dev tip at review time `6eefa1122`)
**Lane worktree left pristine** (`git status --porcelain` → 0 lines at end of review). No `--execute` was run against any tenant database. Tampering was done in a throwaway worktree (`.../scratchpad/w27probe`, removed) and a throwaway PG database `autoerp_test_w27g` (dropped).

# VERDICT: spec ❌ + quality CHANGES-REQUESTED

The core policy (DEFAULT lot = `stock_levels.quantity − Σ real lots`, clamped at 0; FEFO lot for the hold) is **correct, well-tested and green on sqlite + PostgreSQL**. The lane is blocked on two things: (a) the multi-lot **aggregate fallback that this lane introduces opens a NEW oversell hole in the mirror direction of the one it closed — proven by execution, and proven ABSENT on `dev`**; (b) a **55.6 MB build artifact committed at the repo root**.

---

## 0. What I verified by execution

| Check | Command / evidence | Result |
|---|---|---|
| Lane tests, sqlite | `php vendor/bin/phpunit tests/Feature/Inventory/{ImplicitReservationFefoLotTest,SiblingSeamsUntrackedRemainderTest,RepairPhantomDefaultBatchesCommandTest}.php tests/Feature/Document/{BatchTrackedSalesOrderConfirmFefoTest,MissingStockLevelConfirmRefusalTest}.php` | **OK (25 tests, 133 assertions)** — N-2's `MissingStockLevelConfirmRefusalTest` stays green |
| Same set, PostgreSQL 16 | `DB_CONNECTION=pgsql DB_PORT=5433 DB_DATABASE=autoerp_test_w27g …` | **OK (25 tests, 133 assertions)** |
| Pre-existing seam suites | `StockReservationDefaultBatchTest`, `ExpireStockReservationsCommandTest`, `StockAdjustmentDefaultBatchTest`, `InventoryCountingDefaultBatchTest`, `StockAdjustmentBatchDispositionTest`, `EnsureDefaultBatchTest` | **OK (49 tests, 143 assertions)** |
| Inherited BatchExpiry 8 errors | Lane: `Tests: 14, Assertions: 9, Errors: 8, Skipped: 2`. Pure `dev` tree in a clean worktree: **identical** `14/9/8/2` | **genuinely inherited** ✅ |
| PHPStan L8 on the 3 largest touched files | `vendor/bin/phpstan analyse` | **[OK] No errors** |
| Pint `--test` on all 5 touched production files | | **`{"result":"pass"}`** |
| `feature-lane-manifest-check.php` | | **EXIT=0** |
| `deptrac-ratchet.php` | | **TOTAL 182/182 — PASS** |
| DPA baseline direction (a)+(b) | `DocumentPerActionBaselineRatchetTest::repository_violations_match_the_baseline_exactly` | **PASS** (direction (c) fails on unset `DPA_BASELINE_PROTECTED_BLOB` — environmental, as handback states) |
| DPA classification of the repair command's writes | scanner probe over `app/` | `:328 stock_movements::create#1` = **linked**, `:360 inventory_batch_stock::update#1` = **linked**, `:406`/`:411` = **not_applicable** — claim confirmed, zero new keys |
| Wave-2 tenant dry-run | `php artisan inventory:repair-phantom-default-batches --tenant=01a034af-94ea-713d-8ce0-462216bf6ab5 --dry-run` | lot 8 excess **29.0000**, lot 9 excess **9.0000**, **total 38.0000** — reproduces the handback exactly |
| Independent psql census | `tenant01a034af-…` | lot 8 DEFAULT 29.0000 / real lot 30.0000 / stock_level 29.0000; lot 9 DEFAULT 9.0000 / real lot 10.0000 / stock_level 9.0000 — matches |
| Dry-run wrote nothing | post-run psql | `stock_movements WHERE reference_type='batch_ledger_repair'` = **0**; `inventory_batch_movements` = **10** (unchanged); lot 8 = 29.0000, lot 9 = 9.0000 |
| Command refusals | | no scope → **exit 2**; neither flag → **exit 2**; both flags → **exit 2**; unknown tenant → **exit 2** |
| Manifest union vs CURRENT dev `6eefa1122` | computed | dev `gated_ceiling` 1163, `Document` 79, `Inventory` 111 → lane 1167 / 80 / 114; **no other group differs**. **Union arithmetic is exactly right: 1163 + 4 = 1167.** No re-pin needed provided dev's manifest has not moved at merge time. |

---

## FINDINGS

### [CRITICAL] 1 — The oversell fix is one-directional; the mirror hole is REACHABLE, and it is a REGRESSION against `dev`

`apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php:144-192` (aggregate branch, new `max()` hold) and `:111-124` (batch branch, unchanged).

**What's wrong.** The addendum closed *aggregate-branch reads a lot-booked hold*. It did **not** close *batch-branch reads an aggregate-booked hold*. The batch branch at `:119` still computes availability as `batchStock.quantity − batchStock.reserved_quantity` and never consults `stock_levels.reserved` or the active-reservation sum. Because `resolveDefaultBatchIdForImplicitReservation()` now **returns `null` whenever no single lot covers the request** (`:362-364`), a batch-tracked product can now hold a MIX of aggregate-booked and lot-booked reservations — a state that could not exist before this lane, because the phantom DEFAULT lot always absorbed the implicit reservation.

**Proven by execution** (probe worktree, lane HEAD `6911365a9`), on **both** sqlite and PostgreSQL 16, using the lane's own fixture shape (2 dated lots 18 + 12, `stock_levels.quantity = 30.0000`):

```
reserve 25.0000  -> no single lot covers -> aggregate branch -> batch_id = null, stock_levels.reserved = 25.0000
reserve 12.0000  -> FEFO finds LOT-B with available_quantity 12 (no lot ever saw the 25 hold)
                 -> BATCH branch: 12 - 0 = 12 >= 12 -> GRANTED

PROBE total active reservation quantity = 37.0000 against on-hand 30.0000; refused=NO   (sqlite)
PROBE total active reservation quantity = 37.0000 against on-hand 30.0000; refused=NO   (PostgreSQL 16)
```

**Proven NOT to exist on `dev`** — the identical probe, with only `StockReservationService.php` + `BatchStockService.php` checked out at `dev`:

```
PROBE first hold batch_id = 3                       (the phantom DEFAULT lot absorbs it)
PROBE: second reserve REFUSED: RuntimeException — Insufficient batch stock. Available: 5.0000, Requested: 12.0000
PROBE total active reservation quantity = 25.0000 against on-hand 30.0000; refused=yes
```

So the lane trades a phantom-lot defect for an **oversell defect**. `ImplicitReservationFefoLotTest:338-359` pins the fallback as *desired behaviour* and asserts `stock_levels.reserved = 25.0000` — the exact state that makes the next lot-booked reservation over-commit. Both directions are the same bug (`reserve()` writes the hold to the lot **or** the aggregate row, never both); fixing only one is not a fix.

**Why it matters.** Two sales orders on one batch-tracked parapharmacy product can now reserve 37 units of a 30-unit stock. `MovementReason` and the WAC ledger are untouched, but the fulfilment promise at rest is wrong, and the eventual delivery of the second order will drive `stock_levels.quantity` negative or fail at DN confirm after the customer was told the stock was held.

**Exact fix (pick one, in-lane):**
- (a) **Preferred, symmetric:** compute the hold ONCE, before branching, as `max(stock_levels.reserved, SUM(active StockReservation.quantity for the tuple))`, and apply it in BOTH branches — the batch branch must refuse when `stock_levels.quantity − hold < quantity`, *in addition to* its per-lot check. This is 6 lines and needs the `stock_levels` row lock hoisted above the branch (it is already taken for the implicit path at `:323-329`).
- (b) **Alternative:** make the batch branch write `stock_levels.reserved` too (with symmetric decrements in `release()` `:441-455` and `expire()`), which is the lane it deferred — larger, and it inverts `StockReservationDefaultBatchTest:96`.
- (c) **Minimal stop-gap:** remove the aggregate fallback — refuse (typed `InsufficientStockForFulfilmentException`) when no single lot covers an implicit request on a batch-tracked product. This restores dev's refusal semantics without the phantom lot. It changes `ImplicitReservationFefoLotTest:338`'s expectation.

Add a red-first test for the **aggregate-then-lot** order, not only the lot-then-aggregate order.

---

### [CRITICAL] 2 — A 55.6 MB build artifact is committed at the repo root

`.deptrac.cache` — added in commit `1c3adf33b`, blob size **55,666,691 bytes**, containing absolute paths of this lane's worktree (`/Users/houssamr/.../.worktrees/w27-default-batch/...`).

`.gitignore:52` ignores `apps/api/.deptrac.cache` only; the ratchet script writes the cache at the **repo root**, which is not ignored. Rule 10 ("check for build artifacts before committing") — and merging this puts 55 MB in `dev`'s history permanently and pushes it to `origin` on the next promotion. It is also a churn magnet: simply running `php apps/api/tools/deptrac-ratchet.php` during this review dirtied the lane worktree (`M .deptrac.cache`), which I restored.

**Fix before merge:** drop the file from every commit on the branch (`git rm --cached .deptrac.cache` + rewrite/squash so the blob is not reachable from the merge commit) and add `/.deptrac.cache` to `.gitignore`.

---

### [IMPORTANT] 3 — Repair command records a variant-blind `quantity_before/quantity_after` on its justifying movement

`apps/api/app/Console/Commands/RepairPhantomDefaultBatchesCommand.php:320-326` vs `:250-258`.

`phantomExcessFor()` correctly resolves the aggregate with a variant predicate (`:254-257`, `whereNull('variant_id')` / `where('variant_id', …)`). The movement's `quantity_before` / `quantity_after` at `:320-326` uses the **same three keys minus the variant predicate** and `->value('quantity')` — so for a variant-bearing product at a location with several variant `stock_levels` rows it stamps an arbitrary row's quantity, and if the product-level row is absent it stamps `0.0000` (via `decimal(null)` at `:494-510`) while the real aggregate is non-zero. The movement is the audit document for a lot correction; its recorded on-hand must be the same tuple the excess was computed from.

**Fix:** thread `$variantId` into the `:320-326` query exactly as `:254-257` does.

---

### [IMPORTANT] 4 — `--execute` applies a STALE excess under a fresh lock

`RepairPhantomDefaultBatchesCommand.php:151` (census read, **no lock**) → `:175` → `:304` (`$target = $current − $excess`, where `$current` is re-read **under** the lock at `:298`).

The excess is computed from an unlocked census and then subtracted from a freshly locked quantity. Any concurrent receipt/adjustment between the two reads makes the correction wrong in one direction or the other (a concurrent lotless receipt under-removes; a concurrent aggregate increase over-removes). For a command whose whole justification (`:39-47`) is "an operator must look at the numbers before anything is written", the write must be computed from the locked state.

**Fix:** recompute `phantomExcessFor()` **inside** `repairLot()`'s transaction, after `lockForUpdate()`, and abort/report the lot if the recomputed excess differs materially from the censused one.

---

### [IMPORTANT] 5 — New magic string on a type column: `reference_type = 'batch_ledger_repair'`

`RepairPhantomDefaultBatchesCommand.php:80` (`private const string REFERENCE_TYPE`) and `:341`.

`App\Shared\Domain\Enums\StockMovementReferenceType` exists precisely as "the fix going forward" for this column (its own docblock, `:19`). Rule 9 forbids magic strings on type columns. The concrete consequence is already documented in the code the lane bypassed: `apps/api/app/Modules/Inventory/Presentation/Controllers/EntryExitNoteController.php:289-295` resolves `reference_type` through `tryFrom()` and falls back to printing the raw string to the operator — the comment at `:283-288` says that raw-string leak is exactly the failure mode the total-over-the-enum resolution was written to end.

**Fix:** add `case BatchLedgerRepair = 'batch_ledger_repair';` to `StockMovementReferenceType`, use `->value` at `:341`, and add the i18n key alongside the other cases.

---

### [IMPORTANT] 6 — A fifth `ensureDefaultBatch(aggregate)` seam was missed: the parapharmacy seeder

`apps/api/database/seeders/ParapharmacySeeder.php:1380` still calls `ensureDefaultBatch(targetQuantity: (string) $stock->quantity, …)` — the aggregate.

The addendum's enumeration (§A) says "`grep -rn "ensureDefaultBatch(" app` returns exactly three call sites"; the grep was scoped to `app/` only. Full enumeration of every caller outside `BatchStockService` itself, with the shape passed:

| Call site | Passes | Verdict |
|---|---|---|
| `app/Modules/Inventory/Application/Services/StockReservationService.php:339` | untracked remainder | **fine** (fixed) |
| `app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:1748` | untracked remainder | **fine** (fixed) — the private helper is reached from 4 sites (`:153`, `:988`, `:1405`, `:1472`), so `receive()`, `adjust()` and the counting listener (`ApplyStockAdjustmentsOnCountingCompleted.php:231` → `adjust()`) are all covered by the one swap. Confirmed. |
| `app/Modules/Product/Presentation/Controllers/ProductController.php:928` | untracked remainder | **fine** (fixed) |
| `app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php:158` | `$line->quantity` (line quantity) | **not the aggregate hole** — handback claim **CONFIRMED**. See finding 12 for a smaller, separate issue with it. |
| `database/seeders/ParapharmacySeeder.php:1380` | **aggregate `$stock->quantity`** | **HOLE — missed.** Its own docblock (`:1349-1350`) advertises it as re-runnable ("safe to call again after more stock is seeded"), so re-running it on a tenant that has since received real lots re-mints exactly the phantom this lane repaired. Not a production request path, but it IS the parapharmacy vertical's provisioning path. |
| `StockAdjustmentService::receiveIntoDefaultBatchByDelta()` (`:1069-1103`) | delta | **fine** — genuinely delta-based, adds only the delta. |

**Fix:** swap `ParapharmacySeeder.php:1380` to `ensureDefaultBatchForUntrackedRemainder(aggregateQuantity: …)`, or state in writing why the seeder is exempt.

---

### [IMPORTANT] 7 — The stated justification for the multi-lot fallback ("issuance stays FEFO regardless") is not true on the path this lane is about

`StockReservationService.php:292-293` (docblock) and handback §2.3 both rest on *"Issuance stays FEFO regardless ({@see FEFOInventoryService::consumeBatchesAtomically()})"*.

`consumeBatchesAtomically()` has **exactly one caller in `app/`**: `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:1398`. The SO → DN path does **not** use it. `apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php:286-296` decrements lot stock **only when the DN line itself carries a `batch_id`**, and the reservation's `batch_id` is never propagated to the DN line (grep of `batch_id` in `DeliveryNoteService.php` + `SalesOrderService.php` returns only `:288`/`:291`; the reservation is merely released wholesale at `DeliveryNoteService.php:205-208`).

Two consequences: (i) the FEFO lot chosen at reservation time is a **soft hold only** and has zero influence on which lot is issued — the lane's headline benefit is narrower than the handback claims; (ii) a DN line with no `batch_id` moves the aggregate via WAC but leaves the batch ledger untouched, so `Σ lots > stock_levels.quantity` — which then makes `untrackedRemainderAt()` clamp to 0 forever and silently stops backing genuinely untracked stock.

**Fix:** correct the docblock and the handback to state what is actually true, and record (i)/(ii) as a named follow-up lane. Do not leave a decision resting on a citation that does not hold.

---

### [IMPORTANT] 8 — The write-off-reversal path still double-books the DEFAULT lot after this lane

`apps/api/app/Modules/BatchExpiry/Domain/Services/ReverseWriteOffService.php:170-197` calls `StockAdjustmentService::receive(batchId: null)` **first** (which runs the now-remainder-based helper) and credits the original real lot via `receiveBatchStock()` **after**. At helper time the real lot has not yet been credited, so the remainder equals the reversed quantity and the DEFAULT lot is topped up by it; the real lot then gains the same quantity again.

**Proven by execution** (probe on the lane's own `SiblingSeamsUntrackedRemainderTest` fixture, mirroring the reversal ordering — real lot 26, aggregate 26, `receive(null, 4)` then `receiveBatchStock(realLot, 4)`):
```
Sigma lots 34 vs aggregate 30.0000
```
The lane **improves** this (on `dev` the over-count would have been the whole aggregate, not 4), so it is not a regression — but the phantom is not gone, and `ReverseWriteOffService.php:123-127` now carries a comment that is **factually stale** ("tops the DEFAULT lot up to the WHOLE aggregate"). The existing `ReverseWriteOffServiceTest` cannot catch it: its product never sets `requires_batch_tracking` (`tests/Feature/BatchExpiry/ReverseWriteOffServiceTest.php:117-125`), so the helper returns early and the seam is untested.

**Fix:** at minimum correct the stale comment in-lane and record the residual with a named follow-up; the one-line real fix is to skip the implicit-default helper when the caller will credit a lot itself.

---

### [IMPORTANT] 9 — The repair does not restore `Σ lots == stock_levels.quantity`, and does not say so

On the very tenant it was built for (`tenant01a034af-…`, verified by psql):

```
 id |     sku       |  batch_number   | quantity | stock_level
  8 | CREM-BEBE_200 | DEFAULT         |  29.0000 |   29.0000
  1 | CREM-BEBE_200 | LOT-CRÈME-2026A |  30.0000 |   29.0000   <- real lot is 1 ABOVE the aggregate
```

`--execute` would zero lot 8 (excess 29), leaving `Σ lots = 30.0000` against `stock_levels.quantity = 29.0000` — still drifted, in the opposite direction, and reported as a clean success (`Lots only partially reduced: 0`). The command attributes **all** ledger drift to the DEFAULT lot by construction (`phantomExcessFor()` `:246-272`), which is right for the phantom but blind to real-lot over-statement (finding 7(ii) is one way it arises).

**Fix:** after each lot's correction, recompute `Σ lots` vs `stock_levels.quantity` and emit a `warn()` line + a run-level counter for any residual, so the operator does not read "repaired" as "reconciled".

---

### [MINOR] 10 — Driver `SUM()` is normalised without the raw-decimal guard the house FEFO path uses

`apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:145-148` does `(string) $query->sum(...)` then `bcadd(..., '0', 4)`. The comment acknowledges the driver may render the scalar as a float. `FEFOInventoryService.php:102-125` faces the same problem and guards it with `preg_match('/^-?\d+(\.\d+)?$/', …)` before touching bcmath, precisely because SQLite can emit scientific notation and `bcadd` then throws a bare `ValueError`. Test-environment-only risk today (PG returns a decimal string), but the two sites should not disagree.

**Fix:** reuse the same regex guard, or route through `QuantityScale::round()` as the repair command's `decimal()` (`:494-510`) already does.

### [MINOR] 11 — Scope refusal prints a "Repair run id" first

`RepairPhantomDefaultBatchesCommand.php:117-118` emits the run id before `forEachExplicitlySelectedTenant()` refuses at `:438-445`. A run id in the transcript of a run that processed nothing is a misleading audit breadcrumb. Move the id emission after the scope check.

### [MINOR] 12 — `OpeningBalancePostingService.php:158` passes a line quantity into a TOP-UP-TO-TARGET helper

The handback's claim ("not in this class — it passes the posting's own quantity") is **correct as stated**, and the enter-once guard at `:86-100` blocks the obvious double-opening. But `ensureDefaultBatch()` sets the lot **to** the target, it does not add to it (`BatchStockService.php:99-105`), so the call is only correct while the DEFAULT lot is empty. `ensureDefaultBatchForUntrackedRemainder(aggregateQuantity: $quantityAfter)` — `$quantityAfter` is computed two lines above at `:113` — would be correct unconditionally and is a strictly safer call. Worth doing while the file is fresh; not a blocker.

### [MINOR] 13 — Repair command lock order differs from the reservation path

`reserve()` locks `stock_levels` → `inventory_batch_stock` (`:139-143`, `:113-116`, `:323-329`). `repairLot()` locks `inventory_batch_stock` (DEFAULT, `:284-287`) → `stock_reservations` (`:374-381`) → a **second** `inventory_batch_stock` row (`:470-473`), and never locks `stock_levels` even though it reads it at `:320-326`. No cycle exists against `reserve()` today (reserve locks exactly one batch-stock row), but two concurrent repair runs on one tenant can deadlock on the two batch-stock rows.

**Fix:** document `--execute` as a maintenance-window operation in the command description, and/or order the two batch-stock locks by primary key.

---

## Answers to the gate's specific questions

**Q1 — policy, FEFO choice, aggregate fallback, DN-confirm walk.** The DEFAULT-lot policy (`BatchStockService.php:123-225`) is sound: real-lot sum counts inactive/recalled/expired lots (correct — a recalled lot still physically holds units), clamps at 0, and mints nothing at zero. FEFO choice (`StockReservationService.php:350-366`) delegates to `suggestBatchesForSale()`, which orders by `expiry_date ASC`, reads the GENERATED `available_quantity` (so lot-level holds ARE respected) and excludes expired/inactive/recalled. **The fallback is NOT sound**: it does not over-reserve *across* lots (it writes `batch_id = null`, so no lot is misrepresented — that part of the design is fine), but it creates the mixed aggregate/lot hold state that finding 1 exploits. DN-confirm walk: `DeliveryNoteService.php:205-208` releases the reservation by source, `:279-284` decrements the aggregate via `wacService->recordSale()`, and `:286-296` touches the lot **only if the DN line carries its own `batch_id`** — the reservation's lot is never propagated (finding 7). WAC itself is untouched by this lane; no `workingScale()` / running-average arithmetic was modified.

**Q2 — the `max(column, SUM(active))` guard.** Semantics match `StockLevel::recalculateReserved()` exactly (same three predicates, `StockLevel.php:196-206`), so it is the codebase's own definition and not a new one. Under two concurrent aggregate-branch confirms it is safe: the `stock_levels` row lock (`:139-143`) is acquired **before** the SUM, so the second transaction blocks and then reads the first's committed reservation. No regression for untracked products (helper returns null at `:313-315`; column and SUM agree because `release()`/`expire()` set `released_at` in the same transaction as the column decrement) — confirmed green by the 49-test pre-existing suite run. Its defect is scope, not correctness: finding 1.

**Q3 — sibling seams.** Enumerated in finding 6. Three production seams closed correctly; the counting listener is genuinely covered by the `StockAdjustmentService` swap (`ApplyStockAdjustmentsOnCountingCompleted.php:231` → `adjust()` → `:988`/`:1405`/`:1472` → the private helper). `OpeningBalancePostingService.php:158` claim **confirmed** (line quantity, not aggregate) — see finding 12 for the residual. **One seam missed:** `ParapharmacySeeder.php:1380`.

**Q4 — repair command.** Tenant-scoped ✅ (exit 2 without `--tenant`/`--all-tenants`). Exactly one of `--dry-run`/`--execute` ✅ (exit 2 for neither and for both). Each lot reduction writes its own `stock_movements` row with `quantity = 0.0000` and `quantity_before === quantity_after` ✅ (correct — the aggregate never moved, and a non-zero delta here would corrupt WAC) plus an `inventory_batch_movements` leg carrying that `movement_id` ✅. DPA scanner reads both as **linked**, zero new baseline keys ✅ (probe output above). The zero-delta row is also correctly **excluded** from the COGS coverage checker's D-e arm by `whereColumn('quantity_before','<>','quantity_after')` (`CheckCogsCoverageCommand.php`, D-e body) — so no false GL-coverage finding. Dry-run writes nothing ✅ (proven by post-run psql). Wave-2 dry-run reproduces 38.0000 across lots 8/9 ✅ and matches an independent psql census ✅. WAC/valuation unaffected ✅ (aggregate unchanged; no `unit_cost`/`avg_cost` is written). Open reservations on a shrunk DEFAULT lot: re-pointed to the earliest-expiry real lot that covers them whole (`:372-421`, re-checked under a per-row lock at `:470-485`), otherwise **left in place** with the lot floored at its remaining `reserved_quantity` (`:305-307`) and reported — a sound policy. Defects: findings 3, 4, 5, 9, 11, 13.

**Q5 — red-proof and inherited reds.** The three new suites' red-proof narratives are consistent with the code I read, and all are green now on sqlite **and** PostgreSQL (25/25 both legs). The 8 `BatchExpiry` unique-constraint errors are **genuinely inherited**: a clean worktree checked out at pure `dev` produces the identical `Tests: 14, Assertions: 9, Errors: 8, Skipped: 2`.

**Q6 — manifest union vs CURRENT dev.** dev `6eefa1122` carries `gated_ceiling 1163`, `Document 79`, `Inventory 111`. The lane's `1167 / 80 / 114` is the exact union (4 new classes, every other group byte-identical to dev). **Merge value: `gated_ceiling` 1167, `Document` 80, `Inventory` 114** — no adjustment needed unless another session raises the manifest before the merge, in which case re-derive as `dev_value + 4`.

---

## What must change before merge

1. **Close the mirror oversell** (finding 1) — the batch branch must consult the tuple-level hold, or the aggregate fallback must be removed. Add a red-first test in the **aggregate-then-lot** order. This is the merge blocker.
2. **Remove `.deptrac.cache` from the branch** (finding 2) and ignore `/.deptrac.cache`.
3. Findings 3, 4, 5, 6 are small, in-lane, and each is a correctness or convention defect on a P0 path — fix them in the same round.
4. Findings 7, 8 (docblock/comment corrections + named follow-ups) and 9 (residual-drift reporting) must be landed or explicitly recorded as owner-visible residuals; do not leave a design decision resting on the `consumeBatchesAtomically` citation.
