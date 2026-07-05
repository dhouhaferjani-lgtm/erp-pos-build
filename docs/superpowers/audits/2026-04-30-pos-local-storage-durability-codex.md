# POS Local Storage Durability Audit - 2026-04-30 (Codex)

Scope: `apps/pos` Tauri desktop local storage across Tauri crash, restart, OS suspend/resume, mid-write power loss, mid-checkout SIGKILL, full disk, and corrupted SQLite.

## Executive Summary

1. `P1` SQLite durability depends on transitive `sqlx-sqlite` behavior, not POS-owned configuration. The app never issues `PRAGMA journal_mode` or `PRAGMA synchronous`; new DBs are created as WAL only because `sqlx-sqlite 0.8.6` sets hidden `CREATE_DB_WAL=true`, and `synchronous` is SQLite default `FULL`. Existing DBs are not asserted or repaired. (`apps/pos/src/lib/db.ts:7-25`, `apps/pos/src-tauri/src/lib.rs:8-20`, `/Users/houssamr/.cargo/registry/src/index.crates.io-1949cf8c6b5b557f/tauri-plugin-sql-2.3.2/src/wrapper.rs:77-91`, `/Users/houssamr/.cargo/registry/src/index.crates.io-1949cf8c6b5b557f/sqlx-sqlite-0.8.6/src/lib.rs:123-125`, `/Users/houssamr/.cargo/registry/src/index.crates.io-1949cf8c6b5b557f/sqlx-sqlite-0.8.6/src/migrate.rs:20-35`, `/Users/houssamr/.cargo/registry/src/index.crates.io-1949cf8c6b5b557f/sqlx-sqlite-0.8.6/src/options/mod.rs:177-189`)
2. `P1` A crash during receipt sync can strand rows forever in `status='syncing'`. `pushOfflineReceipts()` marks a receipt `syncing` before POST, but restart selection only includes `pending` and `failed`, so SIGKILL/power loss between those points drops the receipt out of the replay queue. (`apps/pos/src/lib/sync/syncService.ts:224-323`, `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:138-145`)
3. `P1` The receipt insert/hash-chain/voucher update is transactional, but `insertOfflineReceipt()` schedules sync before the outer `COMMIT`. The 250 ms debounce usually masks it; correctness still depends on timing. (`apps/pos/src/lib/offline/receiptService.ts:401-463`, `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:66-98`)
4. `P1` Tauri Store is a debounced JSON-file store, not a database. POS persists auth, company, terminal pairing, pending terminal, and current shift there; `set()` returns after memory mutation and queues a 100 ms auto-save, while `save()` uses `fs::write` directly. SIGKILL, power loss, or full disk can lose or corrupt adjacent state needed for recovery. (`apps/pos/src/lib/storage.ts:1-74`, `apps/pos/src/stores/authStore.ts:92-219`, `apps/pos/src/stores/terminalStore.ts:115-309`, `/Users/houssamr/.cargo/registry/src/index.crates.io-1949cf8c6b5b557f/tauri-plugin-store-2.4.2/src/store.rs:57-71`, `/Users/houssamr/.cargo/registry/src/index.crates.io-1949cf8c6b5b557f/tauri-plugin-store-2.4.2/src/store.rs:291-299`, `/Users/houssamr/.cargo/registry/src/index.crates.io-1949cf8c6b5b557f/tauri-plugin-store-2.4.2/src/store.rs:454-458`, `/Users/houssamr/.cargo/registry/src/index.crates.io-1949cf8c6b5b557f/tauri-plugin-store-2.4.2/src/store.rs:565-607`)
5. `P1` There is no cashier-facing corruption recovery path: no `PRAGMA integrity_check`, no backup/restore, no DB rebuild wizard, and no special handling for corrupt SQLite. A damaged DB can make the offline receipt queue inaccessible and make checkout fail at terminal hash-chain lookup. (`apps/pos/src/lib/db.ts:20-67`, `apps/pos/src/stores/terminalStore.ts:83-109`, `apps/pos/src/lib/offline/receiptService.ts:237-247`)

## 1. SQLite Journal Mode And Sync Mode

