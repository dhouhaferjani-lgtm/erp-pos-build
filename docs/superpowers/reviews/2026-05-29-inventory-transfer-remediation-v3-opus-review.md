# Inventory Transfer Remediation Plan v3 — Opus Adversarial Review

**Verdict:** REQUEST-CHANGES (one BLOCKER-class concurrency hole + one self-contradicting enforcement test)
**Confidence:** high
**Date:** 2026-05-29
**Reviewer:** Claude Opus 4.8 (1M), plan + code read in worktree `apps/erp.inventory-transfer` at `384f64bde`

## Summary

v3 correctly closes the three v2 BLOCKERs *as stated* — the aggregate `lockForUpdate()->sum()`
paperware is dropped (A6), the negative-allocation skip is fixed (A7), and the lock-order
deadlock is addressed by replacing the "lock every in-transit parent row" approach with a
single product-lock invariant plus product-id sort ordering. The idempotency catch-and-reload
re-validation (B4/A7) and the `reverses_event_id` partial-unique index (A1/F3) are sound.

But the v3 fix rests on one load-bearing claim — *"every operation that mutates
`stock_levels.quantity` for product P or flips a transfer's status to/from InTransit MUST first
hold `Product::lockForUpdate()` on P, and this is already true for `initiate()`/`complete()`/`cancel()`"*
(plan:636, plan:638). **That claim is factually wrong for `cancel()` in the merged code**, which
re-opens the v1 denominator race through the cancel path. And the mechanism the plan introduces
to *enforce* the invariant — the `InventoryProductLockInvariantTest` architecture test (A6 Step 2)
— **cannot pass against the code it guards**, because it asserts the wrong layer. Both are
plan-text/spec fixes (no app-code authoring needed to correct the plan), so this is
REQUEST-CHANGES → revise to v4, not a hand-back.

I verified every claim below by reading the actual service code, not just the plan prose.

---

## BLOCKER — `cancel()` does not hold the product lock; v1 denominator race re-opens through cancel

**Plan claim (false):** plan:638 — *"This invariant is already true for the existing initiate()
/ complete() / cancel() paths because each of them locks the product before calling
StockAdjustmentService::issue/receive."*

**Code reality:** `StockTransferService::cancel()` returns in-flight stock to source and flips
status `InTransit → Cancelled` with **no `Product::lockForUpdate()` anywhere in the method**:

- `complete()` locks the product per line before `receive()` — `StockTransferService.php:168-172`. ✓
- `moveSourceToInTransit()` (the `initiate()` path) locks the product per line before `issue()` — `StockTransferService.php:304-308`. ✓
- `cancel()` iterates `$transfer->lines` and calls `receive()` directly at `StockTransferService.php:242-250` — **no product query, no product lock** — then flips `status = Cancelled` at `:263`. ✗

So `cancel()` both (a) mutates `stock_levels.quantity` for product P (the return-to-source
`receive()` at `:243`) and (b) flips a transfer's status out of `InTransit` (`:263`) — the two
exact operations the invariant says must hold P's lock — while holding only the **transfer** lock
(`lockTransfer()` at `:232`, which locks the `stock_transfers` row, not the product).

**Two-process scenario (denominator tear, identical in spirit to v1 BLOCKER 1):**

- Product P: on_hand = 100. Transfer C (10 units of P) is `InTransit`, so in_transit = 10.
- **Process B** — `recordCostEvent` on a *different* transfer A (also product P). B locks transfer A
  (`lockTransferUnscoped`, A7 plan:1056-1059), enters WAC, locks **product P** (A6 plan:664-668),
  then reads `on_hand` (plain SUM, A6 plan:674-678) and `in_transit` (plain SUM joined to
  `stock_transfers WHERE status = InTransit`, A6 plan:686-692). The whole correctness argument
  (plan:683-692) is *"these plain reads are consistent because every writer that would change them
  for P holds the product lock I now own."*
