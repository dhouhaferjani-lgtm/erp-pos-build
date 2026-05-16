# Task 19 — `OutboxIngestor.ingest()` — Opus Adversarial Review

**Date:** 2026-05-16
**Reviewer:** Opus (adversarial subagent)
**Commit:** `f202f5ef9` (HEAD, unpushed — no CI/PG verification yet)
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1`
**Branch:** `feat/pos-fiscal-event-engine-phase1`

---

## Verdict: **REQUEST-CHANGES** (1 BLOCKER, 1 P1, 4 P2, 2 P3)

The implementation is well-structured, internally consistent, and the test suite locally passes 11 of 11 with phpstan level 8 clean. **However, JC4 — the try/catch-on-QueryException-then-SELECT pattern — is a load-bearing correctness BLOCKER on PostgreSQL**: a unique violation puts the surrounding transaction into "aborted" state, and the follow-up `SELECT` in `handleConflict()` is then refused with `current transaction is aborted, commands ignored until end of transaction block`. The local test suite passes only because it runs on SQLite, which does not share PG's transaction-aborted semantics. CI on the PR has not yet been run against PostgreSQL (the Task 19 commit is local). This finding was already flagged on the spec round-4 review at line 41-48 of `2026-05-14-pos-fiscal-event-engine-phase1-codex-review-round4.md` and resolved in the spec by mandating `INSERT … ON CONFLICT DO NOTHING RETURNING id` as "the atomic primitive" (spec §7.2 line 380). The implementation deviates from that primitive without re-validating PG semantics — and the deviation reintroduces the exact BLOCKER the spec round 4 closed.

I verified this empirically against a local PostgreSQL 15.15 instance: the second INSERT triggers `ERROR: duplicate key value violates unique constraint`, and the very next SELECT in the same transaction returns `ERROR: current transaction is aborted, commands ignored until end of transaction block`. The fix is the spec-mandated raw `INSERT ... ON CONFLICT DO NOTHING RETURNING id` (preferred) or a `SAVEPOINT` / `ROLLBACK TO SAVEPOINT` wrapper around the INSERT alone.

A secondary BLOCKER concern (genesis-seed bypass for first-event linkage) is **P1, not BLOCKER**, on balance — see JC2 below.

---

## Findings table

| Severity | ID | Location | Description | Fix |
|---|---|---|---|---|
| **BLOCKER** | B1 | `OutboxIngestor.php:138-152` + `:682-697` | `try/catch (QueryException) { SELECT existing }` aborts the surrounding PG transaction. The subagent's JC4 claim "functionally equivalent on PG" is wrong: PG drops the transaction into aborted state on unique violation; any subsequent statement in the same tx — including the `SELECT` in `handleConflict()` — fails with `current transaction is aborted`. Verified locally on PG 15.15 (see "Empirical PG repro" below). Spec §7.2 line 380 explicitly mandates `INSERT … ON CONFLICT DO NOTHING RETURNING id` precisely to avoid this. The local test suite passes only because it runs on SQLite. CI has not yet exercised the PG path for this commit. | Use raw `INSERT ... ON CONFLICT (tenant_id, terminal_id, sequence_number) DO NOTHING RETURNING id` (PG) — switch on driver for SQLite which supports `INSERT ... ON CONFLICT DO NOTHING` since 3.24. Alternatively, wrap **only the INSERT** in `DB::transaction()` (which Laravel implements via SAVEPOINT for nested transactions — verified at `vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php:158-160`), then ROLLBACK-to-savepoint via the inner-tx-rolling-back path. Add a regression test that runs against PG (or at minimum a CI assertion that the test class is in the PG merge-gate filter — which it is, line 358 of ci.yml — but the test must actually exercise the conflict path to surface this). |
| **P1** | F1 | `OutboxIngestor.php:213-224` | Genesis-seed bypass for first-event linkage. Plan §1464 mandates "for a first event (no prior row), `linkage_ok` iff `sequence_number == 1` **AND** `previous_hash == terminal fiscal_event_genesis_seed`." Implementation only checks `sequence_number == 1`; any `previous_hash` is accepted. An attacker (or a buggy device) who can produce a SHA-256-consistent envelope can submit `sequence_number=1` with an arbitrary `previous_hash` and pass linkage. The verify-chain command (Task 27, not yet built) would catch this offline, and the canonical_bytes contains the previous_hash so unilateral forgery requires device-authoring capability — but the in-band check the plan mandates is missing. | Either (a) add a server-side `terminal_state` mirror at terminal provisioning (canonical fix — mirrors spec §6.2 line 317 architecture), or (b) explicitly document the deferral in the spec and bump verify-chain (Task 27) to detect a missing first-event genesis-seed match at chain-verification time. Option (b) MUST be a tracked open item, not a silent deferral — the current implementation comment says "**Documented follow-up: ... flagged for reviewers**", which is the right disposition but the deferral needs an actual open-item bullet in §18 of the spec or a tracked issue. The current state — silent in spec, comment-in-code only — is a P1 because the plan explicitly named the check the implementation skips. |
| P2 | F2 | `OutboxIngestor.php:574-576` | Sequence-conflict reason string is malformed when `$diff === []`. `'sequence_conflict:different_event_at_occupied_slot;'.implode(',', $diff !== [] ? $diff : ['unspecified_difference'])` — when `$diff` is empty, the only possible path is "all 3 compared fields match but matches=false" — which means the conflict is solely on `source_event_class` or `source_event_id` mismatch (per the matches check at :537-541), but the reason builder does NOT enumerate those. The reason string will read "unspecified_difference" even though a forensically meaningful diff exists. | Extend `buildConflictReason()` to also diff `source_event_class` and `source_event_id`. The matches check at :537-541 compares 5 fields; the reason builder compares 3. |
| P2 | F3 | `OutboxIngestor.php:445-460` | Defensive try/catch around `activeProjectorsFor()` is dead code under the current registry implementation. Task 18 F1 already catches resolver throws internally. The only way this catch fires is if the registry's `__construct` throws (LogicException for duplicate names / empty token) — but the registry is a singleton, materialized once at first resolution, not per-ingest. So in steady state this catch can never fire. Untested. The handoff §4.2 standing pattern argues for it, but the test mislabels it: `test_resolver_exception_does_not_crash_ingest` actually tests Task 18 F1, not the ingestor's own catch. | Either (a) remove the defensive wrap if it's truly unreachable (and update the comment), or (b) add a test that EXPLICITLY breaks the registry singleton (e.g., rebind a `FiscalEventProjectionRegistry` whose `activeProjectorsFor()` always throws) to verify ingest still succeeds. (a) is cleaner; (b) preserves defense-in-depth. |
| P2 | F4 | `OutboxIngestorTest.php:340-358` | `test_projection_dispatch_only_after_commit` asserts `Queue::assertNothingPushed()`, which is a Phase-1-bound check — Task 23's `ApplyFiscalEventProjectionJob` doesn't exist yet, so by definition no job can be pushed. The test does NOT prove the `DB::afterCommit()` semantics it claims to lock. There is no test that verifies "rolled-back T1 → no enqueue", and there is no test that verifies "after-commit fires AFTER commit, not before". When Task 23 lands and dispatches a real job from the closure, an incorrectly-placed dispatch (before-commit) would NOT be caught by the current test. | Add a test that wraps the ingest in an outer `DB::transaction()` that ROLLBACKs after `ingest()` returns, and assert (a) the fiscal_events row is gone (b) `Queue::assertNothingPushed()`. Add another test that inserts a real `Queue::push` smoke target inside the after-commit closure (via a mock job class) and asserts it was dispatched. Without these two, the test name overpromises. |
| P2 | F5 | `OutboxIngestor.php:535-541` | `source_event_class` and `source_event_id` are compared with `===` after stringifying nulls/values. The stringification at :534-535 has a defensive `is_string($existing->source_event_class) ? ... : (string) $existing->source_event_class` — but `(string) null === ''`, not `null`. The chained `=== null ? null : ...` at :534-535 handles the null path correctly. However, `hash_equals` is used for `currentHash` / `canonicalBytes` (constant-time compare — correct), but `===` is used for id / class / source-id. The id comparison at :537 is fine (UUIDs are not secret), but if any of these inputs were later treated as secret-comparison targets, the inconsistency would matter. Not a security issue today. | Document the choice: `hash_equals` for hash-like fields, `===` for identifiers. The current inconsistency is correct but not obvious. |
| P3 | F6 | `OutboxIngestor.php:102` + `:280` | Drift calculation uses `CarbonImmutable::now('UTC')` (PHP wall clock), not `DB::raw('NOW()')`. In a horizontally-scaled deployment with slightly out-of-sync web boxes the same envelope could see different `server_received_at` values depending on which web box receives it. The value is then also persisted to `fiscal_events.server_received_at`, which is read by verifier / audit code. Minor — NTP-synced production hosts are well within the 24h tolerance. | Consider `CarbonImmutable::createFromFormat('Y-m-d H:i:s.u', DB::selectOne('SELECT NOW()::text AS n')->n, 'UTC')` for the drift check and the persisted timestamp. Phase-1 fine to defer; flag in §18. |
| P3 | F7 | `OutboxIngestor.php:83` | Clock drift threshold is a hardcoded private const. The spec leaves it normative-text-only (§10 line 531). 24h is the subagent's judgment call (JC1). The constant should at minimum be `protected` (so a test subclass can override) or read from `config('fiscal.clock_drift_limit_seconds')` so a per-deployment override is possible. Spec §18 (open items) should track that this knob exists. | Move to `config('fiscal.clock_drift_limit_seconds', 86400)`. Add a §18 open item documenting the threshold's value + rationale. |

CLEAN/Verified:
- §7.2 atomicity invariant (Step 3 — `fiscal_events` INSERT + `fiscal_event_projections` row inserts in same T1) — confirmed at `OutboxIngestor.php:138-165` (the entire happy path runs inside `db->transaction()`).
- §7.2 conflict-resolution 5-field comparison — confirmed at `:537-541` (id, current_hash, canonical_bytes, source_event_class, source_event_id). Order matches spec §7.2 lines 361-365.
- §7.2 quarantine for sequence_conflict — confirmed at `:579-637`: typed envelope verbatim (`canonical_bytes` + `raw_envelope`) + all 14 metadata columns, `integrity_exception_class = 'sequence_conflict'`, mandatory reason.
- §7.5 suppression for canonical_parse_failure — confirmed at `:424-427` (early return in `dispatchProjections` when class === CanonicalParseFailure). Also confirmed projection-rows-count test.
- §7.5 dispatch-after-commit — `DB::afterCommit()` correctly used at `:487-494`. (But see F4: test doesn't actually prove this invariant.)
- §8 in-table quarantine for the 4 admissible classes — confirmed at `:308-355` (deriveIntegrity): `canonical_hash_mismatch`, `time_anomaly`, `sequence_gap`, `canonical_parse_failure` all set `IntegrityStatus::Quarantined` in fiscal_events; only `sequence_conflict` goes to `fiscal_event_quarantine` (via `:579+`).
- §8 mandatory structured reason — confirmed: every class emits a structured `integrity_exception_reason` (`:310-322`).
- CI filter — `.github/workflows/ci.yml:358` correctly includes `OutboxIngestorTest` in the PG merge-gate filter alongside the existing 10 PG-only tests.
- Idempotent re-delivery returns `stored=false` — confirmed at `:543-544` via `IngestionResult::idempotent()` (DTO `:75-83`); test asserts `assertFalse($second->stored)` at :223.
- PHPStan level 8 — clean (0 errors) on `OutboxIngestor.php` + both DTOs.
- Carry-forward standing patterns (handoff §4.2):
  - No `(type) $array['key']` casts — confirmed; all DB-row reads use `is_string` / `is_int` guards before `(string)` casts (e.g. `:226-229`, `:264-266`).
  - Fail-closed on downstream-service exception — Task 18 F1 in the registry; the OutboxIngestor adds a second-line defense (`:443-460`) — see F3 for the dead-code concern.
  - Regex-validate free-form fields at the boundary — deferred to controller (Task 20), but the OutboxIngestor's `verifyClock` defensively swallows Carbon parse failures (`:255-259`, `:262-277`) and the canonical_bytes themselves are regex-checked by StrictCanonicalParser. Acceptable Phase 1 split.
  - Constructor-asserted invariants (Task 18) — N/A directly; the OutboxIngestor is stateless and has no boot-time invariants of its own. The registry's invariants (Task 18 F2/F3) cover the upstream config-shape concern.

---

## Empirical PG repro for BLOCKER B1

I ran the following against a local PostgreSQL 15.15 instance:

```sql
CREATE TABLE t (id int primary key, val text);
INSERT INTO t VALUES (1, 'first');
BEGIN;
INSERT INTO t VALUES (1, 'second');   -- duplicate, raises 23505
SELECT 'after_failure' AS status;     -- this is what handleConflict() does
COMMIT;
```

Output:

```
INSERT 0 1
BEGIN
ERROR:  duplicate key value violates unique constraint "t_pkey"
DETAIL:  Key (id)=(1) already exists.
ERROR:  current transaction is aborted, commands ignored until end of transaction block
ROLLBACK
```

The second `ERROR` is the precise failure mode the implementation will hit in production. The `COMMIT` becomes a `ROLLBACK`. The `SELECT existing` in `handleConflict` never returns rows.

I verified the SAVEPOINT fix also works:

```sql
BEGIN;
SAVEPOINT before_insert;
INSERT INTO t VALUES (1, 'second');   -- raises 23505
ROLLBACK TO SAVEPOINT before_insert;
SELECT * FROM t WHERE id=1;            -- returns 'first', as desired
COMMIT;
```

Output:

```
BEGIN
SAVEPOINT
ERROR:  duplicate key value violates unique constraint "t_pkey"
ROLLBACK
 status                   | id | val