The POS opens one database per company as `sqlite:izipos-{companyId}.db`, then runs migrations. There is no app-owned `PRAGMA journal_mode`, `PRAGMA synchronous`, `PRAGMA busy_timeout`, `PRAGMA integrity_check`, or `PRAGMA quick_check` in production POS code. (`apps/pos/src/lib/db.ts:7-67`, `apps/pos/src/lib/db/migrations.ts:8-260`, `apps/pos/src-tauri/src/lib.rs:8-20`)

Tauri SQL maps `sqlite:...` to `app_config_dir()` and creates the file if missing before calling `Pool::connect(conn_url)`. (`/Users/houssamr/.cargo/registry/src/index.crates.io-1949cf8c6b5b557f/tauri-plugin-sql-2.3.2/src/wrapper.rs:77-91`)

For a brand-new DB, `Sqlite::create_database()` parses the URL with `create_if_missing(true)` and applies `journal_mode(Wal)` only because `sqlx-sqlite` has hidden `CREATE_DB_WAL: AtomicBool = AtomicBool::new(true)`. (`/Users/houssamr/.cargo/registry/src/index.crates.io-1949cf8c6b5b557f/sqlx-sqlite-0.8.6/src/lib.rs:123-125`, `/Users/houssamr/.cargo/registry/src/index.crates.io-1949cf8c6b5b557f/sqlx-sqlite-0.8.6/src/migrate.rs:20-35`)

For normal connection open, sqlx explicitly does not set `journal_mode` unless requested, and leaves `synchronous` unset because SQLite defaults to `FULL`. (`/Users/houssamr/.cargo/registry/src/index.crates.io-1949cf8c6b5b557f/sqlx-sqlite-0.8.6/src/options/mod.rs:177-189`)

Verdict:

- New databases created by this dependency version should be WAL.
- Existing databases are not guaranteed WAL if they were copied, created by another tool, or affected by a future dependency change.
- `synchronous` is effectively `FULL` unless SQLite compile defaults change.
- WAL + `FULL` is the desired fiscal posture for crash/power-loss durability; rollback journal + `FULL` is still atomic, but has weaker operational characteristics and should not be left to inference.

## 2. Transactional Write Boundaries

`createOfflineReceipt()` generates receipt data, resolves voucher tenders, then opens `BEGIN TRANSACTION`. Inside that transaction it inserts the receipt, advances `terminal_state`, updates local voucher balances, and commits; on error it rolls back. (`apps/pos/src/lib/offline/receiptService.ts:237-383`, `apps/pos/src/lib/offline/receiptService.ts:398-463`)

That transaction boundary is correct for local fiscal consistency: receipt row, hash-chain advance, and voucher balance move together or not at all.

The issue is ordering of the sync side effect. `insertOfflineReceipt()` calls `scheduleDebouncedSync()` immediately after the SQL `INSERT`, but this function is called inside the open transaction. `COMMIT` happens later in `createOfflineReceipt()`. (`apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:66-98`, `apps/pos/src/lib/offline/receiptService.ts:406-459`)

If sync fires before commit:

- On a separate connection, it may not see the uncommitted row and simply do nothing.
- On the same transactional connection, it could see/post a row that later rolls back if `advanceHashChain()` or voucher update fails.
- Even when it "works", it is relying on a 250 ms debounce rather than an explicit post-commit rule.

`paymentStore.createReceiptLocalFirst()` also calls `triggerSync()` only after `createOfflineReceipt()` resolves, so that second trigger is post-commit. (`apps/pos/src/stores/paymentStore.ts:198-226`)

## 3. Tauri Store Usage

The only direct `@tauri-apps/plugin-store` wrapper is `apps/pos/src/lib/storage.ts`, loading `izipos-settings.json`. It defines keys for auth token, server URL, user, company, companies, terminal, pending terminal, and current shift. (`apps/pos/src/lib/storage.ts:1-24`)

Calls found:

- Auth/session: reads token/user/company/company list on initialize; writes token/user/company list/company; removes auth and terminal keys on logout. (`apps/pos/src/stores/authStore.ts:92-219`)
- Terminal pairing/activation/shift: reads/writes terminal, pending terminal id, and current shift; reset removes terminal/pending/shift. (`apps/pos/src/stores/terminalStore.ts:115-309`)

