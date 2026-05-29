# Adversarial Plan Review v2 — Inventory Transfer Remediation

You are Codex acting as an adversarial reviewer. Your previous review of v1 of this plan returned **BLOCKER** with two findings:

1. Cross-transfer denominator race in `recordCostEvent` — `in_transit` was read in `StockTransferService` before the WAC service took the product lock, so concurrent state transitions on other transfers shifted `on_hand` between the two reads.
2. Tenant-folder migrations under `database/migrations/tenant/` were not loaded by the PHPUnit `RefreshDatabase` bootstrap, so Task A1's verification was a false pass.

The author revised the plan to v2 to close both BLOCKERs and your P1/P2 findings. **Your job is to confirm the v2 fixes actually close the findings**, and to surface any new issues the v2 changes introduced.

**Save your review to this exact path** (do not return inline): `docs/superpowers/reviews/2026-05-29-inventory-transfer-remediation-v2-codex-review.md`.

Use this structure:

```
# Inventory Transfer Remediation Plan v2 — Codex Adversarial Review

**Verdict:** APPROVE-PLAN | APPROVE-WITH-MINOR-EDITS | REQUEST-CHANGES | BLOCKER
**Confidence:** low | medium | high
**Date:** 2026-05-29

## Summary

## Regressions from v1 (NEW issues introduced by the v2 edits)

## v1 findings — verification

## P1 / P2 / P3 surviving from v1 (if any)

## What I verified by reading the v2 plan + the code it references

## What I could not verify
```

Cite `plan-path:line` or `code-path:line` for every claim.

---

## Required reading

- The revised plan: `docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md` (v2 — your previous review was of v1; you are reading the same path now with `BLOCKER` findings addressed).
- Your previous review: `docs/superpowers/reviews/2026-05-28-inventory-transfer-remediation-codex-review.md` — the v1 findings you raised.
- The upstream T6 work that landed on `dev` on 2026-05-29 and is now in this branch via merge:
  - `apps/api/tests/Feature/Tenant/TenantMigrationsLoadedInTestingTest.php` — proves T6 wired tenant migrations into the test bootstrap, closing v1's BLOCKER 2.
  - `apps/api/app/Providers/AppServiceProvider.php` — the actual wiring; verify it does what the test claims.
- The precision-fix PR #151 that merged to `dev` on 2026-05-29 and is now in this branch via merge:
  - `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php` — `SCALE` is now `4`.
  - `apps/api/app/Modules/Inventory/Domain/StockLevel.php` and `StockMovement.php` — casts are `decimal:4`.
  - `apps/api/tests/Unit/Inventory/InventoryQuantityPrecisionGuardTest.php` — the regression guard.
  - The migration that widened the columns to `decimal(15,4)`.
- The original code the plan modifies: same files as your previous review.

You are in worktree `/Users/houssamr/Projects/syneriva/apps/erp.inventory-transfer`. Branch `feat/inventory-transfer` head is approximately `59ac646ec`.

---

## Adversarial attack surface — verify each v1 finding is actually closed

### v1 BLOCKER 1 — denominator race

The v2 fix is in **Task A6** (rewrite of `WeightedAverageCostService::recordCostAdjustment`). The method now:

- Takes `tenantId` and `companyId` as required parameters.
- Inside a single `DB::transaction` callback: locks the product row, then sums `stock_levels` with `lockForUpdate()`, then sums `stock_transfer_lines` joined to `stock_transfers WHERE status = InTransit` also with `lockForUpdate()`.
- Computes `totalOwnedQty = onHandQty + inTransitQty` AFTER both reads have committed under the lock.
- `StockTransferService::recordCostEvent` no longer reads in-transit; it just passes `tenantId, companyId` to the WAC service. The previous `inTransitQuantityForProduct` helper is removed.

**Attack:**

1. Walk through the same two-process scenario you wrote in v1's BLOCKER 1 — does the new lock placement actually serialize? Specifically: between Process B's `recordCostAdjustment` and Process C's transfer-state transition, do they conflict on the same row(s)?
   - Process C's `complete()` locks the source `stock_levels` row (in `moveSourceToInTransit` from `initiate`, but that's already done — `complete` itself locks the *destination* stock row via `StockAdjustmentService::receive()`). It also calls `recordCostEvent` later in the flow (which inside will take the product lock).
   - Process B's `recordCostEvent` takes the product lock first.
   - These should serialize on the product lock. Verify.
