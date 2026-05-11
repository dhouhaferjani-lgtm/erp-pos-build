# POS Bugs Cascade Plan — Amendments v1 (post-Codex adversarial review)

> **Read order:** ORIGINAL PLAN (`2026-05-11-pos-bugs-cascade-fix-handoff.md`) → this amendments doc → Codex's adversarial review (`docs/superpowers/reviews/2026-05-11-pos-bugs-cascade-plan-adversarial-review.md`). This doc supersedes specific claims and per-PR steps from the original plan; everything else stands.

Codex's adversarial review surfaced 2 BLOCKERs + 4 P1s + 7 P2s. All are defensible. The amendments below resolve every finding. PR sequencing is unchanged; per-PR scope expands.

---

## Resolution of every finding

### PR A — Bug 5 WAL + recovery

**[BLOCKER] Recovery SQL won't match raw-string Tauri lock failures.**
Codex is right. `syncService.ts:447` writes `error instanceof Error ? error.message : 'Unknown error'` to `sync_error`. The raw lock string is never persisted; affected rows have `sync_error = 'Unknown error'` in SQLite. My LIKE-pattern matches nothing.

**Resolution:** Split PR A into two sub-tasks within the same PR:

1. **Fix error preservation in `pushOfflineReceipts`** so future failures persist a usable signature. Replace the binary `error instanceof Error ? error.message : 'Unknown error'` with a coercer that preserves non-Error throwables when they're plain strings:

   ```typescript
   function coerceSyncError(error: unknown): string {
     if (error instanceof Error) return error.message;
     if (typeof error === 'string') return error;
     if (error !== null && typeof error === 'object') {
       try {
         return JSON.stringify(error);
       } catch {
         return String(error);
       }
     }
     return String(error);
   }
   ```

   Apply this at every site that writes `sync_error` in `syncService.ts` (lines 397-411 + 447-456). Update the matching catch in `pushCashDrawerOps` if it has the same pattern.

2. **Migration v32** now becomes a NARROWER recovery, covering only rows that failed AFTER the error-preservation fix ships. For ALREADY-STUCK rows with `sync_error='Unknown error'`:
   - DO NOT mass-reset. The `'Unknown error'` signature is ambiguous — could be lock OR something else.
   - Document this in the PR body: existing stuck rows from before the error-preservation fix require PR C's recovery UX or manual SQLite intervention (a one-line SQL for the operator to run if they know the cause).

   The migration v32 SQL stays as drafted (`WHERE sync_error LIKE '%database is locked%'`), but its scope is now "future-stuck rows after the preservation fix" not "all existing stuck devices."

**[P1] busy_timeout is already default 5s in SQLx; the PRAGMA at runtime only configures one pooled connection.**
Codex is right (verified SQLx 0.8.6 `options/mod.rs:194-201`). Two consequences:

**Resolution:**
- KEEP `PRAGMA journal_mode=WAL` — WAL is persistent on the database file, so a single execute IS sufficient and the runtime PRAGMA is the right mechanism.
- REMOVE `PRAGMA busy_timeout=5000` from `db.ts`. Replace with a verification-only assertion in the test (`expect(busy_timeout).toBeGreaterThanOrEqual(1000)`) to catch a future regression if Tauri ever overrides SQLx's default.
- Update the root-cause analysis: the bug is "WAL is missing" — NOT "busy_timeout is missing." With WAL, readers see a snapshot and never contend with writers, which closes the cascade.

**[P1] WAL test strategy is unsound.** `:memory:` SQLite can't switch to WAL; Vitest harness doesn't load real plugin-sql.

**Resolution:** Test plan rewritten to:
1. **Mock-based call-order test (Vitest, runs in CI):** Mock `Database.load` to return a stub that records every `db.execute` call. Assert the WAL PRAGMA is invoked BEFORE `runMigrations`. This proves we call the right thing in the right order; doesn't prove WAL behavior.
2. **Manual Tauri smoke step in the PR's acceptance criteria:** boot a real Tauri-built POS app, open devtools, run `await window.__db.select('PRAGMA journal_mode')` (exposing the db handle for diagnostics is acceptable in dev builds, not prod). Confirm the result is `wal`. Document the command in the PR body.
3. **(Optional) Extend `sqliteTestAdapter` to accept a file path:** if the adapter takes a `dbPath` parameter (defaults to `:memory:` for the current tests), a new test can use `tmpdir()/test.db`, switch to WAL, and prove the file-mode behavior. This is a nice-to-have but not blocking — Codex's recommendation to defer to manual smoke is acceptable.

**[P2] Chain-break cascade theory uses the wrong server status terminology.**
Codex is right. Server returns `SyncReceiptResult::failed(..., 'Hash chain break...')`. Client treats it as chain break because `isChainBreakError` matches the error string. The cascade theory is correct; only the wording in the plan is wrong.

**Resolution:** Update the PR A PR body's root-cause section to say "server returns `failed` with `'Hash chain break: client previous_hash does not match terminal last_hash'`, which the client's `isChainBreakError()` matches and routes to the chainBreak banner path." No code change needed.

