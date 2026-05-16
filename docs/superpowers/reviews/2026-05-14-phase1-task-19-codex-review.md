# Task 19 — `OutboxIngestor.ingest()` — Codex Review

**Commit reviewed:** `f202f5ef9` on `feat/pos-fiscal-event-engine-phase1`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1`
**Files under review:**
- `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php`
- `apps/api/app/Modules/Fiscal/Application/DTOs/FiscalEventEnvelope.php`
- `apps/api/app/Modules/Fiscal/Application/DTOs/IngestionResult.php`
- `apps/api/tests/Feature/Fiscal/OutboxIngestorTest.php`
- `.github/workflows/ci.yml` (PG merge-gate filter)

**Reviewer:** Codex (GPT-5.4 via codex-rescue)
**Date:** 2026-05-16

> **Transcribed from inline Codex output** — the sandbox could not write to the fiscal-phase1 worktree (only the main worktree at `/Users/houssamr/Projects/syneriva/apps/erp`), so the parent session transcribed verbatim per handoff §4.2 rule 4.

---

## Verdict

**BLOCK**

5 findings at BLOCKER/P1 level. The review found BLOCKERs Opus missed (T19-B2, T19-B4), elevated one finding Opus called P1 to BLOCKER (T19-B3 — the server-side `pos_terminals.genesis_seed` column exists; the subagent's deferral was unjustified), and converged with Opus on T19-B1 (the try/catch-on-QueryException PG transaction-aborted hazard, already a closed BLOCKER in spec round-4).

---

## Findings Table

| Severity | ID | File:Line | Description | Required Fix |
|---|---|---|---|---|
| BLOCKER | T19-B1 | `OutboxIngestor.php:139` | Uses `insert()` then catches `QueryException` inside a transaction. On PG a unique violation aborts the transaction — the subsequent SELECT and quarantine insert are unsafe. Spec §7.2 requires `INSERT ... ON CONFLICT DO NOTHING RETURNING id`. | Rewrite as raw `INSERT ... ON CONFLICT ON CONSTRAINT fiscal_events_tenant_terminal_sequence_unique DO NOTHING RETURNING id`; branch on returned id. |
| BLOCKER | T19-B2 | `OutboxIngestor.php:682` | Any unique violation is treated as sequence-slot conflict, but `fiscal_events_source_event_unique` also exists on `(source_event_class, source_event_id)`. Wrong quarantine path for source-uniqueness violations. | Scope conflict handling to sequence constraint only; handle source-unique constraint separately. |
| BLOCKER | T19-B3 | `OutboxIngestor.php:205` | First event only checks `sequence_number === 1`; does not validate `previous_hash` against terminal genesis seed. Plan §1464 is explicit. `pos_terminals.genesis_seed` exists server-side (verified at `2026_01_08_190429_create_pos_terminals_table.php:41`). | Fetch genesis seed for terminal on seq=1 and enforce `previous_hash == genesis_seed`; add test for invalid genesis. |
| BLOCKER | T19-B4 | `FiscalEventEnvelope.php:45` | No `fromArray()` or constructor invariants; ingestor does not regex-validate hashes before DB write. Malformed hashes bypass quarantine and hit PG CHECK constraints instead. | Add ingestor-layer invariant checks for UUIDs, hashes, datetime strings before any DB write. |
| P1 | T19-P1 | `OutboxIngestor.php:70` | 24h drift threshold compares device event time to ingestion time — flags legitimate multi-day offline batches. SoT requires devices to operate days offline. | Make clock drift offline-aware; anchor rollback to prior device event time, not server ingest time. |
| P1 | T19-P2 | `OutboxIngestor.php:102` | `server_received_at` is PHP `CarbonImmutable::now('UTF-8')`, not DB `NOW()` as spec §7.2 requires. NTP drift on app server can corrupt the timestamp record. | Use `DB::raw('NOW()')` for `server_received_at`, or serialize explicit UTC offset and document the deviation. |
| P2 | T19-P3 | `OutboxIngestor.php:620` | Sequence-conflict admin alert is `Log::critical()` only. `fraud_alerts.user_id` is non-null FK-bound and no system user exists. | Add a durable compliance channel (seeded system user, `compliance_alerts` table, or dispatched Event). |
| P2 | T19-P4 | `OutboxIngestorTest.php:340` | Post-commit test asserts `Queue::assertNothingPushed()` on success only; no rollback-suppresses-dispatch assertion. Closure is currently no-op. | Add rollback test; assert projection row IDs are captured and queued after commit (needed when Task 23 lands). |
| P2 | T19-P5 | `OutboxIngestor.php:559` | Idempotent comparison covers all 5 required fields, but no test exercises source-only mismatch routing to `sequence_conflict`. | Add test for mismatched `source_event_id` on otherwise-identical envelope. |

---

## JC1–JC5 Verdicts

**JC1 — Clock thresholds:** REQUEST CHANGES. Rollback anchor is prior `event_time_device` for same terminal (correct). 24h drift threshold is too tight for multi-day offline batches. `server_received_at` is PHP time, not DB `NOW()` — raises T19-P2.

**JC2 — Genesis-seed validation:** BLOCKER (T19-B3). Genesis validation is explicitly deferred in comments. Attacker submitting `sequence_number=1` with arbitrary `previous_hash` bypasses all linkage. `pos_terminals.genesis_seed` exists server-side — no technical blocker to enforcing it now.

**JC3 — Log::critical vs FraudAlert:** Accepted gap for Phase 1, but flagged P2. `FraudAlert.user_id` is truly non-null FK-bound; no system user found. Logs are not paged/operator-visible as a compliance channel.

**JC4 — try/catch vs ON CONFLICT:** BLOCKER (T19-B1 + T19-B2). The conflict SELECT is correctly scoped to `(tenant_id, terminal_id, sequence_number)` and the UNIQUE constraint exists — but the primitive is wrong. PG aborts the transaction on unique violation, making the catch branch unreachable with correct PG semantics. Must use `ON CONFLICT ... RETURNING`.

**JC5 — Post-commit dispatch:** P2 gap (T19-P4). `DB::afterCommit()` hook exists and captures row IDs. Body is a no-op placeholder. Rollback suppression is not tested. Seam is a one-line change when Task 23 lands — acceptable, but rollback test must be written before Task 23 ships.

---

## Verified by Grep / Read

- No `FiscalEventEnvelope::fromArray()` exists — confirmed by grep, not inferred.
- No `INSERT ... ON CONFLICT` anywhere in `OutboxIngestor.php` — confirmed by grep.
- `StrictCanonicalParser` enforces canonical envelope regexes and returns `ParseResult::failure()` on malformed canonical bytes — read directly.
- `FiscalEventProjectionRegistry` constructor asserts duplicate names / empty module tokens — read directly.
- No `Eloquent::find()` in ingestor path — grep confirmed.
- `pos_terminals.genesis_seed` column exists — migration confirmed (`2026_01_08_190429_create_pos_terminals_table.php:41`).
- `fiscal_events_source_event_unique` constraint exists on `(source_event_class, source_event_id)` — migration confirmed (`2026_05_14_100001_create_fiscal_events_table.php:100`).
- Local SQLite test suite: `OK (11 tests, 61 assertions)`.
- PG merge-gate test was sandbox-blocked (TCP to 127.0.0.1:5432 unavailable) — not verified at PG driver level.

---

## Summary

Task 19's logic flow follows the §7.2 contract — validate-then-insert, derive integrity status, dispatch projection rows in T1, after-commit-enqueue. The structural concerns surface at the boundaries: the conflict-detection primitive is wrong on PG (T19-B1, a regression of a closed BLOCKER), the conflict path doesn't distinguish the two unique constraints (T19-B2), the first-event genesis-seed check was deferred without justification (T19-B3 — the server-side column exists), and the envelope itself lacks construction-time invariants so malformed inputs bypass quarantine and hit PG CHECK constraints (T19-B4). All four are real and reproducible against the migrations + the spec.
