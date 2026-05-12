# PR #114 — Optimistic pendingReceiptCount on offline receipt commit — Codex review trail

**Branch:** `feat/pos-optimistic-pending-count`
**Base:** `dev`
**Reviewer:** Codex CLI (`codex review --base dev`)
**Final state:** 1 round, APPROVE.

This PR closes the `TODO(go-live-followup)` anchor around pending receipt badge latency. The implementation keeps `insertOfflineReceipt` transactionally pure and increments the in-memory badge only after `createOfflineReceipt` commits successfully.

## Round 1 — APPROVE

> The changes move the pending-count update to the post-COMMIT path and add the corresponding store action/tests. I did not identify a discrete correctness issue introduced by this patch.

No findings. PR ready for merge.

## Final shape

- **1 commit**.
- **5 files changed**:
  - `apps/pos/src/stores/syncStore.ts`
  - `apps/pos/src/stores/__tests__/syncStore.test.ts`
  - `apps/pos/src/lib/offline/receiptService.ts`
  - `apps/pos/src/lib/offline/__tests__/receiptService.test.ts`
  - `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts`
- **+3 tests**:
  - syncStore optimistic increment.
  - receiptService post-COMMIT increment ordering.
  - rollback paths assert no optimistic increment.
- POS gates:
  - targeted tests — 55/55 pass.
  - `pnpm typecheck` — 0 errors.
  - `pnpm lint` — 0 errors / 41 warnings.
  - `pnpm test` — 1203/1203 pass across 133 files.

## Pre-flight audit

- **L9 ingress audit:** audited `insertOfflineReceipt` call sites. The only production caller is `receiptService.createOfflineReceipt`; all other matches are tests/mocks.
- **Ownership decision:** `insertOfflineReceipt` remains transactionally pure after T2.2. The optimistic increment is owned by `receiptService.createOfflineReceipt` after `db.execute('COMMIT')`, beside `scheduleDebouncedSync`, so rollback paths do not drift the badge.
- **SQLite reconciliation:** scheduler/startup hydration still calls `setPendingCount(getPendingReceiptCount(db))`, so SQLite remains authoritative and overwrites any optimistic drift on the next refresh.
- **Logout/reset:** existing `syncStore.reset()` clears `pendingReceiptCount` to 0.
- **L1 cross-tenant audit:** Menu, standard-retail, hybrid, and non-Menu tenants all create offline receipts through the same post-COMMIT path; tenant-specific receipt line shape does not affect the count.
- **L8 ownership audit:** SyncButton/Header and CloseShiftModal continue to read `pendingReceiptCount`; receiptService complements scheduler hydration with a post-COMMIT optimistic increment, while scheduler remains the reconciliation owner.
