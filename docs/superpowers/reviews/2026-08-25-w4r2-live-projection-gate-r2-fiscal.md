# W4R-2 gate r2 — fiscal-pos lens (live POS projection lot legs) — VERIFY-ONLY

**Lane:** `fix/campaign-w4r2-live-pos-projection-lots` — HEAD `c80f4f36e`
(fix commit `c4136ae63`, dev merge `ceada91db`)
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w4r2-live-projection-lots` — **never modified**
**r1:** `docs/superpowers/reviews/2026-08-25-w4r2-live-projection-gate-r1-fiscal.md`
(F-1 CRITICAL merge-blocking · F-2/F-3 IMPORTANT · F-4/F-5/F-6 MINOR)
**Method:** read `git diff 23ba417b1...HEAD` + BY EXECUTION on throwaway PostgreSQL 16
`autoerp_test_w4r2f2` (127.0.0.1:5433), fault injection in a detached tamper worktree at HEAD
(`.worktrees/w4r2f2-tamper`), one test process at a time, never the full suite.

**Throwaway DB `autoerp_test_w4r2f2` dropped and the tamper worktree removed after the run.**

---

## 1. r1 finding disposition

| r1 | Severity | r2 disposition |
|---|---|---|
| **F-1** refund lot arm REJECTS an already-signed refund | CRITICAL | **CLOSED — proved by execution** (PR-3, PR-5, lane test green, red-proof B reproduces the r1 failure) |
| **F-2** only a shortfall contained; any other throw kills the sealed receipt | IMPORTANT | **CLOSED — proved by execution** (PR-1 PHP throw, PR-4 real PostgreSQL 42P01/25P02) |
| **F-3** manifest + ci.yml stale against dev | IMPORTANT | **CLOSED — recomputed against CURRENT dev `274594ebf`** |
| **F-4** class docblock advertises composite coverage it does not pin | MINOR | **CLOSED** — item (h) restated as the W4R-3 residual, `PosCoreReceiptProjectionBatchLotTest.php:70-77` |
| **F-5** shortfall logged, never detected | MINOR/owner | **PARTLY CLOSED** — detector shipped; nothing schedules it (see N-5) |
| **F-6** provenance netting counts refunds that credited no lot | MINOR | **CLOSED** — disposition filter, `PosCoreReceiptProjection.php:2716-2734` |

---

## 2. Execution evidence — containment (brief item 1)

`containLotWork()` at `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:2169`,
call sites `:2046` (sale) and `:2600` (refund). Both arms run in their own `DB::transaction`
(= SAVEPOINT, since `apply()` already holds one at `:252`); `ConcurrencyFault::isRetryable($e)`
rethrows at `:2176`, everything else is `Log::error`'d at `:2183`.

Lane suite on the throwaway PG DB, from HEAD:

```
tests/Feature/Fiscal/PosCoreReceiptProjectionBatchLotTest.php
  → 17 tests, 81 assertions, 16 passed, 1 SKIPPED (the containment test — see N-1), 16.2s
