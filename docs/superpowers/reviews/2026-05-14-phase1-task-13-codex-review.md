# Codex review — Phase 1 Task 13 (device SQLite fiscal_events table)

**Commit:** 442ea109
**Branch:** feat/pos-fiscal-event-engine-phase1
**Date:** 2026-05-16
**Verdict:** BLOCK

## Summary

Task 13 is close on the table shape, append-only trigger model, idempotency guards, and migration rerun behavior, but I found one BLOCKER: the SQLite hash-format CHECK does not enforce "64 lowercase hex characters." It enforces length 64 plus "first character is lowercase hex and no underscores." Non-hex characters after position 1 are accepted. That violates spec v7 §3.1, which defines `previous_hash` and `current_hash` as 64-char lowercase hex, and §3.3's note that hashes are lowercase hex everywhere.

The rest of the migration is generally sound: the `BEFORE UPDATE OF` list is exhaustive for the current column set, a mixed sync+non-sync UPDATE is rejected by SQLite, `ALTER TABLE ADD COLUMN` reruns are guarded, and all indexes/triggers use `IF NOT EXISTS`.

## Findings — BLOCKER / P1 / P2 / P3

**BLOCKER — SQLite hash CHECK accepts non-hex hashes after the first character.**

Spec v7 §3.1 requires `previous_hash` and `current_hash` to be 64-char lowercase hex strings (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:119`, `:120`), and §3.3 reiterates that hashes are lowercase hex everywhere (`:225`). The migration claims the same invariant in comments (`apps/pos/src/lib/db/migrations.ts:913`-`:915`) but implements:

```sql
CHECK (length(current_hash)  = 64 AND current_hash  GLOB '[0-9a-f]*' AND length(replace(current_hash,  '_', '')) = 64)
CHECK (length(previous_hash) = 64 AND previous_hash GLOB '[0-9a-f]*' AND length(replace(previous_hash, '_', '')) = 64)
```

at `apps/pos/src/lib/db/migrations.ts:966`-`:967`. In SQLite, `GLOB '[0-9a-f]*'` means "first char is `[0-9a-f]`, followed by any suffix." My direct `node:sqlite` probe accepted `aZZZZ...` and `0!!!!...` as 64-character hashes. It rejected uppercase at position 1, 63-char strings, 65-char strings, and underscores, but it does not enforce all 64 characters as lowercase hex. The test only tries `NOT_HEX` (`apps/pos/src/lib/db/__tests__/migrations.v37.test.ts:206`-`:213`), which fails because the first character is uppercase/non-matching, so it is a false positive for the full invariant.

**P2 — Immutability tests prove rejection but not post-failure state, and they do not cover trigger exhaustiveness.**

Spec v7 §3.3 says the device trigger must abort updates unless only `sync_status`, `sync_error`, and `synced_at` change, and must abort deletes (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:221`-`:223`). The migration's `BEFORE UPDATE OF` list is currently exhaustive: table columns are declared at `apps/pos/src/lib/db/migrations.ts:929`-`:964`; the trigger lists every non-sync column at `:1023`-`:1034`; only `sync_status`, `sync_error`, and `synced_at` are omitted as allowed lifecycle columns.

The tests only assert that three blocked updates throw (`apps/pos/src/lib/db/__tests__/migrations.v37.test.ts:220`-`:234`) and that deletes throw (`:260`-`:264`). They do not SELECT the row afterward to prove the failed statement left `current_hash`, `sequence_number`, `canonical_bytes`, and row presence unchanged. They also do not exercise every non-sync column in the `UPDATE OF` list. I directly verified SQLite's trigger interaction: a single statement that sets both an allowed column and a listed frozen column is rejected, and the row remains unchanged. That behavior is correct, but it is not locked by the committed tests.

**P3 — Empty-string chain-head defaults are an intentional additive-migration sentinel, but Task 15 must treat them as uninitialized.**

