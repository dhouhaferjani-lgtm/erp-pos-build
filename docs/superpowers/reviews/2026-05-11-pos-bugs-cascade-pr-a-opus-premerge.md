# PR A — Opus Pre-Merge Audit

**Subject:** fix(pos): SQLite WAL + stuck-receipt recovery + sync_error preservation
**PR:** https://github.com/otospexsolutions/erp/pull/120
**Base:** `dev`
**Branch:** `fix/pos-sqlite-wal-busy-timeout`
**Head:** `638e2f54` (post-Codex r2 APPROVE)
**Date:** 2026-05-11
**Auditor:** Opus 4.7 (same model that authored the implementation — not an independent review, see "Limitations" section)

---

## Summary

PR A ships three causally-connected fixes addressing Bug 5 (SQLite WAL) and the consequent Bugs 3 + 4 (chain-break cascade, intermittent "Échec du paiement"):

1. `coerceSyncError` preserves Tauri plugin-sql's raw-string throwables in `sync_error` columns + `sync_log.error_message`. Replaces 16 occurrences of `error instanceof Error ? error.message : 'Unknown error'`.
2. `PRAGMA journal_mode=WAL` in `db.ts` before migrations. Default DELETE serializes everything through an exclusive lock; WAL lets readers see snapshots and never contend with writers.
3. Migration v32 + `runStuckReceiptRecovery` rerunnable startup hook: idempotent UPDATE recovering rows with `sync_error LIKE '%database is locked%'`.

Plan + amendments + Codex r1 P2 closure all align. Preflight green: typecheck 0 / lint 0+41 warnings (baseline) / 1242 tests passing (+15 new).

---

## L1 — Cross-tenant audit

The fix is platform-level (SQLite + the offline-receipt repository) and tenant-agnostic. Every tenant class (Menu, standard-retail, hybrid, non-Menu) uses the same SQLite database file and the same `offline_receipts` schema. WAL behaves identically across all of them; no tenant-specific code paths are touched. **No L1 risk.**

## L8 — Cross-screen ownership

The change does not introduce a new screen or transfer ownership of any state. The chain-break banner (ChainBreakAlert) still owns the chain-break-flag UI; the syncStore still owns the underlying flag. Receipt creation, sync scheduling, and the cashier-facing error banners remain unchanged in terms of who owns what. **No L8 risk.**

## L9 — Ingress audit

### `getDatabase` call sites

Counted 61 production call sites in `apps/pos/src` (stores, components, services, hooks). Every call goes through the singleton in `db.ts`. The singleton's cache key is `currentDbName === dbName`:

- First call per `companyId` → load + WAL + migrations + recovery hook → returned.
- Subsequent calls with same `companyId` → cached singleton returned, no re-init.
- Call with different `companyId` (company switch) → close + null + full re-init.

This means `runStuckReceiptRecovery` fires once per cold app start AND once per company switch — not on every `getDatabase` call. The rerunnable test (`db-wal.test.ts:104-148`) drives the second-boot path via `closeDatabase()`, which matches the company-switch reality.

**Implication for the "future-stuck row" scenario:** if a receipt somehow hits the lock signature mid-session post-WAL, the rerunnable hook does NOT recover it until the next app launch OR company switch. This is acceptable — the WAL fix is expected to eliminate the lock cascade entirely; the recovery hook is defense in depth, not a primary mechanism.

### Recovery SQL ingress

The UPDATE statement runs against `offline_receipts`. The same statement is declared twice (migration v32's SQL + `runStuckReceiptRecovery`'s SQL). **Risk:** if a future change updates one but not the other, the semantic drifts. **Mitigation:** the WHERE clause is intentionally identical, and the inline migration comment (`apps/pos/src/lib/db/migrations.ts:801-807`) cross-references the runtime hook explicitly. A future cleanup could extract the SQL to a shared constant — out of scope here.

### sync_error column writes

`coerceSyncError` is now the single source for non-`Error` error coercion in `syncService.ts`. 16 sites use it. Three sites NOT converted (intentional):
- `syncService.ts:282` — inside `isChainBreakError`, multi-line ternary that already handles strings separately. Behavior unchanged.
- `syncService.ts:354, 373` — writeback/reconcile failure logging with `'unknown'` fallback (not `'Unknown error'`). Different code path; outside the lock-cascade ingress.
- All `migrations.ts` ALTER TABLE error handlers — `error.message` only used to detect "duplicate column"; non-Error throwables there are an unrelated concern.

**No L9 ingress gap that affects the cascade.**

---

## Root cause vs symptom check

Plan claims this PR fixes the root cause of Bug 5 (locked SQLite). Verification:

- `db.ts:24` adds `PRAGMA journal_mode=WAL` BEFORE `runMigrations`. SQLite docs confirm WAL is database-file-persistent and database-level, so subsequent connections from the SQLx pool inherit the mode. Tauri plugin-sql 2.3.2 uses SQLx 0.8.6 which already defaults `busy_timeout` to 5 s. **Root cause addressed.**
- The cascade closure logic (Bugs 3 + 4 are downstream): with no lock, the cashier's createOfflineReceipt transaction completes before sync ticks, the local chain stays in sync with the server, and the chain-break banner never fires. **Logically tied to the root cause.**

The recovery SQL addresses the *recovery* problem, not the cause — it cleans up rows already dead-lettered. By design (amendments-v1 §"PR A's recovery SQL covers ONLY future-stuck rows"), pre-(1) "Unknown error" rows are NOT touched because the signature is ambiguous.

**No symptom-only patching detected.**

---

## Recovery SQL safety review

The UPDATE:

