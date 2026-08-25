# W4-6 gate r1 — stock↔GL seam + inventory-costing lens

Lane `fix/campaign-w46-counting-basket-window` · worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w46-counting-variance` · HEAD `3c6bdd588` · base `3bbe28480` · 6 commits · 14 files · no migration (confirmed: `git diff --stat dev...HEAD` contains no `database/migrations/**`).

**VERDICT: spec ✅ (one leg partially unmet — F-5) · quality CHANGES-REQUESTED · merge-blocking: NO**

Merge-blocking NO for the seam itself: nothing in this diff moves stock without value, books a GL consequence twice, adds a second writer to a stock or GL projection, corrupts a WAC/batch invariant, touches the Q-2 finalize lock, or puts a float on the seam — all verified by execution below. The CHANGES-REQUESTED is for F-1 (a false load-bearing comment), F-3/F-4 (two gaps in the NEW lot-drawdown arm) and F-5 (the operator-facing report still shows the old number). F-1 and F-4 are a ≤10-line in-lane follow-up; F-2, F-3 and F-5 are LEDGER residuals if not closed.

---

## 0. What was verified BY EXECUTION

Throwaway PG `autoerp_test_w46g` (`127.0.0.1:5433`, `autoerp`/`autoerp_secret`, `PGTZ=UTC`) — **created and dropped**. Tampering done in a throwaway `git worktree` at `3c6bdd588` with a hard-linked `vendor` (`cp -al`, never a symlink — a symlinked vendor resolves `$baseDir` back to the main repo and runs stale code); worktree removed, lane left byte-identical (`git status --porcelain` in the lane = empty).

| # | Lane | Command | Result |
|---|---|---|---|
| 1 | sqlite | `CountingVarianceAppliedTest` | **8 passed** (47 assertions) |
| 2 | PG | `CountingVarianceAppliedTest` + `CountCorrectionGlPostingTest` | **17 passed** (101) |
| 3 | PG | `ReplayFinalizeTest` + `PreFinalizeReplayPreviewTest` + `CountingDiscrepancyReportTest` | **22 passed** (141) — the named raise's 18 (12+6) among them |
| 4 | PG | `StockAdjustmentDefaultBatchTest`, `SiblingSeamsUntrackedRemainderTest`, `ImplicitReservationFefoLotTest`, `StockAdjustByDeltaTest`, `InventoryCountingDefaultBatchTest` | **42 passed** (119) — collateral on the legacy `adjust()` arm is clean |
| 5 | web (lane worktree) | `ReviewReplayColumns.test.tsx` | **16 passed** (dev's copy has 15 and passes for the OPPOSITE reason — verified both) |
| 6 | — | PHPStan level 8, live-DB env, all 6 changed production files | **No errors** |
| 7 | — | `deptrac analyse` | **183 violations == `deptrac.baseline.json` total 183** → PASS, no boundary regression |

### Red-proof (three tampers, each reverted)

| Tamper | Expected | Observed |
|---|---|---|
| Put `BasketWindow` back into `blocksStockApplication()` | red | **3 failed** — incl. `items_not_applied` 1 ≠ 0 |
| Revert `varianceQuantity()` to the `expected_qty_at_apply` baseline (the "variance 0 by construction" defect) | red | **2 failed** at `:379` (`items_with_variance`) and `:429` (`items_not_applied`) |
| Delete the `drawLotsDownForCountShortage()` call in `postCountCorrection()` | red | **1 failed** at `:460` — LOT-A stays 30 instead of 28 |

### My own probes (7, written for this gate, all green, then discarded)

1. **sale + refund inside the window nets to zero** → 0 correction movements, on-hand 60. The in-count sale is still counted exactly once. ✅
2. **movement stamped exactly AT the count instant** → the replay window is half-open (`MovementReplayService.php:52` `COALESCE(occurred_at,created_at) > ?`), so the boundary movement is EXCLUDED: counted 60 / on-hand 56 posts **+4** and restores stock to 60. See F-2.
3. **LEGACY path (`final_qty_as_of` null) batch-tracked shortage** → FEFO drawdown fires through the new `else` arm; Σ lots == aggregate (63). ✅
4. **fully reserved FEFO lot** → skipped (`available = quantity − reserved_quantity`, which is exactly the generated column `issueBatchStock()` checks — verified in the migration), DEFAULT absorbs, Σ lots == aggregate. ✅
5. **shortage larger than all lot stock** → tolerant drain, Σ lots 0 vs aggregate 60 (accepted R-4 / gate N-2 posture).
6. **recalled lot** → excluded, Σ lots 30 > aggregate 28. See F-3.
7. **report row/summary reconciliation** → `rows[variance_applied===true] = 2` vs `summary.items_applied = 1`. See F-6.

---

## 1. Findings

### F-1 [Important] A load-bearing comment states a guarantee the code does not give
`apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php:296-298`

```php
// Advisory reasons are stamped on the row BEFORE the posting so the
// annotation survives even if the posting throws and the job retries.
$this->annotateItem($item, $preApplyReasons);
```

It does not survive. `handle()` wraps the **entire item loop** in ONE root `DB::transaction` (`:90`, with its own T21 comment at `:74-88` explaining why), `applyReplay()`'s transaction is therefore a savepoint, and no item exception is caught anywhere in the loop. An item-N throw unwinds to the root and rolls back `annotateItem()`'s `save()` along with everything else — which is precisely what `CountCorrectionGlPostingTest::test_an_item_that_throws_leaves_zero_movements_and_zero_entries…` pins (0 movements AND 0 entries).

No functional harm: the retry re-evaluates the line because `replay_audit` is still null, and re-annotates. But the next reader will take this comment as a durability guarantee it can build on.

**Fix:** reword to the truth — "stamped before the posting so the annotation and the posting share one savepoint; a throw rolls both back and the queue retry re-evaluates the line (`replay_audit` is the idempotency marker)."

### F-2 [Important] The PRE-count half of the ± window now resolves by POSTING, with a GL consequence — undisclosed
`apps/api/app/Modules/Inventory/Domain/Services/MovementReplayService.php:52` · `apps/api/app/Modules/Inventory/Domain/Enums/CountingItemFlagReason.php:59-65` · handback §1 RC-1

The replay neutralises only `(asOf, now]`. A movement stamped **at or before** `asOf` is not replayed away at all. So the handback's claim that the veto "was also redundant" holds for the post-count half of the window and **not** for the pre-count half.

Concretely, the campaign's own shape (receipt 13 min BEFORE the count instant) is only correct if the counter counted **after** the goods reached the shelf. If the receipt was booked before the count but shelved after it, finalize now posts a value-bearing shortage and — once `count_correction_gl_posting_enabled` flips — a shrinkage journal entry that is simply wrong. Probe 2 demonstrates the extreme: a sale stamped exactly at `asOf` is excluded by the strict `>`, so the count posts a **+4 phantom gain** and inflates stock back to 60. Before this lane the line was flagged and skipped.

This is the trade-off the brief asked for ("never suppress the counted-vs-expected delta itself"), and `is_flagged` + the `basket_window` chip still surface the ambiguity — but there is no reversal path other than a fresh count, and the handback lists no residual for it.

**Fix:** amend the handback §5 residuals and the `CountingItemFlagReason` docblock to state that the ± window's pre-count half is now decided in favour of posting, and carry it on the LEDGER next to the OQ-12 flag flip (a wrong shrinkage/gain becomes a wrong JE the moment the flag is on). Optionally: a `basket_window` line could be excluded from GL until reviewed — that is a design call, not a gate demand.

### F-3 [Important] The new FEFO drawdown skips recalled lots — the exact case it exists to fix
`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:1526` (`->where('product_batches.is_recalled', false)`)

The method's own docblock (`:1450-1466`) says the aggregate and the lot ledger "have to move together or FEFO becomes fiction … Σ lots > aggregate and the earliest-expiring lot keeps promising stock that is not on the shelf." A recalled lot is excluded from the candidate list, so a shortage on a product whose only stock sits in a recalled lot lowers the aggregate and leaves the lot untouched. Probe 6: aggregate 30 → 28, Σ lots stays 30.

And recall is the single most likely reason a batch-tracked count comes up short — stock physically pulled from the shelf is exactly what a recall produces. The exclusion is also inconsistent with the deliberate decision one line later to INCLUDE expired lots (handback R-3), whose stated rationale ("stock that has gone missing off a shelf is more likely the oldest") applies verbatim to recalled lots.

Not a regression (before this lane NO lot moved on any count shortage), but an incomplete fix in new code.

**Fix:** drop the `is_recalled` predicate (recalled lots last in the FEFO order, if a preference is wanted), or add an explicit comment + handback residual stating that a recalled-lot shortage is knowingly left diverged and naming the repair path.

### F-4 [Important] Read-then-lock in the drawdown can abort the whole finalize job
`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:1521-1545` vs `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:355-371`

The FEFO candidate list is read **unlocked** (`->get([...])`) and `take = min(available, remaining)` is computed off that snapshot; `issueBatchStock()` then re-reads the row `lockForUpdate()` and **throws `InsufficientBatchStockException`** if `available_quantity < quantity`. Because `handle()` has no per-item `catch`, one such throw aborts the entire finalize job and rolls back every already-applied item. The docblock claims tolerance ("Each lot gives `min(available, remaining)`, which can never overshoot"); it is tolerant only against a snapshot it does not hold.

In mitigation, I traced the only production reservation writer: `StockReservationService::reserve()` takes the `stock_levels` row `lockForUpdate()` FIRST (`:164-169`) before touching any lot, and the count correction holds that same row for the whole `applyCountResult()` closure — so `reserve()` blocks and cannot open the window. `BatchStock::reserve()` (`BatchStock.php:54-61`) has **no** production caller. So I could not prove a reachable racer today. The exposure is nonetheless newly created and cheap to close.

**Fix:** add `->lockForUpdate()` to the FEFO read (order stays `stock_levels` → `inventory_batch_stock`, so no lock inversion is introduced), or wrap each `issueBatchStock()` in a `catch (InsufficientBatchStockException)` and continue to the next lot — which is what "tolerant" already claims.

### F-5 [Important] The report leg is delivered in the payload and rendered nowhere
`apps/web/src/features/inventory-counting/types.ts:317-326` · `apps/web/src/features/inventory-counting/pages/DiscrepancyReportPage.tsx:344-410` · `apps/api/app/Modules/Inventory/Domain/InventoryCountingItem.php:214-221`

The brief requires "the count report must show expected / counted / variance / applied". The backend delivers it correctly and it is tested (`CountingDiscrepancyReportService.php:65` `items`, `:152-153` `items_applied`/`items_not_applied`, `:221-243` the per-row block). Nothing consumes it:

* `DiscrepancyReport` (TS) has no `items`, no `items_applied`, no `items_not_applied`.
* `DiscrepancyReportPage` still renders only `report.flagged_items`, with `item.theoretical_qty` as the "expected" column and `item.variance` as the variance.
* `item.variance` is `InventoryCountingItem::getVariance()` = `(float) $this->final_qty - (float) $this->theoretical_qty` — a **float** on a quantity, on the OLD baseline.

So the operator who ran flow G still reads the pre-W4-6 number, as a float, while the correct bcmath `variance_qty` sits unrendered beside it in the same row. The float itself is pre-existing (typed `variance: number` and documented as such at `types.ts:215-218`) and is NOT this lane's to fix under rule 4 — but the lane has now made two variance fields with different baselines coexist on one row, and picked the wrong one to leave on screen.

**Fix:** either extend `DiscrepancyReport` + the page to render `expected_qty` / `counted_qty` / `variance_qty` / `variance_applied` / `not_applied_reason` and the two summary counters, or record the FE leg explicitly as an owed residual on the LEDGER so the campaign re-run does not report the same defect.

### F-6 [Minor] Row-level `variance_applied` and summary `items_applied` count different populations
`apps/api/app/Modules/Inventory/Application/Services/CountingDiscrepancyReportService.php:111-120` vs `:245-250`

`varianceWasApplied()` returns `true` for a zero-variance row ("nothing outstanding"), but `summary()` only increments `items_applied`/`items_not_applied` inside the `itemsWithVariance` branch. Probe 7 on a 2-line count: rows with `variance_applied === true` = **2**, `summary.items_applied` = **1**, `items_no_variance` = 1. Any consumer that reconciles the rows against the summary will conclude one of them is wrong.

**Fix:** document the semantic in the payload comment (`items_applied` counts VARYING lines only) and/or emit `items_agreeing` so the three counters sum to `total_items_counted`.

### F-7 [Minor] "Blocking" now means two things in one file
`apps/api/app/Modules/Inventory/Domain/Enums/CountingItemFlagReason.php:10-14` and `:45-51`

The class docblock still reads "`is_flagged=true` iff `flag_reasons` contains a BLOCKING reason (basket_window, …)" and `isBlocking()` is still named `isBlocking()`, while `blocksStockApplication()` now answers a different question for the same case. The lane added an excellent explanatory paragraph at `:16-25`, but left the older wording and the method name in place.

**Fix:** rename `isBlocking()` → `raisesReviewFlag()` (call sites: the listener's `annotateItem`/`flagItem` only, per grep) or at minimum retitle the `:10-14` paragraph so "BLOCKING" is qualified as review-visibility.

### F-8 [Minor] Document-per-action is enforced on the replay arm only
`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:1400-1408` vs `apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php:220-224`

`postCountCorrection()` now refuses to write a zero movement. The legacy arm guards only with `if ($finalQty === null || $finalQty === $theoreticalQty)` — a **strict string identity**. Two equal quantities at different string scales (`'10.00'` vs `'10.0000'`) would still drive `adjust()` with `difference = '0.0000'`, write a `qty 0.0000` movement AND enqueue a zero-amount `MovementGlContext`. Reachability is low (both columns are `decimal:4` casts) but the rule the lane just established is not applied symmetrically.

**Fix:** replace the identity check with `bccomp($finalQty, $theoreticalQty, 4) === 0`, or add the same `bccomp(...,'0') === 0` early return inside `postAdjustmentWithinLock` for the counting caller.

### F-9 [Info — CI posture, S-17 class]
Nothing in this lane runs in CI. The Inventory feature lane is PARKED behind `vars.SELF_HOSTED_RUNNER_READY` (manifest note), and none of `CountingVarianceAppliedTest`, `CountCorrectionGlPostingTest`, `ReplayFinalizeTest`, `PreFinalizeReplayPreviewTest` appears in the `backend-test-pgsql` `--filter` allowlist (`.github/workflows/ci.yml:983`). Honestly recorded in the manifest; restated here so the merge is registered **CI-UNVERIFIED**, in-lane-verified only. The newly-reachable GL leg in particular has no CI tripwire.

---

## 2. Both-sides verification — what I traced on the OTHER side of each seam

**GL consequence exactly once (flag ON).** PG-verified: a `basket_window` shortage posts Dr `InventoryShrinkageExpense` 8.500 / Cr `Inventory` 8.500 = `4.250000 × |68 − 70|`, balanced, with `assertEntryAmountEqualsRowCostTimesAbsoluteDelta`. An agreeing line posts **0 movements AND 0 entries**. A queue retry after the marker posts **no second entry**. An item that throws leaves **0 movements AND 0 entries**. Accounts are resolved by `SystemAccountPurpose` (`GeneralLedgerService.php:5394`, `:5106-5111`; provisioner `InventoryVarianceAccountProvisioner.php:168-175`) — no hardcoded 6586/7586/37 anywhere on this path.

**GL consequence with the shipped default (flag OFF).** `config/inventory.php:29` ships `false`. The ONLY gate is `ApplyStockAdjustmentsOnCountingCompleted::enqueueCountCorrectionGl()` `:364` — a single `return` before any buffer work. I confirmed it is the only GL path in the counting lane: `applyReplay()` `:334-336` and `applyLegacyDelta()` `:245` both funnel through it; `postCountOpening()` deliberately has no sink (opening balance is outside the seam); `postAdjustmentWithinLock()` has no GL sink at all, structurally. So with the flag off: **stock moves, cost is on the row, no journal entry** — and `test_the_flag_is_off_by_default_and_the_dormant_listener_posts_nothing` pins it. The report does NOT say so anywhere — the report has no notion of the GL flag, so "the report says so" is **not satisfied**; it reports stock-applied only. That is consistent with the ruling (the ledger is rebuildable from the movement rows) but worth the owner knowing.

**What the owner must ratify (for the LEDGER).** `docs/handoff/reviews/wave3-3c-3d/ORCHESTRATOR-RULING-2026-08-19-m5-oq12-gate.md` — the flag flip is a **deploy-time blocker** pending expert-comptable ratification of the Option A (6586 shrinkage / 7586 gain) liasse presentation; it must not be enabled for any tenant before the ratification is recorded. Consequence for tenant #1 as shipped: a count correction moves stock and carries its row cost but posts no JE; smoke-sheet row 7.5 stays unmet on a default tenant. This lane correctly did **not** flip it. Add to that row: F-2 (a pre-count-window ambiguity now becomes a real JE the moment the flag is on).

**Single writer per projection.** The aggregate `stock_levels` row on this path is written by `postCountCorrection()` (`:1410`) and `postAdjustmentWithinLock()` (`:941`) only. `BatchStockService::issueBatchStock()` (`:355-380`) writes **only** `inventory_batch_stock` + `inventory_batch_movements` and never `stock_levels` — I read it specifically to rule out a double decrement. No second writer introduced.

**Document-per-action.** Every movement written by both arms carries `reference_type = inventory_counting` + `reference_id = <inventory_countings.id>`, asserted at the DB level in the new tests; `assertReferenceLinkagePaired()` guards the half-pair. A zero adjustment writes **no** row — the replay audit is the evidence the line was evaluated.

**Blast radius of the legacy `else` arm.** The new `drawLotsDownForCountShortage()` branch at `:1019-1027` fires only when `batchId === null && !isPositive && !deltaBasedDefaultLot`. `deltaBasedDefaultLot: false` is passed at exactly one site — `adjust()` `:747`. I grepped every `->adjust(` in `app/`: the ONLY production caller is `ApplyStockAdjustmentsOnCountingCompleted::applyLegacyDelta()` `:231` (the other hit, `LoyaltyMemberController:283`, is `PointAdjustmentService::adjust`, unrelated). The docblock's "ONE caller" claim is **true**. A zero `difference` also reaches the arm and no-ops correctly (`remaining <= 0` → return).

**W2-7 consistency.** The overage direction still routes through `ensureDefaultBatchForUntrackedRemainder()` (`StockAdjustmentService.php:1876`), i.e. DEFAULT = untracked remainder, top-up only. PG-verified: LOT-A 30 + DEFAULT 35, count 67 → DEFAULT 37, LOT-A 30, batch count stays 2. No aggregate-total DEFAULT is minted, and a shortage mints no lot at all.

**Batch invariant.** Per-location Σ`inventory_batch_stock` == `stock_levels.quantity` now holds for the shortage direction (probes 3 and 4). It does NOT hold in two cases: F-3 (recalled lots) and probe 5 (shortage larger than all lot stock — the accepted tolerant posture, handback R-4 / gate N-2). No new lock is taken on `product_batches`; the `stock_levels` → `inventory_batch_stock` order is preserved.

**WAC.** No path in this diff computes a cost blend. `postCountCorrection()` resolves `Product::resolveMovementUnitCost()` ONCE before the movement and writes it on the row; the GL amount is derived from that persisted row, never from a re-read WAC. No cost-basis zeroing, no phantom on-hand authored without a movement.

**Append-only.** No `UPDATE`/`save()` on an existing `stock_movements` or `journal_entries` row anywhere in the diff.

**Rule 19 / float.** Zero `(float)`, `floatval`, `parseFloat`, `Number(`, `round(`, `number_format` introduced in any `+` line of the diff across `apps/api/app` and `apps/web/src`. All new arithmetic is bcmath at `StockAdjustmentService::SCALE = 4` / `InventoryScale::QUANTITY_SCALE`. PHPStan level 8 (which carries `ForbidFloatCastOnDecimalProperty` / `ForbidHardcodedBcmathScale`) on all six changed production files: **No errors**. (Two pre-existing floats live adjacent to this seam and are NOT this lane's: `InventoryCountingItem::getVariance()` `:214-221`, and `StockReservationService.php:507`/`:631` `decrement('reserved_quantity', (float) …)`. Flagged as adjacency only.)

**Zero-adjust skip is a true no-op.** `adjustment = expectedNow − onHandNow` at scale 4 (`MovementReplayService.php:162-173`) and `stock_levels.quantity` is `numeric(15,4)` (read from PG `information_schema`). So `bccomp($adjustment,'0',4) === 0` ⟺ `expectedNow === onHandNow` at storage precision; skipping `$stockLevel->update()` cannot leave a sub-scale residue.

**Session B Q-2 lock untouched.** `InventoryCountingService.php` does not appear in `git diff --stat dev...HEAD` at all, nor does any transition guard. `CountingVarianceAppliedTest::test_a_second_finalize_is_refused_and_applies_nothing_twice` re-pins it from this lane's angle (PG green): first finalize applies once, second raises `CountingTransitionException`, on-hand and movement count unchanged in the `finally`.

**Named raise is legitimate.** `inventory_countings.counting_number` is `varchar(20)` (confirmed via `information_schema.columns` on PG: `character_maximum_length = 20`; migration `2026_03_04_100000_…:15`). `'CNT-RPL-'.uniqid()` = **21** chars, `'CNT-PRV-'.uniqid()` = 21 — PG rejects, SQLite does not enforce varchar length, so both files had only ever run on the sqlite lane. New `'CR'`/`'CP'` + `uniqid()` = 15. The 18 previously-unrunnable PG cases (12 + 6) are green. Honest raise, correctly scoped to the fixture line.

**Sentinels re-pinned honestly.** Both rewrites carry a 🚨 block naming W4-6 and stating what the old assertion encoded. `test_case_c` moved from "on-hand 10, `assertDatabaseMissing`" to "on-hand 19, `assertDatabaseHas` qty +9, reason still recorded, `is_flagged` still true, `replay_audit` not null" — it asserts MORE than it did, not less. `test_basket_window_preview_promises_the_post_it_will_make` replaces a one-line helper call with a preview→apply round trip that also asserts the posted movement equals the previewed adjustment. `NOT_POSTED_RECOUNT_REASONS` on the FE was updated in step and a new negative test pins the absent hint. No sentinel was deleted or weakened.

**Manifest union.** `dev` moved during this review (`3bbe28480` → `834c8c017`, two doc-only commits; lane base is still an ancestor). Manifest on current `dev`: `gated_ceiling` **1173**, Inventory **114**, POS **155**. Lane: **1174** / **115** / **155**. Union at merge = exactly the lane's values; no re-arithmetic owed. Re-verify at merge time — `dev` is moving under three parallel sessions.

---

## 3. What to fix before merge

Close F-1 (3-line comment) and F-4 (`->lockForUpdate()` on the FEFO read) in-lane; put F-2, F-3 and F-5 on the LEDGER as named residuals — F-2 alongside the OQ-12 flag-flip row, because a wrong pre-count-window correction becomes a wrong journal entry the moment the flag is on.