**[P2] Dead-letter timing is at retry-cap, not first failure.**
Codex is right. `getPendingReceiptsForSync` includes both `pending` and `failed` while `retry_count < 5`, ordered by `hash_sequence`. Receipt #1 is the sync target until `retry_count >= 5`.

**Resolution:** Update the PR A PR body cascade explanation: "Receipt #1 is retried up to 5 times. Each retry hits the lock. After saturating the retry cap, receipt #1 is excluded by `getPendingReceiptsForSync`. Receipt #2 becomes the next sync target — and its `previous_hash` references receipt #1's local hash, which the server doesn't know about. Server's `previous_hash != terminal.last_hash` check fires → 'Hash chain break' error → client sets the chainBreak banner."

**[P2] WAL sidecar files (-wal, -shm) need operational notes.**

**Resolution:** Add a section to the PR A PR body: "Operational impact — WAL mode creates `-wal` and `-shm` sidecar files next to the main `.db` file. Any backup tool that copies the .db file MUST also copy the sidecars, OR call `PRAGMA wal_checkpoint(TRUNCATE)` and close the connection before the copy. Tauri's appdata persistence is unaffected (the sidecars are stored next to the main file). Document this for whoever owns the device-backup story."

### PR B — Bug 2 cash payment amount

**[P1] Downstream audit misses void/refund behavior.**
Codex found `ReceiptVoidService.php:207-218` sums `payment.amount` for cash-drawer refunds. If `payment.amount = tendered` (PR B's new contract) for an over-tendered sale, voiding moves the FULL tendered amount out of the drawer — but only `total` (net of change given) actually entered the drawer at sale time. The void would over-refund by the change-due amount.

**Resolution:** PR B scope expands. Three sub-tasks:

1. **The original change** — `paymentStore.ts:544` payments[0].amount = tendered.

2. **Fix `ReceiptVoidService`** to compute the cash refund as `payment.amount - receipt.change_due` (for cash payment rows only). For non-cash, refund the full payment.amount. Add a test for the over-tender void scenario.

3. **Add backend regression test** — `ReceiptVoidServiceOverTenderTest` (or extend an existing test) that:
   - Seeds a cash receipt with `total=10`, `tendered=20`, `change_due=10`, `payment_method.amount=20`.
   - Voids it.
   - Asserts the cash-drawer refund row is `10` (net cash that actually entered the drawer), NOT `20`.

4. **Add sync-round-trip test (per Codex P2)** — cash over-tender offline receipt syncs successfully end-to-end. Assert server-persisted `pos_receipt_payments.amount == tendered`, `change_due > 0`, fiscal hash verification passes, printed change line is correct.

**[P2] Bug 3 unification needs over-tender sync coverage.**

**Resolution:** Covered by sub-task 4 above. Bug 3 stays attributed to Bug 5 timing-coincidence theory — over-tender doesn't have a deterministic failure path before OR after PR B; the over-tender sync round-trip test proves it.

### PR C — Bug 4 chain-break recovery UX

**[BLOCKER] `void_local` status incompatible with existing CHECK + TS union.**
Codex is right. `offline_receipts.status` allows only `pending|syncing|synced|failed`. The table has separate `voided` (boolean) + `void_reason` columns.

**Resolution:** Use the existing `voided=1` + `void_reason='chain_break_recovery'` pattern. Status stays `failed` for these rows; queries that filter "active" rows continue to use `status='pending' OR (status='failed' AND voided=0 AND retry_count<5)` or similar.

Update the PR C spec accordingly: option (b) of the recovery flow becomes "mark the local-only receipts as voided locally with `void_reason='chain_break_recovery'`," not "set status to `void_local`."

**[P1] Rewinding terminal_state conflicts with anti-regression guards.**
Codex is right. `upsertTerminalState` rejects lower sequence; `advanceHashChain` rejects non-increasing writes.

**Resolution:** PR C must introduce a dedicated `rewindTerminalChainForRecovery(db, terminalId, serverState, justification)` repository function in `terminalStateRepository.ts`:

- Bypasses the anti-regression guard via a flag (e.g., `_internal_allow_regression: true` on the upsert helper, with extensive comments + assertion that the caller is the recovery service).
- Runs in the SAME transaction as the local-only receipt voiding (atomicity guarantee — either the chain is rewound AND receipts are voided, or neither).
- Writes an audit-log row to `pos_chain_recovery_log` (new table — see migration spec below).
- Requires a `justification: string` (the manager who initiated, the time, the receipt count being voided).

Update test coverage to prove normal `upsertTerminalState` / `advanceHashChain` calls still cannot regress the chain. Add an explicit regression test for the new bypass.

**[P2] Recovery audit log has no ingestion surface.**

**Resolution:** For PR C v1, the audit log is LOCAL-ONLY. Schema: `pos_chain_recovery_log (id, attempted_at, option_chosen, receipts_affected_count, server_sequence_before, server_sequence_after, local_sequence_before, local_sequence_after, manager_id, justification)`. The cashier-facing view can list past recoveries from this table.

Server ingestion is a follow-up:
- A future endpoint `POST /api/v1/pos/terminals/{id}/chain-recovery-log` accepts the log row.
- The sync scheduler queues these like Z-reports or cash-drawer ops.
- For PR C v1, skip this — document the follow-up explicitly in the PR body's "Out of scope" section.

### PR D — Bug 1 catalog WebSocket

**[P2] Ingress list is incomplete.** Composite items, modifiers, active-menu publication state can all change the POS catalog without touching Product/MenuCategory/MenuCategoryItem directly.

**Resolution:** Pivot to a **coarse "catalog changed" event** for v1. Backend emits `CatalogChannelEvent` (with no payload other than `reason: string`) on any mutation that could affect the POS catalog:
- Product create/update/delete
- MenuCategory create/update/delete
- MenuCategoryItem create/update/delete
- CompositeItem create/update/delete (if used by Menu tenants)
- Modifier / ModifierGroup mutations
- Menu publication state changes (active vs. inactive menu versions)
- Stock visibility toggles if exposed to POS

POS subscribes to a single `tenant.{tenantId}.company.{companyId}.catalog` channel. On ANY event, debounce 500ms and then call `productStore.fetchProducts(true)`. Debouncing covers bulk operations (e.g., admin imports 100 products → 100 events → 1 fetch).

Per-entity diff payloads are a v2 follow-up if real-time precision becomes a UX requirement. For v1, the user's requirement is "POS sees the change without a 60s polling delay" — debounced full-refetch achieves that.

---

## Updated PR scope per amendment

### PR A: WAL + error preservation + recovery (expanded scope)

Files now also touched:
- `apps/pos/src/lib/sync/syncService.ts` — `coerceSyncError` helper + every `sync_error` write site.
- `apps/pos/src/lib/sync/__tests__/coerceSyncError.test.ts` — new unit test.

**Single PR still.** The three pieces (error preservation, WAL, recovery SQL) ship together because they're causally connected: error preservation MUST land before the recovery SQL has any matching rows.

### PR B: Cash payment amount + void/refund correction (expanded scope)

Files now also touched:
- `apps/api/app/Modules/POS/Application/Services/ReceiptVoidService.php` — refund calculation fix.
- `apps/api/tests/Feature/POS/ReceiptVoidServiceOverTenderTest.php` — new backend test.
- `apps/api/tests/Feature/POS/OfflineCashOverTenderSyncTest.php` — new end-to-end sync test.

Splitting into two PRs is tempting but they're causally connected too: the void fix is a direct consequence of the payment.amount change. Bundling avoids a known-broken intermediate state on `dev`.

### PR C: Chain-break recovery UX (scope clarified, not expanded)

Spec updates:
- Use `voided=1` + `void_reason='chain_break_recovery'` (not `status='void_local'`).
- New `rewindTerminalChainForRecovery` repo function with bypass + audit log + tests.
- `pos_chain_recovery_log` table is LOCAL-ONLY for v1.

### PR D: Coarse catalog-changed event (simpler than original)

The simplification (coarse "catalog changed" event vs per-entity events) actually shrinks the implementation surface. Single channel, single hook, debounced refresh.

---

## What changed in the kickoff prompt for Phase 2

The original Codex Phase 2 prompt still applies, with this addition at the top:

```
PHASE 2 KICKOFF AMENDMENT:

Before implementing PR A, read:
  1. The original plan at docs/superpowers/plans/2026-05-11-pos-bugs-cascade-fix-handoff.md
  2. The amendments at docs/superpowers/plans/2026-05-11-pos-bugs-cascade-fix-handoff-amendments-v1.md (THIS DOC)
  3. Your own adversarial review at docs/superpowers/reviews/2026-05-11-pos-bugs-cascade-plan-adversarial-review.md

The amendments doc supersedes specific claims from the original; everything not explicitly amended stands.

Implement PR A per the amended scope:
  - Error preservation in syncService.ts (sub-task 1)
  - WAL PRAGMA in db.ts, NO busy_timeout PRAGMA (sub-task 2)
  - Recovery migration v32 (sub-task 3, NARROWER than originally drafted)
  - Test strategy: mock-based call-order test + manual Tauri smoke (per amended P1 resolution)

After PR A, proceed to PR B (also expanded), then PR C, then PR D.
```

---

## Open questions for Opus (none, but flagging the trade-offs)

1. **PR A's recovery SQL covers ONLY future-stuck rows, not currently-stuck devices.** Accepting this means production devices that are stuck right now still need PR C (or manual SQL on each device). That trade-off seems right because mass-resetting `sync_error='Unknown error'` rows is unsafe — but it does mean the cascade isn't fully fixed by PR A alone. The user should know.

2. **PR C's "rewind chain" repo function intentionally violates a fiscal invariant.** With strong gates (manager PIN, audit log, dedicated function, comprehensive tests) the risk is contained. But it's worth flagging as a sensitive surface that Opus should pre-merge review carefully.

3. **PR B's bundle (payment.amount + void/refund) is causally correct but increases review surface.** An alternative is splitting into B1 (payment.amount) and B2 (void/refund), shipped back-to-back. B1 alone is broken (void would over-refund). The bundled approach avoids the broken intermediate state. Recommend keeping bundled.
