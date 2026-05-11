# PR A — Codex Round 1 Review

**Subject:** fix(pos): SQLite WAL + stuck-receipt recovery + sync_error preservation
**PR:** https://github.com/otospexsolutions/erp/pull/120
**Base:** `dev` (tip `851e8dd1` at review time)
**Branch:** `fix/pos-sqlite-wal-busy-timeout` (head `3d499894`)
**Date:** 2026-05-11

## Findings

### [P2] Move lock recovery out of one-shot migration

`apps/pos/src/lib/db/migrations.ts:819-825`

When a database has already applied v32, this `UPDATE` will never run again because `_migrations` records the version after the first launch. If a receipt later dead-letters with `sync_error` containing `database is locked` and reaches the retry cap, the next app launch skips this migration and `getPendingReceiptsForSync` still excludes it, so the intended automatic recovery does not happen. This recovery needs to run during startup outside the one-shot migration path, or otherwise be explicitly rerunnable.

## Verdict

**REQUEST-CHANGES** — single P2 must close before merge.

## Resolution applied (round 2 prep)

Extracted the recovery UPDATE into `runStuckReceiptRecovery(db)` invoked from `getDatabase` after `runMigrations`. The migration v32 stays as the retroactive marker logged in `_migrations`; the runtime hook makes the same UPDATE rerunnable on every boot (idempotent — its WHERE clause is empty when there's nothing to recover). New mock-based test asserts the hook runs after migrations and is invoked on every `getDatabase` call.

## Raw codex output

```
codex
The WAL setup and error coercion compile and tests pass, but the added recovery is implemented as a one-shot migration, so it cannot recover later lock-related dead-lettered receipts on subsequent launches.

Review comment:

- [P2] Move lock recovery out of one-shot migration — apps/pos/src/lib/db/migrations.ts:819-825
  When a database has already applied v32, this `UPDATE` will never run again because `_migrations` records the version after the first launch. If a receipt later dead-letters with `sync_error` containing `database is locked` and reaches the retry cap, the next app launch skips this migration and `getPendingReceiptsForSync` still excludes it, so the intended automatic recovery does not happen. This recovery needs to run during startup outside the one-shot migration path, or otherwise be explicitly rerunnable.
```
