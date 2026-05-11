# POS Bugs Cascade — Multi-PR Fix Handoff Plan

> **For Codex (adversarial-review-then-implementer) + Opus (pre-merge reviewer per PR):**
>
> **Phase 1 — ADVERSARIAL REVIEW (do this FIRST, before any code):** Read this plan end-to-end. Critique the root-cause analysis, the per-PR fix designs, and the test plans. Find gaps, race conditions, alternative designs, missing tests, or wrong assumptions. Push back where you disagree. Output a structured review — findings as `[BLOCKER]` / `[P1]` / `[P2]` / `[NIT]` per section. Then HAND BACK to Opus. The plan iterates 1-2 rounds with Opus before implementation begins.
>
> **Phase 2 — IMPLEMENTATION (after plan is locked):** Codex implements ONE PR at a time, in the sequence below. Each PR follows the cadence already established for PRs #106-#118: branch off `dev`, TDD red anchor, implement, run quality gates, push, open PR, run `codex review --base dev` until APPROVE or STOP-3, save review trail to `docs/superpowers/reviews/`. Then hand to Opus for pre-merge review.
>
> **Phase 3 — OPUS PRE-MERGE REVIEW (every PR):** Opus does a final audit per PR. The cascade is production-critical and the bugs reinforce one another — every PR needs Opus to sign off before merging.