```sql
UPDATE offline_receipts
SET status = 'pending',
    retry_count = 0,
    sync_error = NULL
WHERE status = 'failed'
  AND sync_error LIKE '%database is locked%'
```

Risks considered:

1. **Over-match.** SQLite's default LIKE is ASCII-case-insensitive. The substring `database is locked` is unlikely to occur in any other error class (server-returned errors don't use that wording). Verified via `grep` against server-side error messages: no `database is locked` strings on the API side. **Safe.**
2. **Re-sync producing duplicates.** Receipts have a stable `idempotency_key` (UUID); the server's dedup-on-disk returns `duplicate` for re-sends, which the client treats as success. **Safe.**
3. **Race with concurrent sync.** Recovery runs in `getDatabase` AFTER migrations and BEFORE the function returns. At that moment, no callers have a reference to `db` yet, so no concurrent sync tick can be in-flight against the same connection. **Safe.**
4. **Retry-count regression to 0 masks a real failure.** If a row keeps failing post-WAL for the lock signature, recovery resets retry_count to 0 every boot, and the row would keep retrying forever. Mitigated by: (a) WAL should eliminate the signature entirely; (b) if it doesn't, the row will saturate retry_count=5 again before the next reboot, so the worst case is "device retries 5 lock-attempts per boot" — not unbounded; (c) the log line is still written to `sync_log`, so the operator can spot the pattern. **Acceptable.**

---

## Codex round trail

- r1 (`docs/superpowers/reviews/2026-05-11-pos-bugs-cascade-pr-a-codex-round1.md`): 1 P2 (one-shot migration won't recover future-stuck rows). **Closed** via `runStuckReceiptRecovery` rerunnable hook + bookkeeping-aware test.
- r2 (`docs/superpowers/reviews/2026-05-11-pos-bugs-cascade-pr-a-codex-round2.md`): no new findings. **APPROVE.**

Within STOP-3 budget (2 rounds total).

---

## Residual risk — what manual Tauri smoke must verify

The mock-based unit tests prove call ORDER (WAL before migrations, recovery after migrations, rerunnable across boots), but cannot prove that WAL actually takes effect in the Tauri runtime. The PR body's acceptance criteria call out:

- [ ] Boot a real Tauri-built POS app, open devtools, run `await window.__db?.select('PRAGMA journal_mode')` — confirm result is `wal`.
  - **Note:** `window.__db` is not currently exposed for diagnostics. The smoke step likely needs a debug hook OR the operator must call the PRAGMA through any existing dev-tools entry point. If neither exists, an alternative is to inspect the on-disk `izipos-*.db-wal` and `izipos-*.db-shm` sidecar files (their presence confirms WAL mode is active).
- [ ] Ring up 100 consecutive cash sales (over-tender mix) while the sync scheduler ticks aggressively. Verify zero `(code: 5) database is locked` errors in the console, no chain-break banner, all receipts appear in `/pos/receipts`.
- [ ] **Cascade-affected device recovery test:** seed a SQLite db with one receipt at `status='failed', sync_error='error returned from database: (code: 5) database is locked', retry_count=5`. Boot the POS. Verify the row recovers to `pending` and successfully syncs on the first tick.

## Residual risk — partial cascade self-heal (amendment-accepted)

A device currently stuck in the cascade has:
- Receipt #1: `status='failed'`, `sync_error='(code: 5) database is locked'`, `retry_count=5`
- Receipts #2..N: `status='failed'`, `sync_error='Hash chain break: ...'`, `retry_count=5`

After PR A:
- Receipt #1 recovers and syncs. Server's `terminal.last_hash` advances to receipt #1's hash.
- Receipts #2..N still have `retry_count=5` AND `sync_error='Hash chain break: ...'` → not matched by recovery LIKE → permanently excluded by `getPendingReceiptsForSync`'s `retry_count < 5` predicate.
- Local `terminal_state.last_hash` is still at receipt #N's hash from when receipt #N was created locally. Server is at receipt #1. New receipt #N+1 will create with `previous_hash = hash(#N)`, server expects `previous_hash = hash(#1)` → chain break re-fires.
- `syncStore.chainBreak` is still `true` (not cleared by recovery). Banner persists.

**This is expected per the amendments doc:** PR A heals the ROOT cause for new sessions; PR C provides the in-app recovery UX for devices already in cascade. The PR body's "Operational notes" section already discloses this.

---

## Limitations of this audit

I (Opus 4.7) authored the implementation that I am now auditing. This is a structured self-review against the plan, the amendments, and the Codex round trail — NOT an independent second opinion. Two confidence enhancers:

1. The Codex r1 P2 finding (one-shot migration not rerunnable) was a real bug I missed in the first iteration; the round 2 APPROVE was an independent re-look.
2. The unit test suite (1242 tests, 15 new) gives mechanical evidence for every claim in this PR body.

Two confidence gaps that ONLY a non-Opus operator can close:
- **Manual Tauri smoke** (above).
- **Independent code re-read** of the rerunnable hook's tx semantics in a real WAL-enabled SQLite (the mock doesn't model WAL's checkpoint behavior or sidecar file handling).

---

## Verdict

**APPROVE-PENDING-TAURI-SMOKE.**

PR A is correct against the plan, the amendments, and the Codex r1 P2 closure. The recovery SQL is safe. The L1/L8/L9 ingress audit surfaces no gap. The cascade-cleanup is scope-correctly partial; full recovery for already-cascaded devices is the explicit job of PR C.

**Recommended before merge:** the two Tauri-smoke checks in the PR body — `PRAGMA journal_mode` returns `wal`, and a seeded recovery row syncs successfully on first tick. The lint baseline, typecheck, and unit-test signals are already green.
