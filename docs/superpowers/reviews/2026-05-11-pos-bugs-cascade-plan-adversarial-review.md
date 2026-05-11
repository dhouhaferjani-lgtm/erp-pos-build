# POS Bugs Cascade Plan — Codex Adversarial Review

Review target: `docs/superpowers/plans/2026-05-11-pos-bugs-cascade-fix-handoff.md`

Pre-flight confirmed:
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp-pos-stabperf`
- Branch: `dev`
- Tip: `cc8a2f13 docs(pos): bugs-cascade fix handoff plan (Bugs 1-5, 4 PRs, adversarial-review-first)`

## PR A — Bug 5 WAL + Stuck-Receipt Recovery

### [BLOCKER] Recovery SQL will not recover the raw-string Tauri lock failures described in the symptom catalog

- File/line: `apps/pos/src/lib/sync/syncService.ts:447`, `apps/pos/src/lib/sync/syncService.ts:455-457`
- Plan claim challenged: migration v32 can recover stuck rows with `WHERE status = 'failed' AND sync_error LIKE '%database is locked%'`.
- Evidence: the plan's symptom catalog says Tauri SQL throws the database-lock failure as a raw string. In `pushOfflineReceipts`, the persisted message is computed as `error instanceof Error ? error.message : 'Unknown error'`, then written to `offline_receipts.sync_error` and `sync_log` via `updateReceiptStatus`/`logSyncOperation`. The raw string is visible in console logging through `serializeErrorForLog`, but it is not persisted as `sync_error`. Therefore the proposed LIKE predicate will miss the exact production shape the plan is trying to recover.
- Proposed resolution: before v32 recovery, fix sync error normalization to preserve safe non-Error string messages at least for SQLite lock signatures. For already-deployed rows that only have `sync_error = 'Unknown error'`, do not blindly mass-reset them. Add a narrower recovery path only if another durable discriminator exists; otherwise PR C/manual recovery must handle them.

### [P1] The plan overstates "missing busy_timeout"; SQLx already defaults SQLite connections to 5 seconds

- File/line: `apps/pos/src/lib/db.ts:20-23`; local source: `~/.cargo/registry/src/.../sqlx-sqlite-0.8.6/src/options/mod.rs:194-201`
- Plan claim challenged: "`No PRAGMA journal_mode=WAL and no PRAGMA busy_timeout are set. SQLite default journal mode is DELETE...`"
- Evidence: production `getDatabase` does not set WAL/busy timeout in app code, and `rg` found no production `journal_mode`, `busy_timeout`, or `locking_mode` PRAGMAs under `apps/pos/src`. However, Tauri plugin SQL 2.3.2 uses `sqlx::Pool::connect`, and SQLx SQLite defaults `busy_timeout` to `Duration::from_secs(5)`. WAL is still absent, but "no busy timeout" is not accurate unless Tauri overrides SQLx defaults elsewhere; I found no such override in the plugin wrapper.
- Proposed resolution: amend root cause to "WAL is missing; busy_timeout may already be 5s at SQLx connection level." Keep a verification query for `PRAGMA busy_timeout`, but do not treat adding `PRAGMA busy_timeout=5000` as the primary fix without proving current runtime value.

### [P1] `PRAGMA busy_timeout=5000` through `db.execute` may only touch one pooled connection

- File/line: `~/.cargo/registry/src/.../tauri-plugin-sql-2.3.2/src/wrapper.rs:146-154`
- Plan claim challenged: setting `await db.execute('PRAGMA busy_timeout=5000')` in `getDatabase` configures the database handle.
- Evidence: Tauri plugin SQL wraps an SQLx pool. `db.execute` delegates to `pool.execute(query)`, which acquires one connection for that statement. `busy_timeout` is connection-local in SQLite, unlike WAL mode which is persistent on the database file. SQLx's connect-option default likely covers all pool connections already, but a runtime PRAGMA through one execute call is not a reliable way to configure the whole pool.
- Proposed resolution: either rely on SQLx's default after verifying `PRAGMA busy_timeout`, or use connection-string/connect-option support if Tauri plugin SQL accepts it. If using runtime PRAGMA anyway, document that it is a verification/no-op guard, not a pool-wide configuration mechanism.

### [P1] WAL test strategy is not sound as written; current "integration" harness is in-memory Node SQLite, not a real Tauri db file

- File/line: `apps/pos/src/lib/db/__tests__/helpers/sqliteTestAdapter.ts:55-56`
- Plan claim challenged: `apps/pos/src/lib/db/__tests__/migrations.integration.test.ts` can host WAL-aware tests.
- Evidence: the migration integration adapter opens `new DatabaseSync(':memory:')`. SQLite docs state in-memory databases cannot switch to WAL. Also, `getDatabase()` uses `@tauri-apps/plugin-sql`, which is not available in the normal Vitest Node harness. A mock can prove that the app calls PRAGMAs in order; it cannot prove WAL behavior in Tauri.
- Proposed resolution: split tests into (a) unit/mock call-order test for `getDatabase` calling WAL before migrations, (b) Node SQLite file-backed test only if the adapter is extended to accept a temp file, and (c) mandatory manual Tauri smoke that runs `PRAGMA journal_mode`/`PRAGMA busy_timeout` in the actual app runtime. Do not rely on current migration integration tests for WAL.

### [P2] Chain-break cascade theory is directionally correct, but the plan names the wrong server status for batch-of-one sync

- File/line: `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:228-233`; `apps/pos/src/lib/sync/syncService.ts:397-403`
- Plan claim challenged: receipt #2 hits the server and "returns `chain_broken`."
- Evidence: the client currently sends receipts batch-of-one. On previous-hash mismatch, the server returns `SyncReceiptResult::failed(..., 'Hash chain break...')`, not `chain_broken`. The client still treats this as a chain break because `isChainBreakError()` matches the error string and sets the chain-break flag. The cascade theory still holds after receipt #1 reaches retry cap, but the status vocabulary in the plan is wrong.
- Proposed resolution: amend the plan and tests to cover `status: 'failed'` with a hash-chain error string, in addition to the `chain_broken` enum branch.

### [P2] Dead-letter skip only happens at retry cap; the plan should not describe every `failed` receipt as skipped

- File/line: `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:200-207`, `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:218-224`
- Plan claim challenged: "Once receipt #1 is dead-lettered (status `failed`), receipt #2 is the next sync target."
- Evidence: `getPendingReceiptsForSync` includes both `pending` and `failed` while `retry_count < 5`, ordered by `hash_sequence`. Receipt #1 is skipped only once it is `failed` at `retry_count >= 5`. Until then, it remains the first sync target and blocks receipt #2 by sequence order.
- Proposed resolution: keep the cascade theory, but word it precisely: the server mismatch starts after receipt #1 is saturated at the dead-letter cap, not merely after the first transition to `failed`.

### [P2] WAL switch is acceptable mid-life, but the plan needs operational notes for WAL sidecar files and verification

- File/line: `apps/pos/src/lib/db.ts:20-23`
- Plan claim challenged: mid-life DELETE-to-WAL flip has no data-integrity concerns.
- Evidence: SQLite documents WAL as persistent, database-file-format compatible with SQLite >= 3.7.0, and convertible by `PRAGMA journal_mode=WAL` when no transaction is active. `getDatabase` runs before migrations and before app transactions, which is the right timing. But WAL creates `-wal` and `-shm` sidecar files; backups/copies must include them or checkpoint/close cleanly first.
- Proposed resolution: document "set at first app open only, verify returned mode is `wal`, app data backup must include/checkpoint WAL sidecars." Add a manual smoke step that confirms no open transaction prevents the switch.

## PR B — Bug 2 Cash Payment Amount Is Tendered

### [P1] Downstream audit misses void/refund behavior once cash `amount` becomes tendered

- File/line: `apps/pos/src/stores/paymentStore.ts:542-545`; `apps/api/app/Modules/POS/Application/Services/ReceiptVoidService.php:207-218`
- Plan claim challenged: changing `payments[0].amount` from total to tendered is only a reporting/cash-variance contract fix.
- Evidence: server-side reports do treat `pos_receipt_payments.amount` as tendered. `ReportGenerationService::buildExpectedPerMethod()` sums payment amounts for cash counts, consistent with `CashCountToleranceVarianceRegressionTest`'s contract of expected cash = opening + payments - change_due. But `ReceiptVoidService` also sums CASH receipt payment amounts and records that full amount as a cash-drawer refund. For an over-tendered 20.000 sale on a 10.000 receipt with 10.000 change, voiding/refunding the tendered amount would move 20.000 out of the drawer even though only 10.000 net sale cash remained after change was given.
- Proposed resolution: PR B's downstream audit must include void/return/refund services, not just reports. Add a regression test for over-tendered cash receipt void/refund and decide whether refund amount should be `receipt.total`/net paid or `payment.amount - change_due` for cash.

### [P2] Bug 3 is not fully unified with Bug 5 until over-tender hash/server paths are ruled out

- File/line: `apps/pos/src/stores/paymentStore.ts:538-548`; `apps/pos/src/lib/offline/receiptService.ts` payment hash construction; `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:433-448`
- Plan claim challenged: "over-tender fails" is only timing coincidence from Bug 5.
- Evidence: exact and over-tender cash currently send the same `payments[0].amount` (the total), while `tendered_amount`/`change_due` differ. Backend payment validation allows overpayment, and sync persists `change_due` from payload, so I did not find a deterministic reject solely caused by over-tender. Still, after PR B changes `payments[0].amount` to tendered, the fiscal hash input changes on both client and server; this needs explicit over-tender sync coverage, not only a checkout-store unit test.
- Proposed resolution: add a backend/client sync fixture for cash over-tender where `payment.amount=tendered`, `receipt.total<amount`, and `change_due>0`, asserting server hash verification, persisted `pos_receipt_payments.amount`, and printed change.

## PR C — Bug 4 Chain-Break Recovery UX

### [BLOCKER] Proposed `void_local` status is incompatible with the existing SQLite status CHECK and TypeScript union

- File/line: `apps/pos/src/lib/db/migrations.ts:272`; `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:4`
- Plan claim challenged: recovery option (b) can set local-only receipts to `status='void_local'`.
- Evidence: the recreated `offline_receipts` table constrains status to `pending`, `syncing`, `synced`, or `failed`. The repository type union has the same four values. Writing `void_local` will violate the SQLite CHECK on upgraded databases and fail TypeScript unless the type/schema are migrated first.
- Proposed resolution: either use the existing `voided=1`/`void_reason='chain_break_recovery'` columns while keeping an allowed status, or add a real migration that rebuilds the table/type/repository filters to include `void_local`.

### [P1] Rewinding terminal_state conflicts with existing anti-regression guards unless a dedicated recovery path is designed

- File/line: `apps/pos/src/lib/db/repositories/terminalStateRepository.ts:137-150`, `apps/pos/src/lib/db/repositories/terminalStateRepository.ts:179-194`
- Plan claim challenged: recovery option (b) can rewind `terminal_state.last_hash + hash_sequence` to server values.
- Evidence: `upsertTerminalState` rejects lower `hash_sequence`, and `advanceHashChain` rejects non-increasing sequence writes. A recovery action can bypass those helpers with raw SQL, but that would intentionally violate a core fiscal invariant without a separate audited API.
- Proposed resolution: PR C must introduce an explicit `rewindTerminalChainForRecovery` repository function with manager-PIN gating, local-only receipt voiding in the same transaction, audit log write, and tests proving normal sync paths still cannot regress the chain.

### [P2] Recovery audit log "included in next sync batch" has no ingestion surface

- File/line: plan PR C spec; `apps/pos/src/lib/sync/syncService.ts:1612+`
- Plan claim challenged: `pos_chain_recovery_log` is included in the next sync batch as a side-channel diagnostic.
- Evidence: current `runFullSync` pushes receipts, Z reports, vouchers, cash drawer ops, and PIN updates; there is no recovery-log queue or API endpoint in the plan's file list.
- Proposed resolution: either add backend endpoint + client push path + tests to PR C, or mark the audit log as local-only for PR C and create a follow-up for server ingestion.

## PR D — Bug 1 Catalog WebSocket

### [P2] Catalog broadcast ingress list is incomplete for the actual POS catalog shape

- File/line: `apps/pos/src/stores/productStore.ts:195-234`; `apps/api/routes/channels.php:85-103`
- Plan claim challenged: broadcasting Product/MenuCategory/MenuCategoryItem mutations is sufficient for POS real-time catalog sync.
- Evidence: POS catalog state branches by tenant modules. Menu tenants pull `fetchActiveMenu()` then flatten/reconcile menu products; standard tenants pull products through the paginated foreground/background product pull. The API already has product-specific broadcast channel definitions, but no broad catalog channel. The plan does not enumerate composite-item/modifier/menu active-menu publication changes, which can affect the flattened POS catalog even when Product/MenuCategory/MenuCategoryItem do not change.
- Proposed resolution: PR D's L9 ingress audit should enumerate every backend mutation that changes `fetchActiveMenu()` or `fetchPOSProducts()` output for POS: products, menu categories/items, active menu publication, composite items, modifiers/modifier groups, stock/price visibility if exposed. For v1, it is acceptable to broadcast a coarse "catalog changed" event from more sites rather than trying to diff each entity.

## Cross-Cutting Conclusions

- Bug 5 WAL is still a plausible root fix, but the current plan needs amendments before implementation because (a) existing raw-string lock failures are persisted as `Unknown error`, (b) busy_timeout is probably already set by SQLx, and (c) the proposed WAL tests do not run against the actual Tauri SQL runtime.
- Bug 4 cascade theory holds after receipt #1 reaches retry cap, but server/client status wording should be corrected from "returns `chain_broken`" to "`failed` with hash-chain text, which client treats as chain break."
- Bug 2 is independently real: POS cash path writes total instead of tendered. The fix is contract-aligned, but the downstream audit must include void/refund cash movement.
- Bug 1 is a missing feature, but PR D's ingress list should be broadened before implementation.

Sources consulted:
- Local POS/API code cited above.
- Tauri plugin SQL 2.3.2 local source in Cargo/npm cache.
- SQLite WAL documentation: https://www.sqlite.org/wal.html
- SQLite PRAGMA documentation: https://www.sqlite.org/pragma.html