--------------------------+----+-------
 after_savepoint_rollback |  1 | first
COMMIT
```

The cleanest fix is to use raw `INSERT … ON CONFLICT DO NOTHING RETURNING id` (the spec-mandated primitive). Laravel does not expose this in the Eloquent query builder; you'll need `DB::statement` or `DB::selectOne` with a parameterized SQL string. SQLite 3.24+ supports the same syntax, so the path is portable.

---

## Explicit JC1-JC5 verdicts

### JC1 — §10 clock thresholds (rollback + 24h drift)

**Verdict: ACCEPTABLE-AS-IS, with one tightening required.**

- Rollback-vs-prior-event: correct logic (prior's timestamp parsed defensively; if envelope's `event_time_device` strictly less than prior's → flag). Edge case: equal timestamps (two events on the same second) are allowed — that's right, since the sequence_number is the authoritative ordering (§10).
- 24h drift threshold: defensible for Phase 1. A multi-day offline batch from a terminal that's been offline >24h could trip this falsely, BUT all events in such a batch share the same NTP drift since they were authored on the same device clock — they'd all report `event_time_device` values close to each other, and the drift is measured vs `server_received_at` (which is "now" when the batch hits the server). So a 7-day offline batch authored when the device clock was correct will see drift in the hour range (since `event_time_device` is the authoring time, not the sync time), well under 24h. The 24h threshold only fires when the device clock itself was actually wrong — which is the right signal.
- Clock source: PHP `CarbonImmutable::now('UTC')`, not DB `NOW()`. P3 finding F6 — acceptable for Phase 1, flag in §18.
- **Tightening required**: F7 — move the threshold to config and track in §18.

### JC2 — Genesis-seed validation deferred for server-side first-event case

**Verdict: NEEDS TIGHTENING — bump from "silent comment-only deferral" to "tracked open item in §18".**

The deferral itself is defensible — Phase 1 has no server-side `terminal_state` mirror, and building one is scope creep. **But the plan §1464 explicitly named the check, and the implementation silently skipped it.** Acceptance requires:
1. An explicit entry in spec §18 (open items) naming the gap: "server-side first-event genesis-seed validation deferred; verify-chain (§15.1) catches it at chain-verification time, and Phase 1 has no production terminal seeded with both server-side genesis-seed and live ingestion."
2. The implementation comment at `:204-211` should reference that §18 entry by section number/anchor (not just "Documented follow-up: flagged for reviewers").
3. Optionally — and ideally — a `FIXME(server-genesis-seed-mirror)` annotation that grep-finds.

The implementation is not security-broken without it (the chain is verifiable offline; the canonical_bytes contains previous_hash; an attacker would need device authoring), but the plan-vs-code drift is real. P1 because the plan named the check.

### JC3 — `Log::critical` instead of `FraudAlert`

**Verdict: ACCEPTABLE-AS-IS.**

`FraudAlert` requires NOT NULL `user_id` (verified at `2025_12_23_160001_create_fraud_alerts_table.php:21-22`: `$table->uuid('user_id'); $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');`). A server-side `sequence_conflict` has `operator_id` from the envelope, which is a device-side identifier and is NOT guaranteed to match a `users` row — the device authoring path predates server-side user validation. Inserting a FraudAlert with a fabricated user_id would fail FK constraints. The subagent's choice to defer FraudAlert dispatch is structurally correct.