- **Process D** — `cancel()` transfer C. D locks transfer C (a different `stock_transfers` row, so
  **no contention with B's transfer-A lock**), calls `receive()` → increments source on_hand by 10
  (`StockTransferService.php:243`), and flips C to `Cancelled` (`:263`). **D never requests
  product P's lock.**

Because D never blocks on P, B's product lock does not serialize D. B can read `on_hand = 100`
(before D's `receive` commits) but `in_transit = 0` for C (after D's status flip is visible), or
any other torn combination. B computes a denominator of 100 when the true owned quantity is
110 (or vice-versa) and capitalizes the cost event against the wrong base → **corrupted WAC**.
This is precisely the financial-correctness race v3 was supposed to eliminate; it survives
through the one mutator the plan forgot to bring under the lock.

**Why the plan's own machinery doesn't catch it for the executor:** the architecture test (A6
Step 2) *does* list `'public function cancel'` in `gatedMethodsProvider()` (plan:751), so the test
*would* fail on `cancel()` at baseline. But A6 Step 3 asserts *"Expected: all dataProvider rows
pass"* (plan:813) and frames any failure as a *pre-existing* violation to *"fix the offending
callsite"* (plan:814) — yet **gives no concrete code** to add the lock + sort to `cancel()`'s
return-to-source loop. That violates the plan's own no-placeholder contract (CLAUDE.md rule 1;
plan:2744 "Placeholders: none"). C2 Step 1 (plan:2143) only swaps the relabel for the direct
`receive()` call; F1 Step 2 (plan:2407) only changes the movement type; F3 (plan:2420) adds the
reversal loop. **No task step adds `Product::query()->lockForUpdate()` to `cancel()`'s
stock-return loop.** An executor following the steps literally ships `cancel()` still unlocked,
the architecture test goes red, and they're left to improvise the most safety-critical edit in
the plan.

**Required v4 fix:**
1. Correct the false claim at plan:638 — `cancel()` does **not** currently lock the product.
2. Add an explicit task step (in C2 or a new Batch-A task) that rewrites `cancel()`'s
   return-to-source loop to: sort lines by `product_id` ascending, then per line
   `Product::query()->where(tenant)->where(company)->lockForUpdate()->findOrFail($line->product_id)`
   **before** the `receive()` call, mirroring `complete()` at `:168-172`. Show the literal code.
3. Re-fix A6 Step 3's expectation (see next finding).

---

## BLOCKER-adjacent — the `InventoryProductLockInvariantTest` asserts the wrong layer and cannot pass at baseline

The architecture test (A6 Step 2, plan:717-805) is the *enforcement mechanism* for the entire v3
concurrency strategy. Its `gatedMethodsProvider()` (plan:743-754) lists, among others:

- `StockAdjustmentService::issue`
- `StockAdjustmentService::receive`
- `StockAdjustmentService::adjust`

and asserts each method body contains both `Product::query` **and** `lockForUpdate` (plan:765-776).

**Code reality:**
- `StockAdjustmentService::receive()` — locks the **StockLevel** via `getOrCreateStockLevel`,
  never the Product (`StockAdjustmentService.php:40-107`; no `Product::query` in the body). → test **FAILS**.
- `StockAdjustmentService::issue()` — locks the **StockLevel** via `lockStockLevel`, never the
  Product (`StockAdjustmentService.php:117-196`). → test **FAILS**.
- `StockAdjustmentService::adjust()` — *does* self-lock the product (`StockAdjustmentService.php:499`,
  `:529`). → test passes.

So A6 Step 3's *"Expected: all dataProvider rows pass"* (plan:813) is false on at least two rows
(`issue`, `receive`) at baseline — plus `recordCostEvent`, which the provider lists (plan:752) but
which **does not exist until A7**, so `extractMethodBody`'s `assertNotFalse($start, ...)`
(plan:783) throws when A6 Step 3 runs *before* A7. Three rows are wrong before a single line of
the real fix is written.

