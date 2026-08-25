# W4R-2 gate r2 — inventory-costing lens (live POS projection lot legs), VERIFY-ONLY

**Lane:** `fix/campaign-w4r2-live-pos-projection-lots` · worktree
`/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w4r2-live-projection-lots`
**Reviewed:** HEAD `c80f4f36e` (fix commit `c4136ae63`; `merge dev` `ceada91db`)
**Diff:** `git diff 23ba417b1...HEAD` (r1 HEAD → r2 HEAD). Code delta of the fix round is
`c4136ae63` alone: 6 files, +1028/−78.
**Gate run:** 2026-08-25, BY EXECUTION, throwaway PostgreSQL 16 `autoerp_test_w4r2g2`
(`127.0.0.1:5433`, `autoerp`/`autoerp_secret`, dropped at the end). No lane file modified.

> **(verdict at §6)**

---

## 1. F-1 — containment (`containLotWork()`)

**Code, read and confirmed.**
`apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:2185-2214`:

```php
private function containLotWork(FiscalEvent $event, string $receiptId, string $productId, string $arm, \Closure $work): void
{
    try { DB::transaction($work); }
    catch (\Throwable $e) {
        if (ConcurrencyFault::isRetryable($e)) { throw $e; }
        Log::error('PosCoreReceiptProjection: the lot arm failed; the sealed receipt, its payments and its GL are projected WITHOUT lot legs …', [...]);
    }
}
```

* **BOTH arms wrapped.** Sale arm `:2046-2052` (`'sale'`), refund arm `:2625-2631`
  (`'refund'`). The whole FEFO call *and* the allocation snapshot are inside the closure, so
  a snapshot failure rolls the ledger legs back with it rather than leaving a half state.
* **Savepoint is real.** `DB::transaction()` nested inside `apply()`'s transaction
  (`:252`) issues `SAVEPOINT`; Laravel's `Connection::handleTransactionException()` calls
  `rollBack()` → `ROLLBACK TO SAVEPOINT` for a non-concurrency throwable, which is what lets
  PostgreSQL continue after a 25P02 abort. The lane's docblock (`:2147-2160`) states this
  correctly.
* **Retryable rethrow is correct and load-bearing.** For a concurrency error at nesting
  level > 1 Laravel does **not** emit `ROLLBACK TO SAVEPOINT` — it only decrements the
  counter and rethrows. Swallowing one would leave the enclosing transaction aborted and let
  `apply()` "COMMIT" a discarded receipt. `ConcurrencyFault::isRetryable($e)` rethrows before
  the log. Verified by reading both the guard (`:2196-2198`) and the framework path.
* **Probe P6 shape now passes.** `test_refund_of_a_product_level_line_on_a_variant_bearing_product_still_projects`
  (`tests/Feature/Fiscal/PosCoreReceiptProjectionBatchLotTest.php:441-500`) — **green on PG**:
  2 `pos_receipts` rows (the refund receipt exists), aggregate restored to `10.0000`.
* **Census sees it.** The `Log::error` message names `inventory:lot-drift-census`, and the
  drift the containment leaves is state, so the new census reports it (§4).

**Residual (see F-1r below): the containment pin executes in NO CI job.**

## 2. F-2 — DEFAULT-lot provenance honoured

`apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:459-461` builds
ONE ceiling map with `includeDefaultLots: true`; `:613-655` makes that flag the only thing
that drops the `pb.batch_number != 'DEFAULT'` predicate; `:496-509` keeps the **heuristic**
pass skipping DEFAULT lots via the new `defaultLotIds()` set (`:552-573`). So DEFAULT is
reachable by EVIDENCE (step 1) and never by GUESS (step 2) — exactly the split r1 asked for.

**Executed:** `test_refund_credits_the_default_lot_when_that_is_the_lot_the_sale_took`
(`:511-556`) reproduces probe P4 verbatim (DEFAULT @+10d, LOT-DATED @+200d, sell 4 then 8,
refund receipt 1) and is **green on PG**:
`DEFAULT 4.0000`, `LOT-DATED 8.0000`, `stock_levels 12.0000`, `Σ lots 12.0000`.
Pre-fix this was `DEFAULT 2 / LOT-DATED 10`. The dated lot is untouched. ✅