`Log::critical` is monitored in production if the deployment is wired to a log-shipping target that pages on `critical`. This is a deployment concern, not an implementation concern. The Phase 1 incident-alert path being structured-log-only is consistent with the spec saying "raise an admin alert" (§7.2 line 374) without specifying the channel.

**Minor sub-finding** (not separately enumerated; rolled into the JC3 verdict): the log context at `:629-635` is excellent — tenant, terminal, sequence, both event ids, reason. A future `ComplianceNotificationDispatcher`-backed FraudAlert (with a nullable `user_id` or a system-user route) can replace the Log::critical without changing the call site contract. Track in §18 as a Phase 2 enhancement.

### JC4 — `try/catch (QueryException)` instead of `INSERT … ON CONFLICT DO NOTHING RETURNING id`

**Verdict: REJECTED — this is the BLOCKER B1.**

The subagent's claim "functionally equivalent on PG" is wrong. See B1 + Empirical PG repro above. The spec mandates the raw primitive precisely because the try/catch pattern was already rejected as a BLOCKER in the spec round-4 review (line 41-48 of `2026-05-14-pos-fiscal-event-engine-phase1-codex-review-round4.md`).

The portability cost the subagent cited (SQLite compatibility) is solvable: SQLite 3.24+ supports `INSERT ... ON CONFLICT DO NOTHING` natively. The fallback is to use a driver-switch:

```php
if ($this->db->getDriverName() === 'pgsql') {
    $insertSql = '... ON CONFLICT DO NOTHING RETURNING id';
} else {
    $insertSql = 'INSERT OR IGNORE ...';
}
```

Or — cleaner — use Laravel's `insertOrIgnore()` (which compiles to `INSERT OR IGNORE` on SQLite and `INSERT ... ON CONFLICT DO NOTHING` on PG), then check the returned row count, and `SELECT` the existing row only when the insert was a no-op. The SELECT happens in a fresh statement, not in an aborted transaction.

### JC5 — Post-commit job dispatch is a TODO closure

**Verdict: ACCEPTABLE-AS-IS, with a tightening (F4).**

The seam is wired (`DB::afterCommit()`), the row IDs are captured, the closure is in place. When Task 23 lands, the closure body changes inside one class. Good.

The tightening is F4: the current test (`test_projection_dispatch_only_after_commit`) does not actually prove the "rollback → no enqueue" or "after-commit fires after commit" invariants. It only proves "no job pushed today, because Task 23 isn't built". When Task 23 lands, an incorrectly-placed dispatch (e.g. inside the transaction instead of `afterCommit()`) would not be caught. Add the two missing tests F4 enumerates.

---

## Test-quality concerns

- 11 tests for the most load-bearing service in the project. The plan §1361 cases (8) are all covered. The 3 defense tests are appropriate but partially mislabeled (see F3, F4).
- **Missing tests** worth adding:
  - **PG-driver transaction-aborted test (B1 regression).** Add a test that runs only on `pgsql` (skip on sqlite) and asserts an idempotent-redelivery and a sequence-conflict both succeed. The current tests only prove SQLite behavior; the PG path is unexercised locally.
  - **Genesis-seed first-event linkage** (F1, JC2). At minimum a documented `markTestSkipped` test naming the deferred check.
  - **Rolled-back outer transaction → no enqueue** (F4). Wrap `ingest()` in an outer `DB::transaction()` that rolls back; assert `fiscal_events` is empty and `Queue::assertNothingPushed()`.
  - **Real after-commit dispatch** (F4). Mock the job class minimally, dispatch via the closure, assert it fires.
  - **Non-hex `current_hash` reaches PG CHECK constraint** — the DTO accepts a non-hex string today (regex validation is deferred to Task 20). When that arrives at PG, the CHECK fires before the INSERT returns. The `isUniqueViolation` check correctly returns false, and the outer catch logs critical + re-throws. A test would lock this contract.
  - **Registry-throws-from-ctor** (F3). Exercises the OutboxIngestor's own defensive wrap.