The deeper problem is conceptual, not a typo: the actual invariant is **caller-side** — the
*orchestrator* (`StockTransferService`) holds the product lock and then calls the leaf mutators
`issue`/`receive`, which are deliberately product-lock-free so they can be reused by sales,
returns, purchasing, etc. A lexical substring scan of a *leaf* method body fundamentally cannot
express "the caller holds the lock." Two failure modes follow:

1. **False negative (the realistic one):** an executor, seeing `issue`/`receive` fail, "fixes"
   them by adding `Product::lockForUpdate()` *inside* `issue`/`receive`. That is an unscoped,
   cross-app behavior change — every sales/return/purchase path now double-locks (caller already
   holds it in some flows; leaf re-acquires) — and risks new deadlocks and re-entrancy surprises
   far outside the transfer feature. Violates CLAUDE.md rule 4 (no scope creep).
2. **False positive:** a future mutator can call `Product::query()->lockForUpdate()` on the *wrong*
   product (e.g. a read-only validation lookup) and still mutate a *different* product's stock —
   the substring check passes while the invariant is violated. The v3 prompt itself flags this
   (prompt:82); it is real.

**Required v4 fix:** decide what the test actually guards and make it honest. Either
(a) gate only the **orchestrator** methods that own the lock (the `StockTransferService` entry
points + `adjust`) and explicitly document that `issue`/`receive` are lock-free leaves invoked
under a caller-held lock — and remove them from the provider; or (b) replace the substring scan
with a runtime/integration assertion (e.g. a test that runs the two-process cancel-vs-cost-event
scenario above against Postgres and asserts no torn denominator). Option (a) is the minimum to
unblock; (b) is what would actually catch the `cancel()` hole. Also fix the `recordCostEvent`
sequencing so the provider only references methods that exist when the test first runs.

---

## Lesser findings (P1/P2)

### P1 — the product-id sort (cross-product deadlock fix) is applied in only one of the four flows it claims to cover

A7 prose (plan:878) states the sort must be applied in *"`initiate()` (via `moveSourceToInTransit`),
`complete()`, `cancel()` (the return-to-source path), and the new `recordCostEvent()`."* But the
concrete code is shown **only** for `recordCostEvent` (`$transfer->lines->sortBy('product_id')->values()`,
plan:993). The merged code iterates lines in natural order in `complete()` (`:167`) and
`moveSourceToInTransit()` (`:303`), and no task step (A9, C2, B3, F-series) adds the sort to them.
A transfer A with lines `[P,Q]` completing while transfer F with lines `[Q,P]` records a cost event
can still deadlock on the product locks in opposite order — the exact v1 cross-product deadlock the
v3 sort was meant to kill. **v4 must show the explicit `sortBy('product_id')->values()` edit in the
`complete()`, `moveSourceToInTransit()`, and `cancel()` task code, not just in prose.**

### P2 — null `idempotency_payload_hash` is silently accepted in both catch-and-reload paths

