# Adversarial Plan Review v4 — Inventory Transfer Remediation

You are Codex acting as an adversarial reviewer. v3 of this plan returned **BLOCKER** from two independent reviewers
(Opus + Codex), converging on one root cause: the "product-lock invariant" was false in the merged code (public
`StockMovementController` receive/adjust, the transfer `cancel()` path, and the leaf `StockAdjustmentService::issue`/
`receive` mutate `stock_levels.quantity` without locking `Product`), and the architecture test meant to enforce it
could not pass because it gated lock-free leaf methods.

The author rewrote the plan to **v4** with an owner-locked design change grounded in a deep-research pass:

1. **Per-product advisory lock (Decision D2).** A new `ProductCostLock` helper acquires a Postgres
   `pg_advisory_xact_lock(hashtext("wac:{tenant}:{company}:{product}"))` for each product, in ascending `product_id`
   order, wrapped in a deadlock-retry. It is acquired by the WAC writer (`recordCostAdjustment`), every
   `StockAdjustmentService` quantity mutator (`receive`/`issue`/`adjust`), and the cost confirm/reverse paths.
2. **Two-step pending → confirmed cost lifecycle (Decision D1).** Cost events are created `pending` (no WAC effect) and
   capitalize forward into WAC only on explicit `confirmCostEvent`. Forward-only, no retroactive replay (Decision D3).
3. **Treasury decoupled seam (Decision D4).** `StockTransferCostConfirmed` event + forward-pointer; Treasury not built here.
4. Fixes for every v3 finding: full model `$fillable`, DTO `reversesEventId`, sorted product locks in ALL flows,
   null-hash → 409, `fingerprintForTest` seam, `role="dialog"` cancel modal.

**Your job:** verify v4 actually closes the v3 BLOCKERs and the design is internally sound, AND surface any NEW issues
v4 introduced. Be adversarial about the advisory lock specifically.

**Save your review to this exact path** (do not return inline):
`docs/superpowers/reviews/2026-05-29-inventory-transfer-remediation-v4-codex-review.md`

Structure: `# Inventory Transfer Remediation Plan v4 — Codex Adversarial Review`, then **Verdict:**
(APPROVE-PLAN | APPROVE-WITH-MINOR-EDITS | REQUEST-CHANGES | BLOCKER), **Confidence:**, **Date:** 2026-05-29, then:
`## Summary`, `## v3 BLOCKERs — are they actually closed?`, `## New issues introduced by v4`, `## Advisory-lock correctness`,
`## Pending/confirmed lifecycle + accounting`, `## P1/P2/P3`, `## What I verified`, `## What I could not verify`.

Cite `plan-path:line` or `code-path:line` for every claim. Walk every concurrency claim through an explicit two-process
scenario. This is a PLAN review — not-yet-created files are expected; do NOT flag "file does not exist yet" as a defect
(that was a v3-digest error). Judge the plan's design and whether its code snippets are correct and complete enough to execute.

---

## Required reading
- v4 plan (target): `docs/superpowers/plans/2026-05-29-inventory-transfer-remediation-v4.md`
- v4 inherits unchanged batches from v3: `docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md`
- The two v3 reviews: `.../2026-05-29-inventory-transfer-remediation-v3-opus-review.md`, `.../v3-codex-review.md`
- The research: `docs/superpowers/research/2026-05-29-wac-concurrency-erp-best-practices.md`
- Code v4 modifies: `StockTransferService.php`, `WeightedAverageCostService.php`, `StockAdjustmentService.php`,
  `StockMovementController.php`.

You are in worktree `/Users/houssamr/Projects/syneriva/apps/erp.inventory-transfer`, branch `feat/inventory-transfer`.

---

## Adversarial attack surface — verify each

### 1. Advisory-lock correctness (the heart of v4)
- **Does every divisor writer take the SAME lock key?** The WAC writer keys on `(tenantId, companyId, product->id)`;
  `StockAdjustmentService` receive/issue/adjust must key on the SAME `(tenant, company, product)`. v4's Task A6b note
  says receive/issue/adjust currently resolve company from location — verify the proposed `resolveTenantCompany` yields
  the IDENTICAL tenant+company the WAC writer uses, or the two locks miss each other and the race is NOT closed.
- **Is `pg_advisory_xact_lock` acquired INSIDE the transaction in every path?** A xact-scoped lock taken outside a txn
  releases immediately. Confirm A6/A6b acquire it within the open `DB::transaction`.