## 3. F-3 — no phantom `DEFAULT` from the projection

`FEFOInventoryService::restoreBatchesForReturn()` gains
`bool $mintDefaultLotForUnattributed = true` (`:450`) and now RETURNS the unattributed
`numeric-string` (`:521-526`, `:536`). The projection passes `false`
(`PosCoreReceiptProjection.php:2644`) and logs the remainder at `info` (`:2647-2657`).

* **Document channel unchanged — verified at both call sites, neither passes the flag:**
  `app/Modules/Document/Domain/Services/ReturnNoteService.php:886-895` and
  `app/Modules/POS/Application/Services/ReceiptReturnService.php:541-551`. Default `true`
  ⇒ step 3 still mints for those channels.
* **Executed:** `test_refund_of_a_pre_lane_sale_mints_no_phantom_default_lot_and_closes_the_drift`
  (`:562-605`) is **green on PG** — after the refund: aggregate `10.0000`,
  `LOT-A 10.0000`, `Σ lots 10.0000`, `product_batches WHERE batch_number='DEFAULT'` count
  **0**, `driftFor() == '0.0000'`. Probe P1's `DEFAULT@today+365` is gone and drift is 0. ✅

## 4. F-4 — `inventory:lot-drift-census`, and the repair command delegating to it

* **Read-only by construction.** `app/Console/Commands/LotLedgerDriftCensusCommand.php`
  has no `--execute`, no write of any kind; its only work is
  `LotLedgerDriftCensus::driftedTuples()` (`:87`) plus `$this->line()/warn()/info()`.
  `--fail-on-drift` returns `self::FAILURE` only at `:134-136`, after reporting.
* **Same query, one owner.** `app/Modules/BatchExpiry/Application/Services/LotLedgerDriftCensus.php:72-93`
  is the builder lifted VERBATIM out of `RepairPhantomDefaultBatchesCommand::batchTrackedTuples()`,
  which now returns `$this->driftCensus->batchTrackedTuples($companyId)`
  (`app/Console/Commands/RepairPhantomDefaultBatchesCommand.php:545-548`) — a one-line
  delegation, constructor-injected `private readonly` (`:121`). No `app()`.
* **Executed on the re-run tenant `tenant01a038cd-4a42-7193-97db-2acb598752da`, read-only:**

```
$ php artisan inventory:lot-drift-census --tenant=01a038cd-…
  DRIFT  6.0000  01a038cf-5b0d… @ 01a038cd-6359…  (61.0000 vs 55.0000)
  DRIFT  4.0000  01a038cf-5b37… @ 01a038cd-6359…  (68.0000 vs 64.0000)
  DRIFT  2.0000  01a038cf-5b5d… @ 01a038cd-6359…  (60.0000 vs 58.0000)
  DRIFT 10.0000  01a038cf-5b5d… @ 01a038ce-581a…  (25.0000 vs 15.0000)
  DRIFT  5.0000  01a038cf-5b82… @ 01a038ce-581a…  (35.0000 vs 30.0000)
  Tuples drifted: 5 · Net drift: 27.0000 · Absolute drift: 27.0000 · EXIT=0
```

  An **independent** hand-written `psql` SELECT of the same shape returns the identical 5
  tuples and the identical **27.0000**, and matches r1's census product-for-product
  (GEL-HYDR/ARIA 10, SIRO-TOUX/MAIN 6, LAIT-CORP/ARIA 5, COMP-MAGN/MAIN 4, GEL-HYDR/MAIN 2).
* **Nothing was written.** Post-run counts on that tenant are byte-identical to r1's:
  `pos_receipts=4, stock_movements[pos_sale]=6, [pos_return]=1,
  inventory_batch_movements=10, pos_receipt_line_batch_allocations=0`.
* `driftedTuples()` is bcmath at scale 4 throughout (`:112`, `:114`), with a driver-normalising
  `decimal()` guard (`:142-155`). **No float.** `QuantityScale::round(..., FLOOR)` for zero
  literals. The `Command` uses `bcadd`/`bccomp`/`bcmul` only (`:97-104`).
