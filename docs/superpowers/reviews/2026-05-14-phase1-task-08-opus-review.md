# Opus review — Phase 1 Task 8 (`fiscal_events` immutability triggers + break-glass runbook)

**Reviewer:** Claude Opus 4.7 (1M context), headless
**Date:** 2026-05-16
**Branch:** `feat/pos-fiscal-event-engine-phase1`
**Commit under review:** `2e036485` (parent `82b32292`)
**Files reviewed:**
- `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php` (262 lines, new)
- `apps/api/docs/runbooks/fiscal-events-break-glass.md` (195 lines, new)
- `apps/api/tests/Feature/Fiscal/FiscalEventsImmutabilityTest.php` (207 lines, new)

**Grounding sources:**
- Plan §Task 8 (`docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:641-746`) + §Task 24 (lines 1788-1862) + §Task 19 (lines 1344-1480)
- Spec v7 §3.3 + §3.2 schema block + §7.5 (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:155-225, 456-457, 700-701`)
- SoT v3 D1, D4, D13 (`docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md:254-269`)
- Reference trigger pattern (`apps/api/database/migrations/2026_05_01_000003_update_pos_receipts_immutability_trigger_for_pending_seal.php`)
- Codebase reality §1.5 + finding 8 (`docs/superpowers/research/2026-05-14-pos-fiscal-codebase-reality.md`)
- CI-gate discipline memory (`feedback_audit_ci_gate_check.md`)

---

## Verdict

**APPROVE-WITH-MINOR-EDITS**, no BLOCKER. 2× P2 (test-coverage gaps that hide easy regressions of the v1 BLOCKER fix), 5× P3.

The implementation faithfully maps the spec §3.3 allowed-column whitelist and named transition rules into a single PL/pgSQL trigger function, including the 7-condition gated `failed → parsed` resume path that closes the v1 plan-review BLOCKER for Task 24. Column-by-column freeze list matches Task 7's schema 1:1 (34 frozen + 7 whitelisted = 41 columns, exactly the Task 7 column count). The Task 24 resolver `UPDATE` payload prescribed in the plan composes through the trigger without contradiction. DELETE + TRUNCATE coverage closes reality §1.5 finding 8 (`prevent_receipt_modification` lacks TRUNCATE coverage); the BEFORE TRUNCATE belt is reinforced by `REVOKE TRUNCATE` suspenders. The break-glass runbook is genuinely useful operational documentation, not a placeholder.

The P2 findings are about **test discipline regressing the BLOCKER fix**, not about the trigger code itself. The implementer's "9 PG smoke cases all pass" testimony is real, but the PHPUnit suite under review covers only one of the four negative paths a future refactor of the gated transition could break. Cheap fix; recommend before this trigger ships behind a `pgsql` CI gate that the project still owes itself (`feedback_audit_ci_gate_check.md`).

---

## Findings

### BLOCKER

_None._

### P1

_None._

### P2

#### P2-1 — Negative test coverage of the gated `failed → parsed` transition is asymmetric: only 1 of 7 conditions is exercised

Spec §3.3 + plan Task 8 Step 3 specify that `failed → parsed` raises unless **all seven** of these hold in the same `UPDATE`:

1. `OLD.payload IS NULL`
2. `NEW.payload IS NOT NULL`
3. `OLD.integrity_exception_class = 'canonical_parse_failure'`
4. `OLD.integrity_status = 'quarantined'`
5. `NEW.integrity_status = 'verified'`
6. `NEW.integrity_resolved_at IS NOT NULL`
7. `NEW.integrity_resolved_by IS NOT NULL`

`test_failed_to_parsed_is_rejected_when_not_a_parse_failure_resolution()` covers only the case where **condition 4** is violated (`OLD.integrity_status = 'verified'` instead of `'quarantined'`). The other six negative paths are not tested in PHPUnit — they are covered only in the implementer's "9 PG smoke cases" testimony documented in commit narration but not in the suite.

This is the **exact failure mode `feedback_audit_ci_gate_check.md` warns about**: strict TDD on the bug-of-the-moment, but the regression infrastructure for the rest of the named contract is left to manual verification that no future contributor will repeat. A refactor of the trigger that, say, drops the `NEW.integrity_resolved_by IS NOT NULL` clause will sail past the suite — and that refactor reopens the v1 plan-review BLOCKER, the very thing Task 8 was rebuilt to prevent.

**Recommendation:** Add a parameterized / data-provider negative test (or six discrete tests) that flips exactly one of the seven conditions at a time and asserts `QueryException`. Minimal cost; pins the contract Task 24's resolver depends on. The implementer's manual 9-case fixture is the right test matrix — code it into PHPUnit.

#### P2-2 — Named transition `verified → quarantined` (ingestor flag path) is not tested

Spec §3.3 explicitly names `verified → quarantined` as the ingestor-flag path on `integrity_status`. The trigger handles it in Step 5 (`NULL` branch — no extra required fields). The test suite does not exercise it.

In Phase 1 the OutboxIngestor sets `integrity_status` at INSERT (which does not fire BEFORE UPDATE), so this transition has no production caller today — but the trigger explicitly permits it, and Task 19 / Task 23 retry-or-flag paths plausibly need it for post-ingestion reclassification. The named transition is part of the §3.3 contract being delivered; a Codex / Opus reader of the trigger has to take it on faith.

**Recommendation:** One additional test — insert verified, UPDATE to quarantined with mandatory `integrity_exception_class` + `integrity_exception_reason`, assert success. Two lines.

### P3

#### P3-1 — REVOKE TRUNCATE is a no-op when the application role is the table owner

PostgreSQL grants the table owner full DDL and DML privileges, **including TRUNCATE**, regardless of `REVOKE` (PG docs: "Ownership rights are not affected by `REVOKE`"). In most environments (dev, CI, single-role prod), the migration role IS the application role IS the table owner — so the `REVOKE TRUNCATE ON fiscal_events FROM <appRole>` issued in `up()` is silently ineffective. The BEFORE TRUNCATE trigger still catches everything (the runbook §1 calls the trigger the "belt"), so this isn't a security hole — but the migration's claim of "belt + suspenders" is partly aspirational.

The runbook §7 acknowledges the multi-role case ("If your environment uses a different application role, run the REVOKE manually") but does not call out the owner case explicitly. Worth a one-paragraph note in the runbook (or the migration's class-level comment) so the deploy team isn't surprised when their post-migration `information_schema.table_privileges` check returns a row for the application role.

**Recommendation:** Add a sentence to runbook §7 calling out the table-owner case explicitly; optionally, document in the migration header that the trigger is the primary enforcement and the REVOKE is a defense-in-depth only when the application role is distinct from the table owner.

#### P3-2 — Whitelisted resolution stamps can be written outside a `quarantined → verified` transition

`integrity_resolved_at` and `integrity_resolved_by` are in the column whitelist (correctly — they have to be writable for the resolver). The trigger requires them to be non-NULL during the `quarantined → verified` flip (Step 5), but it does **not** forbid writing them in isolation (e.g., on a `verified` row whose status is unchanged, or writing only one of the two).

Spec §3.3 doesn't explicitly forbid this. The resolver service in Task 24 controls the actual write callsites, so the chance of a bogus write is low. But the trigger is the deepest defense; encoding "stamps are write-once and only inside a resolution transition" would harden the contract against a future operator-tool bug or a Task 24 implementation slip.

**Recommendation:** Optional — add a Step 6 that raises if `integrity_resolved_at` / `integrity_resolved_by` change without a `quarantined → verified` flip in the same UPDATE. Tracked as a P3 (defense-in-depth) and explicitly out of scope per the spec read; flagging so a future hardening pass can pick it up.

#### P3-3 — `integrity_exception_class` and `integrity_exception_reason` are mutable on an already-quarantined row

Same shape as P3-2 — the whitelist allows them to change at will after the initial quarantine, with no transition rule. The OutboxIngestor sets them at INSERT (no UPDATE trigger fire), but the trigger doesn't pin them to "set once at quarantine, cleared on resolution." Operator tooling could legally rewrite the exception class on a quarantined row.

Spec §3.3 doesn't address this. Same P3 disposition as P3-2; flagging for future hardening, not blocking.

#### P3-4 — Sticky-tenant/terminal static state in the test helper is fragile, not broken

`insertEvent()` reuses `static $stickyTenant` / `static $stickyTerminal` across calls — and since PHP statics persist across tests within the same PHPUnit process, every test in the class uses the same `(tenant_id, terminal_id)`. With `RefreshDatabase` wiping the row between tests and every helper call defaulting `sequence_number = 1`, this works today.

It will silently break the moment a future test makes a second `insertEvent()` call in the same test without overriding `sequence_number` — that will hit the `(tenant_id, terminal_id, sequence_number)` unique constraint, throw a `QueryException`, and a test expecting a trigger-induced `QueryException` will swallow it as a false positive. The Task 7 sibling `FiscalEventsTableTest::insertEvent()` reportedly has the same pattern (the comment cites deliberate duplication), so it's a known-tolerated idiom — but it's worth a regression-test-the-test note for whoever extends this file.

**Recommendation:** Add a brief docblock note on `insertEvent()` warning "if you call this twice in one test, pass `'sequence_number' => N` explicitly," or refactor to auto-increment.

#### P3-5 — PG-only test skip means local SQLite runs never exercise this trigger; CI must run PG to gate it

Per `feedback_audit_ci_gate_check.md` and `project_fiscal_chain_ci_gates.md`, the project has an open carry-forward on enforcing that PG-only-skip tests actually run in CI on PG. The implementer's deviation #1 (SQLite skip) is structurally correct — the triggers don't exist on SQLite — but means every signal from this test file is gated on the merge-gate PG run.

Task 8 doesn't own the CI gate work. Flagging here as a reminder to the wave-level reviewer that **none of the verifications in this test file are exercised by the SQLite path that PR authors run locally**; the implementer's "9 PG smoke cases all pass" testimony is the only out-of-suite signal we have until the project's PG merge gate exists. Not a finding against Task 8; a reminder that Task 8's signal is partial without the CI gate.

---

## Implementer deviations from the plan — assessment

The commit narration declares three deviations. Per-item assessment:

### Deviation 1 — Tests skip on SQLite via `skipUnlessPostgres()` helper

**Plan called for:** PG-only triggers; the plan's Step 4 just says "Run on `apps/api && ./vendor/bin/phpunit`."

**Implementer did:** Added explicit `skipUnlessPostgres()` helper; every test calls it as the first line.

**Assessment:** **Correct and necessary.** The triggers literally do not exist on SQLite (the migration `return;`s when `getDriverName() !== 'pgsql'`); running these tests on SQLite would assert against a table with no triggers and either pass spuriously (delete/update would succeed) or fail with misleading errors. Deviation is the only way to make the suite green on the SQLite path PR authors run locally. Per P3-5 above, the cost is "CI must run PG"; the project already owes itself that gate.

### Deviation 2 — Broader `IS DISTINCT FROM` coverage in the freeze list (vs. mixed `!=` / `IS DISTINCT FROM` in the reference trigger)

**Plan called for:** Implicit — "compare OLD/NEW and RAISE EXCEPTION unless only the allowed columns changed."

**Implementer did:** Used `IS DISTINCT FROM` uniformly across every frozen-column comparison.

**Assessment:** **An improvement on the reference pattern.** `!=` in PostgreSQL is not NULL-safe (`NULL != NULL` is NULL, evaluated as false in a boolean context — meaning the trigger would silently allow a NULL→NULL "no change" but ALSO a NULL→value change in some edge cases via `IF NEW.x != OLD.x THEN raise`). `IS DISTINCT FROM` correctly treats NULL as a value. The reference `prevent_receipt_modification()` mixes both styles because some columns are NOT NULL (`!=` is safe) and some are nullable (`IS DISTINCT FROM` is required). For `fiscal_events` many of the frozen columns are nullable (`reference_event_id`, `partner_id`, the entire signature object, `last_server_time_seen`, `source_event_class`/`id`, `partner_identity_snapshot`); uniform `IS DISTINCT FROM` is the correct call. **Approve.**

### Deviation 3 — Runbook §7 includes an `information_schema.table_privileges` verification snippet

**Plan called for:** "Break-glass: DBA-only maintenance requires a signed full export first; documented in an ops runbook."

**Implementer did:** Added a SELECT against `information_schema.table_privileges` to verify the post-REVOKE state, and called out the multi-role environment edge case.

**Assessment:** **Adds operator value.** The verification snippet is the kind of post-condition check that turns a runbook from a script into a procedure. The multi-role caveat is correct (see P3-1 for the table-owner-role complement). **Approve;** would benefit from the P3-1 owner-role addendum.

---

## Coherence with downstream tasks

### Task 24 (parse-failure resume) — the v1 BLOCKER fix

Confirmed end-to-end. Task 24 Step 3 says: "in **one transaction** — write `payload`, flip `payload_parse_status → parsed` (and `integrity_status quarantined → verified` with `integrity_resolved_at`/`integrity_resolved_by`), and insert one `pending` `fiscal_event_projections` row per currently-active projector."

The trigger's gated `failed → parsed` clause requires precisely the seven conditions Task 24's `UPDATE` will deliver:

| Task 24 resolver writes | Trigger §3 condition | Match |
|---|---|---|
| `OLD.payload` already NULL from quarantine | `OLD.payload IS NULL` | ✓ |
| `NEW.payload = <corrected>` | `NEW.payload IS NOT NULL` | ✓ |
| `OLD.integrity_exception_class` unchanged from quarantine | `= 'canonical_parse_failure'` | ✓ |
| `OLD.integrity_status` unchanged from quarantine | `= 'quarantined'` | ✓ |
| `NEW.integrity_status = 'verified'` | `= 'verified'` | ✓ |
| `NEW.integrity_resolved_at = now()` | `IS NOT NULL` | ✓ |
| `NEW.integrity_resolved_by = $resolverUser` | `IS NOT NULL` | ✓ |

The resolver UPDATE composes through the trigger in one transaction. **The v1 BLOCKER is genuinely closed.**

### Task 19 (OutboxIngestor) — initial insert path

OutboxIngestor INSERTs with `integrity_status = 'quarantined'` and `integrity_exception_class = 'canonical_parse_failure'` for parse failures (Task 19 Step 3, §7.2 Step 2). INSERTs do not fire BEFORE UPDATE — the initial quarantined state lands fine. No conflict with the trigger.

### Task 23 (`ApplyFiscalEventProjectionJob`) — flag-on-projection-failure

If a future code path flips `integrity_status` `verified → quarantined` on an existing row after projection fail (not in Phase 1), Step 5 of the trigger permits it. Forward-compat confirmed.

### Task 25 (chain-recovery events)

`CHAIN_BREAK_DETECTED` / `CHAIN_RESTART` are ordinary `fiscal_events` INSERTs (§9), not UPDATEs. Trigger does not interfere. ✓

### Future signature provider (D12)

The trigger freezes `signature_status` and the entire signature object in the freeze list, matching spec §3.3's omission of those columns from the allowed-change whitelist. When a signature provider is eventually wired up, a follow-up migration will need to expand the whitelist (or use a separate, signature-specific table). This is spec-correct ("pre-signature events are never retroactively upgraded" — D12). ✓

---

## Spec / SoT / reality conformance

- **Spec §3.3 allowed columns** — exact match: `payload`, `payload_parse_status`, `integrity_status`, `integrity_exception_class`, `integrity_exception_reason`, `integrity_resolved_at`, `integrity_resolved_by`. ✓
- **Spec §3.3 named transitions** — all three `payload_parse_status` (`pending→parsed`, `pending→failed`, gated `failed→parsed`); both `integrity_status` (`verified→quarantined`, `quarantined→verified` with stamps); `payload` write-once gated on parse-status flip. ✓
- **Spec §3.3 BEFORE DELETE + BEFORE TRUNCATE** — both implemented. ✓
- **Spec §3.3 REVOKE TRUNCATE** — implemented with username-format guardrail (regex check before string interpolation, ✓ no injection vector); see P3-1 for the table-owner caveat.
- **Spec §3.3 break-glass DBA-only with signed full export** — runbook §3 prescribes signed export, §5 prescribes DISABLE TRIGGER inside a transaction, §6 prescribes post-change re-verification + new signed export, §8 prescribes 8 audit-trail artifacts. ✓
- **SoT v3 D4 (append-only ledger)** — DELETE + TRUNCATE always raise; UPDATE confined to the 7-column whitelist + named transitions. ✓
- **SoT v3 D13 (per-class anomaly handling: accept-and-flag, never silently exclude)** — `integrity_status verified → quarantined` is permitted (accept-and-flag); `quarantined → verified` is the operator-resolution path. ✓
- **SoT v3 D1 (device-authority + verify-only mirror)** — server-side ledger immutability is the mirror integrity guarantee. ✓
- **Reality §1.5 finding 8 (`prevent_receipt_modification` lacks TRUNCATE coverage)** — closed for `fiscal_events` here. (The `pos_receipts` TRUNCATE gap remains, but that's not Task 8's scope.) ✓
- **Reference pattern (`prevent_receipt_modification`)** — mirrored at the architectural level: one CREATE OR REPLACE FUNCTION + TG_OP branching + spec-cited ERRCODE. Improved at the comparison level (uniform `IS DISTINCT FROM`, see Deviation 2).

---

## Recommended actions before merge

1. **P2-1** — code the 6 missing negative cases for the gated `failed → parsed` transition into the test file. The implementer's 9-case manual matrix already enumerates them.
2. **P2-2** — add one `verified → quarantined` ingestor-flag positive test.
3. **P3-1** — append the table-owner case to runbook §7.
4. (Optional, defer) P3-2 / P3-3 / P3-4 — note as future hardening; not Task 8 scope per spec.
5. (Out of Task 8 scope) P3-5 — track that the project still owes itself a PG-backed CI merge gate; this trigger is one of the gated artifacts.

---

## Conclusion

The trigger is correct against spec §3.3 and SoT v3 D1/D4/D13. The 7-condition gated transition discharges the v1 plan-review BLOCKER cleanly, with the Task 24 resolver UPDATE composing through it in one transaction. The freeze list matches Task 7's column count exactly. DELETE + TRUNCATE coverage closes reality §1.5 finding 8. The runbook is operator-grade.

The two P2s are about **defending the BLOCKER fix against future regression** — code the implementer's 9-case PG smoke matrix into PHPUnit so the named transition contract is gated. The five P3s are defense-in-depth and CI-gate carry-forwards; none block Task 8.

Recommend merging after P2-1 + P2-2 + P3-1 are addressed.
