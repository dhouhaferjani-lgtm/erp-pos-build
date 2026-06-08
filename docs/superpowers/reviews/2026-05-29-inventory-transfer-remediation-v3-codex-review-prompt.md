# Adversarial Plan Review v3 — Inventory Transfer Remediation

You are Codex acting as an adversarial reviewer. v2 of this plan returned BLOCKER with three findings:

1. Lock-order deadlock: `recordCostEvent` held transfer A + product P then tried to lock joined `stock_transfers` rows; `complete()` for transfer C held C and waited for P. Classic cycle.
2. Aggregate `lockForUpdate()->sum()` queries: PG doesn't actually lock rows under FOR UPDATE on aggregates; the v2 row-lock claims were paperware.
3. Negative-allocation skip: A7's `if ($allocated <= 0) { continue; }` silently dropped every reversal event's WAC adjustment, so F3's cancel-time reversal flow wrote an event row but never moved the WAC backwards.

Plus four P1 issues (idempotency catch-and-reload didn't re-validate route/payload; signature drift in pre-existing test call sites) and several P2/P3 items.

The author revised the plan to v3 to close all of those. **Verify the v3 fixes actually close the v2 findings**, and surface any new issues v3 introduced.

**Save your review to this exact path** (do not return inline): `docs/superpowers/reviews/2026-05-29-inventory-transfer-remediation-v3-codex-review.md`.

Use this structure:

```
# Inventory Transfer Remediation Plan v3 — Codex Adversarial Review

**Verdict:** APPROVE-PLAN | APPROVE-WITH-MINOR-EDITS | REQUEST-CHANGES | BLOCKER
**Confidence:** low | medium | high
**Date:** 2026-05-29

## Summary

## Regressions from v2 (NEW issues introduced by the v3 edits)

## v2 findings — verification

## P1 / P2 / P3 surviving from v2 (if any)

## What I verified by reading the v3 plan + the code it references

## What I could not verify
```

Cite `plan-path:line` or `code-path:line` for every claim. Walk every concurrency claim through an explicit two-process scenario.

---

## Required reading

- The v3 plan (your target): `docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md`
- Your v2 review: `docs/superpowers/reviews/2026-05-29-inventory-transfer-remediation-v2-codex-review.md`
- Your v1 review: `docs/superpowers/reviews/2026-05-28-inventory-transfer-remediation-codex-review.md`
- The code the plan modifies — same files as your previous reviews. Specifically pay attention to:
  - `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php` (Task A6 rewrites)
  - `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php` (Tasks A7/A8/A9/B3/F3 affect)
  - `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php` (architecture-test target)

You are in worktree `/Users/houssamr/Projects/syneriva/apps/erp.inventory-transfer`. Branch `feat/inventory-transfer` head is approximately `384f64bde`.

---

## Adversarial attack surface — verify each v2 finding is actually closed

### v2 BLOCKER 1 — lock-order deadlock

v3 fix is in **Task A6** (product-lock invariant; drop aggregate locks) + the new architecture test + the v3 sorting fix in Task A7.

**Attack:**

1. Read the rewritten A6. The product lock is the only lock taken inside `recordCostAdjustment` now. The `stock_levels` sum and the in-transit join sum are plain reads. Walk through:
   - Process B records a cost event on transfer A for product P. B locks transfer A (in `recordCostEvent`), then enters WAC which locks product P. B reads `on_hand` (plain SELECT SUM, no lock). B reads `in_transit` (plain SELECT SUM joined to `stock_transfers WHERE status = InTransit`, no lock). B writes WAC. B releases product P, releases transfer A.
   - Process C completes transfer C for product P. C locks transfer C, iterates lines, locks product P (waits if B holds it). When C gets P, C calls `StockAdjustmentService::receive()` which updates `stock_levels` and writes `stock_movements`. C then flips `stock_transfers.status` to Completed (under transfer lock).
   - Can B and C deadlock? B holds A + P, blocks no one (B doesn't take any further locks). C holds C, waits for P. **No cycle.** ✓ closed.
2. But: what about the case where B and another concurrent cost event D race on the same transfer A? Both lock A — they serialize on A. ✓.
3. What about B and concurrent recordCostEvent E on DIFFERENT transfers but SAME product? B locks transfer A → product P. E locks transfer F → blocks on product P. No cycle. ✓.
4. What about TWO concurrent cost events on DIFFERENT transfers with DIFFERENT but OVERLAPPING product sets (the cross-product deadlock Codex flagged in v1)? The v3 Task A7 sorts lines by product_id before iterating. Verify:
   - B's transfer A has lines [P, Q] (sorted: P, Q). B locks A → P → Q.
   - E's transfer F has lines [Q, P] (sorted: P, Q after the sort). E locks F → P → Q.
   - B holds A+P, wants Q. E holds F, waits for P. B finishes, releases. E proceeds. ✓ no deadlock.
   - **But verify the sort is applied in EVERY flow that locks products per line.** Read tasks: A7 (recordCostEvent — sorted ✓), A8 (initiate's emit-at_initiate path — does it sort?), A9 (complete — does it sort?), F3 (cancel reversal — does it sort?). For each, find the `foreach` over lines and confirm the sort is applied.

### v2 BLOCKER 2 — aggregate FOR UPDATE

v3 dropped `lockForUpdate()` from both sums in Task A6. The product lock is the single serialization point.

**Attack:**

1. Confirm the v3 A6 code has plain `->sum()` calls (no `->lockForUpdate()` before them).
2. The architecture test `InventoryProductLockInvariantTest` enforces that every mutator acquires `Product::query()->lockForUpdate()`. Read the test code. Does it actually parse the method body, walk it for `Product::query` + `lockForUpdate`, and fail otherwise? The simple substring check looks brittle — what if a method calls `Product::query()` for some other purpose (e.g., a read-only validation) but doesn't actually lockForUpdate the product it's about to mutate? The test would pass but the invariant could be violated.
3. The test's `gatedMethodsProvider()` lists 7 methods. Is that exhaustive? Specifically check:
   - `StockReservationService::reserve` and `releaseReservation` (in the existing `StockAdjustmentService::reserve` path) — those mutate `stock_levels.reserved`, not `quantity`. Does the invariant apply? If not, document why.
   - Any future caller — does the test guard against new mutators slipping in without the lock? (The data provider is static; new methods need to be added manually. The test is best-effort, not exhaustive.)

### v2 BLOCKER 3 — negative-allocation skip

v3 fix in Task A7: `if ($allocated <= 0) { continue; }` → `if (round($allocated, 4) === 0.0) { continue; }`.

**Attack:**

1. Walk the math through: original event +100 capitalizes at allocated +100 per line (single-product case). Reversal event -100 capitalizes at allocated -100. WAC delta = -100 / on_hand. WAC moves back. ✓.
2. Distribution edge cases: ProRataValue with mixed positive/negative line weights — can `$weights[$line->id] / $totalWeight` go negative if `$totalWeight` is negative? Negative weights aren't expected for line values (qty*cost), but if `unit_cost_snapshot` could be negative on a line, `$weights` could be negative. Verify the line cost-snapshot can't be negative.
3. The line's `allocated_transfer_cost` becomes negative on reversal — does any downstream consumer (UI, report, frontend type) assume it's positive? Grep `allocated_transfer_cost` across the codebase.

### v2 P1 — Idempotency catch-and-reload paths

v3 fix in Tasks B4 and A7 catch blocks: re-validate transfer_id (route scope) and payload_hash (fingerprint) before returning.

**Attack:**

1. Read the new B4 catch block. It computes `$expectedHash = $this->fingerprintPayload($data)` and compares against `$winner->idempotency_payload_hash`. Does this assume `fingerprintPayload()` is a method on `StockTransferService`? Or is it a static? Walk through whether the catch block has access to the helper.
2. The cost-event A7 catch block does the same with `$this->fingerprintCostEvent($data)`. Same question.
3. What if the seeded race-winner row was written by a different code path that didn't compute the canonical fingerprint? `$winner->idempotency_payload_hash` could be null. The v3 code checks `if ($winner->idempotency_payload_hash !== null && ...)`. So a null-hash winner is accepted without validation. Is that correct, or should it be 409 because we can't verify the payload?

### v2 P1 — Signature drift sweep

v3 fix in Task B3 description: sweep every pre-existing `complete()`/`cancel()` call site to the locked five-/six-argument shape.

**Attack:**

1. Grep the v3 plan for `service()->complete(` and `service()->cancel(`. Every call site should use named-argument form with `tenantId, companyId`. If any survive without those, the sweep missed them.
2. Specifically check the multi-event determinism test (Task A5) — v3 updated lines 522/530/560/573 to the new shape. Confirm.

### v2 P1 — F3 reversal idempotency

v3 fix: `reverses_event_id` column + partial-unique index in Task A1. Double-reversal raises `COST_EVENT_ALREADY_REVERSED` 409.

**Attack:**

1. Read the v3 A1 migration. Column added, partial-unique index added under `pgsql`. Verify SQLite testing-env equivalent (does the test bootstrap handle the missing index gracefully?).
2. Read v3 F3 Step 2. The reversal loop checks `$original->reverses_event_id !== null` to reject reversing a reversal. The partial-unique index catches duplicate reversals. Together: every original event can be reversed at most once.
3. Edge case: what if the user submits multiple `cost_event_reversal_ids` in one cancel request, some valid and some invalid (e.g., one is already reversed)? Does the cancel transaction roll back the whole batch on the first failure? The plan doesn't specify. Spec the expected behavior.
4. What about reversing a cost event on a transfer that's NOT being cancelled — i.e., a standalone reversal? The plan says reversals only emit during cancel. Is that the intended product surface? If the user wants to reverse a quoted cost mid-transit without cancelling the transfer, do they have a path? (Probably "use the public POST cost-event endpoint with a negative amount" — verify the negative-amount path actually works through `recordCostEvent`, not just `recordCostEventInternal`.)

### v2 P2 — fingerprint includes transferNumber

v3 fix in `fingerprintPayload`: includes `'transfer_number' => $data->transferNumber`.

**Attack:** when `transferNumber` is null (server-allocated), it's null in the hash; when supplied, it's part of the identity. Walk through whether the comment claim matches the code.

### v2 P3 — Playwright disambiguation

v3 fix: use `page.locator('[role=dialog]', { hasText: 'Cancel this transfer' })` for the modal.

**Attack:** does the actual modal markup expose `role=dialog`? The plan's earlier inline modal code uses `<div className={tokens.modal.backdrop}>` and `<div className={tokens.modal.container}>` with no `role=dialog`. Verify the modal markup needs to be updated as part of the same task; otherwise the Playwright selector finds nothing.

### v2 P3 — H1 path

v3 fix: `git add docs/superpowers/coordination/2026-05-28-inventory-transfer.md` (worktree-relative). Verify.

---

## New attack surface — issues v3 might have introduced

1. **Architecture-test brittleness** — `InventoryProductLockInvariantTest::extractMethodBody` walks balanced braces. If any test-listed method has nested closures, the brace walker might terminate early at the closure's closing brace. Spec a failure case.
2. **The architecture test's `gatedMethodsProvider` is non-exhaustive** — new mutators added by future PRs slip through unless someone manually adds them to the provider. Is the plan honest about this limitation? Should the test scan the codebase for ALL methods that mutate stock_levels/stock_transfers and require each to lock the product?
3. **F3's `recordCostEventInternal` bypass** — the cancel flow calls `recordCostEventInternal` to bypass the AtInitiate phase guard. But the cancel flow uses phase `at_receipt` (or `in_transit`), not `at_initiate`. So why is the bypass needed? If F3 calls `recordCostEvent` (public), the phase guard accepts in_transit + at_receipt cleanly. Verify the v3 flow is internally consistent.
4. **The B4 test's `fingerprintForTest` helper** — the plan references `$service::fingerprintForTest($data())` but doesn't define this helper anywhere. Either the test reflects into a private method or the plan needs to spec the test-only helper. Flag this gap.
5. **Reverses-event chain** — what if the user reverses a reversal? The migration says `reverses_event_id` references `stock_transfer_cost_events.id`, allowing arbitrary depth. The plan's F3 Step 2 rejects this. Is the rejection enforced? Walk through.
6. **Sort-by-product-id under `Collection::sortBy('product_id')->values()`** — `$transfer->lines` is an Eloquent Collection. `sortBy` with a string key sorts by attribute; `values()` reindexes. Verify the sort order is the SAME across every flow (initiate, complete, cancel, recordCostEvent) — if one flow sorts ascending and another sorts descending, deadlocks come back.

---

## Workflow

1. You are already in the worktree.
2. Read the v3 plan + your v2 review + your v1 review.
3. For each lettered v2 finding, confirm the v3 fix actually closes it.
4. For each new-issues item above, walk through the failure scenario.
5. Save the review at the exact path above.
6. Reply with just the file path and the one-line verdict — no preamble.