**Goal:** Ship per-PR fixes for the 5 production bugs reported on `dev` so the POS reaches a stable state for go-live. Bug 5 (SQLite WAL) is root cause for Bugs 3+4; Bug 2 (Codex's contract violation) is independent; Bug 1 (catalog WebSocket) is a missing feature.

**Architecture:** 4 independent PRs to `dev`, ordered by urgency. Bug 5's PR is critical and ships first. Bug 2's PR can ship in parallel. Bug 4's chain-break recovery UX ships after Bug 5 lands and Bug 1's catalog WebSocket is the lowest-urgency feature add.

**Tech Stack:**
- `apps/pos`: React 19 / Vite 7 / TypeScript strict / Zustand 5 / Tauri 2 / Vitest. Commands: `pnpm typecheck`, `pnpm lint`, `pnpm test`. Lint baseline: **0 errors / 41 warnings — do not introduce new warnings.**
- `apps/api`: Laravel 12 / PHP 8.4 / PostgreSQL 16. Commands: `./vendor/bin/phpstan` (level 8), `./vendor/bin/pint`, `./vendor/bin/phpunit`.
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp-pos-stabperf`. **Never push to `main`; merge to `dev` via PR.**

---

## Symptom catalog (as reported by the user)

### Bug 1 — Real-time catalog sync not working

When a new article or menu category is created in the web dashboard, the POS doesn't pick it up until a manual refresh. The POS has WebSocket only for terminal-activation pairing (`useTerminalActivation` in `hooks/`); there is no real-time catalog subscription. Background sync runs every 60s but that's not "real-time."

### Bug 2 — "Monnaie rendue" (change due) shows 0 on printed ticket

For any cash sale with `tendered > total`, the printed ticket shows `Monnaie rendue: 0` instead of the actual change. The change appears correctly in the in-app UI but never reaches the print template.

### Bug 3 — Payment fails on over-tender ("Échec du paiement")

When the cashier enters tendered = total, the order completes. When tendered > total, the cashier sees a red "Échec du paiement" banner. Symptom is INTERMITTENT per follow-up reports — "sometimes works, sometimes fails."

### Bug 4 — Broken fiscal chain ("Chaîne fiscale rompue") with cascade

Red alert displayed in the POS:
- New orders fail with "Échec du paiement" intermittently.
- Created tickets don't appear in the transactions report or on the web `/pos/receipts` page.
- The chain-break banner persists until manually acknowledged (and `acknowledgeChainBreak` only times-stamps the acknowledgement, doesn't clear the underlying state).

### Bug 5 — SQLite database lock (code 5)

Console error:
```
[POS][sync][pushOfflineReceipts] receipt push threw
  errorType: "string" (not Error instance — raw string thrown by Tauri SQL plugin)
  message: "error returned from database: (code: 5) database is locked"
  receiptNumber: "MAIN-C1-2026-00000001"  (the very first receipt)
  retryCount: 4  (approaching dead-letter cap of 5)
  idempotencyKey: "4dc757bb-..."
[POS][syncScheduler] tick threw
  currentInterval: 300000  (5 min — max backoff)
```

---

## Root cause analysis (these are MY hypotheses — Codex's adversarial review is invited to challenge each)

### Bug 5 (root cause)

**`apps/pos/src/lib/db.ts:7-26`** opens SQLite via `Database.load('sqlite:...')` and immediately runs migrations. **No `PRAGMA journal_mode=WAL` and no `PRAGMA busy_timeout` are set.** SQLite default journal mode is `DELETE` — every write takes an exclusive lock that blocks readers and other writers.

With Tauri's async plugin-sql layer:
1. Cashier confirms → `createOfflineReceipt` (`apps/pos/src/lib/offline/receiptService.ts:300+`) opens a transaction (BEGIN → insert offline_receipts row + advanceHashChain + voucher updates → COMMIT).
2. Sync scheduler ticks (debounced ~250 ms after commit, but Tauri's async layer can fire earlier).
3. Sync tries `SELECT * FROM offline_receipts WHERE status='pending'`. Hits the exclusive lock from #1. Throws `"error returned from database: (code: 5) database is locked"` as a raw STRING (not an Error instance).
4. Sync error handler at `syncService.ts:412+` catches → `incrementRetryCount` → marks the receipt `failed` with the lock message → next tick retries → same race → same lock → fails again.
5. After 5 retries (the dead-letter cap), the receipt is permanently `failed`. The scheduler has backed off to 5 min between ticks.

### Bug 4 (consequence of Bug 5)

Once receipt #1 is dead-lettered:
1. The local terminal chain has already advanced past receipt #1 (the offline-receipt transaction COMMITted; `advanceHashChain` ran in the same transaction).
2. The server's `terminal.last_hash` is still GENESIS (receipt #1 never reached the server).
3. Receipt #2's `previous_hash = hash(#1)`. Sync sends it. Server's `ReceiptSyncService.php:209` checks `$clientPreviousHash !== $terminalLastHash` → mismatch → returns `chain_broken`.
4. Client's `syncService.ts:397` detects `chain_broken` → `useSyncStore.setChainBreak(true, lastSynced)` → red alert appears.
5. Every subsequent receipt cascades through the same mismatch. None sync. None appear in `pos_receipts` server-side, reports, or `/pos/receipts`.

### Bug 3 (consequence of Bug 5)

The user's "over-tender fails" pattern is a TIMING coincidence, not a logical one:
- Cashier's `createOfflineReceipt` transaction itself can hit SQLITE_BUSY if a sync tick acquires the lock first.
- Over-tender vs exact-tender doesn't change the SQL; it changes timing slightly (extra keypresses, extra validation render).
- The cashier sees the receipt-creation throw, formatted as "Échec du paiement" via `formatCheckoutError` at `paymentStore.ts:277`.

### Bug 2 (independent — Codex's diagnosis confirmed)

`paymentStore.ts:544` sets `payments[0].amount = totalEstimate.toFixed(decimals)` (the cart total), not the tendered amount. Backend contract documented at `apps/api/tests/Feature/POS/CashCountToleranceVarianceRegressionTest.php:34-40`:

> "The 'amount' column already stores what the cashier physically tendered, so a €100 receipt with €99.70 tender + €0.30 tolerance write-off contributes +€99.70 to drawer cash."

Print path `apps/pos/src/lib/buildReceiptData.ts:100-102` derives change as `Σ(payments[].amount) − total`. With `payments[0].amount = total`, change is always 0.

### Bug 1 (missing feature)

The POS has WebSocket only for terminal-activation pairing (`hooks/useTerminalActivation.ts`). No subscription exists for Product / MenuCategory / MenuCategoryItem mutations. The server doesn't broadcast these events. Background polling (`runFullSync`, 60 s tick) is the only catalog-update mechanism.

---

## Standing review disciplines (apply to every PR)

These are the lessons from PRs #106-#118 — the cascade we ran here would have been smaller with these disciplines baked into the PR body upfront.

**L1 — Cross-tenant audit.** Before pushing any change that runs for all tenants, explicitly enumerate the tenant classes (Menu / standard-retail / hybrid / non-Menu) and verify each is handled correctly.

**L8 — Cross-screen ownership boundaries.** When a new screen / surface takes ownership of a state transition, enumerate every OTHER screen that previously co-owned anything in that transition surface and decide whether the new owner replaces, complements, or races the old.

**L9 — Ingress-site enumeration.** When introducing a new invariant on a data shape (canonicalization, comparison, serialization), enumerate every state-machine ingress that may operate on the pre-invariant shape: every `set({...})` site, every diff/merge function input, every cache read that feeds those sites, every wire boundary. Document the enumeration in the PR body.

**Round budget (STOP-3).** Rounds 1-2 = routine. Rounds 3-5 = kickoff under-spec. Round 6+ = structural rework or a self-introduced regression. STOP-3 fires past round 5 — brief, do not push past, hand to Opus.

---

## ADVERSARIAL REVIEW PHASE — what I want Codex to check

Before any code lands, perform an adversarial review of this plan. Format your output as `[BLOCKER]` / `[P1]` / `[P2]` / `[NIT]` findings, grouped by PR.

**Specific things to challenge:**

1. **Bug 5 root cause.** Is `PRAGMA journal_mode=WAL` actually missing? Verify with `git grep` for any PRAGMA setting at db open. If WAL IS already set somewhere I missed, my root cause is wrong. **Look for it before accepting my analysis.**
2. **Bug 5 fix.** Does Tauri's `@tauri-apps/plugin-sql` allow `PRAGMA` via `db.execute`? Some plugins restrict it. If the PRAGMA can't be executed at runtime, the fix won't apply and we need a different approach (e.g., a `:memory:` test mode for the unit tests, or a connection-string parameter).
3. **Bug 5 transaction safety.** WAL changes journal mode persistently on the database file. If a device has been running on `journal_mode=DELETE` and now flips to WAL mid-life, are there any data integrity concerns? Check SQLite docs.
4. **Bug 5 stuck-receipt recovery.** Is my recovery SQL safe? `UPDATE offline_receipts SET status='pending', retry_count=0, sync_error=NULL WHERE sync_error LIKE '%database is locked%'`. What if a different error message also matches that LIKE pattern? What if the receipt was dead-lettered for OTHER reasons too?
5. **Bug 4 chain-break cascade theory.** Trace it yourself: after receipt #1 is dead-lettered (status `failed`), does `getPendingReceiptsForSync` skip it? If yes, then receipt #2 is the next sync target and the chain-mismatch theory holds. If no (dead-lettered receipts are excluded BUT still part of the chain), the cascade theory breaks.
6. **Bug 3 unification.** Is my "intermittent due to timing" theory consistent with "exact works / over fails" specifically? Or is there a deterministic difference I'm missing? The user's report is contradictory: their initial report says "exact works / over fails" (deterministic), but a later one says "sometimes works / sometimes fails" (probabilistic).
7. **Bug 2 second-order effects.** If `payments[0].amount` changes from `total` to `tendered`, what server-side reports change? Cash-variance, end-of-day shift close, payment-methods report. Are any of these computed using the OLD shape? If yes, the fix could cause a regression in those reports.
8. **PR sequencing.** Should Bug 5 + the stuck-receipt SQL recovery ship in the SAME PR, or split? If split, ordering matters because devices with broken state can't recover until the SQL also lands.
9. **Test coverage.** The Vitest harness mocks Tauri's plugin-sql. WAL behavior isn't testable in the unit harness. The integration tests at `apps/pos/src/lib/db/__tests__/migrations.integration.test.ts` use a real db file — check if they can host WAL-aware tests.
10. **Anything missing.** Does the plan cover all reported symptoms? Are any of the 5 bugs MORE than one bug? Are any of the 5 bugs actually the same bug viewed from different angles?

**My known assumptions:**
- Tauri's `@tauri-apps/plugin-sql` is sqlx-backed and supports WAL.
- The Vitest test harness doesn't faithfully reproduce SQLite locking semantics, so the WAL fix must rely on integration tests OR be smoke-tested manually.
- Stuck receipts at the device can be safely recovered by mass-resetting `status='pending'` and `retry_count=0` — the server-side `idempotency_key` dedup catches double-sends as duplicates.
- `acknowledgeChainBreak` is intentionally non-destructive (timestamps the acknowledgement; does NOT clear `chainBreak: true`). Clearing the underlying broken state requires a separate "Resolve chain break" action that doesn't exist yet (PR C below).

**My known risks:**
- A WAL switch on a device with an open transaction could fail. Mitigated by setting WAL on the very first `Database.load` of a session (no transactions exist yet at that point).
- Recovery SQL could over-reset (resetting receipts that failed for non-lock reasons). Mitigated by LIKE-matching the lock error message specifically.
- Server-side reports change when `payments[0].amount = tendered` — but they SHOULD because the contract was always tendered. Need to confirm no existing report relies on the wrong shape.

---

## PR sequence

### PR A — Bug 5 WAL + stuck-receipt recovery (CRITICAL — ship first)

**Branch:** `fix/pos-sqlite-wal-busy-timeout`

**Why first:** Bug 5 is the root cause for Bugs 3+4. Without it, devices can't recover from the cascade. Every minute this stays unshipped, more devices may enter the broken state.

**Files:**
- Modify: `apps/pos/src/lib/db.ts:7-26` (add PRAGMA WAL + busy_timeout after `Database.load`)
- Modify: `apps/pos/src/lib/db/migrations.ts` (add migration v32 — one-shot recovery for stuck receipts)
- Create: `apps/pos/src/lib/__tests__/db-wal.integration.test.ts` (WAL regression tests)
- Modify: `apps/pos/src/lib/db/__tests__/migrations.v32.test.ts` (or similar — recovery migration test)

**Pre-flight downstream audit (L9 ingress audit):**

Every code path that calls `getDatabase` will now get WAL mode + 5 s busy_timeout. Audit:
- `getDatabase` call sites — list every one. Confirm none assume DELETE journal mode (e.g., relying on exclusive locking for serialization).
- Migration v31 (PR #118) writes `pos_migration_state`. Verify migration ordering: v32 runs AFTER v31, so by the time the recovery SQL runs the schema is up to date.
- The `Database.load` singleton — if a device is mid-session and we deploy this fix, the next `Database.load` (next app launch) flips WAL. Confirm Tauri restart is required for the fix to take effect.

**TDD steps:**

- [ ] **Step 1: Branch + verify journal_mode is currently DELETE.**

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp-pos-stabperf
git checkout -b fix/pos-sqlite-wal-busy-timeout dev
git pull --ff-only
grep -rn "PRAGMA journal_mode\|busy_timeout\|locking_mode" apps/pos/src --include="*.ts" --include="*.rs"
```

Expected: no production PRAGMA matches. If any match exists in `apps/pos/src/`, this PR's premise is wrong — STOP and re-audit.

- [ ] **Step 2: Write the failing test.** Create `apps/pos/src/lib/__tests__/db-wal.integration.test.ts`:

```typescript
import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import { getDatabase, closeDatabase } from '@/lib/db';

const TEST_COMPANY = 'test-wal-' + Date.now();

describe('SQLite WAL configuration (Bug 5 regression guard)', () => {
  beforeAll(async () => {
    await getDatabase(TEST_COMPANY);
  });

  afterAll(async () => {
    await closeDatabase();
  });

  it('journal_mode is set to WAL', async () => {
    const db = await getDatabase(TEST_COMPANY);
    const rows = await db.select<{ journal_mode: string }[]>('PRAGMA journal_mode');
    expect(rows[0]!.journal_mode.toLowerCase()).toBe('wal');
  });

  it('busy_timeout is at least 1000 ms', async () => {
    const db = await getDatabase(TEST_COMPANY);
    const rows = await db.select<{ timeout: number }[]>('PRAGMA busy_timeout');
    expect(rows[0]!.timeout).toBeGreaterThanOrEqual(1000);
  });
});
```

Note: this integration test requires a real `@tauri-apps/plugin-sql` runtime. If Vitest's mock environment doesn't load it, gate the test with `it.skipIf(typeof window === 'undefined' || !window.__TAURI__)` or run only via the Tauri integration suite. If a unit-test substitute is needed, mock `Database.load` to return a stub that records the `db.execute('PRAGMA ...')` calls.

- [ ] **Step 3: Run the failing test.**

```bash
cd apps/pos && pnpm test src/lib/__tests__/db-wal.integration.test.ts
```

Expected: FAIL (journal_mode is `delete`, not `wal`).

- [ ] **Step 4: Apply the WAL fix.** Edit `apps/pos/src/lib/db.ts:7-26`:

```typescript
export async function getDatabase(companyId: string): Promise<Database> {
  const dbName = `izipos-${companyId}.db`;

  if (db && currentDbName === dbName) {
    return db;
  }

  if (db && currentDbName !== dbName) {
    await db.close();
    db = null;
  }

  db = await Database.load(`sqlite:${dbName}`);
  currentDbName = dbName;

  // Bug 5 fix — WAL mode allows concurrent readers + serialized writers.
  // The default journal_mode=DELETE serializes EVERYTHING through an
  // exclusive lock, so a sync-tick read collides with the cashier's
  // createOfflineReceipt transaction and surfaces SQLITE_BUSY (code 5).
  // The cascade also breaks the fiscal chain: dead-lettered receipts
  // leave the local chain ahead of the server's, and every subsequent
  // receipt fails with chain_broken (Bug 4). WAL mode prevents the
  // contention at the root.
  //
  // busy_timeout=5000 covers rare write-vs-write contention by waiting
  // up to 5s for the lock to release instead of throwing immediately.
  // 5s is well below any user-visible UI delay (the cashier-facing
  // operations are sub-second under normal load).
  await db.execute('PRAGMA journal_mode=WAL');
  await db.execute('PRAGMA busy_timeout=5000');

  await runMigrations(db);

  return db;
}
```

- [ ] **Step 5: Run the WAL test — expect PASS.**

- [ ] **Step 6: Add the stuck-receipt recovery migration.** Edit `apps/pos/src/lib/db/migrations.ts` — append v32:

```typescript
{
  version: 32,
  name: 'recover_stuck_offline_receipts_from_db_lock',
  sql: `
    -- Bug 5 fix — one-shot recovery for receipts that were marked
    -- 'failed' because of the SQLITE_BUSY cascade (Bug 5). The Tauri
    -- plugin-sql plugin throws the raw libsqlite error message as a
    -- string; "(code: 5) database is locked" is the unique signature.
    -- After this migration runs, the WAL mode set in db.ts prevents
    -- the original cause, and the recovered receipts retry against a
    -- working sync layer. The server's idempotency_key dedup catches
    -- any accidental double-sends as duplicates.
    UPDATE offline_receipts
    SET status = 'pending',
        retry_count = 0,
        sync_error = NULL
    WHERE status = 'failed'
      AND sync_error LIKE '%database is locked%';
  `,
},
```

- [ ] **Step 7: Write the recovery migration test.** Create `apps/pos/src/lib/db/__tests__/migrations.v32.test.ts`:

```typescript
import { describe, it, expect, beforeEach } from 'vitest';
// ... use the existing migration test harness (see migrations.v31.test.ts for the
// pattern with a real sqlite-via-Tauri DB instance OR a sqlite3 stub).

describe('Migration v32 — recover stuck offline receipts from DB lock', () => {
  it('resets status, retry_count, and sync_error for lock-failed receipts', async () => {
    // Setup: seed 3 receipts
    //   r1: status='failed', sync_error='error returned from database: (code: 5) database is locked', retry_count=5
    //   r2: status='failed', sync_error='Hash chain break: ...', retry_count=1
    //   r3: status='pending', sync_error=NULL, retry_count=0
    //
    // Run the v32 migration.
    //
    // Assert:
    //   r1 → status='pending', retry_count=0, sync_error=NULL  (recovered)
    //   r2 → unchanged  (different error class — preserved for separate triage)
    //   r3 → unchanged  (not failed)
  });
});
```

- [ ] **Step 8: Run the migration test — expect PASS.**

- [ ] **Step 9: Full preflight battery.**

```bash
cd apps/pos
pnpm typecheck      # expect 0 errors
pnpm lint           # expect 0 errors / 41 warnings (baseline)
pnpm test           # expect all pass; new tests counted
```

- [ ] **Step 10: Commit + PR.**

```bash
git add apps/pos/src/lib/db.ts \
        apps/pos/src/lib/db/migrations.ts \
        apps/pos/src/lib/__tests__/db-wal.integration.test.ts \
        apps/pos/src/lib/db/__tests__/migrations.v32.test.ts

git commit -m "fix(pos): enable SQLite WAL mode + recover stuck receipts (Bug 5 + cascade for Bugs 3+4)

WAL mode allows concurrent readers + serialized writers; the default
journal_mode=DELETE serializes EVERYTHING through an exclusive lock,
so the sync scheduler's read collides with the cashier's
createOfflineReceipt transaction and surfaces SQLITE_BUSY (code 5).

The cascade also breaks the fiscal chain — dead-lettered receipts
leave the local chain ahead of the server's, and every subsequent
receipt fails with chain_broken (Bug 4). The 'Échec du paiement'
banner the cashier sees intermittently (Bug 3) is the same root cause
when the BEGIN transaction inside createOfflineReceipt races a sync
tick.

Migration v32 mass-recovers receipts that were marked 'failed' with
the lock signature, so devices currently stuck in the cascade can
resume sync after the WAL fix lands and the app restarts.

Preflight (apps/pos):
  - typecheck: 0 errors
  - lint: 0 errors / 41 warnings (baseline preserved)
  - tests: all pass + 2 new (WAL regression guard, v32 migration)

Closes Bug 5 root cause. Cascade closes Bugs 3 and 4 once new sessions
start under WAL mode. Bug 2 is independent (PR B). Bug 1 is a missing
feature (PR D).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"

git push -u origin fix/pos-sqlite-wal-busy-timeout
gh pr create --base dev --title "fix(pos): SQLite WAL + stuck-receipt recovery (Bug 5 + cascade)" \
  --body "<see PR body template in the plan doc §PR A>"
```

- [ ] **Step 11: Codex review.** Run `codex review --base dev` rounds. STOP-3 at round 5.

- [ ] **Step 12: Hand to Opus for pre-merge review.**

**PR body template:**

```markdown
## Summary

Bug 5 root-cause fix. Enables SQLite WAL mode at db open so the sync
scheduler's reads no longer collide with the cashier's
createOfflineReceipt transaction. Migration v32 mass-recovers
receipts that were marked 'failed' with the lock signature.

This is the root cause for Bugs 3 (intermittent "Échec du paiement"
at checkout) and 4 (chain-broken cascade). Once devices restart
under WAL mode, both cascade-symptoms self-heal.

## Pre-flight downstream audit

[L9 ingress audit — list every getDatabase call site, every migration
that runs at startup, every place that assumes DELETE journal mode.]

## What shipped

- `apps/pos/src/lib/db.ts`: WAL + busy_timeout PRAGMAs at db open.
- `apps/pos/src/lib/db/migrations.ts`: migration v32 (recovery).
- `apps/pos/src/lib/__tests__/db-wal.integration.test.ts`: regression guard.
- `apps/pos/src/lib/db/__tests__/migrations.v32.test.ts`: recovery test.

## Test plan

- [x] typecheck 0 errors
- [x] lint 41 warnings (baseline preserved)
- [x] tests: all pass + 2 new
- [ ] Manual smoke (audit phase): on a clean install, ring up 100
  consecutive cash sales while the sync scheduler ticks aggressively.
  No code-5 errors in the console; chain advances correctly; all
  receipts appear in /pos/receipts.
- [ ] Recovery smoke: seed a SQLite db with 3 receipts as described
  in the v32 test. Boot the POS. Verify the lock-flagged receipt is
  recovered to 'pending' and successfully syncs on the first tick.

## Acceptance criteria

- New sessions start with `journal_mode=WAL` and `busy_timeout=5000`.
- Stuck receipts (sync_error LIKE '%database is locked%') are recovered
  on first boot after deploy.
- No regression: existing tests continue to pass.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
```

**Expected Codex rounds:** 1-2. WAL is a well-understood fix; the only thing Codex might catch is the migration's edge cases (e.g., what if `sync_error` is NULL or has different casing).

---

### PR B — Bug 2 contract violation fix

**Branch:** `fix/pos-cash-payment-amount-is-tendered`

**Why second:** Independent from PR A. Can ship in parallel. Production-correctness fix.

**Files:**
- Modify: `apps/pos/src/stores/paymentStore.ts:544` (cash path: payments[0].amount = tendered, not total)
- Modify: `apps/pos/src/stores/__tests__/paymentStore.stress.test.ts` (if fixtures assume current shape)
- Modify: `apps/pos/src/stores/__tests__/paymentStore.offlineFirst.test.ts` (same)
- Optionally: `apps/pos/src/lib/offline/getOfflineReceiptForPrint.ts` + `apps/pos/src/types/receipt.ts` (expose change_due directly as defense-in-depth)
- Optionally: `apps/pos/src/lib/buildReceiptData.ts:100-102` (prefer receipt.change_due when present)

**Pre-flight downstream audit (L9 ingress audit):**

Every consumer of `payments[].amount`:
1. Local v3 fiscal hash (`computeV3FiscalHash` at receiptService.ts:210) — uses input.payments[].amount. Will now hash tendered instead of total. Server recomputes from the same wire payload, so hashes match.
2. `payments_json` column on `offline_receipts` — will store tendered. `getOfflineReceiptForPrint` reads this back.
3. Wire payload `payments[].amount` — sent to server. Server's `ReceiptSyncService:545` writes it to `pos_receipt_payments.amount`.
4. Server-side reports — confirm `pos_receipt_payments.amount` is treated as tendered everywhere (cash variance, end-of-day, shift close).
5. Print path: `buildReceiptData.ts:100-102` derives change as `Σ(payments) − total = tendered − total = correct`.
6. **Bug 2 second-order check:** Any server-side report or test that EXPECTED `pos_receipt_payments.amount == receipt.total` (i.e., the wrong shape)? Codex's adversarial review must specifically search for this.

**TDD steps:**

- [ ] **Step 1: Branch + audit consumers.**

```bash
git checkout -b fix/pos-cash-payment-amount-is-tendered dev
git pull --ff-only

# Server-side: any report that compares payments[].amount to receipt.total?
grep -rn "payments\|payment.*amount" apps/api/app/Modules/POS/Application/Services --include="*.php"
grep -rn "ReceiptPayment.*amount" apps/api/app --include="*.php"
```

Document findings in the PR body — Codex r1 will check this anyway.

- [ ] **Step 2: Write the failing test.**

```typescript
// apps/pos/src/stores/__tests__/paymentStore.cashTenderedAmount.test.ts (or extend existing)

it('Bug 2 fix: processCashCheckout stores payments[0].amount as tenderedAmount, not totalEstimate', async () => {
  // Setup: cart total = 10.00 TND, tenderedAmount = 20.00 TND.
  // ... seed paymentMethods + cashRegister
  // Mock createReceiptLocalFirst to capture the payments array passed in.
  let capturedPayments: any[] = [];
  vi.mocked(createReceiptLocalFirst).mockImplementation((_s, _t, _c, payments) => {
    capturedPayments = payments;
    return Promise.resolve({ ... });
  });

  await processCashCheckout(terminalId, cartItemsWithTotal10, /* tendered */ 20, ...);

  expect(capturedPayments).toHaveLength(1);
  expect(capturedPayments[0].amount).toBe('20.000');  // tendered, not 10.000
});
```

- [ ] **Step 3: Apply the fix.** Edit `apps/pos/src/stores/paymentStore.ts:544`:

```typescript
// BEFORE:
const result = await createReceiptLocalFirst(
  set,
  terminalId,
  cartItems,
  [{
    methodCode: cashMethod.code,
    amount: totalEstimate.toFixed(decimals),  // ← WRONG: should be tendered
    paymentMethodId: cashMethod.id,
    repositoryId: cashRegister.id,
  }],
  tenderedAmount,
  ...
);

// AFTER:
// Bug 2 fix: pos_receipt_payments.amount is the cashier's tendered
// amount per the backend contract documented at
// CashCountToleranceVarianceRegressionTest.php:34-40 ("the 'amount'
// column already stores what the cashier physically tendered").
// The previous code stored totalEstimate (cart total), which (a) made
// the print path's change derivation always-zero (Bug 2 root cause)
// and (b) broke cash-variance reporting for any over-tender (phantom
// shortage of (tendered - total) on the shift close). The fix aligns
// the cash path with the advanced/multi-payment path's convention.
const result = await createReceiptLocalFirst(
  set,
  terminalId,
  cartItems,
  [{
    methodCode: cashMethod.code,
    amount: tenderedAmount.toFixed(decimals),
    paymentMethodId: cashMethod.id,
    repositoryId: cashRegister.id,
  }],
  tenderedAmount,
  ...
);
```

- [ ] **Step 4: Run tests.** Update any fixtures that asserted on the old shape. Expected: all pass.

- [ ] **Step 5: Defense-in-depth (optional but recommended).** Add `tendered_amount` + `change_due` to `FullReceiptResponse` type, propagate through `getOfflineReceiptForPrint`, prefer in `buildEscPosReceiptData`. Separate steps + tests; bundle in same PR.

- [ ] **Step 6: Full preflight + commit + PR + Codex review + Opus pre-merge review.**

**Expected Codex rounds:** 2-3. r1 might flag the server-side report search (defense in depth). r2 might flag fixture updates.

---

### PR C — Bug 4 chain-break recovery UX

**Branch:** `feat/pos-chain-break-recovery-action`

**Why third:** After PR A lands, new sessions don't enter the cascade. But devices that ARE currently stuck need explicit recovery beyond the v32 SQL. The chain-break banner has no in-app "Resolve" action today. PR A's migration only recovers receipts that failed with the lock message; receipts that hit a non-lock chain-mismatch still need manual SQLite intervention without this PR.

**Files:**
- Create: `apps/api/app/Modules/POS/Presentation/Controllers/TerminalChainController.php` (diagnostic endpoint)
- Create: `apps/api/database/migrations/2026_05_XX_add_terminal_chain_diagnostic_endpoint.php` (if route doesn't exist yet — but routes are usually in routes.php; check)
- Modify: `apps/api/app/Modules/POS/routes.php` (register diagnostic endpoint)
- Create: `apps/pos/src/api/terminalChainApi.ts` (frontend API client)
- Modify: `apps/pos/src/components/atoms/ChainBreakAlert.tsx` (add "Resolve" button)
- Create: `apps/pos/src/components/molecules/ChainBreakResolveModal.tsx` (the recovery flow UI)
- Create: `apps/pos/src/lib/recovery/resolveChainBreak.ts` (the recovery action)
- Tests: backend Feature test + frontend component test + recovery-action unit test.

**Pre-flight downstream audit (L8 + L9):**

- L8 (cross-screen ownership): ChainBreakAlert previously only showed timestamps; now it owns a recovery action. Trace what other surfaces could trigger or display chain-break state (none, per the syncStore audit — only ChainBreakAlert reads it).
- L9 (ingress): the recovery action writes to terminal_state (rewind last_hash + sequence) AND offline_receipts (mark unsynced as void_local). Every other reader of those tables must tolerate the rewind/void mid-session.

**Spec (high-level):**

1. **Server: diagnostic endpoint.** `GET /api/v1/pos/terminals/{id}/chain-state` returns:
```json
{
  "terminal_id": "...",
  "current_sequence": 5,
  "last_hash": "abc123...",
  "fiscal_schema_version": 3,
  "recent_receipts": [
    { "chain_sequence": 5, "fiscal_hash": "abc...", "receipt_number": "MAIN-C1-2026-00000005", "posted_at": "..." },
    { "chain_sequence": 4, "fiscal_hash": "def...", "receipt_number": "MAIN-C1-2026-00000004", "posted_at": "..." }
  ]
}
```

2. **POS: ChainBreakAlert gets a "Résoudre" button.** Opens `ChainBreakResolveModal`.

3. **ChainBreakResolveModal flow:**
   - Calls `GET /pos/terminals/{id}/chain-state` to fetch authoritative server state.
   - Compares to local `terminal_state.last_hash` + `hash_sequence`.
   - Shows the divergence point: "Le serveur connaît jusqu'à la séquence #N. Votre terminal est à la séquence #M. Cela signifie X tickets locaux qui ne sont pas sur le serveur."
   - Lists the local-only receipts (status `pending` / `failed`) — receipt number, total, timestamp.
   - Offers two destructive choices:
     - **(a) Conserver les tickets locaux et réessayer.** Resets `retry_count=0` and `status='pending'` on the local-only receipts, sets a `recovery_attempted_at` timestamp, schedules an immediate sync. If sync succeeds, chain advances. (Use this when the chain break was transient — db lock, network blip.)
     - **(b) Marquer les tickets locaux comme annulés et redémarrer la chaîne.** Sets the local-only receipts' status to `void_local` with `void_reason='chain_break_recovery'`. Rewinds `terminal_state.last_hash` + `hash_sequence` to server's values. Clears `chainBreak` flag in syncStore. The voided receipts are listed for the cashier to re-enter if needed. (Use this when option (a) doesn't succeed after retry.)
   - Both actions are gated on a manager-PIN modal (the recovery is consequential).

4. **Audit log:** every recovery attempt writes a row to a new `pos_chain_recovery_log` SQLite table (id, attempted_at, option_chosen, receipts_affected, server_sequence_before, server_sequence_after, local_sequence_before, local_sequence_after). The log is included in the next sync batch as a side-channel diagnostic.

**TDD steps:** detailed in the implementation — too long for here. Codex implements per the spec.

**Expected Codex rounds:** 3-5. The destructive nature of the recovery + manager-PIN integration + audit-log design will surface 2-3 P2s on the first pass.

---

### PR D — Bug 1 catalog WebSocket

**Branch:** `feat/pos-catalog-websocket-sync`

**Why fourth:** Lowest urgency. Polling fallback (60s) covers the gap; this is a UX improvement, not a correctness fix.

**Files:**
- Modify: `apps/api/app/Modules/Product/Domain/Product.php` (broadcast on create/update/delete via Laravel events)
- Modify: `apps/api/app/Modules/Menu/Domain/MenuCategory.php` (same)
- Modify: `apps/api/app/Modules/Menu/Domain/MenuCategoryItem.php` (same)
- Create: `apps/api/app/Modules/Product/Events/ProductCatalogChanged.php` (and Menu counterparts)
- Create: `apps/api/app/Modules/POS/Domain/Channels/CatalogChannel.php` (channel auth)
- Modify: `apps/api/routes/channels.php` (register the catalog channel)
- Create: `apps/pos/src/hooks/useCatalogChannel.ts` (subscribe + handle events)
- Modify: `apps/pos/src/App.tsx` or `AppShell.tsx` (mount the hook once per active company)
- Tests: backend Feature tests for event broadcast + frontend test for the hook.

**Spec (high-level):**

1. **Server:** when `Product` is created/updated/deleted, broadcast `ProductCatalogChanged` on private channel `tenant.{tenantId}.company.{companyId}.catalog`. Payload contains an action discriminator (`create` / `update` / `delete`) and the affected entity id. Same for MenuCategory + MenuCategoryItem.

2. **POS:** `useCatalogChannel` subscribes once per active company. On event:
   - For Menu tenants: call `productStore.fetchProducts(true)` (which goes through the Menu fetch branch and reconciles via `reconcileMenuProducts`).
   - For non-Menu: call `productStore.fetchProducts(true)` (which uses the standard pull).
   - Polling fallback (60 s tick) continues as today; WebSocket reduces the latency from 60 s to ~real-time.

3. **Hook lifecycle:**
   - Subscribe when `useAuthStore.companyId` and `useAuthStore.user.tenantId` are set.
   - Unsubscribe on logout / company switch.
   - Reconnect on connectivityStore transitions.

**Expected Codex rounds:** 3-5. Backend broadcasting is mechanical; the POS hook's lifecycle vs. logout/reconnect/connectivity drift could surface 2-3 P2s.

---

## Out of scope (deferred to other workstreams)

1. **Performance work.** Pagination (T2.1 — Phase 3 paginated catalog warmup), virtualization, image caching. Tracked in `project_pos_performance.md` memory note.
2. **Design / UX polish.** Stranded-receipt operator/admin UI, SyncButton state-transition animations, any other Tier 3 cosmetic items.
3. **Audit-phase gates.** T2.3 / Phase 6 release-gate items.
4. **Tenant-isolation sweep.** Separate workstream.
5. **The C2 migration follow-ups (r11 + v31 ordering).** Tracked in `docs/superpowers/plans/2026-05-11-pos-c2-migration-followup-handoff.md`.

---

## Cross-references

- PR #92 (DRAFT release vehicle): https://github.com/otospexsolutions/erp/pull/92
- POS production-readiness handoff (sibling): `docs/superpowers/plans/2026-05-11-pos-production-readiness-handoff.md`
- C2 cluster review trails: `docs/superpowers/reviews/2026-05-1{0,1}-pos-c2-*-codex-rounds.md`
- T2.4 cluster review trails: `docs/superpowers/reviews/2026-05-1{0,1}-pos-t2.4-*-codex-rounds.md`
- C2 cart-line migration review trail (PR #118): `docs/superpowers/reviews/2026-05-11-pos-c2-bare-cart-migration-codex-rounds.md`

---

## What I want Opus to do per PR

Pre-merge review covering:
1. The PR body's L1/L8/L9 audit is complete and matches the actual diff.
2. Every Codex review round finding was either closed or explicitly deferred.
3. No regression in lint baseline (41 warnings) or test count.
4. The fix actually addresses the root cause (not just the symptom).
5. For PR A specifically: confirm the recovery SQL is safe and the WAL switch is verified in a real Tauri environment, not just unit-mocked.
6. For PR C specifically: the destructive recovery actions are appropriately gated (manager PIN) and audit-logged.