- **Lock ordering across the WHOLE operation.** `confirmCostEvent` locks the cost-event row + transfer row
  (`lockForUpdate`) and then, per line, calls `recordCostAdjustment` which takes the product advisory lock. Walk: can a
  transfer-row lock + advisory-lock combination deadlock against `complete()`/`cancel()` which also take transfer-row
  locks then product locks? Is the row-lock-then-advisory-lock order consistent everywhere?
- **`hashtext` collisions.** v4 claims over-locking is safe. Verify: a collision serializes two unrelated products —
  is that truly only a perf issue, never a correctness issue? Any path where the lock key for a READ differs from the
  key for the WRITE of the same logical product?
- **Does the advisory lock actually serialize the multi-row read?** Two processes both take product P's advisory lock →
  serialized. But a writer that does NOT take the lock (did v4 miss any `stock_levels.quantity` writer? e.g.
  `recordPurchase`, `recordSale`, `recordReturn` in WAC service; reservation paths; any direct `StockLevel::...->update`
  outside `StockAdjustmentService`) reopens the hole. Grep for every `stock_levels` quantity writer and confirm each is
  covered or argue why it isn't a divisor writer.

### 2. v3 BLOCKERs closed?
- Product-lock invariant hole → is it really closed by advisory locks, given the grep above?
- `cancel()` locks → Task F3 Step 1 + Batch C delta say cancel's loop is sorted + lock-acquired. Verify the plan gives
  concrete code, not just prose (the v3 sin was prose-only).
- Architecture test → A6c gates `costLock->acquire` usage. Is gating "the helper is called" sufficient, or can a method
  call `costLock->acquire([$wrongProductId], ...)` and pass the test while mutating a different product? Spec the gap.
- `$fillable`/DTO omission → A3/A4 include them. Confirm every column the service `create([...])`s is fillable.
- Sort gap → A8/A9/C2/F3 all add `sortBy('product_id')`. Confirm all four flows.

### 3. Pending/confirmed lifecycle + accounting
- `initiate()` seeds a PENDING at_initiate event (A8). The pre-existing single-event WAC test expected capitalization at
  complete. v4 says complete no longer capitalizes (A9). Does v4 update/del that test? Is there a coverage gap where the
  old behavior silently changes?
- Confirm idempotency: `confirmCostEvent` returns the event if already confirmed (no-op). Walk two concurrent confirms
  of the same pending event: both lockForUpdate the event row → serialize → second sees Confirmed → no double-capitalize. Verify.
- Reverse: `reverseCostEvent` creates a negative event then confirms it, and marks original `reversed`. The
  `stce_reverses_unique` partial index prevents double-reverse. Walk a concurrent double-reverse two-process scenario.
- Forward-only correctness: if units were sold between confirm-time and now, the cost spreads over current owned qty.
  Is that consistent with the test assertions (which assume no intervening sale)? Any divide-by-changed-denominator
  surprise in the reversal (reverse uses current owned, not the owned-at-original-confirm)? Flag if reversal can fail to
  exactly restore WAC because the denominator changed between confirm and reverse — is that acceptable per Decision D3?

### 4. New v4 issues
- `withDeadlockRetry` re-runs the whole `DB::transaction` closure on `DeadlockException`. Does Laravel surface PG
  deadlock (SQLSTATE 40P01) as `Illuminate\Database\DeadlockException`? Verify the exception class is correct, else the
  retry never fires.
- The advisory lock is a no-op on SQLite (test runner). So the entire unit-test suite proves NOTHING about
  serialization; only the pg-gated two-process test does. Is the plan honest that SQLite tests don't cover the race?
- `confirmed_cost_total` vs `transfer_cost`: two denormalized totals now exist (pending+confirmed in `transfer_cost`?
  only confirmed in accessor?). Is the source-of-truth rule coherent? Could the list view sort by a stale `transfer_cost`?
- Treasury seam: `StockTransferCostConfirmed` fires afterCommit. If the Treasury listener fails, the cost is already
  capitalized but no pending payment exists — is the month-end audit query the safety net, or is there a lost-update?

---

## Workflow
1. You are in the worktree. Read v4 + v3 + the two v3 reviews + the research.
2. For each v3 BLOCKER, confirm v4 closes it (with code citations).
3. Grep every `stock_levels.quantity` writer and verify advisory-lock coverage.
4. Walk each concurrency claim through a two-process scenario.
5. Save the review to the exact path above.
6. Reply with only the file path + one-line verdict + confidence.
