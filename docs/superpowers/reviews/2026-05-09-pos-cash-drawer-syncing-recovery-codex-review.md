# PR #95 — Codex Round-1 Adversarial Review

**Verdict:** REQUEST-CHANGES (single P2 / MAJOR finding)

**Summary:** The recovery shape mirrors PR #94 Step D faithfully but inherits an asymmetry in cash-drawer-specific `retry_count` semantics that leaves final-attempt stranded rows permanently invisible. One targeted fix closes it.

## Findings

### BLOCKERS
None.

### MAJORS

**P2 — Avoid leaving recovered final-attempt ops unsyncable**
File: `apps/pos/src/lib/db/repositories/cashDrawerRepository.ts:120` (the new `recoverStrandedSyncingCashDrawerOps`)

When a cash-drawer op has `retry_count = 4`, `getPendingCashDrawerOps` will pick it up (filter is `retry_count < 5`). `updateCashDrawerOpStatus(..., 'syncing')` then increments retry_count to 5 BEFORE the HTTP call (line 71 of cashDrawerRepository — the non-`'synced'` branch increments). If the app is killed at that point, the recovery demotes status back to `'pending'`, but `getPendingCashDrawerOps` still filters on `retry_count < 5`, so the recovered row is skipped forever and the crash recovery does not actually re-attempt the op in this final-attempt scenario.

**Why this asymmetry vs the receipts recovery:** `offlineReceiptRepository.updateReceiptStatus` does NOT increment retry_count for non-`'synced'` status changes — only an explicit `incrementRetryCount` does. So PR #94 Step D's recovery doesn't need to touch retry_count. The cash-drawer repository's `updateCashDrawerOpStatus` does increment, so the recovery must compensate.

**Fix:** decrement retry_count by 1 in the recovery UPDATE (with a `MAX(0, retry_count - 1)` floor for safety). The decrement subtracts the spurious increment that the syncing transition introduced, restoring the row to its pre-attempt state. T0.2's idempotency_key dedups any double-send server-side, so the retry is safe.

**Regression test:** seed an op at retry_count = 4 with status = 'syncing'; recover; verify it ends up at retry_count = 3 with status = 'pending', and that `getPendingCashDrawerOps` returns it.

### MINORS
None.

### NITS
None.

## Verification done

- Read the new recovery function at `cashDrawerRepository.ts:140-152`.
- Read `getPendingCashDrawerOps` at `cashDrawerRepository.ts:38-43` to confirm the `retry_count < 5` filter.
- Read `updateCashDrawerOpStatus` at `cashDrawerRepository.ts:56-75` to confirm the non-`'synced'` branch increments retry_count.
- Read PR #94 Step D's `recoverStrandedSyncingReceipts` at `offlineReceiptRepository.ts:167-175` and confirmed the receipts recovery does not touch retry_count.
- Read `offlineReceiptRepository.updateReceiptStatus` to confirm the receipts side does NOT increment retry_count on non-`'synced'` status changes (so the receipts recovery is correct as-is).

## Out of review scope

- Pre-existing double-counting of retry_count in cash-drawer (each completed sync attempt increments by 2 — once on `'syncing'`, once on `'failed'`). This is a wider behaviour question that should land in its own change.
- The SqliteTestAdapter `isMultiStatement` regex's behaviour against SQL string literals containing semicolons. No production repo function uses such SQL today; flag if that ever changes.
