# POS Local Storage Durability Audit — Opus

> Date: 2026-04-30
> Scope: Tauri POS desktop app (`apps/pos/`) — durability of SQLite, Tauri Store, and offline-receipt queue across crash, suspend/resume, power loss, SIGKILL, full-disk, and SQLite corruption.
> Method: static read of source + plugin internals (`tauri-plugin-sql 2.3.2`, `@tauri-apps/plugin-store 2.4.2`, sqlx-sqlite 0.8.6).
> Companion audit: `2026-04-30-pos-offline-first-audit-claude.md`, Phase 5 of `plans/2026-04-30-pos-offline-first-hardening.md`.

---

## Executive Summary — Top 5 Durability Findings

1. **D1 — No explicit SQLite PRAGMAs anywhere.** `journal_mode`, `synchronous`, `busy_timeout`, `wal_autocheckpoint` are all left at sqlx-sqlite defaults (WAL + Full sync + 5 s busy timeout). The defaults are reasonable, but the app is one transitive dependency upgrade away from silently flipping to `journal_mode=DELETE` or `synchronous=NORMAL`. Verifiable: zero `PRAGMA journal_mode|synchronous` calls in `apps/pos/src/` or `apps/pos/src-tauri/src/`. Severity **P1** (latent regression).
2. **D2 — `scheduleDebouncedSync()` is invoked inside an uncommitted transaction.** `insertOfflineReceipt` (`offlineReceiptRepository.ts:88–91`) fires the 250 ms sync trigger BEFORE `createOfflineReceipt` reaches `COMMIT` (`receiptService.ts:216–224`). In practice the 250 ms timer wins the race against the rest of the JS turn, but it is a timing contract, not a correctness contract. The Phase 5 hardening plan already lists this (Step 5.1). Severity **P2** for crash-safety; **P3** in steady state.
3. **D3 — Tauri Store writes are debounced 100 ms; `save()` is never called explicitly.** `setStoredValue` (`storage.ts:54–64`) calls `s.set(...)` and returns. `auto_save: 100ms` is the plugin default (`@tauri-apps/plugin-store 2.4.2/dist-js/index.d.ts:13–16`). A SIGKILL within 100 ms of `setStoredValue('current_shift', …)` or `setStoredValue('terminal', …)` loses that write. The encrypted token write at `storage.ts:55–60` is the most exposed (no fsync between `set()` and the IPC return). Severity **P2** (operational), **P3** (data-loss). Actual scenario: shift open + SIGKILL → user loses their open shift state.
4. **D4 — Idempotency key is generated in-memory and only persisted as part of the same uncommitted transaction.** `crypto.randomUUID()` at `receiptService.ts:151` lives only in JS heap until the INSERT lands at `offlineReceiptRepository.ts:60–86`, which is itself inside the `BEGIN…COMMIT` from `receiptService.ts:216–224`. **Cart store is not persisted** (`cartStore.ts` has no `persist` middleware; verified — only `customerDisplayStore`, `printerStore`, `settingsStore`, `cashDrawerStore`, `scannerStore` use `persist`). Therefore: a SIGKILL between `executeCheckout` payment-button click and COMMIT loses both the receipt AND the cart, so no double-bill on retry (the old key is dead). **However:** if COMMIT lands but the success modal/print never fires (Tauri killed in the millisecond after COMMIT, before UI repaints), the cashier sees the cart still on screen / re-rings the sale → fresh UUID → server stores both copies on next sync. Severity **P1** for a cash-paid no-receipt scenario; **P2** for card. Mitigation today is purely operational.
5. **D5 — No corruption recovery, no backup, no integrity self-check.** DB lives at `app_config_dir()/izipos-{companyId}.db` (resolved by `tauri-plugin-sql-2.3.2/src/wrapper.rs:79–86`). There is no `PRAGMA integrity_check` on open, no `.bak` rotation, no shadow copy of `terminal_state` outside the DB, and no documented cashier-facing recovery flow. If the file becomes unusable mid-shift, the terminal loses its hash chain seed (`terminal_state.last_hash`) — cannot create offline receipts (`receiptService.ts:108–111` throws). Z-reports, queued offline receipts, and PIN updates are also gone. Severity **P1** for a single-machine-deployment shop.

---

## 1. SQLite Journal Mode + Sync Mode