- **`test_canonical_parse_failure_quarantines_payload_null_projection_suppressed` merges two concerns** (parse-failure quarantining + projection-suppression) into one test. Reviewer-stated intent: "verify the merged test asserts BOTH things." Verified at `:194-213`: the test asserts (a) `integrity_status='quarantined'`, (b) `canonical_parse_failure` class, (c) `payload IS NULL`, (d) `payload_parse_status='failed'`, (e) `fiscal_event_projections` count = 0. All five claimed assertions are present. The merge is acceptable.
- **No test asserts `fiscal_event_projections.projector_name` matches the registry's `name()` return value** — the test at `:132-137` does assert `'fake_sale_receipt'` is the stored projector_name, which is exactly the FakeSaleReceiptProjector's `name()`. Adequate.
- **No test for partial-failure-during-projection-row-insert.** If the bulk `fiscal_event_projections` insert at `:477` fails for one row, the entire bulk insert fails, the surrounding T1 rolls back, and the fiscal_events row is rolled back too. (Verified: bulk insert is one statement; on failure, the QueryException propagates up to the outer `db->transaction` which rolls back the entire T1.) This is a correctness issue worth verifying with a test, but the current behavior is in fact spec-compliant — projection-row failures DO roll back T1, which is the §7.5 "same transaction" invariant. The concern is rather: should they? Spec §7.5 says yes. Acceptable.