Tauri Store stores one JSON file under `BaseDirectory::AppData`, which Tauri resolves to `data_dir/${bundle_identifier}`. (`/Users/houssamr/.cargo/registry/src/index.crates.io-1949cf8c6b5b557f/tauri-plugin-store-2.4.2/src/store.rs:26-31`, `/Users/houssamr/.cargo/registry/src/index.crates.io-1949cf8c6b5b557f/tauri-2.10.3/src/path/desktop.rs:244-251`)

It is not a real database:

- Default auto-save is 100 ms. (`/Users/houssamr/.cargo/registry/src/index.crates.io-1949cf8c6b5b557f/tauri-plugin-store-2.4.2/src/store.rs:57-71`)
- `set()` mutates the in-memory map, triggers debounced auto-save, and returns. (`/Users/houssamr/.cargo/registry/src/index.crates.io-1949cf8c6b5b557f/tauri-plugin-store-2.4.2/src/lib.rs:146-156`, `/Users/houssamr/.cargo/registry/src/index.crates.io-1949cf8c6b5b557f/tauri-plugin-store-2.4.2/src/store.rs:454-458`)
- `save()` serializes the whole map and calls `fs::write(path, bytes)`, not an explicit temp-file/rename/fsync sequence. (`/Users/houssamr/.cargo/registry/src/index.crates.io-1949cf8c6b5b557f/tauri-plugin-store-2.4.2/src/store.rs:291-299`)
- Exit tries to save every store, but SIGKILL and power loss bypass that path. (`/Users/houssamr/.cargo/registry/src/index.crates.io-1949cf8c6b5b557f/tauri-plugin-store-2.4.2/src/lib.rs:448-459`)

Impact:

- Kill within the debounce can lose fresh token/company/terminal/shift writes.
- Full disk can surface after a JS caller already treated `setStoredValue()` as success.
- Mid-write power loss can leave `izipos-settings.json` stale or corrupt.
- The riskiest key is `current_shift`: loss after offline shift-open can make restart look like no shift is active even though sales may exist in SQLite. (`apps/pos/src/stores/terminalStore.ts:249-283`)

## 4. Idempotency Key Persistence

`createOfflineReceipt()` generates both `receiptId` and `idempotencyKey` with `crypto.randomUUID()` before `BEGIN TRANSACTION`. (`apps/pos/src/lib/offline/receiptService.ts:310-338`)

The key becomes durable only when the `offline_receipts` row commits. The table has `idempotency_key TEXT NOT NULL UNIQUE`, and sync later sends that key in the payload. (`apps/pos/src/lib/db/migrations.ts:88-121`, `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:66-93`, `apps/pos/src/lib/sync/syncService.ts:101-132`, `apps/pos/src/lib/sync/syncService.ts:1259-1325`)

Crash answers:

- Kill before commit: receipt row, hash advance, voucher balance update, and idempotency key are lost together. Retry creates a fresh key.
- Kill after commit before sync: key survives in SQLite and replay uses the same key.
- Kill after server accepts POST but before local mark-synced: if the row is retried, the same key should let the server return `duplicate`/success. (`apps/pos/src/lib/sync/syncService.ts:236-291`)
- Kill after commit but before the UI/print acknowledges success: the local receipt is durable, but the cashier may re-ring because there is no "last committed receipt not acknowledged" marker. A second sale creates a fresh key and becomes a distinct valid receipt.

The key design is good after commit; the missing recovery affordance is around human duplicate prevention after a post-commit UI crash.

## 5. DB Location And Corruption Recovery

SQLite DB name: `izipos-{companyId}.db`. (`apps/pos/src/lib/db.ts:7-20`)

SQLite DB directory: Tauri SQL uses `app_config_dir()`, which resolves to `config_dir/${bundle_identifier}`. The bundle identifier is `com.syneriva.izipos`. (`/Users/houssamr/.cargo/registry/src/index.crates.io-1949cf8c6b5b557f/tauri-plugin-sql-2.3.2/src/wrapper.rs:79-86`, `/Users/houssamr/.cargo/registry/src/index.crates.io-1949cf8c6b5b557f/tauri-2.10.3/src/path/desktop.rs:235-242`, `apps/pos/src-tauri/tauri.conf.json:1-10`)

