# POS Offline Cash Drawer Operations & Local Void

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make cash drawer deposit/payout work offline and allow voiding unsynced local receipts. Add clear "requires internet" messaging for online-only operations (void of synced receipts, returns).

**Architecture:** POS desktop (Tauri 2 + React) at `apps/pos/`, SQLite via `@tauri-apps/plugin-sql`, sync via `SyncScheduler`.

**Tech Stack:** React 19, TypeScript strict, Zustand 5, Vitest

---

## Task 1: SQLite Table for Offline Cash Drawer Operations

**Files:**
- Modify: `apps/pos/src/lib/db/migrations.ts` — add new migration
- Create: `apps/pos/src/lib/db/repositories/cashDrawerRepository.ts`

- [ ] **Step 1: Add migration for offline_cash_drawer_ops table**

```sql
CREATE TABLE IF NOT EXISTS offline_cash_drawer_ops (
  id TEXT PRIMARY KEY,
  idempotency_key TEXT NOT NULL UNIQUE,
  type TEXT NOT NULL CHECK(type IN ('deposit', 'payout')),
  amount TEXT NOT NULL,
  reason TEXT NOT NULL,
  operator_id TEXT NOT NULL,
  operator_name TEXT NOT NULL,
  terminal_id TEXT NOT NULL,
  shift_id TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending', 'syncing', 'synced', 'failed')),
  retry_count INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  synced_at TEXT,
  sync_error TEXT
)
```

- [ ] **Step 2: Create cashDrawerRepository.ts with CRUD operations**

Functions: `insertCashDrawerOp`, `getPendingCashDrawerOps`, `updateCashDrawerOpStatus`, `getCashDrawerOpsForShift`, `getLocalCashDrawerBalance`, `cleanupSyncedOps`

- [ ] **Step 3: Run tests**

---

## Task 2: Offline Cash Drawer Deposit/Payout in CashDrawerModal

**Files:**
- Modify: `apps/pos/src/api/cashDrawerApi.ts` — add offline fallback
- Modify: `apps/pos/src/components/pos/CashDrawerModal.tsx` — if needed for UX

- [ ] **Step 1: Write failing test — depositCash works offline**

- [ ] **Step 2: Add offline fallback to depositCash and payoutCash**

Try API first → on failure, insert into SQLite `offline_cash_drawer_ops` with `status: 'pending'`.

- [ ] **Step 3: Add offline fallback to fetchCashDrawerOps in reportApi.ts**

Query local SQLite `offline_cash_drawer_ops` for the current shift.

- [ ] **Step 4: Run tests**

---

## Task 3: Push Cash Drawer Ops in Sync Service

**Files:**
- Modify: `apps/pos/src/lib/sync/syncService.ts` — add `pushCashDrawerOps`

- [ ] **Step 1: Add pushCashDrawerOps function**

Similar to `pushOfflineReceipts` but simpler — no hash chain ordering required.

- [ ] **Step 2: Wire into runFullSync**

Add after receipt push, before pulls.

- [ ] **Step 3: Run tests**

---

## Task 4: Void of Unsynced Local Receipt

**Files:**
- Modify: `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts` — add void function
- Modify: `apps/pos/src/components/pos/VoidReturnModal.tsx` — add local void path

- [ ] **Step 1: Add voidOfflineReceipt to offlineReceiptRepository**

Mark a pending offline receipt as voided: set a `voided` flag and `void_reason`. Create a corresponding negative receipt in the hash chain for fiscal compliance.

- [ ] **Step 2: Add local receipt lookup in VoidReturnModal**

When offline, search `offline_receipts` in SQLite by receipt number (WHERE `receipt_number LIKE $1` AND `status IN ('pending', 'failed')`). Only show unsynced receipts.

- [ ] **Step 3: Add offline void flow in VoidReturnModal**

If the found receipt is a local unsynced receipt → void locally. If it's a synced/server receipt → show "Requires internet connection" message.

- [ ] **Step 4: Add i18n keys for offline void messages**

- [ ] **Step 5: Run tests**

---

## Task 5: "Requires Internet" Messaging for Online-Only Operations

**Files:**
- Modify: `apps/pos/src/components/pos/VoidReturnModal.tsx`

- [ ] **Step 1: Add connectivity check for return operations**

When offline and user tries a return → show clear message: "Returns require an internet connection."

- [ ] **Step 2: Add connectivity check for void of synced receipts**

When offline and receipt is from server → show: "This receipt has been synced. Voiding it requires an internet connection."

---

## Task 6: Run Full Suite and Commit

- [ ] **Step 1: Run all tests**
- [ ] **Step 2: Commit and push to main + dev**