---

## Spec-section coverage table

| Spec section | Requirement | Implementation | Verdict |
|---|---|---|---|
| §7.2 line 336-339 | hash_ok, linkage_ok, clock_ok, parse_result — 4 checks BEFORE insert | `verifyHash` (`:191-194`), `verifyLinkage` (`:213-240`), `verifyClock` (`:253-290`), `parser->parse()` (`:105`) | OK |
| §7.2 line 340-341 | Derive integrity_status / class / reason / payload / payload_parse_status | `deriveIntegrity` (`:308-355`) | OK |
| §7.2 line 344-347 | `INSERT … ON CONFLICT DO NOTHING RETURNING id` (atomic primitive) | `try { INSERT } catch { SELECT existing }` — NOT the spec-mandated primitive | **BLOCKER B1** |
| §7.2 line 351-353 | Same-T1 `fiscal_event_projections` inserts (one per active projector, suppression for parse_failure) | `dispatchProjections` (`:418-495`) — registered inside `db->transaction` closure | OK |
| §7.2 line 354 | After commit: enqueue jobs | `DB::afterCommit()` (`:487-494`) — closure is a TODO until Task 23 (JC5 — OK with F4 tightening) | OK (mostly) |
| §7.2 line 359-367 | Conflict idempotent path: 5-field comparison | `:537-541` (id, current_hash, canonical_bytes, source_event_class, source_event_id) | OK |
| §7.2 line 369-377 | Non-identical conflict → `fiscal_event_quarantine`; admin alert; return sequence_conflict | `:549-557` + `quarantineSequenceConflict` (`:579-637`) | OK |
| §7.2 line 380 | "INSERT … ON CONFLICT DO NOTHING RETURNING id **remains** the atomic primitive" | Implementation does NOT use the primitive | **BLOCKER B1** |
| §7.5 suppression | `canonical_parse_failure` → no projection rows | `:424-427` early return | OK |
| §7.5 dispatch-after-commit | enqueue ONLY after T1 commits | `DB::afterCommit()` at `:487` | OK (test F4 weakness) |
| §8 in-table quarantine for 4 classes | `canonical_hash_mismatch`, `time_anomaly`, `sequence_gap`, `canonical_parse_failure` → `fiscal_events` with quarantined status | `deriveIntegrity` + buildInsertRow set `integrity_status='quarantined'` and the appropriate class | OK |
| §8 sequence_conflict | → `fiscal_event_quarantine` only | `:579-637` | OK |
| §8 mandatory reason | Every quarantine row has structured reason | `deriveIntegrity` builds `reasons[]`; `quarantineSequenceConflict` builds via `buildConflictReason` | OK |
| §10 clock check | rollback vs prior + drift vs server | `verifyClock` (`:253-290`) — 2 cases | OK (JC1 acceptable; F7 tightening) |
| §10 device clock untrusted | server_received_at is the authoritative timestamp | `:102` PHP wall-clock (F6 P3 tightening) | OK Phase 1 |
| Plan §1464 first-event genesis-seed | `previous_hash == terminal fiscal_event_genesis_seed` | NOT IMPLEMENTED — silent skip | **P1 F1** |
| Handoff §4.2 patterns | No `(type) $arr[]` casts; fail-closed on downstream-service exception; constructor-asserted invariants | Confirmed via grep | OK |
| CI PG merge-gate filter | Test class must be in `.github/workflows/ci.yml` filter | `OutboxIngestorTest` added at line 358 | OK |