2. What if the in-transit query's `lockForUpdate()` on a JOINed table only locks the `stock_transfer_lines` rows (the leaf table), not the `stock_transfers` rows (the WHERE-filtered parent)? In PostgreSQL, `FOR UPDATE` on a joined query locks ONLY the explicitly named tables (Postgres 9.3+ supports `FOR UPDATE OF table`, but the bare `lockForUpdate()` in Eloquent locks all tables in the FROM). Walk through whether the lock actually prevents a concurrent `complete()` from flipping the `stock_transfers.status` mid-read.
3. What about `complete()` ordering — Task A9 says `complete()` no longer calls `capitalizeTransferCost()`. So the per-event capitalization happens in `recordCostEvent`, which `complete()` does NOT call. Cost capitalization is fully decoupled from state transitions. Verify by reading the v2 `complete()` body.
4. What if a `recordCostEvent` happens DURING a `complete()` of a different transfer for the same product? Both want the product lock; one blocks the other. The serializer wins; the loser sees post-transition state. ✓ — but verify the WAC math under this ordering is still deterministic. (Cost event amount is unchanged; denominator after the state transition is unchanged because `complete` doesn't change `on_hand + in_transit` total; so the capitalization delta is unchanged.)

### v1 BLOCKER 2 — tenant migration bootstrap

The v2 claim: the T6 work that landed on `dev` (PR cascade #141–#146 + #148) already registered the tenant migration path under the testing env, so `RefreshDatabase` picks up `database/migrations/tenant/` automatically. The test `TenantMigrationsLoadedInTestingTest` proves it.

**Attack:**

1. Read `AppServiceProvider.php` and find the actual wiring. Confirm it activates ONLY under `testing` (or whatever env name PHPUnit uses) — not under any other env that would change production behavior.
2. Confirm `TenantMigrationsLoadedInTestingTest` actually proves the claim: it asserts `channels` exists after `RefreshDatabase`, which is created by a `tenant/` migration. ✓ but verify the wiring isn't load-bearing on the exact migration file name pattern.
3. The v2 plan creates the `stock_transfer_cost_events` migration at `database/migrations/tenant/2026_05_28_140000_…`. After the T6 wiring, does `RefreshDatabase` pick this file up? (It should, because the wiring globs `tenant/*.php`.) Verify by checking that the same wiring picks up the precision-fix migration that landed via PR #151.

### v1 P1-1 — idempotency lookups not route-scoped

The v2 fix is in **Tasks B3 (complete idempotency) and A7 (cost-event idempotency)**. Both now: look up the existing record by `(tenant_id, company_id, key)`, and if the existing record's `transfer_id` (or `id` for the complete case) doesn't match the route target, throw `IdempotencyKeyConflictException`. The same exception is reused from Task B5.

**Attack:**

1. Read the rewritten idempotency branches in both tasks. Does the comparison check what it claims to check?
   - In `complete`: `if ($existing->id !== $transfer->id)`. The `$transfer` here is the one locked by `lockTransfer($transferId, $tenantId, $companyId)`. So the comparison is "key matched a transfer that isn't the one I'm locking". ✓ correct.
   - In `recordCostEvent`: `if ($existing->transfer_id !== $transfer->id)`. Same shape. ✓ correct.
2. What if the key isn't found at all? Then the conditional skips and the normal path runs. ✓ correct.
3. What if two route-scoped retries happen in parallel? They both miss the read-before-insert and both attempt INSERT. The unique constraint catches the second; the catch-and-reload pattern from Batch B4 returns the winner. ✓ correct.

### v1 P1-2 — signature drift between Batch B and D

The v2 fix is in **Task B3 Step 2**: `complete` and `cancel` are introduced with the FINAL locked shape (`transferId, userId, tenantId, companyId, ?idempotencyKey`). Task D1 is rewritten to just add a regression test using the same signature.

**Attack:**

1. Read the new B3 signature snippet vs the v1 version. Is the final shape consistent across `complete`, `cancel`, and `lockTransfer`? Specifically check parameter order, default vs required, and that `cancel`'s extra `?reason` parameter sits in a sane position.
2. Does any other task in the plan still describe these signatures with the old shape? Grep for `complete(string $transferId, string $userId)` (the v1 shape) — if any survive, they're now stale.
3. Does the controller call site in Task B3 (or any task) pass the parameters in the new order? The v2 plan should show the controller change once; check it lines up.

### v1 P1-3 — cancel-cost-event policy gap

The v2 fix is **Task F3 (cancel-time cost-event confirmation UI)** — owner-locked to Option B+ with Treasury hook.

**Attack:**

1. Read the new F3 task. The cancel modal lists each cost event with a per-event Reverse/Keep choice. Reverse emits a compensating negative event.
2. Walk through the math: original at_initiate event = +100 (capitalizes to WAC). User reverses on cancel. Service emits a compensating event with `amount = -100`. WAC capitalization runs again with the negative amount → WAC returns to pre-initiate value. Verify with a concrete number.
3. The negative event's phase: F3 says "at_receipt if the transfer is Completed when cancelled" but cancel-from-Completed is forbidden by the lifecycle. So reverses always happen when the transfer is still in_transit (or draft, which has no cost events because the at_initiate emission happens during the initiate transaction). Walk through whether the in-transit reverse fires the right denominator.
4. F3 also says the new event's `idempotency_payload_hash` is the canonical hash of the reverse payload. What if the user clicks reverse twice (double-submit)? Should the second submit be a no-op (because the original is already reversed)? F3 mentions "validate that it has not already been reversed (no prior negative event with the same `idempotency_payload_hash` or a `reverses_event_id` link if added later)". Is this enforceable today, or does it need a `reverses_event_id` column on `stock_transfer_cost_events`?

### v1 P1-4 — TransferReversed enum case callsite audit

The v2 fix is **Task F0 (pre-flight grep)**. Read its steps; they describe a grep + write-up in the design note. Verify the pattern covers the callsite categories you mentioned in v1 (reports filtering "transfers in" vs "reversals", physical-stock summaries treating both identically, audit reports needing distinction).

### v1 P2-1 — Distribution-mode coherence

v2 says: document the cost-event ledger as source of truth; `line.allocated_transfer_cost` is a convenience projection. **Verify the plan does this explicitly** — find the documentation note or report that v2 forgot.

### v1 P2-3 — Fingerprint canonicalization

v2 rewrites `fingerprintPayload` to sort lines by `product_id`, include `transfer_type`, exclude `initiatedByUserId` and `idempotencyKey`. Read the new snippet. **Attack:** are there fields on `InitiateTransferData` not mentioned? Grep the DTO definition (Task A4 or its predecessor in the original `initiate` flow). If a field exists that materially changes the transfer outcome and isn't in the hash, flag it.

### v1 P2-4 — B4 parallel-create test

The v1 plan said the "parallel" test ran serially. Did v2 fix this? Read Task B4 and check.

### v1 P2-5 — Sub-cent validator claim with no task

v2 says: PR #151 fixed this upstream; the validator workaround is no longer needed. Verify:

1. Read PR #151's actual changes (`StockAdjustmentService.php`, the migration). Does the storage layer truly accept 4 decimals end-to-end?
2. The v2 plan removed the "validator claim" from the not-covered section. Confirm.

### v1 P2-6 — Docs scope inconsistency

v2 removed the CLAUDE.md / architecture / database doc references from the file map and consolidated Batch H to a single forward-pointer task. Verify no stale references remain. Grep `CLAUDE.md` across the plan; the only mention should be in the forward-pointer note.

### v1 P2-7 — Cost-event idempotency without payload comparison

v2 added `idempotency_payload_hash` to the `stock_transfer_cost_events` migration (Task A1) AND added the `fingerprintCostEvent()` helper in Task A7 AND added the hash comparison in the route-scoped idempotency branch. Verify all three landed.

### v1 P3 — placeholders, commits, paths

v2 added a "Plan-document conventions" section explaining `// ...` shorthand and worktree-relative path semantics. v2 also rewrote the Playwright cancel-test placeholder with a real test. v2 fixed the `apps/erp/docs/...` paths to `docs/...`. Verify each by grepping the plan.

---

## New attack surface — issues v2 might have introduced

1. **`lockForUpdate()` on a joined query** — Postgres semantics. Read Task A6's join query carefully. Does Eloquent's `lockForUpdate()` on a `->join(...)` chain emit `FOR UPDATE` on both tables, only the leaf, or something else? If it's not airtight, the WAC service still has a race window even after v2's "fix".
2. **The `recordCostEventInternal` extraction** — the v2 plan still describes a public `recordCostEvent` that calls a private `recordCostEventInternal`, and `initiate` calls `recordCostEventInternal` directly to bypass the AtInitiate phase guard. Is the extraction shown clearly? If `recordCostEventInternal` isn't actually defined in any task, the plan ships broken.
3. **Cost-event reversal idempotency** — Task F3 says "validate that it has not already been reversed" but doesn't add a `reverses_event_id` column. Is this enforced by `idempotency_payload_hash` alone? If two reversals of the same original event with no idempotency_key are submitted, both succeed and over-reverse the WAC.
4. **`Cancel transfer` button label collision in the Playwright test** — the v2 Playwright cancel-test uses `button:has-text("Cancel transfer")` twice (header + modal confirm), so the second click might hit the wrong button. Read the Playwright snippet; if the selectors aren't disambiguated, the test will flake.
5. **F3's `cost_event_reversal_ids` validation** — uses `ScopedExists::tenantAndCompany('stock_transfer_cost_events', ...)`. Does that helper exist? Confirm with a grep against `ScopedExists`.
6. **Frontend changes to `RecordCostEventInput`** — v2 mentions `phase: 'in_transit' | 'at_receipt'` (subset). If TS types claim `at_initiate` is also valid, the frontend can submit something the backend rejects. Verify consistency.

---

## Workflow

1. Read the v2 plan. The latest version is at `docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md`.
2. Read your v1 review for context on what you previously flagged.
3. For each lettered v1 finding above, verify the v2 fix actually closes it.
4. For the new-issues section, spec each scenario as a two-process / two-codepath walkthrough.
5. Save the review to the exact path above.
6. Reply with just the path and the one-line verdict.

Be honest. If the v2 plan is genuinely good, APPROVE-PLAN is the right verdict and the author can start execution. If it's APPROVE-WITH-MINOR-EDITS, list the edits in the review. If you find NEW blockers in v2's edits, BLOCKER is the verdict.