* **PG-vs-SQLite aggregate risk:** the census is `SUM()` over a LEFT JOIN, exactly the class
  of aggregate the reviewer checklist warns SQLite can mask — and here it is exercised on
  **PostgreSQL** by `test_a_lot_shortfall_is_reported_by_the_drift_census` /
  `test_the_operator_command_reports_the_shortfall_drift_and_writes_nothing`
  (both `requiresPostgresLotDraw()`), plus the live tenant run above. Adequate.

## 5. Green / gate evidence (executed on `autoerp_test_w4r2g2`)

| Check | Result |
|---|---|
| `tests/Feature/Fiscal/PosCoreReceiptProjectionBatchLotTest.php` on **PG** | **16 passed, 1 skipped (81 assertions)** |
| same class on **sqlite** | 3 passed, 14 skipped — the 1 sqlite-only containment test is among the 3 |
| W2-7 / document-channel regression set on **PG** (`RepairPhantomDefaultBatchesCommandTest`, `ReceiptStockPolicyTest`, `ReceiptReturnRefactorTest`, `ImplicitReservationFefoLotTest`, `BatchTrackedSalesOrderConfirmFefoTest`, `StockReservationDefaultBatchTest`, `AtomicFEFOConsumptionTest`, `ReturnNoteConfirmSealAndPeriodTest`) | **87 passed (341 assertions), 0 failures** |
| PHPStan L8 on all 5 changed production files | **[OK] No errors** |
| deptrac | **183 violations** — unchanged from r1 (`183/183`); the two new doc-only `use` imports add no edge |
| `tools/feature-lane-manifest-check.php` | `OK — 1442 Feature classes in 74 groups`, `1187 class(es)` parked, **EXIT=0** |
| DPA ratchet (`DocumentPerActionBaselineRatchetTest` + `DocumentPerActionWriteGuardTest`) | 7 passed, **1 failed** — and the only failure is the fail-closed `DPA_BASELINE_PROTECTED_BLOB` probe, unset locally by design. The NEW/STALE-key assertions pass ⇒ **zero DPA delta** |
| `tests/Architecture/ConsoleCommandTenantContextTest.php` | **RED — inherited, byte-identical.** Run on lane `c80f4f36e` and on dev `274594ebf` in the SAME probe tree: both list the same **12** unclassified commands, `diff` empty. The lane's new `LotLedgerDriftCensusCommand` is **not** among them (it extends `TenantScopedCommand`, `:42`) |
| No float on money/qty | grep of `c4136ae63` for `(float)`/`floatval`/`parseFloat`/`number_format`/`round(` on qty → nothing new; all new arithmetic is `bcadd`/`bcsub`/`bccomp`/`bcmul` at scale 4 with `precision-ok` markers |
| Scale resolution | the fix round adds **no** currency-scale call at all — no queue-reachable no-arg `getScale()` introduced (rule 19/20 clean) |
| Lock order | unchanged by the fix round: `stock_levels` → `inventory_batch_stock` still holds; the savepoint nests inside the same transaction and takes no new lock |
| Lane worktree | `git status --porcelain` **empty** — I modified nothing |

### Red-proof (tampered in a throwaway `git worktree`, vendor hard-linked so autoload
resolves to the probe tree — verified via `ReflectionClass::getFileName()`)

| Tamper | Result |
|---|---|
| drop `includeDefaultLots: true` (revert F-2) | `test_refund_credits_the_default_lot_when_that_is_the_lot_the_sale_took` **FAILS** at `:544` (`4.0000` expected, DEFAULT under-credited) ✅ pin bites |
| flip `mintDefaultLotForUnattributed` back to `true` (revert F-3) | `test_refund_of_a_pre_lane_sale_mints_no_phantom_default_lot_and_closes_the_drift` **FAILS** at `:582` (Σ lots ≠ 10.0000 — the phantom is back) ✅ pin bites |
| replace `containLotWork()`'s `try { DB::transaction($work) } catch` with a bare `$work()` (revert F-1) | on **sqlite** `test_a_throwing_lot_arm_never_takes_the_sealed_receipt_down` **FAILS** — the `QueryException` from `FEFOInventoryService.php:243` escapes `apply()` ✅ pin bites — **but see F-1r: on PostgreSQL, all 16 tests still pass with containment fully removed** |