B4 (plan:1809) and A7 (plan:974) both guard with `if ($winner->idempotency_payload_hash !== null
&& $winner->...!== $expectedHash)`. A race winner written by any path that didn't persist the
fingerprint (or a legacy row) has a null hash and is returned **without** payload validation — the
same trust the plan elsewhere refuses to extend. Low real-world likelihood given B5 always persists
the hash on `create()`, but the plan should state the decision explicitly: either treat null-hash
collisions as 409 (can't verify → reject) or document why null is trusted. Matches the v3 prompt's
attack at prompt:105.

### P2 — F3 batch-reversal failure semantics undefined

`cancel(costEventReversalIds: [...])` (F3, plan:2420) can receive several ids; if one is already
reversed, the partial-unique index throws mid-loop (plan:2469). Because the whole thing runs in the
`cancel()` `DB::transaction`, the first failure rolls back the entire cancel — including the
legitimate reversals and the status flip. The plan never states this. Spec it: all-or-nothing
(current de-facto behavior — make it explicit and 409 the whole request) vs. best-effort. Matches
v3 prompt attack at prompt:124.

### P2 — standalone mid-transit reversal path unverified

The design note (I0, plan:2594) says negative reversal events are "recorded by the cancel flow,"
but a user may want to reverse a quoted-but-unpaid fee mid-transit **without** cancelling.
`RecordCostEventRequest` validates `amount => gt:0` (plan:1178), so the public endpoint **cannot**
submit a negative amount — the only way to emit a reversal is via `cancel()`/`recordCostEventInternal`.
That may be the intended product surface, but the plan should say so, because A4's
`RecordCostEventData` constructor explicitly permits negative amounts (plan:454-461) and the design
note implies signed amounts are a general capability. Either relax the request rule and add a path,
or document that mid-transit reversal is cancel-only by design.

### P3 — Playwright modal selector vs. actual markup

J1 uses `page.locator('[role=dialog]', { hasText: 'Cancel this transfer' })` (plan:2677), but F3's
inline cancel modal is described as plain `<div>` (no `role=dialog` is specified anywhere in F3,
plan:2415-2425). Unless F3 Step is told to add `role="dialog"` to the modal container, the selector
matches nothing. Add the aria role to the F3 modal task, or change the selector to a `data-testid`.

---

## v2 findings — verification (all three BLOCKERs closed *as written*, but see caveats above)

- **v2 BLOCKER 1 (deadlock):** Closed *as designed* — A6 drops the in-transit parent-row locks; the
  product lock is the sole serialization point (plan:657-668). Caveat: the design is only as good as
  the invariant's coverage, and `cancel()` is outside it (see BLOCKER above), so the *guarantee* is
  not actually established.
- **v2 BLOCKER 2 (aggregate FOR UPDATE):** Closed — A6's sums are plain `->sum()` with no
  `->lockForUpdate()` (plan:674-692), vs. the merged code's `->lockForUpdate()->sum('quantity')` at
  `WeightedAverageCostService.php:484-485`. Correct.
- **v2 BLOCKER 3 (negative-allocation skip):** Closed — `if ($allocated <= 0) continue;` →
  `if (round($allocated, 4) === 0.0) continue;` (plan:1009), so reversals now flow through to
  `recordCostAdjustment`. The merged code's bug is at `StockTransferService.php:400`. Correct.
  Sub-check: line-value weights are `qty * unit_cost_snapshot` (`:429`); `unit_cost_snapshot` is a
  cost snapshot and won't be negative, so `$totalWeight` stays positive and the share sign follows
  the event amount. Fine.
- **v2 P1 (idempotency catch re-validation):** Closed except the null-hash gap above.
- **v2 P1 (signature drift):** B3 (plan:1500) now explicitly sweeps test call sites with an `rg`
  audit. Adequate as a plan instruction.
- **v2 P1 (F3 reversal idempotency):** Closed via `reverses_event_id` + partial-unique index
  (plan:166-190, plan:2469). Batch-failure semantics still undefined (P2 above).

## What I could not verify

- I did not run the test suite or a Postgres two-connection deadlock harness; the cancel-path
  denominator race is from lock-order analysis against the merged `StockTransferService.php` and the
  v3 A6/A7 plan text. It is, however, grounded in directly-read code (`cancel()` has no
  `Product::query`), not inference.
- SQLite test-env behavior of the `pgsql`-only CHECK constraints and partial-unique index (A1
  plan:179-191) — the migration guards them behind `DB::getDriverName() === 'pgsql'`, so SQLite
  skips them; the F3 double-reversal test therefore only exercises the index under Postgres CI, not
  the default SQLite runner. Worth a note in the plan but not a blocker.

---

## Bottom line

v3 is a genuine improvement and the three v2 BLOCKERs are addressed in design. It is **not yet
implementation-ready** because the new safety guarantee has a hole exactly where the plan asserts it
doesn't (`cancel()`), and the test meant to prevent such holes can't pass against the real code.
Both are correctable in plan text. Recommend v4 with: (1) explicit product-lock + sort code in
`cancel()`, `complete()`, and `moveSourceToInTransit()`; (2) a corrected/honest architecture test
that gates orchestrators (or runs the real race) and stops gating lock-free leaf mutators; (3) the
four P2 clarifications. Then re-review.
