# Adversarial review — POS Phase 1 Task 7
**Reviewer:** Codex
**Review date:** 2026-05-14
**Branch:** feat/pos-fiscal-event-engine-phase1
**Base SHA:** d695e9cf
**Head SHA:** 245f5e85
**Verdict:** APPROVE-WITH-MINOR-EDITS
**Total findings:** 0 BLOCKER, 0 P1, 0 P2, 1 P3

## Executive summary
The migration is faithful to spec §3.2: all server `fiscal_events` columns, defaults, nullability, indexes, and PostgreSQL-only CHECK constraints are present, and the `event_type` whitelist is pulled from `FiscalEventType::checkConstraintList()` at migration runtime. The model uses the frozen Fiscal namespace/table/enum names and exposes the same mutable column names Task 8 will allow. The only issue is test coverage: one portable invariant is unnecessarily skipped under SQLite, which weakens the local red/green guard but does not indicate a schema defect in this commit.

## Findings
### [P3] Portable sequence-uniqueness coverage is skipped on the default SQLite test driver
**Plan/spec citation:** Spec §3.2 indexes and constraints require `UNIQUE (tenant_id, terminal_id, sequence_number)` as the chain integrity/idempotency key (lines 194-197). Task 7's failing-test stub includes `test_unique_sequence_key_blocks_duplicate_slot()` without a driver gate (plan lines 603-608), and the task conventions require backend TDD red/green with `RefreshDatabase` (plan line 32).
**Codebase evidence:** The migration creates the composite unique key inline with `$table->unique(['tenant_id', 'terminal_id', 'sequence_number'], ...)` before the PostgreSQL-only `if` block, so it is not a PG-only raw constraint (migration lines 90-97). The test still calls `$this->skipUnlessPostgres()` before exercising the duplicate slot (test lines 45-55), and `skipUnlessPostgres()` skips whenever the driver is not `pgsql` (test lines 126-130). That means the default SQLite run would not catch removal or breakage of this portable unique key, even though the current migration itself is correct.
**Concrete fix:** Remove `skipUnlessPostgres()` from `test_unique_sequence_key_blocks_duplicate_slot()` so the duplicate-slot invariant runs on SQLite and PostgreSQL. Keep the PostgreSQL skip helper only around the raw CHECK / partial-index cases that actually depend on PG syntax or enforcement.

## Implementer deviations assessment
SQLite-skip pattern: ACCEPTED-WITH-NOTE. It is appropriate for the PG-only raw CHECK constraints and partial indexes created inside the `pgsql` guard (migration lines 97-135), but not for the inline composite unique key; see the P3 above.

Local PG unreachable: ACCEPTED-WITH-NOTE. I did not run phpunit, composer, artisan, or pnpm per the action-safety instructions. Static review of the PG DDL found the regex checks, paired-null logic, partial indexes, and enum-derived `event_type` CHECK aligned with spec §3.2 (spec lines 194-207; migration lines 97-135), but this should still be exercised in a PostgreSQL environment before merge.

Two extra tests: ACCEPTED. `test_event_type_check_rejects_unknown_value()` and `test_source_event_paired_null_check()` cover constraints explicitly required by Task 7 (plan line 625) and spec §3.2 (lines 204-206); they are additive and non-tautological (test lines 66-82).

Untracked unrelated file not staged: ACCEPTED-WITH-NOTE. `git show --name-status 245f5e85` shows only the three Task 7 files added, while `git status --short --branch` shows unrelated modified/untracked files outside this commit. I wrote only this review file, per the requested output path.

## Forward-compat assessment
Task 8's server immutability trigger must allow changes only to `payload`, `payload_parse_status`, `integrity_status`, `integrity_exception_class`, `integrity_exception_reason`, `integrity_resolved_at`, and `integrity_resolved_by` (spec §3.3 lines 213-215). The Task 7 migration creates those exact column names (migration lines 77-85), and the model fillable list exposes those same names for the parser/resolver surface (model lines 126-132). I saw no drift in the Task 8 trigger vocabulary, no projection-state columns on `fiscal_events` contrary to spec §3.2 line 192, and no `updated_at` column that would complicate the immutable-row trigger.