**Files**:
- `apps/pos/src/lib/db.ts:1–94`
- `apps/pos/src/lib/db/migrations.ts` (entire file)
- `apps/pos/src-tauri/Cargo.toml:17` (`tauri-plugin-sql = { version = "2", features = ["sqlite"] }`)
- `apps/pos/src-tauri/src/lib.rs:9` (`.plugin(tauri_plugin_sql::Builder::new().build())`)
- Plugin internals: `tauri-plugin-sql-2.3.2/src/wrapper.rs:78–92`

**What is configured explicitly**: nothing. The Tauri side calls `tauri_plugin_sql::Builder::new().build()` with zero customization (`lib.rs:9`). The JS side calls `Database.load(\`sqlite:${dbName}\`)` (`db.ts:20`) and runs `runMigrations(db)` which only issues schema DDL — there is no `PRAGMA` execution at boot.

**What you get implicitly**: `Pool::connect(conn_url)` (`wrapper.rs:91`) parses only the URL, then connects with `SqliteConnectOptions::default()`. As of sqlx-sqlite 0.8.6, that default is:

| PRAGMA | Implicit value |
| --- | --- |
| `journal_mode` | **WAL** |
| `synchronous` | **FULL** |
| `foreign_keys` | ON |
| `busy_timeout` | 5000 ms |
| `auto_vacuum` | NONE |
| `wal_autocheckpoint` | 1000 pages (SQLite default) |

**Durability against power loss**: WAL + synchronous=FULL means SQLite calls `fsync` on the WAL after every commit AND `fsync`s on every checkpoint. This is the safe configuration for fiscal data. A power loss between BEGIN and COMMIT loses the in-flight transaction (correct). A power loss after a successful COMMIT is durable (correct). WAL recovery on next open is automatic.

**Risks (despite safe defaults)**:
- **Latent regression**: future `tauri-plugin-sql` or sqlx may flip a default. There is no integration test asserting `PRAGMA journal_mode == 'wal'` and `PRAGMA synchronous == 2`. The migrations integration test (`migrations.integration.test.ts`) does not assert PRAGMAs.
- **`-shm` and `-wal` sidecar files** must travel with the `.db` file. Any backup/copy script that picks up only `*.db` will silently lose committed-but-uncheckpointed data. (No backup script exists today, but if ops adds one this becomes a footgun.)
- **`wal_autocheckpoint=1000` pages** ≈ 4 MB — fine, but on a crashy laptop with frequent SIGKILLs the WAL can grow large. No upper bound is set.
- **Cross-platform fsync semantics**: macOS `fsync` is famously not a true full barrier without `F_FULLFSYNC`. SQLite calls `F_FULLFSYNC` only when `synchronous=EXTRA`, not `FULL`. On macOS POS hardware (we ship Tauri on macOS for some Otospex sites — see `tauri.conf.json` icons), a hard power-loss can lose a *committed* transaction in rare cases. This is a SQLite-on-macOS limitation, not specific to us, but worth noting.

**Verdict**: defaults are fine, lack of explicit assertion is the real risk. **D1, P1.**

---

## 2. Transactional Write Boundaries

**Files**:
- `apps/pos/src/lib/offline/receiptService.ts:101–237`
- `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:60–91`
- `apps/pos/src/lib/db/repositories/terminalStateRepository.ts:171–213`

**The transaction**:
```ts
// receiptService.ts:216–224
await db.execute('BEGIN TRANSACTION');
try {
  await insertOfflineReceipt(db, offlineReceipt);   // INSERT into offline_receipts + scheduleDebouncedSync()
  await advanceHashChain(db, terminalId, fiscalHash, newSequence);  // UPDATE terminal_state
  await db.execute('COMMIT');
} catch (error) {
  await db.execute('ROLLBACK');
  throw error;
}
```

This atomically advances `offline_receipts` and `terminal_state.last_hash` together — the right design: a half-written receipt without a chain advance, or vice versa, would brick the chain.

**The bug**:

`insertOfflineReceipt` calls `scheduleDebouncedSync()` at line 90 of `offlineReceiptRepository.ts` — **inside the open transaction**. The sequence is:

1. `BEGIN TRANSACTION`
2. `INSERT INTO offline_receipts …` (uncommitted)
3. `scheduleDebouncedSync()` queues a 250 ms timeout
4. `advanceHashChain(...)` (uncommitted)
5. `COMMIT`
6. … at some point ≥ 250 ms after step 3, the timer fires `useSyncStore.getState().triggerSync()` → `syncScheduler.syncNow()` → `runFullSync()` → `pushOfflineReceipts()` → `getPendingReceiptsForSync()` SELECT.