---

## 6. Findings

### F-1r [IMPORTANT] the F-1 containment pin executes in **no CI job** — the one Critical this round fixed has no regression guard on the merge path
`tests/Feature/Fiscal/PosCoreReceiptProjectionBatchLotTest.php:404-406`
· `.github/workflows/ci.yml:1048` · `.github/workflows/ci.yml:1467`

The only test that pins containment skips itself on PostgreSQL by design
(`if (DB::connection()->getDriverName() === 'pgsql') { $this->markTestSkipped(…); }`) — it
needs a driver where the FEFO draw genuinely throws. But the class's ONLY CI execution is
the `backend-test-pgsql --filter` allowlist (it was appended there this lane), because its
home lane `feature-lane-fiscal-finance` is parked behind `vars.SELF_HOSTED_RUNNER_READY`.
So: on PG it skips, and nowhere else runs the class at all.

**Executed proof, not inference.** With `containLotWork()`'s `try/catch/savepoint` replaced
by a bare `$work()` in the probe tree, the whole class on PostgreSQL is
**`1 skipped, 16 passed (81 assertions)`** — byte-identical to the untampered run. The
sibling test the class advertises as the outcome-level guard,
`test_refund_of_a_product_level_line_on_a_variant_bearing_product_still_projects`
(`:441`), passes with containment entirely deleted, because F-3's
`mintDefaultLotForUnattributed: false` means `defaultBatchId()` is never reached — so its
own comment ("keeps biting if either is undone", `:462-464`) is **half wrong**: it bites for
F-3, not for F-1.

*Why it is Important and not Critical:* the fix itself is correct and I verified it by
execution. What is missing is the ratchet. A later refactor that removes the savepoint gets
a fully green CI.

**Suggested (cheap, no new class):** add a PG-executable containment test that injects a
deterministic non-concurrency fault the FEFO service will raise on PostgreSQL — e.g. seed the
sale line's product with a lot row whose `batch_id` FK is then made to violate on the
allocation insert, or bind a container decorator over `FEFOInventoryService` for that one
test — and assert `pos_receipts = 1` + the `arm => 'sale'` `Log::error`. Alternatively name
the class in a **sqlite** CI selector too, so the existing test actually runs somewhere.

### F-2r [MINOR] a Domain service and an Application service now `use`-import classes from other modules for docblocks only
`app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:17`
(`use App\Modules\Document\Domain\Services\ReturnNoteService;`) and
`app/Modules/BatchExpiry/Application/Services/LotLedgerDriftCensus.php:7-8`
(`use App\Console\Commands\RepairPhantomDefaultBatchesCommand;`,
`use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;`).

All three are referenced **only** inside `{@see …}` tags. deptrac does not count them
(I re-ran it: **183**, identical to r1), and PHPStan is clean, so nothing goes red — but a
BatchExpiry Domain service that names `Document\…\ReturnNoteService`, and a read-only census
that names a POS projection, are exactly the cross-module references rule 6 exists to keep
out. Use the fully-qualified name inline in the docblock (no `use`) or drop the `{@see}` to a
plain prose reference. Purely cosmetic; noted because it is new in this commit.

### Findings from r1 — disposition

