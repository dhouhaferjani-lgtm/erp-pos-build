# W4-6 gate r2 — stock↔GL seam + inventory-costing lens (VERIFY-ONLY)

Lane `fix/campaign-w46-counting-basket-window` · worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w46-counting-variance` · HEAD `a421bf5de` (fix round `3f5b9aedc`, then `git merge dev` `a421bf5de`) · r1 = `docs/superpowers/reviews/2026-08-25-w46-counting-gate-r1-stockgl.md` · handback §"Fix round r1".

**VERDICT: spec ✅ · quality APPROVED (3 named conditions) · merge-blocking: NO**

All six r1 findings (F-1..F-6) are closed, five of them verified by execution on BOTH engines. Nothing in the fix round moves stock without value, books a GL consequence twice, adds a second writer to a stock or GL projection, corrupts a WAC or batch invariant, touches the Session B Q-2 finalize lock, or puts a float on the seam. The three new findings are **documentation-accuracy and test-proof** defects, not behaviour: NEW-2 is a false load-bearing LOCK comment (the exact F-1 class the round just fixed, reintroduced one paragraph lower), NEW-3 is a guard with no tripwire, NEW-1 is a residual that names one horn of a two-horned ambiguity. None changes what the code does.

**Manifest values.** `dev` moved during this gate (`a3c2406e6` → **`562cb1331`**, two doc-only commits: `docs/sessions/session-B-2026-08-23/{REPORT-C27-implementer.md,SESSION-LOG.md}` — `git diff --name-only a3c2406e6..dev` confirms nothing else). Manifest on **current dev `562cb1331`**: `gated_ceiling` **1173**, Inventory **114**, POS **155**. Lane: **1174 / 115 / 155**. **Union at merge = exactly the lane's values; no re-arithmetic owed.** `dev` is no longer an ancestor of the lane (`git merge-base --is-ancestor dev <lane>` fails), so a trivial doc-only `git merge dev` is owed before the merge — re-verify the three numbers at that moment, three sessions are pushing.

---

## 0. What was verified BY EXECUTION

Throwaway PG `autoerp_test_w46g2` (`127.0.0.1:5433`, `autoerp`/`autoerp_secret`, `PGTZ=UTC`) — **created and dropped**. All tampering and all probes ran in a throwaway `git worktree` detached at `a421bf5de` with a hard-linked `vendor` (`cp -al`, never a symlink) — worktree removed, DB dropped. **The lane was never modified: `git status --porcelain` in the lane worktree = 0 lines, HEAD still `a421bf5ded3e35b72f3a7e90f5b6cbcc5b5960ae`.** One test process at a time; never the full suite.

| # | Lane | Command | Result |
|---|---|---|---|
| 1 | sqlite | `CountingVarianceAppliedTest` + `MovementReplayServiceTest` | **22 passed** (76 assertions) |
| 2 | PG | 12 gate-r2 probes (see §1) | **12 passed** (45) |
| 3 | sqlite | the same 12 probes | **12 passed** (45) — identical outcomes on both engines |
| 4 | PG | `CountingVarianceAppliedTest`, `MovementReplayServiceTest`, `ReplayFinalizeTest`, `PreFinalizeReplayPreviewTest`, `CountingDiscrepancyReportTest`, `NormalizedReconciliationTest`, `CountCorrectionGlPostingTest` | **58 passed** (298) |
| 5 | PG | 2 GL probes on the REAL Option-A codes (§3) | **2 passed** (15) |
| 6 | PG | 1 lot-ledger document-per-action probe (§4) | **1 passed** (6) |
| 7 | web | `vitest run src/features/inventory-counting/` (8 files) | **50 passed** |
| 8 | web | `tsc --noEmit` | **exit 0** |
| 9 | web | `eslint src/features/inventory-counting/` | **0 errors**, 35 pre-existing warnings |
| 10 | api | PHPStan level 8, live-DB env, all **7** changed production files | **No errors** |
| 11 | api | `php tools/deptrac-ratchet.php` | **PASS — 183/183, no boundary regression**, EXIT=0 |
| 12 | api | `php tools/feature-lane-manifest-check.php` | **OK, EXIT=0** — 1422 Feature classes, gated 1174 |

### Red-proof (three tampers, each reverted; lane untouched)

| Tamper | Expected | Observed |
|---|---|---|
| T1 — revert the replay window to `>` (`whereRaw '… > ?'` at `signedDelta` + `$eventAt->gt($from)` at `computeMany`) | red | **3 failed**: `MovementReplayServiceTest::test_boundary_movements_at_both_ends_are_included` (`'2.0000'` vs `'3.0000'`), `CountingVarianceAppliedTest::test_a_movement_at_the_exact_count_instant_is_neutralised_not_re_added`, and my probe P2a |
| T2 — restore `->where('product_batches.is_recalled', false)` | red | **3 failed**: the lane's `test_a_shortage_reaches_a_recalled_lot` + my probes P5 and P5c |
| T3 — remove **both** F-4 defences (`->lockForUpdate()` deleted AND the per-lot `catch` made to rethrow) | red | **24 passed — NOTHING went red.** See NEW-3 |

---

## 1. My own probes (12, written for this gate, run on sqlite AND PG with identical results, then discarded)

| # | Probe | Result |
|---|---|---|
| P1a | movement **1 s BEFORE** the count instant (sale 60→56), counter counted 56 | on-hand stays **56**, **0** corrections — baseline is not re-added ✅ |
| P1b | receipt booked 1 s before the count (56→60) but shelved after, counter counted 56 | on-hand 60 → **56**, **1** correction, `basket_window` recorded, `is_flagged` true — **R-8's owner-facing consequence, confirmed** |
| P2a | movement **exactly AT** the count instant, counter counted **pre**-movement (60) | on-hand **56**, **0** corrections, `variance_qty 0.0000`, `expected_qty 60.0000` — F-2's phantom `+4` is gone ✅ |
| P2b | movement **exactly AT** the instant, counter counted **post**-movement (56) | on-hand 56 → **52**, **1** correction — the sale is subtracted **twice**. See NEW-1 |
| P3 | movement **1 s AFTER** the instant | neutralised exactly once, **0** corrections ✅ |
| P4 | in-count **sale + refund** (−5 then +5) | net zero, on-hand **60**, **0** corrections — counted exactly once ✅ |
| P4b | in-count sale **−5** on a line with a **real** shrinkage of 2 | posts exactly **−2.0000** (`53` from `55`), one movement, `reference_type=inventory_counting`, `reference_id=<counting>` ✅ |
| P5 | shortage on a product whose **only** lot is RECALLED | aggregate 30→**28**, Σ lots **28** — r1's F-3 divergence gone ✅ |
| P5b | saleable (exp +30 d, 30) + recalled (exp **+5 d**, 20), shortage 5 | saleable **25**, recalled **20** — saleable first even though pure FEFO would take the recalled lot ✅ |
| P5c | same chart, shortage **35** | saleable **0**, recalled **15**, Σ lots **15** == aggregate — the recalled lot is reachable ✅ |
| P5d | W2-7: LOT-A 30 + untracked remainder 35, count **67** (overage) | **2** lot rows, Σ **67.0000** == aggregate, LOT-A untouched at 30 → DEFAULT is the **remainder (37)**, not an aggregate total ✅ |
| P6 | 2-line count, one varying + one agreeing | `total=2, agreeing=1, applied=1, not_applied=0, with_variance=1` — the three counters **partition** `total_items_counted`; every row carries all five new keys as strings ✅ |

---

## 2. Findings

### NEW-1 [Important] The same-second ambiguity was not eliminated — it was flipped, and R-8 names only one of its two horns
`apps/api/app/Modules/Inventory/Domain/Services/MovementReplayService.php:34-60` · `apps/api/app/Modules/Inventory/Domain/Enums/CountingItemFlagReason.php:27-45` · handback §"Fix round r1 → F-2" and residual R-8

The boundary fix is right and I verified it: probe P2a resolves at variance **0** where r1 measured a phantom **+4**. But `[from, to]` only decides the same-second case in favour of *one* reading of the counter. Probe **P2b** takes the other reading — the sale was rung up at instant `T` and the counter walked past *after* it, counting **56** — and the replay subtracts the same 4 units a second time: on-hand **56 → 52**, one `count_correction` movement of **−4**. Verified identically on sqlite and PG.

Two docblock claims are therefore false for that reading:
* `MovementReplayService.php:24-26` / `CountingItemFlagReason.php:22-24` — "the replay … is what keeps an in-window sale counted exactly ONCE";
* `CountingItemFlagReason.php:32-34` — "Movements from the count instant ONWARDS are neutralised, so they cannot move the variance."

Both are true only under the assumption the counter did **not** observe the boundary movement. The handback's own justification is honest and I agree with the choice ("between two unprovable readings, take the one that invents no stock" — a phantom shortage understates assets, a phantom gain overstates them) — but R-8 documents only the pre-count half, and the shipped code now has **two** wrong-case horns, not one.

This matters more than it did at r1. The lane's own merge brought in `29072453d` — **owner RULED 2026-08-25: flip `inventory.count_correction_gl_posting_enabled` to true (seeded default) when W4-6 lands**, via `BRIEF-P1-count-correction-gl-default.md`. So "a wrong journal entry the moment the flag is flipped" is the very next lane, not a distant deploy step.

**Fix:** extend R-8 to name both horns, and correct the two "exactly ONCE" / "cannot move the variance" sentences to state the assumption they rest on. LEDGER-blocking **before the P1 flip**, not before this merge.

*Other side of the seam:* with the flag on I confirmed the −4 correction of P2b's shape posts a balanced, correctly-signed shrinkage entry — the GL leg faithfully books whatever the stock leg decides. The defect is entirely upstream of the posting.

### NEW-2 [Important] The new lock claim is false on PostgreSQL — `lockForUpdate()` on a JOIN also locks `product_batches`
`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:1486-1493` (comment) vs `:1528-1546` (the query) · handback §"F-3 / F-4"

The docblock says: *"Lock order stays `stock_levels` → `inventory_batch_stock` … so no inversion is introduced and **no lock is taken on `product_batches`**."* The handback repeats it verbatim. It is not true. The builder joins `product_batches` and then calls `->lockForUpdate()`, which Laravel's PostgreSQL grammar compiles to a bare `FOR UPDATE` with no `OF` clause — and PostgreSQL locks rows in **every** table in the `FROM` list.

Proven by execution on the throwaway PG 16 instance: session A held `SELECT zz_a.id FROM zz_a JOIN zz_b ON … ORDER BY zz_b.flag FOR UPDATE`; session B's `SELECT id FROM zz_b WHERE id=1 FOR UPDATE NOWAIT` returned

```
ERROR:  could not obtain lock on row in relation "zz_b"
```

Reachable harm today: **none, and I checked rather than assumed.** `grep -rn -B12 "lockForUpdate" app/ | grep -e "->join(" -e "joinSub"` returns exactly ONE hit — this new query — so it is the only place in `app/` that takes a `product_batches` row lock at all, and no other path locks `product_batches` before `inventory_batch_stock` (I read every `lockForUpdate` on the batch lane: `BatchStockService:359`/`:403`, `FEFOInventoryService:597`, `GroupedWriteOffService:262`/`:271` — whose `acquireLocks()` at `:250-274` explicitly orders `stock_levels` → `inventory_batch_stock` — and `RepairPhantomDefaultBatchesCommand:361`/`:606`/`:698`; none joins, none locks `product_batches`).

So this is not a deadlock today. It is the **F-1 class defect reintroduced one round later**, on a *lock-order* statement — the single kind of comment the next author is most likely to build on when they add the first `product_batches` locker.

**Fix (one line):** `->lock('for update of inventory_batch_stock')` in place of `->lockForUpdate()` — Laravel's SQLite grammar compiles any lock clause to `''`, so the sqlite lane is unaffected — or correct the comment to say the join takes a `product_batches` row lock too and that this is the only such site.

*Other side of the seam:* the lock change does not alter what is posted. Σ lots == aggregate held in every probe on both engines, and `postCountCorrection()` is still the only `stock_levels` writer on this path; `BatchStockService::issueBatchStock()` (`:355-380`) writes only `inventory_batch_stock` + `inventory_batch_movements`, never `stock_levels` — re-read this round to re-rule out a double decrement.

### NEW-3 [Important] The F-4 fix has no red-proof — reverting BOTH defences leaves the suite green
`apps/api/tests/Feature/Inventory/CountingVarianceAppliedTest.php` (`test_a_lot_shortfall_narrows_the_gap_instead_of_aborting_the_job`, `test_a_shortage_prefers_saleable_lots_over_recalled_ones`) · handback §"Fix-round verification → Red-first proof"

Tamper T3: I removed `->lockForUpdate()` from the FEFO read **and** replaced the per-lot `catch (InsufficientBatchStockException) { … continue; }` with `throw $e;` — i.e. the exact pre-fix state — and ran the lane's own file plus my probes: **24 passed, 0 failed.**

The reason is structural, not incidental. The test seeds a 1-unit lot against a 10-unit shortage; `take = min(available 1, remaining 10) = 1`, which `issueBatchStock()` accepts (`BatchStockService.php:362-372` compares `available_quantity` — a stored generated column, confirmed on PG as `(quantity - reserved_quantity)`, exactly what the lane computes at `:1554`). The `InsufficientBatchStockException` branch is never entered, so the case cannot distinguish fixed from unfixed code. Its docblock says it "pins F-4's posture"; it pins the tolerant-drain behaviour that already existed at r1. The same is true of `test_a_shortage_prefers_saleable_lots_over_recalled_ones` — it stayed green in tamper T2 with the `is_recalled` filter fully restored.

The handback is honest that both were `✓` in the red-first block; the overclaim is the parenthetical labels ("pins F-4's posture", "pins the ORDER after F-3").

**Fix:** either a real two-connection PG race probe (the pattern `HeldOrderRecallContractTest` already uses in this repo for exactly this shape), or retitle both cases as posture documentation and say plainly that the F-4 defences are belt-and-braces with no tripwire.

*Other side of the seam:* I did verify the catch is structurally safe rather than merely untested — `issueBatchStock()` opens its own `DB::transaction`, so a throw rolls back to a savepoint and the outer transaction is never poisoned; and the refusal is an application-level `bccomp` check, not a failed SQL statement, so PG's aborted-transaction state never arises. The code is right. Only the proof is missing.

### NEW-4 [Minor] With the flag off, the report still does not say the value was not booked — and R-1 now cites a superseded ruling
`apps/api/app/Modules/Inventory/Application/Services/CountingDiscrepancyReportService.php:141-162` · `apps/api/config/inventory.php:29` · handback R-1

Verified on PG with the **shipped default** (`count_correction_gl_posting_enabled` false): the correction movement is written (`quantity -4.0000`, `unit_cost 4.250000` on the row) and **zero** journal entries exist for the company — of `inventory_shrinkage` source_type or of any other. The report payload contains no `gl_`, `journal` or `posted` key anywhere; the summary keys are exactly `total_items_counted, items_no_variance, items_with_variance, variance_breakdown, total_variance_value, items_agreeing, items_applied, items_not_applied, late_sales_corrections, opening_items, opening_value`, and the row reads `variance_applied: true`. So on this page **"applied" means "reached STOCK", never "booked VALUE"** — the r1 leg "the report says so" remains **UNMET**, and the fix round did not claim to close it.

Separately: handback R-1 still justifies the false default as *"a deploy-time blocker pending expert-comptable ratification (OQ-12/H-5)"*. That justification was **superseded by the owner ruling this lane itself merged** (`29072453d`, `OWNER-SHEET-2026-08-21-first-client-session.md`): seed it **true** when W4-6 lands; the expert reviews 6586/7586 later at onboarding. Not flipping it here is correct under rule 4 (it is lane P1's job) — but the residual points readers at a blocker the owner has already lifted.

**Fix:** re-point R-1 at the 2026-08-25 ruling + `BRIEF-P1-count-correction-gl-default.md`, and state plainly that until P1 lands the report shows "applied" for lines whose value is not in the ledger.

### NEW-5 [Minor] `items_no_variance` and `items_agreeing` are the same integer under two names
`apps/api/app/Modules/Inventory/Application/Services/CountingDiscrepancyReportService.php:145` and `:158`

Both are assigned `$itemsNoVariance`. F-6 is genuinely closed — probe P6 confirms `2 = 1 + 1 + 0` — but the payload now ships one number twice, and the FE type makes only the new one required (`types.ts:304`, alongside optional `late_sales_corrections?`). A consumer that later diverges them has no way to know which is canonical. **Fix:** say in the `:151-157` comment that `items_agreeing` is the alias `items_no_variance` kept for the partition, or drop one.

### NEW-6 [Minor] No test asserts the lot LEDGER row, only the lot projection
`apps/api/tests/Feature/Inventory/CountingVarianceAppliedTest.php` — `grep -n "inventory_batch_movements"` returns **nothing**

Every lot case asserts `inventory_batch_stock.quantity` via `lotQty()`. The append-only `inventory_batch_movements` rows — the document-per-action evidence on the lot lane — are unpinned. I verified by execution that they are correct: a 35-unit shortage across two lots wrote **2** batch-movement rows, each carrying `movement_id = <the count-correction `stock_movements`.id>`, Σ **−35.0000** == the aggregate delta **−35.0000**, and the parent movement carries `reference_type = inventory_counting` + `reference_id = <inventory_countings.id>`. **Fix:** add that assertion to the existing lot case; it is three lines and it is the only thing tying the lot ledger to its justifying document.

### NEW-7 [Minor] 418 changed lines in `ar/inventory.json` for 8 keys
`apps/web/src/locales/{en,fr,ar}/inventory.json`

`git show --stat 3f5b9aedc` reports `ar` at **+418/−43** and `en`/`fr` at +102/−13 each. I diffed the flattened key sets across `3f5b9aedc^..3f5b9aedc`: **each locale is exactly +8 keys, 0 removed, 0 changed** (`counting.report.{applied,appliedNo,appliedYes,counted,countedItems,expected,itemsApplied,itemsNotApplied}`). The rest is pure re-indentation. Zero risk, but it buries the semantic change and will do the same to the next reviewer of these files.

### Carried from r1

| r1 | Status this round |
|---|---|
| F-1 false annotate-durability comment | **CLOSED** — reworded at `ApplyStockAdjustmentsOnCountingCompleted.php:298-306`; I re-read it and it now states the truth (one savepoint, no durability, `replay_audit` is the idempotency marker) |
| F-2 pre-count half posts a phantom | **CLOSED for the boundary** (probe P2a, tamper T1 red) — the residual is NEW-1 |
| F-3 recalled lots skipped | **CLOSED** — probes P5/P5b/P5c, tamper T2 red |
| F-4 read-then-lock can abort the job | **CLOSED in code** (locked read + per-lot catch), **not proven by any test** — NEW-3; and its comment is now inaccurate — NEW-2 |
| F-5 report leg rendered nowhere | **CLOSED** — `DiscrepancyReportPage.tsx:404-450` renders `expected_qty`/`counted_qty`/`variance_qty`/`variance_applied`/`not_applied_reason` for **every** counted line through `formatQuantity(value, product.quantity_decimals)` + string sign predicates (`:465-478`); the float `item.variance` is gone from the page (`grep parseFloat\|Number(\|item.variance` on the file returns nothing but the rule-19 comment). The two `toFixed(1)` at `:327`/`:544` are on `accuracy_rate` and a percentage — not money, not quantity, pre-existing. 4 new vitest cases, all green |
| F-6 rows vs summary count different populations | **CLOSED** — probe P6; leaves NEW-5 |
| F-7 `isBlocking()` naming | **not taken — acceptable.** Minor, a public-enum rename across the module on a lane already changing that enum's behaviour; the `:14-45` paragraph now states the two-questions distinction. R-9 |
| F-8 legacy string-identity zero check | **not taken — acceptable, and I verified the stated reason.** `InventoryCountingItem::casts()` `:118` and `:122` are both `'decimal:4'`, so `final_qty` and `theoretical_qty` can never differ only by string scale. R-10 genuinely unreachable today |

---

## 3. Both-sides verification — what I traced on the OTHER side of each seam

**GL consequence exactly once, flag ON, on the REAL Option-A codes.** I re-provisioned the chart with the actual codes the provisioner installs for TN (`InventoryVarianceAccountProvisioner::definitions()` `:160-176`: `6586` shrinkage under `65`, `7586` gain under `75`) plus `37` Inventory, all carrying their `SystemAccountPurpose`. PG result: a counted-6-vs-on-hand-10 shortage posts **one** entry, `status = posted`, **2 lines** — **Dr `6586` 17.000 / Cr `37` 17.000**, each with `0.000` on the opposite side. `17.000 = 4.250000 × |6 − 10|`, i.e. the **WAC persisted on the movement row**, rounded once at the TND scale of 3, asserted by `assertEntryAmountEqualsRowCostTimesAbsoluteDelta`. `shrinkageEntries() === 1`. The lane's own 9 GL cases (agreeing line → 0 movements AND 0 entries; queue retry after the marker → no second entry; item that throws → 0 and 0; fail-soft chart still applies the stock correction) are all green on PG in the same run.

**GL consequence with the shipped default, flag OFF.** Stock moves, the cost is on the row, **zero journal entries of any source_type for the company**. The report says nothing about it — see NEW-4. The gate leg "report says so" is **not satisfied**, honestly and unchanged from r1.

**COGS books at stock-exit.** Nothing in this diff books COGS, defers it to invoice posting, or books it twice. The count correction is a shrinkage/gain write-off on its own movement, entirely movement-driven.

**Event emission.** No new GL path. The count correction reaches the ledger only through `enqueueCountCorrectionGl()` → `InventoryGlPostingBuffer` → the existing posting service, and the entry I read back was `status = posted` with balanced lines — never a directly-inserted Posted row. `flushIfOutermost()` at `DB::transactionLevel() === 1` is why `CountCorrectionGlPostingTest` opts out of transactional refresh; that structure is unchanged by this round.

**Single writer per projection.** Unchanged and re-checked: `stock_levels` on this path is written by `postCountCorrection()` (`:1410`) and `postAdjustmentWithinLock()` (`:941`) only; `inventory_batch_stock` by `BatchStockService` only. The fix round adds no writer — it adds a **lock** and a **catch** to an existing call site.

**Document-per-action, including the lot lane.** Verified to the row: the aggregate movement carries `reference_type = inventory_counting` + `reference_id = <inventory_countings.id>`, and each lot draw writes an `inventory_batch_movements` row whose `movement_id` is that movement's id (`BatchStockService::recordBatchMovement()` `:436-454`). Σ lot deltas == aggregate delta (−35.0000 == −35.0000). A zero adjustment still writes no row. Untested in-lane — NEW-6.

**Batch/lot invariant.** Per-location Σ`inventory_batch_stock` == `stock_levels.quantity` now holds for the recalled case that failed at r1 (probes P5, P5b, P5c) and for the W2-7 overage direction (P5d: 2 lot rows, Σ 67 == aggregate 67, real lot untouched, DEFAULT = untracked remainder only). It still does **not** hold when the shortage exceeds all lot stock — the accepted tolerant posture (R-4 / gate N-2), now `Log::warning`-ed at `:1591-1603` with product, location, movement and unallocated quantity, which is a real improvement over silence.

**WAC.** No path in this diff computes a cost blend. `postCountCorrection()` resolves the unit cost ONCE before writing the movement and the GL amount is derived from that persisted row — confirmed again by the `4.250000 × |Δ|` assertion. No cost-basis zeroing; no on-hand authored without a movement (probe P1a proves the strictly-before movement is baseline, not a phantom quantity).

**Append-only.** No `UPDATE`/`save()` on an existing `stock_movements` or `journal_entries` row anywhere in `git diff 3c6bdd588...HEAD`.

**Rule 19 / float.** PHPStan level 8 (carrying `ForbidFloatCastOnDecimalProperty` / `ForbidHardcodedBcmathScale`) on all 7 changed production files: **No errors**. All new backend arithmetic is bcmath at `StockAdjustmentService::SCALE = 4` / `InventoryScale::QUANTITY_SCALE`. On the FE the page now has **zero** `parseFloat`/`Number(`/`toFixed` on any quantity; sign is decided by the string predicates `isPositiveQuantity`/`isNegativeQuantity`/`isZeroQuantity` at `DiscrepancyReportPage.tsx:465-478` and formatting by `formatQuantity(value, product.quantity_decimals)`. `tsc --noEmit` exit 0, ESLint 0 errors.

**Precision claim, verified against the real schema.** The `[from, to]` boundary depends on second-precision storage. Read from PG `information_schema`: `inventory_counting_items.final_qty_as_of`, `count_N_at_estimate`, `count_1_device_at` and `stock_movements.occurred_at`/`created_at` all have `datetime_precision = 0`. So `boundary()`'s truncation is a no-op and `computeMany()`'s untruncated `$eventAt->gte($from)` (`:161`) cannot disagree with `signedDelta()`'s `>= boundary($from)` — **preview and apply select the same set**. The claim in the docblock is true.

**Blast radius of the `>` → `>=` change.** Three consumers, all read: `ApplyStockAdjustmentsOnCountingCompleted:546` (apply + audit), `CountingReplayPreviewService:83` (preview, via `computeMany`), and `CountingReconciliationService::normalizeCount()` `:252` — the count-vs-count normalisation, which the r1 review did not name. Its semantics change in the same direction (a movement at count N's own instant is now credited to count N). `NormalizedReconciliationTest` (5 cases, including "raw disagreement normalizes to agreement with intervening sale" and "same counts without intervening sale stays genuine mismatch") is **green on PG** in run #4. `hasMovementNear()` `:207-222` already used `>=`/`<=`, so the flag detector and the replay window are now consistent — they were not before.

**Session B Q-2 lock untouched.** `git diff --name-only dev...HEAD -- apps/api/app apps/web/src` lists 7 backend files and 10 frontend files; `InventoryCountingService.php` is **not** among them, nor is any transition guard. `CountingVarianceAppliedTest::test_a_second_finalize_is_refused_and_applies_nothing_twice` is green on PG.

**CI posture (S-17, unchanged).** `grep -c` for `CountingVarianceAppliedTest|CountCorrectionGlPostingTest|MovementReplayServiceTest|ReplayFinalizeTest|PreFinalizeReplayPreviewTest|CountingDiscrepancyReportTest` in `.github/workflows/ci.yml` returns **0**, and the Inventory feature lane is PARKED behind `vars.SELF_HOSTED_RUNNER_READY` (manifest check: "70 group(s) / 1174 class(es) are laned but not yet running"). **Register this merge CI-UNVERIFIED, in-lane-verified only.** The newly-reachable GL leg still has no CI tripwire — and it stops being "newly reachable" and becomes "live" the moment lane P1 flips the flag.

---

## 4. What to fix before merge

Nothing blocks the merge. Do the doc-only `git merge dev` (`562cb1331`), then close **NEW-2** in-lane (`->lock('for update of inventory_batch_stock')` or correct the comment — it is a lock-order claim, and a wrong one is how the next deadlock gets written), and put **NEW-1** on the LEDGER as a hard precondition of lane P1's flag flip, since the owner's 2026-08-25 ruling makes that flip the very next step. NEW-3 (retitle or race-probe), NEW-4..NEW-7 are residuals.