**What can go wrong**:

- **Same-connection visibility**: `tauri-plugin-sql` uses an `sqlx::Pool` (`wrapper.rs:91`). Each `db.execute`/`db.select` checks out a connection from the pool. SQLite WAL mode allows concurrent readers + one writer per *connection*, but here the JS-side `Database` object holds a single pool that may give different physical connections to interleaving calls. If the timer-driven `getPendingReceiptsForSync` runs on a different connection from the one holding the open `BEGIN`, the SELECT will not see the uncommitted INSERT — fine, no double-push. **But** if the pool reuses the same connection (typical for a `max_connections=1` setup, which is sqlx-sqlite's default to avoid lock contention), the SELECT runs *inside* the still-open transaction, sees the uncommitted INSERT, and tries to POST it to the server. If the original transaction then ROLLBACKS, the server has accepted a receipt that the local DB rejected. Hash chain mismatch, server thinks sequence N exists, local thinks it doesn't.
- **Today, the 250 ms timer almost always wins the race against the COMMIT**: `advanceHashChain` is a single UPDATE plus a SELECT (`terminalStateRepository.ts:177–204`), and the COMMIT is a single fsync. Total ≪ 250 ms in normal operation. So in practice the bug doesn't fire. But under heavy load (1000-row WAL checkpoint, slow disk, kernel I/O pressure), the timer can fire before COMMIT.
- **The 250 ms is an undocumented contract**, not a correctness guarantee.

**Phase 5.1 fix is correct**: move `scheduleDebouncedSync()` out of `insertOfflineReceipt` and into `createOfflineReceipt` after `await db.execute('COMMIT')`. The repository should not be the one triggering side effects.

**Other transactions**:
- `zReportService.ts:334` — Z-report close also wraps writes in BEGIN/COMMIT. Did not deep-read; recommend the audit follow-up include it.
- `migrations.ts:462` — migration 21 (TND precision widening) runs in a transaction; correct.

**Verdict**: D2, P2. Race is real but rare. Fix is small and already planned.

---

## 3. Tauri Store Usage

**Files**:
- `apps/pos/src/lib/storage.ts` (entire file, 75 lines)
- Plugin: `@tauri-apps/plugin-store@2.4.2/dist-js/index.d.ts`
- Backing crate: `tauri-plugin-store@2`

**File format**: a single JSON file at `app_config_dir()/izipos-settings.json`. NOT a database — no transactions, no journaling, no atomicity beyond what the Rust plugin does on save (typically: write to temp + rename, but this is implementation-dependent).

**Atomicity guarantees**:
- `set/get/delete/clear` are in-memory only (`storage.ts:62`); they update the in-process map maintained by the plugin.
- Persistence to disk is via `auto_save` debounce — **default 100 ms** (`plugin-store/dist-js/index.d.ts:13–16`). No explicit `s.save()` is ever called in `storage.ts` or anywhere else (`grep save\(\) /apps/pos/src/lib/storage.ts /apps/pos/src/stores/` returns nothing).
- A SIGKILL within 100 ms of a `set(...)` loses that write. Multiple sets in quick succession are coalesced.
- File-write atomicity: tauri-plugin-store v2 writes via Rust `serde_json::to_writer` then file replace; on most platforms this is a tmpfile + rename, which is atomic at the FS level. So a *partial* JSON corruption is unlikely; *missing the most recent write* is the realistic failure.

**Keys persisted** (from `storage.ts:14–24`):
- `auth_token` — encrypted via `encrypt_secret` Tauri command (`storage.ts:55–60`). On SIGKILL within 100 ms of login, token write is lost — user must re-login. Acceptable.
- `server_url` — set during onboarding. One-time write. Risk minimal.
- `user`, `companies`, `company_id` — written at login. One-time per session.
- `terminal` — terminal pairing artifact. Written during pairing; if SIGKILL within 100 ms, pairing is lost and operator must re-pair. **P2 operationally.**
- `pending_terminal_id` — short-lived.
- `current_shift` — **the riskiest key**. Updated when shift opens, when shift closes, possibly during the shift. SIGKILL between shift-open and 100 ms-debounce flush means on restart, app reads no shift, cashier opens a new shift, the old shift never closes — fiscally awkward (Z-report won't aggregate the missing receipts unless we resolve via `terminal_state` cumulative counters).

**Encryption mid-write**: `encrypt_secret` is a Tauri IPC call (`storage.ts:34, 58`). The full token-write sequence is:
1. JS calls `invoke('encrypt_secret', {plaintext})` — Rust returns ciphertext.
2. JS calls `s.set('auth_token', encrypted)` — in-memory.
3. 100 ms later, plugin auto-saves.

A kill between (2) and (3) loses the new token but leaves the old one — mostly safe, but if the user JUST logged in fresh, they appear logged-out on next launch. Acceptable.

**Recommendation**:
- For **`current_shift`** specifically, after `setStoredValue` call `s.save()` explicitly OR migrate the shift to SQLite (where the receipt queue already lives, durability-correlated).
- Add a startup self-check: if `izipos-settings.json` is missing or unreadable, surface a one-time "settings reset" warning to the cashier rather than silently treating the app as freshly-installed.

**Verdict**: D3, P2 for `current_shift`, P3 for everything else.

---

## 4. Idempotency Key Generation + Persistence

**Files**:
- `apps/pos/src/lib/offline/receiptService.ts:150–151, 168, 216–224`
- `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:60–91, 159–168`
- `apps/pos/src/stores/cartStore.ts` (no persist middleware)

**Lifecycle**:
1. `executeCheckout` → `offlineCheckout` → `createOfflineReceipt`.
2. `crypto.randomUUID()` at `receiptService.ts:151` generates the key.
3. Key is set on the `offlineReceipt` object at line 168.
4. `BEGIN TRANSACTION` at line 216.
5. `insertOfflineReceipt` writes the row including `idempotency_key` (`offlineReceiptRepository.ts:64–86`, parameter $2). Column has `UNIQUE` constraint (`migrations.ts:93`).
6. `advanceHashChain` writes new last_hash + sequence.
7. `COMMIT` (or `ROLLBACK` on error).

**Crash scenarios**:

| When killed | Receipt? | Idempotency key? | Cart? | Customer state | Risk |
| --- | --- | --- | --- | --- | --- |
| Between cart-add and pay-button | n/a | Not yet generated | In-memory (lost) | No money exchanged | None |
| After UUID gen, before BEGIN | No (rolled back implicitly — never wrote) | Lost | Lost | No receipt printed, no charge committed locally | None — cashier re-rings |
| Inside transaction, before COMMIT | No (ROLLBACK on relaunch via WAL recovery) | Lost | Lost | Same as above | **None for cash, P2 for card** — see below |
| Between COMMIT and "show success modal / print receipt" | Yes (committed locally) | Persisted | Lost | Cashier sees no receipt, re-rings | **P1 — double-bill on next sync** |
| After modal shown, before sync | Yes | Persisted | Cleared | Cashier moves on | None |

**The double-bill path**:
- Cashier rings €50 cash sale, customer hands over €50, cashier taps "Pay".
- `createOfflineReceipt` runs, COMMIT lands at t=0.
- t=0+ε: Tauri killed (OS update, kernel panic, manual force-quit, OS Out-Of-Memory-Killer on a low-end terminal).
- Cashier sees POS reload to a fresh cart. Customer is still standing there. Cashier re-rings €50.
- New `crypto.randomUUID()` → new receipt number → new local row.
- Network resumes. `pushOfflineReceipts` runs. **Both receipts sync** with different `idempotency_key`s. Server has two valid receipts. Customer charged twice on the books (cash drawer is fine, but the NF525 audit trail and the Z-report cumulative_sales are doubled).

**Card variant is worse**: if the original payment was already authorized through the PSP integration in `processReceiptPayments`, the customer's card was charged once, the local DB has a receipt, and now the cashier authorizes a second card charge.

**Why this is hard to fix completely**: the cart is intentionally not persisted because resuming a half-rung cart after a crash has its own UX horrors (stale prices, modifier expiry, customer left). But there's a middle ground:
- After COMMIT, write the receipt's `idempotency_key` + cashier-visible `receipt_number` to a **last-success marker** in the Tauri Store (or a dedicated SQLite `last_committed_receipt` row), with `created_at`.
- On app boot, if there's a marker < 60 s old that hasn't been "acknowledged" (by the success modal showing), surface a banner: "Last sale €50 cash to receipt POS-001-2026-00012345 was committed locally — verify with customer before re-ringing."
- This is the same duplicate-detection logic POS systems have used for 40 years. We just don't have it.

**Verdict**: D4, **P1** for cash-go-live readiness on terminals that crash. Note: the Phase 5 plan does not currently address this — it is a new finding.

---

## 5. Database File Location + Corruption Recovery

**Resolution**:
- `tauri-plugin-sql-2.3.2/src/wrapper.rs:79–86` calls `app.path().app_config_dir()`.
- `db.ts:20` passes `sqlite:izipos-${companyId}.db` to `Database.load`.
- `path_mapper(app_path, conn_url)` (referenced at `wrapper.rs:86`) joins the two.

**Per-OS path** (assuming bundle identifier `com.syneriva.izipos` from `tauri.conf.json:5`):
- **macOS**: `~/Library/Application Support/com.syneriva.izipos/izipos-{companyId}.db`
- **Windows**: `%APPDATA%\com.syneriva.izipos\izipos-{companyId}.db` (i.e., `C:\Users\<user>\AppData\Roaming\com.syneriva.izipos\`)
- **Linux**: `~/.config/com.syneriva.izipos/izipos-{companyId}.db`
- WAL/SHM sidecars live alongside: `izipos-{companyId}.db-wal`, `izipos-{companyId}.db-shm`.

**Backup**: none. No `.bak` rotation, no scheduled SQLite backup API call, no shadow copy of `terminal_state` (the most precious row in the system — `last_hash`, `genesis_seed`, `cumulative_*` counters drive the entire fiscal chain).

**Corruption recovery story**: there is none. If the file becomes corrupt:
- Next `Database.load` either succeeds (sqlx is somewhat resilient) or throws.
- The migrations re-run path checks `_migrations` table — if that's gone, all schema migrations are re-applied to existing data, which can fail on `CREATE TABLE IF NOT EXISTS` collisions for partially-extant tables.
- `getTerminalState` returns null → `createOfflineReceipt` throws `Terminal hash chain not initialized` (`receiptService.ts:109–110`) → cashier cannot ring sales.
- All queued offline receipts are gone.
- All Z-reports not yet pushed are gone.
- All offline cash-drawer ops are gone.
- All queued PIN updates are gone.

**What recovery looks like today**: cashier deletes the `.db` file, app re-bootstraps from server (`pullTerminalState`, `pullProducts`, etc.), cumulative counters are repaired by `pullZChainState`'s skip-if-server-zero protection (`syncService.ts:582–608`). Lost receipts that were only local are simply lost.

**Recommendation** (in priority order):
1. **`PRAGMA integrity_check` on every app start**, before any writes. Fail loudly with a "POS data may be corrupt — contact support" modal rather than silently continuing.
2. **Daily SQLite Online Backup API snapshot** (`sqlite3_backup_init`). Tauri Store can hold the last-known-good backup path. Even just one rotating snapshot at `app_config_dir()/izipos-{companyId}.db.bak` would be a 5-line Rust command.
3. **Mirror `terminal_state` to Tauri Store** on every advance. It's a single row, ~500 bytes. Loss of just this row is what bricks a terminal — protecting it is cheap.
4. **Document** the recovery procedure in cashier-facing terms: "If POS won't start, run X" — today, support has to know to delete the file.

**Verdict**: D5, P1 for any single-machine deployment without IT support.

---

## 6. Offline Receipt Queue Durability

**Lifecycle (success path)**:

1. **Create** (`receiptService.ts:101–237`):
   - Compute fiscal hash from in-memory `terminalState`.
   - `BEGIN TRANSACTION`.
   - `INSERT` into `offline_receipts` with `status='pending'`, `synced_at=NULL`, `sync_error=NULL`, `retry_count=0`.
   - `UPDATE terminal_state SET last_hash=…, hash_sequence=…`.
   - `COMMIT` (atomic — both rows or neither).
   - With sqlx-sqlite WAL + synchronous=FULL defaults: an `fsync` is called on the WAL file before COMMIT returns. **This is the durability point.** A power-loss after this returns is recoverable.

2. **Schedule sync** (`offlineReceiptRepository.ts:88–91`):
   - `setTimeout(250 ms)` queues `useSyncStore.getState().triggerSync()`.
   - **Bug**: this happens BEFORE the COMMIT — see §2.

3. **Sync trigger** (`syncScheduler.ts:59–126` → `syncService.ts:166–266`):
   - Guarded by `isSyncing` (no parallel pushes).
   - For each pending receipt (ordered by `hash_sequence ASC`):
     - `UPDATE offline_receipts SET status='syncing'`.
     - `POST /pos/receipts/sync` with the payload from `receiptToPayload`.
     - On success: `UPDATE … status='synced', synced_at=now(), server_receipt_id=…`.
     - On `chain_broken`: `incrementRetryCount`, `UPDATE … status='failed'`, halt loop.
     - On other failure: `incrementRetryCount`, `UPDATE … status='failed'`, continue.

4. **Restart resilience**:
   - On Tauri relaunch, `useSyncStore.getState().scheduler.start()` runs (`SyncScheduler.start` triggers an immediate `tick()`).
   - `tick()` calls `runFullSync` → `pushOfflineReceipts` → `getPendingReceiptsForSync` which selects `WHERE status IN ('pending', 'failed') AND retry_count < 5`.
   - **Receipts left in `'syncing'` state are NOT re-tried.** This is a real bug: if Tauri crashes between `updateReceiptStatus(db, id, 'syncing')` (`syncService.ts:180`) and the `apiPost` returning, the row is stuck at `status='syncing'` forever and the receipt is silently dropped from the sync queue.

   **Mitigation needed**: at app start, run `UPDATE offline_receipts SET status='pending' WHERE status='syncing'` — a "stale syncing" reset. Or change the SELECT to include `'syncing'`. Or — best — never set `'syncing'` at all, since it adds nothing for the single-pusher case (we already serialize via `isSyncing`).

5. **Idempotency on retry**: server-side dedupe by `idempotency_key` (`migrations.ts:93` UNIQUE; assumed enforced server-side too). Same key → server returns `'duplicate'` → `updateReceiptStatus(db, id, 'synced')`. OK.

6. **Cleanup**: `cleanupSyncedReceipts` deletes synced rows older than 30 days (`offlineReceiptRepository.ts:170–174`). `cleanupStuckReceipts` deletes failed rows older than 90 days when retry_count ≥ 5 (`offlineReceiptRepository.ts:177–185`). **The 5-attempt cap with no operator surface is itself a P2 — receipts that fail 5 times for any reason (server bug, malformed payload, transient persistent network-routing issue) become dark debt that nobody knows about until 90 days later when they're deleted.**

**`'syncing' ghost row` is the new finding**:
- File: `apps/pos/src/lib/sync/syncService.ts:180`
- File: `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:131–139`
- Severity **P1** — silent receipt loss on Tauri kill mid-sync.

**Verdict**: queue is durable for the steady-state crash (kill before sync starts, kill between syncs). It is **NOT** durable for the kill-mid-sync case.

---

## 7. Crash-Safety Regression Test Matrix

Tests below are sketched — orchestrator writes them. Each is a unit test that would fail against the current code and pass after the corresponding fix. All should live in `apps/pos/src/lib/__tests__/durability.test.ts` (new file) unless noted.

### T1 — `assertWalAndFullSyncOnBoot`
- **File**: `apps/pos/src/lib/db/__tests__/pragmas.integration.test.ts` (new)
- **Setup**: spin up a real Tauri-mock SQLite via the project's existing test adapter (`db/__tests__/helpers/sqliteTestAdapter.ts`).
- **Assert**:
  - `SELECT * FROM pragma_journal_mode` returns `wal`.
  - `SELECT * FROM pragma_synchronous` returns `2` (FULL).
  - `SELECT * FROM pragma_busy_timeout` returns ≥ 5000.
- **Why**: pins the implicit defaults so a future sqlx upgrade can't silently flip them.

### T2 — `scheduleDebouncedSyncFiresAfterCommit`
- **File**: `apps/pos/src/lib/offline/__tests__/receiptService.test.ts` (extend)
- **Setup**: spy on `db.execute` and on `useSyncStore.getState().triggerSync`. Use `vi.useFakeTimers()`.
- **Assert**:
  - During `createOfflineReceipt`, capture the order of execute calls.
  - The COMMIT call happens BEFORE the first call to `triggerSync` after advancing timers by 250 ms.
- **Why**: locks down the Phase 5.1 fix.

### T3 — `staleSyncingResetOnBoot`
- **File**: new `apps/pos/src/lib/sync/__tests__/staleSyncingReset.integration.test.ts`
- **Setup**: insert a receipt with `status='syncing'` directly. Re-instantiate `SyncScheduler`.
- **Assert**: after `start()`, the receipt is back to `status='pending'` and gets attempted.
- **Why**: protects against the kill-mid-sync ghost row described in §6.

### T4 — `idempotencyKeyPersistsBeforeUserVisibleSuccess`
- **File**: new `apps/pos/src/lib/offline/__tests__/idempotencyDurability.test.ts`
- **Setup**: mock the post-COMMIT path to throw or not-call the success modal.
- **Assert**:
  - After `createOfflineReceipt` returns, `getReceiptByIdempotencyKey(db, key)` returns the row.
  - A "last-committed receipt" record is written to a new `last_committed_receipt` table or Tauri Store key.
- **Why**: pins the duplicate-detection scaffolding once it's added (§4 recommendation 1 of D4).

### T5 — `currentShiftSurvivesSimulatedSigkill`
- **File**: new `apps/pos/src/lib/__tests__/storageDurability.test.ts`
- **Setup**: mock `@tauri-apps/plugin-store` to expose its in-memory map and the auto-save timer.
- **Assert**:
  - After `setStoredValue('current_shift', shift)`, calling a new helper `flushStorage()` calls the underlying `s.save()`.
  - Simulating "kill before auto-save" by destroying the in-memory map produces a recoverable state for `current_shift` (because we explicitly saved).
- **Why**: pins the §3 D3 recommendation (explicit `save()` for shift writes).

### T6 — `transactionRollbackLeavesNoOrphans`
- **File**: extend `apps/pos/src/lib/offline/__tests__/receiptService.test.ts`
- **Setup**: mock `advanceHashChain` to throw AFTER `insertOfflineReceipt` succeeded.
- **Assert**:
  - `db.execute` calls include both `BEGIN TRANSACTION` and `ROLLBACK`.
  - Post-rollback, `getOfflineReceiptById(db, id)` returns null.
  - `getTerminalState(db, terminalId)` returns the original `last_hash` (unchanged).
- **Why**: existing test covers ROLLBACK is called; this one verifies the rollback actually rolls back data (not just emits the SQL).

### T7 — `failedReceiptRetryDoesNotDuplicateOnServer`
- **File**: extend `apps/pos/src/lib/sync/__tests__/syncService.test.ts`
- **Setup**: mock `apiPost` to return `'duplicate'` on first call (simulating server already saw this idempotency_key).
- **Assert**:
  - `updateReceiptStatus(db, id, 'synced')` is called.
  - `setServerReceiptId` is called with the receipt_id from the duplicate response.
  - No double-row is inserted locally.
- **Why**: protects the duplicate-detection contract on the server-roundtrip.

### T8 — `dbIntegrityCheckOnBootFailsLoudly`
- **File**: new `apps/pos/src/lib/db/__tests__/integrityCheck.integration.test.ts`
- **Setup**: feed a deliberately corrupted SQLite file via the test adapter.
- **Assert**:
  - `getDatabase` (extended to run `PRAGMA integrity_check`) throws a structured `DbCorruptionError`.
  - The error includes the corruption details.
- **Why**: pins the §5 D5 recommendation 1.

### T9 — `terminalStateMirroredToStore`
- **File**: new `apps/pos/src/lib/db/__tests__/terminalStateMirror.test.ts`
- **Setup**: spy on Tauri Store `set`.
- **Assert**:
  - After `advanceHashChain`, the Tauri Store is called with `terminal_state_mirror.last_hash` and the new hash.
- **Why**: pins §5 D5 recommendation 3.

### T10 — `staleRetryDoesNotResetSequence`
- **File**: extend `apps/pos/src/lib/db/__tests__/migrations.integration.test.ts` or new
- **Setup**: insert a receipt at sequence=10. Manually set terminal_state.hash_sequence=10. Now simulate a relaunch + crash + relaunch where a `pullTerminalState` returns sequence=8.
- **Assert**:
  - `pullTerminalState` logs `[fiscal] preserved local state` (`syncService.ts:516–530`).
  - `terminal_state.hash_sequence` remains 10.
- **Why**: Already largely tested via `pullZChainState.integration.test.ts`, but the receipt-chain (not Z-chain) version of the regression guard deserves its own test.

---

## 8. Reconciliation vs Phase 5 (Crash Safety) of Offline-First Hardening

| Phase 5 step | This audit's verdict |
| --- | --- |
| **5.1 — Move `scheduleDebouncedSync` past COMMIT** | **Confirmed P2.** See §2 D2. The 250 ms debounce makes the bug rare in practice but it is a correctness contract masquerading as a timing one. Fix is correct; add T2 to lock it. |
| **5.2 — Fingerprinted regression tests** | The four tests Phase 5.2 names (paymentStore refresh, login token persistence, pendingReceiptCount source, request<T> abort) are unrelated to durability. **This audit recommends adding T1, T2, T3 to that batch** — they are equally cheap and cover the durability gaps. T4–T10 are a follow-up wave once D4/D5 fixes are scoped. |
| **5.3 — TODO sweep** | Add explicit TODOs at: `receiptService.ts:151` (idempotency key persistence — D4), `db.ts:20` (PRAGMA assertions — D1), `storage.ts:55` (explicit `save()` for shift — D3). |

**Phase 5 should be widened to include**:

- **D4** (idempotency double-bill on post-COMMIT crash) — currently uncovered by Phase 5. **Most user-visible defect of the audit.** Recommend dedicated step 5.4: add `last_committed_receipt` marker + boot-time "verify-with-customer" banner.
- **D5** (corruption recovery) — currently uncovered. Recommend dedicated step 5.5: PRAGMA integrity_check on boot + nightly online-backup snapshot. May exceed Phase 5 timebox; flag for Phase 6 follow-up.
- **§6 'syncing' ghost row** — currently uncovered. Cheap, ~3-line fix in `syncService.ts:180` plus T3.

---

## Findings Index

| ID | Severity | One-liner | Locations |
| --- | --- | --- | --- |
| D1 | P1 (latent) | No explicit PRAGMA journal_mode/synchronous/busy_timeout — relies on sqlx defaults | `db.ts:20`, `lib.rs:9`, `wrapper.rs:91` |
| D2 | P2 | `scheduleDebouncedSync()` fires from inside an open transaction | `offlineReceiptRepository.ts:88–91`, `receiptService.ts:216–224` |
| D3 | P2 (`current_shift`), P3 (other) | Tauri Store auto-save 100ms debounce; no explicit `save()` calls | `storage.ts:54–64`, `plugin-store@2.4.2/dist-js/index.d.ts:13–16` |
| D4 | P1 (cash & card) | Idempotency key not persisted outside the receipt row → re-ring after post-COMMIT crash double-bills | `receiptService.ts:151, 168, 216–224`, `cartStore.ts` (no persist) |
| D5 | P1 (single-machine) | No corruption recovery, no backup, no integrity check, no `terminal_state` mirror | `wrapper.rs:79–86`, `db.ts:20–26`, no recovery code |
| §6 ghost | P1 | Receipts left in `status='syncing'` after Tauri crash mid-sync are never retried | `syncService.ts:180`, `offlineReceiptRepository.ts:131–139` |
| §6 cap | P2 | `MAX_SYNC_RETRIES=5` deletes after 90 d with no operator surface | `offlineReceiptRepository.ts:129, 177–185` |

---

## Suggested Remediation Order

1. **Cheap and obvious wins** (≤ 1 day):
   - Stale-`syncing` reset on boot (§6 ghost) + T3.
   - Move `scheduleDebouncedSync` past COMMIT (D2 / Phase 5.1) + T2.
   - Explicit `s.save()` for `current_shift` writes (D3) + T5.
   - Add explicit PRAGMA assertions to startup, fail loudly if defaults drift (D1) + T1.
2. **Medium effort, high value** (2–4 days):
   - `last_committed_receipt` marker + boot-time "verify with customer" banner (D4) + T4.
   - `PRAGMA integrity_check` on every app start (D5 #1) + T8.
   - Mirror `terminal_state` to Tauri Store on every advance (D5 #3) + T9.
3. **Larger** (≥ 1 week):
   - Daily online-backup snapshot (D5 #2) + smoke-test that backup is restorable.
   - Operator-facing UI for stuck receipts (§6 cap), so the 5-retry deadlock surfaces before deletion.
   - A choice point on `current_shift`: keep in Tauri Store with explicit save, or migrate to SQLite. The latter aligns with the rest of fiscal state.

---

## Method Notes

- All citations verified by direct file read (`Read` tool) or grep (`Bash`). No assumptions imported from prior conversations or memory.
- sqlx-sqlite default values cross-checked against `tauri-plugin-sql-2.3.2/src/wrapper.rs` and the published sqlx-sqlite 0.8.6 behavior.
- Plugin-store auto-save behavior cross-checked against `@tauri-apps/plugin-store@2.4.2/dist-js/index.d.ts:13–16`.
- No code was modified during this audit.