Spec v7 §6.2 says `terminal_state` gains one fiscal-event chain head and that the genesis seed is issued once by server provisioning (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:315`-`:318`). Spec v7 §3.3 says the first event's `previous_hash` uses the terminal fiscal-event genesis seed (`:225`). This migration adds `fiscal_event_genesis_seed` and `fiscal_event_last_hash` as `TEXT NOT NULL DEFAULT ''` plus sequence `0` (`apps/pos/src/lib/db/migrations.ts:1055`-`:1058`) and documents that Task 15 wires the boot path (`:1050`-`:1053`).

That is acceptable for a non-breaking schema add, but the downstream append path must reject or initialize `fiscal_event_sequence = 0` with empty `fiscal_event_genesis_seed`/`fiscal_event_last_hash`. Otherwise the engine can accidentally hash an empty previous hash instead of a genesis seed. I could not verify Task 15 behavior from this commit, so the downstream risk is UNVERIFIED.

## What works well

- The device column set matches spec v7 §3.1: all 36 columns from `id` through `synced_at` appear in the migration (`apps/pos/src/lib/db/migrations.ts:929`-`:964`) and in the column-shape test (`apps/pos/src/lib/db/__tests__/migrations.v37.test.ts:119`-`:165`).
- Trigger exhaustiveness is correct for this commit's table: frozen columns are `id`, `tenant_id`, `company_id`, `terminal_id`, `operator_id`, `event_type`, `event_version`, `signature_version`, `sequence_number`, `event_time_device`, `business_date`, `last_server_time_seen`, `reference_event_id`, `reference_document_id`, `source_event_class`, `source_event_id`, `partner_id`, `partner_identity_snapshot`, `canonical_bytes`, `previous_hash`, `current_hash`, all signature/provider columns, `time_source_value`, `time_format`, `provider_transaction_id`, and `created_at`; all appear in the `UPDATE OF` list (`apps/pos/src/lib/db/migrations.ts:1023`-`:1034`). The only omitted columns are the allowed sync lifecycle fields.
- The chain uniqueness test proves both sides of the invariant: same sequence on a different terminal is allowed and same `(tenant, terminal, sequence)` is rejected (`apps/pos/src/lib/db/__tests__/migrations.v37.test.ts:167`-`:179`).
- Migration reruns are guarded: the table/index/trigger DDL uses `IF NOT EXISTS` (`apps/pos/src/lib/db/migrations.ts:928`, `:980`, `:986`, `:994`, `:1002`, `:1007`, `:1021`, `:1043`), and the `ALTER TABLE ADD COLUMN` statements catch duplicate-column errors (`:1060`-`:1068`, `:1074`-`:1080`).
- Device/server column asymmetry is intentional per spec: server-only mirror/projection fields (`server_received_at`, integrity/quarantine fields, derived `payload`, `payload_parse_status`) are in spec v7 §3.2 (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:157`-`:188`) and server migration lines `apps/api/database/migrations/2026_05_14_100001_create_fiscal_events_table.php:43`-`:88`; device-only sync lifecycle fields are in spec v7 §3.1 (`:133`-`:137`) and migration lines `apps/pos/src/lib/db/migrations.ts:961`-`:964`.
- `source_event_class` being device `TEXT` and server `VARCHAR(255)` matches spec v7 §3.1/§3.2 (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:114`, `:160`). I do not see a Task 13 issue there.
- Sync status values match exactly: spec v7 §3.1 lists `pending|syncing|synced|failed` (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:133`-`:134`), and the migration CHECK uses `('pending', 'syncing', 'synced', 'failed')` (`apps/pos/src/lib/db/migrations.ts:968`).
- `offline_receipts.canonical_bytes` is nullable by design in this commit (`apps/pos/src/lib/db/migrations.ts:1071`-`:1075`) to avoid breaking projection-only callers before Task 15 wiring (`:909`-`:911`). Also, the prompt's contrast with server-side `pos_receipts.canonical_bytes NOT NULL` does not match the current worktree: the Task 11 server migration adds `pos_receipts.canonical_bytes BYTEA NULL` and documents the legacy-row rationale (`apps/api/database/migrations/2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php:18`-`:20`, `:55`-`:59`).
- The `node:sqlite` availability guard in the new v37 test (`apps/pos/src/lib/db/__tests__/migrations.v37.test.ts:5`-`:14`) matches the existing v32 pattern (`apps/pos/src/lib/db/__tests__/migrations.v32.test.ts:5`-`:14`).

Remaining coverage gaps from spec v7 §3.1/§3.3: exact hash format edge cases, `sequence_number > 0`, invalid `sync_status`, invalid `signature_status`, source-class/source-id paired-null validation, NOT NULL/default checks beyond presence, post-abort unchanged-row assertions, mixed allowed+blocked UPDATE, and broader trigger coverage over every non-sync column.

## Verification I ran

- `git -C /Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1 show 442ea109`
- `cd /Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/pos && pnpm vitest run src/lib/db/__tests__/migrations.v37.test.ts` — passed: 12 tests, 1 file.
- `grep -n 'GLOB' /Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/pos/src/lib/db/migrations.ts`
- `ls /Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/api/database/migrations/ | grep fiscal_events`
- Read the server-side fiscal events migrations:
  - `apps/api/database/migrations/2026_05_14_100001_create_fiscal_events_table.php`
  - `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php`
- Read grounding docs:
  - `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md` §3.1, §3.3, §4, §6.2
  - `docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md` §1, §3, §4
  - `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md` Task 13
  - `docs/superpowers/coordination/2026-05-14-pos-fiscal-engine-session-handoff.md` §4.2
- Direct `node:sqlite` probe of the exact hash CHECK:
  - accepted: 64 lowercase hex, 64 zeros, `a` + 63 `Z`, `0` + 63 `!`
  - rejected: 64 uppercase `A`, 63 chars, 65 chars, underscores after first char
- Direct `node:sqlite` probe of `UPDATE OF` interaction:
  - `UPDATE u SET sync_status='synced', frozen='new'` fired the frozen-column trigger, rejected the statement, and left both fields unchanged.