| r1 | Status |
|---|---|
| **F-1** sealed REFUND dead-letters (CRITICAL) | **CLOSED** by `containLotWork()`; verified by execution (§1). Residual = **F-1r** (pin has no CI job) |
| **F-2** DEFAULT-lot provenance dropped (CRITICAL) | **CLOSED**; probe P4 now yields DEFAULT 4 / LOT-DATED 8; red-proved (§2) |
| **F-3** refund of a pre-lane sale mints a phantom DEFAULT (IMPORTANT) | **CLOSED** for the projection (`mintDefaultLotForUnattributed: false`); drift 0, no `DEFAULT@today+365`; document channel bit-for-bit unchanged (both call sites omit the flag); red-proved (§3) |
| **F-4** shortfall drift has no detector (IMPORTANT) | **CLOSED** — `inventory:lot-drift-census`, read-only, `--fail-on-drift`, delegated-from by the W2-7 repair command; reproduces the re-run tenant's 5 tuples / **27.0000** exactly (§4) |
| **F-5** manifest ceiling collides with W4-3 (IMPORTANT) | **CLOSED** — W4-3 landed first (`2b471d61c`, `Document 88→89`, ceiling `1185→1186`); the lane re-took the union to **1187** in `ceada91db`. Verified against **CURRENT** dev `274594ebf` (§7) |
| **F-6** partial-refund lot order was unordered | **CLOSED** — `orderBy('pb.expiry_date')` then `orderBy('a.batch_id')` (`PosCoreReceiptProjection.php:2686-2699`) with `test_partial_refund_credits_the_earliest_expiry_lot_the_line_took` (`:614-648`, green on PG) |
| **F-7** docblock advertised composite coverage | **CLOSED** — bullet (h) restated as "moves NOTHING on this path… deliberately no test" (`test:61-66`) |
| **F-8** `find()` per lot in the loop | **CLOSED** — one `whereIn`, `snapshotLotAllocations()` (`PosCoreReceiptProjection.php:2085-2109`) |

## 7. Manifest values (recomputed against CURRENT dev)

`git log -1 dev` = **`274594ebf`** ("sessions: [Session B2] log — B2-6 fix round, inventory r2").
The three dev commits that landed after the lane's `merge dev` (`274594ebf`, `34147b183`,
`7ef6ba8ae`) touch **only** `docs/` — verified with `git show --stat`; **no Feature test class**,
so the union arithmetic taken at `ceada91db` still holds at merge time.

| | dev `274594ebf` | lane `c80f4f36e` | union at merge |
|---|---|---|---|
| `gated_ceiling` | 1186 | **1187** | **1187** ✅ |
| `groups.Fiscal.classes` | 81 | **82** | **82** ✅ |
| `groups.Document.classes` | 89 | 89 (untouched) | **89** ✅ |
| deptrac violations | — | 183 | 183 (r1 parity) |

`tools/feature-lane-manifest-check.php` on the lane: `OK — 1442 Feature classes in 74 groups`,
`70 group(s) / 1187 class(es)` parked, **EXIT=0**.

**`ci.yml` filter union = 155 tokens, all unique** (parsed with a script, not by eye), one
token appended (`PosCoreReceiptProjectionBatchLotTest`) to the single `backend-test-pgsql`
alternation at `.github/workflows/ci.yml:1048`; nothing else in the file changed vs dev. The
manifest checker independently confirms "every `--filter` entry is anchored and uniquely
matched". Valid. (Caveat carried forward from F-1r: for THIS class the pgsql lane is the only
live execution, and the containment test cannot run there.)

## 8. Verdict

> **VERDICT: spec ✅ · quality APPROVED · merge-blocking: NO**
>
> All five r1 findings are genuinely closed, and I proved each by execution rather than by
> reading the handback: the refund arm no longer dead-letters a sealed event, a DEFAULT-lot
> provenance hint is honoured while the heuristic still refuses to guess it, the projection
> mints no phantom `DEFAULT` and leaves a pre-lane refund at drift 0, the document channel is
> untouched at both call sites, and the new read-only census reproduces the re-run tenant's
> 27.0000 of drift exactly while writing nothing. Manifest union resolves to **1187 / Fiscal
> 82 / Document 89** against current dev. Two non-blocking residuals: the F-1 containment pin
> executes in no CI job (F-1r, proven — 16/16 PG tests still pass with containment deleted),
> and three docblock-only cross-module `use` imports (F-2r).

**Manifest values for the merge commit: `gated_ceiling` 1187 · `groups.Fiscal.classes` 82 ·
`groups.Document.classes` 89 (unchanged) · deptrac 183.**

**What to fix before merge:** nothing blocking — but file **F-1r** as a follow-up so the
containment savepoint gets a regression pin that actually runs on the PR→dev gate.