---

## Verified (what I read/grepped)

- `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php` (698 LOC, full read)
- `apps/api/app/Modules/Fiscal/Application/DTOs/FiscalEventEnvelope.php` (full read)
- `apps/api/app/Modules/Fiscal/Application/DTOs/IngestionResult.php` (full read)
- `apps/api/tests/Feature/Fiscal/OutboxIngestorTest.php` (full read, 657 LOC)
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionRegistry.php` (full read — confirms Task 18 F1 catches resolver throws internally; F2/F3 are constructor-time)
- `apps/api/app/Modules/Fiscal/Application/Services/HashChainIntegrityProvider.php` (`hash('sha256', ...)`, `hash_equals(...)`)
- `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php` (partial read — §7.6 contract)
- `apps/api/database/migrations/2026_05_14_100001_create_fiscal_events_table.php` (full read — UNIQUE shape, hash-format CHECKs, partial indexes)
- `apps/api/database/migrations/2026_05_14_100003_create_fiscal_event_projections_table.php` (full read — UNIQUE on `(fiscal_event_id, projector_name)`)
- `apps/api/database/migrations/2026_05_14_100004_create_fiscal_event_quarantine_table.php` (lines 100-150 — hash-format CHECKs, sequence_conflict-only CHECK)
- `apps/api/database/migrations/2025_12_23_160001_create_fraud_alerts_table.php` (lines 16-50 — confirms NOT NULL `user_id` FK)
- `vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php` (lines 1-200 — confirms SAVEPOINT is only created for nested transactions, NOT for top-level)
- `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md` §7.2, §7.3, §7.5, §7.6, §8, §10, §13 (multiple reads)
- `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md` Task 19 §1344-1480 (full read)
- `docs/superpowers/coordination/2026-05-14-pos-fiscal-engine-session-handoff.md` §1, §4.2, §4.3 (full read)
- `docs/superpowers/reviews/2026-05-14-pos-fiscal-event-engine-phase1-codex-review-round4.md` lines 30-100 (full read — found the prior BLOCKER on this exact pattern)
- `.github/workflows/ci.yml` line 320-360 (CI filter — confirmed OutboxIngestorTest is included)
- `vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php:158-160` (savepoint nesting behavior)
- Ran `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/OutboxIngestorTest.php` — 11/11 pass on SQLite, 2.081s
- Ran `cd apps/api && ./vendor/bin/phpstan analyse ... --level=8` — 0 errors
- Ran empirical PG 15.15 SQL to reproduce the transaction-aborted behavior — confirmed (see "Empirical PG repro" above)
- Grepped for `FraudAlert` codebase-wide — found the model + dispatcher + notification service; confirmed `user_id` is NOT NULL
- Grepped for `fiscal_event_genesis_seed` — only on the device-side TS (`apps/pos/src/lib/fiscal/FiscalEventEngine.ts`); no server-side mirror

---

## Recommended disposition

1. **Close BLOCKER B1 in a round-2 fix-up commit** by switching to `INSERT ... ON CONFLICT DO NOTHING RETURNING id` (raw SQL or `insertOrIgnore` + post-check). Add a PG-only regression test that exercises the conflict path.
2. **Address P1 F1 in the same commit** — either add the server-side terminal-state mirror (preferred — the architectural correct fix) or formalize the deferral in spec §18 with a `FIXME` annotation in code that grep-finds.
3. **P2 F2-F5** — single commit, no blocking concerns.
4. **P3 F6-F7** — defer to a follow-up cleanup commit; track in §18.

After the round-2 fix, re-run the dual-gate (Opus + Codex). I expect Codex will also catch B1 — the round-4 reviewer who originally caught this exact pattern is the same Codex reviewer. The handoff §4.2 BLOCKER-streak is currently 6; if Codex catches B1 it becomes 7. Reading the round-4 review and the spec line 380 explicitly before re-implementing is non-negotiable — this is a recurrence of a closed BLOCKER, which is the worst class of regression.

---

**End of review.**