Expected OS roots:

- macOS: `~/Library/Application Support/com.syneriva.izipos/izipos-{companyId}.db`
- Windows: `%APPDATA%/com.syneriva.izipos/izipos-{companyId}.db`
- Linux: usually `~/.config/com.syneriva.izipos/izipos-{companyId}.db`

Tauri Store JSON directory: `app_data_dir()`, or `data_dir/${bundle_identifier}`. On macOS this usually also lands under Application Support; on Linux it differs from config. (`/Users/houssamr/.cargo/registry/src/index.crates.io-1949cf8c6b5b557f/tauri-plugin-store-2.4.2/src/store.rs:26-31`, `/Users/houssamr/.cargo/registry/src/index.crates.io-1949cf8c6b5b557f/tauri-2.10.3/src/path/desktop.rs:244-251`)

Recovery today:

- No backup/export/import path exists in POS code.
- No integrity check or quick check runs on open. (`apps/pos/src/lib/db.ts:20-67`)
- `Database.load()`/migrations errors bubble or are logged by callers; terminal bootstrap logs a seed failure but does not repair. (`apps/pos/src/stores/terminalStore.ts:83-109`)
- If terminal state is unavailable, checkout throws "Terminal hash chain not initialized." (`apps/pos/src/lib/offline/receiptService.ts:243-247`)

If the file corrupts, unsynced receipts, Z-reports, cash drawer ops, queued PIN updates, local product/payment/operator mirrors, and terminal hash state may become inaccessible. Reinstall/delete-and-repull can restore server mirrors, but it cannot restore local-only unsynced fiscal records.

## 6. Offline Receipt Queue Lifecycle

1. Receipt creation writes `offline_receipts.status='pending'` inside the transaction and advances the local hash chain. (`apps/pos/src/lib/offline/receiptService.ts:336-383`, `apps/pos/src/lib/offline/receiptService.ts:406-459`)
2. Insert schedules a debounced sync before commit; paymentStore also triggers sync after commit. (`apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:95-98`, `apps/pos/src/stores/paymentStore.ts:225-226`)
3. Terminal bootstrap starts a `SyncScheduler`; `start()` immediately calls `tick()` and then repeats every minute. (`apps/pos/src/stores/terminalStore.ts:83-106`, `apps/pos/src/lib/sync/syncScheduler.ts:25-45`)
4. `runFullSync()` pushes PIN updates, receipts, Z-reports, cash drawer ops, voucher ledger, then pulls server mirrors. (`apps/pos/src/lib/sync/syncService.ts:1172-1228`)
5. `pushOfflineReceipts()` selects `pending`/`failed` rows with `retry_count < 5`, ordered by `hash_sequence`. (`apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:136-145`)
6. Each row is marked `syncing`, POSTed to `/pos/receipts/sync`, then marked `synced` on `synced` or `duplicate`; failures increment retry count and set `failed`; chain break halts the loop. (`apps/pos/src/lib/sync/syncService.ts:224-323`)

Durability by failure mode:

- Tauri crash/SIGKILL before local commit: no row survives, no hash advance survives.
- Tauri crash/SIGKILL after local commit before sync: row survives restart and should replay.
- Tauri crash/SIGKILL while row is `syncing`: row is stranded because replay selection excludes `syncing`. (`apps/pos/src/lib/sync/syncService.ts:236-239`, `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:138-145`)
- Power loss after SQLite commit returns: expected durable on WAL/FULL new DBs; unverified if existing DB is not WAL.
- Full disk during receipt commit: transaction should fail and roll back; no specific UX or regression test proves no partial state leaks.
- OS suspend/resume: committed rows survive; sync resumes by timer, connectivity false->true, or visibility-change trigger after one minute, not by a dedicated suspend hook. (`apps/pos/src/lib/sync/syncScheduler.ts:35-45`, `apps/pos/src/components/AppShell.tsx:41-59`)
- Corrupted SQLite: no replay until support recovers or replaces the DB.

## 7. Crash-Safety Test Matrix

Tests that should exist or be added:

- `getDatabase_asserts_sqlite_pragmas_on_open`: open a real Tauri SQL DB and assert `journal_mode='wal'`, `synchronous=2`, and `foreign_keys=1`.
- `getDatabase_fails_loudly_on_integrity_check_failure`: simulate corrupt DB/open failure and assert a structured recoverable error path, not a silent empty POS.
- `createOfflineReceipt_rolls_back_receipt_hash_and_voucher_on_advance_failure`: force `advanceHashChain()` failure and assert `ROLLBACK`, no receipt row, no hash advance, no voucher balance change.
- `createOfflineReceipt_schedules_sync_only_after_commit`: assert no sync trigger can run before `COMMIT`.
- `committed_pending_receipt_survives_restart_and_replays`: seed a committed pending row, recreate DB/scheduler, assert `getPendingReceiptsForSync()` selects it and POSTs it.
- `uncommitted_receipt_is_absent_after_reopen`: begin/insert without commit, close/reopen, assert no receipt and no hash advance.
- `syncing_receipt_is_reset_or_retried_after_restart`: seed `status='syncing'`, restart sync, assert the row is selected/reset and POSTed.
- `post_success_before_mark_synced_replays_as_duplicate`: simulate server success followed by local write failure/crash; restart and assert same idempotency key is replayed and `duplicate` marks local row synced.
- `receipt_retry_cap_surfaces_exception_queue`: seed `failed` with `retry_count=5`, assert it is visible to ops and not silently hidden by the header count.
- `sqlite_full_disk_during_commit_preserves_atomicity`: inject execute/commit failure and assert checkout fails without partial receipt/hash/voucher writes.
- `tauri_store_set_without_save_can_be_lost_on_sigkill`: integration test or harness around Store showing a kill before 100 ms loses the write.
- `tauri_store_full_disk_save_failure_is_observable`: assert `setStoredValue()` path cannot report durable success when auto-save fails, or that explicit save errors are surfaced.
- `tauri_store_corrupt_json_shows_recoverable_settings_reset`: corrupt `izipos-settings.json` and assert startup warns/requires re-pair instead of silently misrouting.
- `post_commit_pre_ack_marker_prevents_duplicate_rering`: commit receipt, kill before success UI ack, restart, assert cashier sees last committed receipt details before re-ringing.
- `resume_after_suspend_with_pending_rows_eventually_triggers_sync`: simulate visibility regain/connectivity regain and assert committed rows are pushed.

Existing tests cover pieces of the behavior but not the crash boundaries. For example, receipt unit tests assert `BEGIN TRANSACTION`, `COMMIT`, and `ROLLBACK` calls, and sync tests assert the `syncing -> synced` status sequence, but they do not exercise process death, durable reopen, full disk, corrupt DB, or stale `syncing` recovery. (`apps/pos/src/lib/offline/__tests__/receiptService.test.ts:215-261`, `apps/pos/src/lib/sync/__tests__/syncService.test.ts:196-217`)

## 8. Reconciliation Vs Offline-First Audit Phase 5

This audit agrees with the offline-first audit that the normal receipt queue is SQLite-backed, uses a unique idempotency key, and preserves receipt/hash order through a transaction. (`docs/superpowers/audits/2026-04-30-pos-offline-first-audit-codex.md:103-129`)

It extends Phase 5 in five ways:

1. Phase 5 Step 5.1 is correct: move `scheduleDebouncedSync()` past `COMMIT`. The current code still schedules inside `insertOfflineReceipt()`. (`docs/superpowers/plans/2026-04-30-pos-offline-first-hardening.md:278-286`, `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:95-98`)
2. Phase 5 should add stale-`syncing` recovery; otherwise a crash during push can permanently remove a committed receipt from automatic replay.
3. Phase 5 should assert SQLite PRAGMAs at runtime. WAL is currently a dependency side effect, not an app invariant.
4. Phase 5 should treat Tauri Store state as non-transactional and explicitly save or move fiscal-adjacent state such as `current_shift` into SQLite.
5. Phase 5 does not cover corruption recovery; add integrity checks and a backup/recovery runbook before claiming crash-safety coverage.

Bottom line: committed receipts are reasonably durable against ordinary restart. They are not yet hardened against sync-time SIGKILL, full-disk/store persistence failures, corrupted SQLite, or operator duplicate re-ringing after a post-commit UI crash.