same class on sqlite → 17 tests, 3 passed, 14 skipped (the containment test PASSES here)
```

Fault-injection probes (tamper worktree, PostgreSQL, a test-local subclass of the non-final
`FEFOInventoryService`; every probe ran after `app(CompanyContext::class)->clear()`):

| Probe | Injection | Observed |
|---|---|---|
| **PR-1** sale arm, non-retryable PHP throw (`\LogicException`) | `consumeBatchesAtomically()` throws | `thrown=NONE receipts=1 lines=1 payments=1 movements=1 stock=6.0000 lot=10.0000 batchmoves=0 allocs=0` + `Log::error` with `arm=sale` and the `inventory:lot-drift-census` pointer. **Contained.** (r1 measured `receipts=0`.) |
| **PR-4** sale arm, REAL PostgreSQL SQL error (`42P01` undefined table → transaction in `25P02`) | a `select` against a missing table inside the savepoint | `thrown=NONE receipts=1 lines=1 payments=1 movements=1 stock=6.0000 lot=10.0000 allocs=0`. **`ROLLBACK TO SAVEPOINT` clears the aborted-transaction state and the enclosing transaction commits normally** — the savepoint is genuinely load-bearing, not decorative. |
| **PR-3** refund arm, credit legs written THEN throw | real `restoreBatchesForReturn()` runs to completion, then `\LogicException` | `thrown=NONE receipts=2 refundrow=YES payments=2 stock=10.0000 lot_after_sale=6.0000 lot_now=6.0000 batchmoves_after_sale=1 batchmoves_now=1`. **The sealed refund projects; the half-written credit leg is rolled back to the savepoint — no orphan `creditLot()` movement, no partial state.** |
| **PR-5** retryable fault at the PRODUCTION transaction depth | `QueryException` wrapping `PDOException` SQLSTATE `40P01`; `apply()` called at `DB::transactionLevel()==0`, the depth `ApplyFiscalEventProjectionJob` uses (T_apply runs OUTSIDE T_lock — `ApplyFiscalEventProjectionJob.php:60-62`, `:394`) | `depth_at_apply=0 thrown=Illuminate\Database\DeadlockException receipts=0 movements=0 level_now=0`. **Rethrown out of `apply()` so Horizon retries; the whole projection rolled back; connection left clean.** |
| PR-2 same fault at depth 1 (RefreshDatabase's own outer transaction) | as PR-5 | `thrown=DeadlockException receipts=1` — a HARNESS artifact, not a defect: Laravel's `handleTransactionException` skips `ROLLBACK TO SAVEPOINT` for a concurrency error when `transactions > 1`, so at depth 1 the rollback lands on the test harness's transaction instead. PR-5 shows production depth is correct. |

The `MissingVariantException` pre-lane-sale case (r1 F-1's reachability argument) now projects:
`test_refund_of_a_product_level_line_on_a_variant_bearing_product_still_projects` is **green on PG**.

**Red-proofs (tamper worktree, both reverted afterwards; lane file restored, `git diff` empty):**

* **RP-A** — `containLotWork()` neutered to `$work(); return;`
  → `test_a_throwing_lot_arm_never_takes_the_sealed_receipt_down` **ERRORS on sqlite**, the
  `QueryException` escaping `apply()` at `PosCoreReceiptProjectionBatchLotTest.php:420`.
* **RP-B** — same neutering **plus** `mintDefaultLotForUnattributed:` back to `true`
  → `test_refund_of_a_product_level_line_on_a_variant_bearing_product_still_projects`
  **ERRORS on PG at :490** (the refund `apply()` — the r1 F-1 CRITICAL reproduced verbatim) and
  `test_refund_of_a_pre_lane_sale_mints_no_phantom_default_lot_and_closes_the_drift`
  **FAILS** with lot total `14.0000` vs expected `10.0000` (the phantom `DEFAULT`).

Both pins genuinely bite.

---

## 3. Replay / out-of-order / sealed bytes (brief item 2)

* **Structural:** the fix commit's only hunks in the projection are line 4 (one `use`) and
  everything at/after `:2042` — i.e. **inside the lot arms only**. Nothing between `:245`
  (`CanonicalPayloadReader::forSaleReceipt`) and `:2042` is touched: not the sealing/canonical-bytes
  write, not the `INSERT … ON CONFLICT DO NOTHING` receipt anchor, not
  `assertOriginalReceiptResolvableForRefundOrVoid()` (`:343`, still upstream of every lot arm and
  outside `containLotWork`), not the VAT/payment/GL writes. Hunk ranges: `@@ -4,6 +4,7 @@`,
  `@@ -2042,48 +2043,150 @@`, `@@ -2438…`, `@@ -2468…`, `@@ -2498…`, `@@ -2536…`, `@@ -2553…`, `@@ -2561…`.
* **Executed:** `test_projection_leaves_the_sealed_bytes_and_chain_hash_byte_identical`,
  `test_replay_of_the_same_sale_writes_no_second_lot_leg`,
  `test_replay_of_the_same_refund_writes_no_second_credit` — all green on PG.
* **Regression, PostgreSQL, one process each:**
  * `PosCoreReceiptProjectionRefundDispositionStockTest` + `…RefundStockTest` + `…RefundNoDecrementTest`
    + `…VariantStockTest` + `…ConcurrentRedeliveryTest` + `PosCoreReceiptProjectionTest`
    → **64 passed, 326 assertions**.
  * `RepairPhantomDefaultBatchesCommandTest` + `ReceiptReturnRefactorTest` + `ReceiptStockPolicyTest`
    + `AtomicFEFOConsumptionTest` + `StockReservationDefaultBatchTest` → **49 passed, 198 assertions**
    (the document channel keeps `mintDefaultLotForUnattributed = true` by default and is unchanged).
* Both lot arms remain downstream of the receipt-level idempotency anchor, so they inherit
  exactly-once; nothing new was added to `apply()` outside that anchor.

---

## 4. F-3 — ci.yml union + manifest vs CURRENT dev (brief item 3)

Local dev moved twice during this gate: `274594ebf` → **`001bd9bdc`** (the lane merged `cb6a86529`).
`dev` is **not** an ancestor of HEAD, so both were recomputed — and then RE-taken against `001bd9bdc`,
which changed only `OpeningCashFloatSeedsRepositoryTest.php` plus two review docs (manifest still
`1186` / Fiscal `81` / Document `89`; filter still **154**). The union below therefore still holds.

* **ci.yml `backend-test-pgsql --filter`** — dev **154** entries, HEAD **155**, `HEAD − dev =
  {PosCoreReceiptProjectionBatchLotTest}`, `dev − HEAD = {}`, **0 duplicates**, pattern compiles.
  A clean union, no wholesale-side loss. ✅
* **Manifest** — dev `001bd9bdc`: `gated_ceiling 1186`, `Fiscal 81`, `POS 156`, `Document 89`,
  `Inventory 116`. HEAD: `gated_ceiling 1187`, `Fiscal 82`, others identical.
  `git diff --name-status dev...HEAD -- apps/api/tests` shows exactly **one** added Feature class and
  dev added **zero** since the merge base ⇒ the post-merge tree measures 1186 + 1 = **1187**. ✅
* `php tools/feature-lane-manifest-check.php` at HEAD: **OK** — "1442 Feature classes in 74 groups;
  every `--filter` entry is anchored and uniquely matched"; parked count **1187** == ceiling **1187**. ✅

---

## 5. New `inventory:lot-drift-census` (brief item 4)

* `app/Console/Commands/LotLedgerDriftCensusCommand.php:52-56` — signature carries
  `--tenant` / `--all-tenants` / `--company` / `--fail-on-drift`. **No `--execute` arm.**
* Extends `App\Console\TenantScopedCommand` and drives `forEachExplicitlySelectedTenant()` (`:77`),
  so it refuses an unscoped run — pinned by `test_the_census_command_refuses_to_run_without_an_explicit_scope`
  (green on both drivers).
* **Read-only:** `LotLedgerDriftCensus` (`app/Modules/BatchExpiry/Application/Services/LotLedgerDriftCensus.php:72-93`)
  issues one `SELECT` and nothing else; `test_the_operator_command_reports_the_shortfall_drift_and_writes_nothing`
  asserts `stock_movements` and `inventory_batch_movements` counts are unchanged. Green on PG.
* **`ConsoleCommandTenantContextTest` classification:** the command is **NOT** in the offender list.
  The test is red on this branch with **12** offenders — all pre-existing and unrelated
  (`ScanPercentScaleDrift`, `ExportFrontendPermissionsMap`, `ConfigureMethodRepositoryRoutingCommand`,
  3× `Backfill*`, `RunEnrichmentCommand`, `BackfillLocationAttributionCommand`, `BackfillMembershipsCommand`,
  3× `SupportAccess\…`). **Zero delta from this lane.**
* **No new queue:** `git diff dev...HEAD -- apps/api/app | grep '^+' | grep onQueue` → empty.
  `HorizonQueueCoverageTest` unaffected.

---

## 6. Guards (brief item 5)

| Gate | Result |
|---|---|
| `tools/deptrac-ratchet.php` | **PASS — TOTAL 183/183**, no boundary regression (identical to r1) |
| `tools/feature-lane-manifest-check.php` | **OK**, 1187/1187 |
| `DocumentPerActionWriteGuardTest` + `DocumentPerActionBaselineRatchetTest` | 7/8 green incl. **"Repository violations match the baseline exactly"** ⇒ **zero DPA delta**. `git diff dev...HEAD -- apps/api/tests/Architecture/baselines/` is **empty**. The one red is `the_working_baseline_never_grows_against_the_owner_pinned_blob`, which fails closed on a missing `DPA_BASELINE_PROTECTED_BLOB` env var — environmental, not a lane defect. |
| `vendor/bin/pint --test` on the three changed dirs | **pass** |
| `vendor/bin/phpstan analyse` on the four changed prod files | **[OK] No errors** |
| Rule 19 money/quantity | fix commit adds **no** money math: `(float)` / `parseFloat` / `number_format` / no-arg `getScale()` / `bcformat(` — all **zero hits**. Only quantity bcmath at scale 4 with `precision-ok` markers. ✅ |
| Rule 20 CompanyContext | new arms read tenant/company off `$event`; every probe ran with the context CLEARED. `app(` — zero hits in the fix diff. ✅ |
| Rule 8 events immutable | no Event class renamed/restructured/deleted; refund is still a `SALE_RECEIPT` with `invoice_type_code`; no parallel refund event invented. ✅ |

---

## Findings (r2)

### N-1 [IMPORTANT — not merge-blocking] The fix round's headline pin executes in NO live CI lane

`PosCoreReceiptProjectionBatchLotTest.php:408-410` skips
`test_a_throwing_lot_arm_never_takes_the_sealed_receipt_down` on `pgsql`
(`markTestSkipped('Needs a driver on which the FEFO draw genuinely throws…')`). That is a
deliberate, documented choice (handback §r1.1), and the fault injector is honest. But combine it
with where the class actually runs:

* the only **live** executor is the `backend-test-pgsql --filter` allowlist (`.github/workflows/ci.yml:1046`)
  — where this test **skips**;
* its manifest home `feature-lane-fiscal-finance` is **PARKED** behind
  `vars.SELF_HOSTED_RUNNER_READY` (`ci.yml:1467`);
* the sqlite `backend-test` job runs `--testsuite=Unit`, `tests/PHPStan`, two Architecture ratchets
  and a manifest census — **not** `tests/Feature` (`ci.yml:325-502`).

Measured: **PG 16 pass / 1 skip (the containment one); sqlite 3 pass / 14 skip.** So the contract the
whole fix round exists to establish — *a lot arm that throws never takes the sealed receipt down* —
has **zero standing CI guard**, while every other pin in the class has one on PG.

*Why it matters:* r1's F-1 was a CRITICAL that shipped once already. A pin that runs nowhere cannot
stop it shipping again.

*Fix:* make the containment test driver-agnostic by injecting the fault instead of relying on the
sqlite parse error — `FEFOInventoryService` is **not** `final`, so a test-local subclass works and
needs no mocking framework (probes PR-1/PR-3/PR-4 above do exactly this on PostgreSQL). Then it runs
in the live pgsql allowlist alongside its siblings. Failing that, name the class in a live
path-based sqlite step.

**Corroboration + in-flight status.** The inventory lens reached the same conclusion independently
in its r2 (`docs/superpowers/reviews/2026-08-25-w4r2-live-projection-gate-r2-inventory.md`, merged to
dev as `d743c2e4f`: *"F-1r containment pin skips on PG — PG-capable pin requested before merge"*), so
this is a shared condition, not a single-lens preference. At the time of writing the lane worktree
carries an **uncommitted** work-in-progress that does exactly this — a container-bound
`PartiallyWritingThenThrowingFefoService` double that writes a real `inventory_batch_movements` leg
and then throws, with the `markTestSkipped('pgsql')` guard removed. That work is **not in HEAD
`c80f4f36e`** and is therefore outside this VERIFY-ONLY gate; it needs its own r3 pass (in particular:
the leg-then-throw double must be re-red-proved, and the added `pos_receipt_vat_details` assertion
checked on both drivers).

### N-2 [MINOR] `containLotWork()` omits the GL half of the idiom it cites

The docblock (`PosCoreReceiptProjection.php:2159-2161`) says "Same idiom, same reasoning, as
`applyScrapDisposition()`". That method takes `$marker = $this->glBuffer->mark()` **before** the
savepoint (`:2847`) and `$this->glBuffer->rollbackTo($marker)` in the catch (`:2885`);
`containLotWork()` does neither. **Harmless today** — `FEFOInventoryService` never touches
`InventoryGlPostingBuffer` (grep for `glBuffer|GlPosting|JournalEntry|enqueue` in that file returns
nothing) — but if a lot arm ever enqueues an inventory GL entry, the entry would survive the savepoint
rollback and flush at `:516` against lot legs that no longer exist. Add the marker now, or state in
the docblock that the lot arms are GL-free by construction.

### N-3 [MINOR] `BatchStockConsumed` is dispatched inside the savepoint

`FEFOInventoryService.php:325` fires `event(new BatchStockConsumed(...))` inside
`consumeBatchesAtomically()`, i.e. inside the new savepoint. On a contained failure the consumption
is rolled back but the event has already been dispatched. Inert today — a grep of `app/` finds **no
listener** — but a future listener would act on a consumption that never happened. Prefer
`afterCommit`, or dispatch from the caller once the savepoint has released.

### N-4 [MINOR] Docblock-only cross-module `use` imports

Three runtime-unused imports were added purely so a `{@see}` renders short:
`FEFOInventoryService.php:17` `use App\Modules\Document\Domain\Services\ReturnNoteService;`, and
`LotLedgerDriftCensus.php:7-8` `use App\Console\Commands\RepairPhantomDefaultBatchesCommand;` /
`use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;`. Nothing is red (deptrac 183/183,
PHPStan OK, Pint pass), but a `BatchExpiry\Domain` service now `use`s a `Document\Domain` service —
the rule-6 shape the very same file refuses two dozen lines earlier for `BatchStockService`
("Duplicated rather than imported because Domain must not depend on Application", `:33-37`). Use a
fully-qualified `{@see \App\…}` in the docblock and drop the `use`.

### N-5 [MINOR / owner] The detector is shipped but nothing schedules it

`inventory:lot-drift-census` is not referenced in `routes/console.php`, `bootstrap/app.php`,
`app/Console/Kernel.php` or `app/Providers/` — nor is its sibling
`inventory:repair-phantom-default-batches`. r1 F-5's ask ("nothing surfaces it") is now *answerable*
but still fully opt-in: the 27 historical drifted units and every future contained failure stay
invisible until an operator remembers to run the command. `--fail-on-drift` exists precisely for a
scheduled check — wiring it (per tenant, daily) is a one-line follow-up and the natural home for the
onboarding gate the handback references.

---

## Verdict

**VERDICT: spec ✅ + quality APPROVED — merge-blocking: NO.**

r1's CRITICAL F-1 and IMPORTANT F-2 are closed and *proved closed by execution on PostgreSQL*, not
by assertion: a non-retryable throw in either arm now leaves the sealed receipt, its lines, its
payments and its GL intact with the lot legs absent and a `Log::error` naming the census; a real
`42P01`/`25P02` abort is recovered by `ROLLBACK TO SAVEPOINT`; a refund arm that throws *after*
writing `creditLot()` legs leaves no orphan leg; and a retryable deadlock at the production
transaction depth escapes `apply()` with the whole projection rolled back so Horizon retries. Both
red-proofs reproduce the r1 defects verbatim when the fixes are reverted. Sealed bytes, the chain
hash, replay idempotency and the out-of-order dependency guard are structurally untouched (no hunk
below line 2042) and green. F-3's manifest/ci.yml union is correct against the CURRENT dev tip
(1187 / Fiscal 82; filter 155, exact superset of dev's 154). The new census command is read-only,
tenant-scoped, has no `--execute` arm, adds no queue and is correctly classified.

The five residuals are all follow-ups, and only N-1 is worth blocking a *promotion* on rather than
this merge: the containment pin itself currently runs in no live CI lane.

**What to fix before merge:** land N-1 — the containment pin must run on PostgreSQL (the inventory r2
asked for the same, and an uncommitted in-flight fix already exists in the lane worktree); that
change is not in HEAD `c80f4f36e` and needs an r3 pass before merge.
