REQUEST-CHANGES

# Adversarial review - POS Phase 1 Task 10
**Reviewer:** Codex
**Review date:** 2026-05-16
**Branch:** feat/pos-fiscal-event-engine-phase1
**Base SHA:** 2e8aec56^
**Head SHA:** 2e8aec56

## Summary table
| Severity | Count |
|---|---:|
| BLOCKER | 0 |
| P1 | 1 |
| P2 | 4 |
| nit | 0 |

## Executive summary
The Task 10 migration is materially faithful to spec §8 on the production PostgreSQL schema: `claimed_sequence_number` is `BIGINT`, `canonical_bytes` is `BYTEA` via Laravel's PostgreSQL grammar, `raw_envelope` is `JSONB`, both hash columns are `CHAR(64)`, nullability/defaults match the §8 block, and the model has `$timestamps = false` with `created_at` cast. I do not see a confirmed column-drift BLOCKER.

The request changes verdict is driven by the same recurring merge-gate problem from Tasks 8/9: this task adds PostgreSQL-only constraints and a PostgreSQL-only partial index, but the new test is skipped for those invariants on SQLite and is not included in the PG merge-gate filter. There are also focused coverage gaps around hash CHECKs, the partial-index predicate, production `JSONB` behavior, and the fact that the test only proves column names rather than the §8 type/nullability/default contract.

## BLOCKER findings

No BLOCKER findings.

I specifically checked the Task 8 round-2 reclassification class issue against quarantine. In this table, the Phase 1 class CHECK is `CHECK (integrity_exception_class = 'sequence_conflict')` at `apps/api/database/migrations/2026_05_14_100004_create_fiscal_event_quarantine_table.php:102-106`, and the model excludes `integrity_exception_class` / `integrity_exception_reason` from `$fillable` at `apps/api/app/Modules/Fiscal/Domain/Models/FiscalEventQuarantine.php:92-117`. Because the only current legal value cannot be rewritten to another class without violating the CHECK, I do not classify the absence of a Task 8-style class reclassification trigger as a current BLOCKER.

## P1 findings

### P1-1 - PostgreSQL-only Task 10 invariants are absent from the PG merge gate
**Location:** `.github/workflows/ci.yml:316-339`; `apps/api/tests/Feature/Fiscal/FiscalEventQuarantineTableTest.php:100-106`; `apps/api/database/migrations/2026_05_14_100004_create_fiscal_event_quarantine_table.php:97-130`

**Evidence:** The Task 10 migration creates all enforcement beyond simple column shape only inside the PostgreSQL branch:

```php
if (DB::connection()->getDriverName() === 'pgsql') {
    // class CHECK, hash CHECKs, unresolved partial index
}
```

That branch covers the Phase 1 class CHECK at migration lines 102-106, both hash-format CHECKs at lines 112-120, and the unresolved partial index at lines 126-130. The only Task 10 PG-only test also skips unless the driver is PostgreSQL:

```php
$this->skipUnlessPostgres();
$this->expectException(QueryException::class);
$this->insertQuarantineRow(['integrity_exception_class' => 'time_anomaly']);
```

The PG merge-gate filter at `.github/workflows/ci.yml:337-339` includes `FiscalEventsTableTest`, `FiscalEventsImmutabilityTest`, and `FiscalEventProjectionsTableTest`, but not `FiscalEventQuarantineTableTest`. The nearby comments enumerate Task 7, Task 8, and Task 9 tests at `.github/workflows/ci.yml:322-329`; Task 10 is not listed.

**Impact:** A regression in the raw PostgreSQL DDL for `fiscal_event_quarantine` can pass the default SQLite suite because SQLite does not execute the PG-only branch. It can also pass the current PG-backed CI job because the hard-coded filter does not run this test file. This would manifest before merge as a false green build, and in production as missing/incorrect class enforcement, hash enforcement, or unresolved-incident indexing.

**Recommendation:** Add `FiscalEventQuarantineTableTest` to the `backend-test-pgsql` filter and update the CI comments to name the Task 10 coverage. If the project replaces the hard-coded filter with discovery, prove that this test is selected in the PG job.

## P2 findings

### P2-1 - Hash-format CHECK constraints are implemented but untested for quarantine
**Location:** `apps/api/database/migrations/2026_05_14_100004_create_fiscal_event_quarantine_table.php:112-120`; `apps/api/tests/Feature/Fiscal/FiscalEventQuarantineTableTest.php:34-116`; `apps/api/tests/Feature/Fiscal/FiscalEventsTableTest.php:57-63`

**Evidence:** The migration adds the quarantine hash CHECKs:

```sql
CHECK (current_hash ~ '^[0-9a-f]{64}$')
CHECK (previous_hash ~ '^[0-9a-f]{64}$')
```

The Task 10 test file has column existence coverage, cast coverage, a class CHECK rejection, and resolution-null defaults at lines 34-116, but no test that rejects malformed `current_hash` or `previous_hash`. Task 7 has the analogous pattern in `FiscalEventsTableTest::test_hash_format_check_constraint()` at lines 57-63.

**Impact:** The verifier and export paths consume quarantine `current_hash` according to spec §8 at `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:517` and §15.1 at lines 689-690. If the regex DDL is dropped, mistyped, or only applied to one hash column, the current test suite will not catch malformed quarantine hashes before they reach those paths.

**Recommendation:** Add PG-only tests that insert bad values for both `current_hash` and `previous_hash`, including non-hex characters, uppercase hex, and wrong length. Include this file in the PG merge gate per P1-1.

### P2-2 - The unresolved partial-index predicate has no catalog-level test
**Location:** `apps/api/database/migrations/2026_05_14_100004_create_fiscal_event_quarantine_table.php:126-130`; `apps/api/tests/Feature/Fiscal/FiscalEventQuarantineTableTest.php:34-116`; `.github/workflows/ci.yml:327-339`

**Evidence:** The migration creates the hot-path partial index:

```sql
CREATE INDEX fiscal_event_quarantine_unresolved_idx
    ON fiscal_event_quarantine (terminal_id, claimed_sequence_number)
    WHERE resolved_at IS NULL
```

The Task 10 tests do not inspect `pg_indexes`, `pg_class`, or `pg_get_indexdef()` for that index or predicate. Task 9's CI comment explicitly calls out its PG-only partial-index coverage pattern at `.github/workflows/ci.yml:327-329`, but the current filter at lines 337-339 has no Task 10 test entry.

**Impact:** The admin-resolution view and verifier hot path can silently lose the intended partial index or drift to the wrong predicate. The functional behavior may still pass on small test data, but production unresolved-incident scans would regress once resolved quarantine rows accumulate.

**Recommendation:** Add a PG-only catalog assertion that `fiscal_event_quarantine_unresolved_idx` exists on `(terminal_id, claimed_sequence_number)` and that its definition contains `WHERE (resolved_at IS NULL)` or the normalized equivalent from `pg_get_indexdef()`.

### P2-3 - `raw_envelope` production `JSONB` behavior is not exercised by the SQLite test
**Location:** `apps/api/database/migrations/2026_05_14_100004_create_fiscal_event_quarantine_table.php:69-71`; `apps/api/tests/Feature/Fiscal/FiscalEventQuarantineTableTest.php:83-97, 140`; `apps/api/vendor/laravel/framework/src/Illuminate/Database/Schema/Grammars/PostgresGrammar.php:982-984`; `apps/api/vendor/laravel/framework/src/Illuminate/Database/Schema/Grammars/SQLiteGrammar.php:897-899`

**Evidence:** Task 10 uses `$table->jsonb('raw_envelope')` at migration line 70. Laravel's PostgreSQL grammar emits `jsonb` at vendor lines 982-984, but the SQLite grammar maps `jsonb` to `text` unless `use_native_jsonb` is enabled at vendor lines 897-899. The current round-trip test inserts `json_encode(...)` and asserts the Eloquent `array` cast at test lines 83-97; the default helper also stores JSON text at line 140.

**Impact:** This is not a schema violation because spec §8 explicitly requires `raw_envelope JSONB NOT NULL` at `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:504`. It is a test-vs-production fidelity gap: SQLite proves only that Laravel can decode a text blob, not that PostgreSQL rejects invalid JSON, stores the column as `jsonb`, or behaves correctly for later JSONB operators/type coercion. It also will not surface JSONB normalization differences if future code relies on raw key order rather than `canonical_bytes`.

**Recommendation:** Keep `JSONB`, but add a PG smoke test that asserts the physical column type is `jsonb` and that invalid JSON cannot be inserted. If future resolver/export code queries inside `raw_envelope`, add PG tests for those exact operators.

### P2-4 - The schema test proves column names only, not the §8 type/nullability/default contract
**Location:** `apps/api/tests/Feature/Fiscal/FiscalEventQuarantineTableTest.php:34-80`; `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:481-514`; `apps/api/database/migrations/2026_05_14_100004_create_fiscal_event_quarantine_table.php:30-95`

**Evidence:** Spec §8 defines every quarantine column's type, nullability, and `created_at` default at lines 481-514. The implementation appears to match that block: `claimed_sequence_number` is `bigInteger()` at migration line 50; `canonical_bytes` is `binary()` at line 69, which maps to PostgreSQL `bytea` per Laravel's grammar at `apps/api/vendor/laravel/framework/src/Illuminate/Database/Schema/Grammars/PostgresGrammar.php:1097-1099`; `raw_envelope` is `jsonb()` at line 70; hashes are `char(..., 64)` at lines 63-64; and `created_at` uses `useCurrent()` at line 94. However, the test at lines 34-80 only loops over `Schema::hasColumn()`.

**Impact:** A future edit could change `claimed_sequence_number` to `integer`, hashes to `string`, `created_at` to nullable/no default, or nullable state on required metadata columns, and this Task 10 test would still pass as long as the column names remain. That is exactly the kind of §8 column drift this table is meant to prevent.

**Recommendation:** Add a PG schema contract test using `information_schema.columns` / `pg_attribute` / `pg_attrdef` that verifies the critical physical types and nullability: `claimed_sequence_number` = `bigint`, `canonical_bytes` = `bytea`, `raw_envelope` = `jsonb`, hashes = `character(64)`, all §8 NOT NULL columns are non-nullable, resolution/reference nullable columns remain nullable, and `created_at` has a current-timestamp default.

## nit findings

No nit findings.

## Closing summary
Schema drift assessment: accepted. I found no confirmed mismatch between the migration and spec §8 lines 481-514. `raw_envelope` degrading to SQLite text is a coverage limitation, not a reason to change the production `JSONB` column.

Mutability assessment: accepted with caution. The plan explicitly says "No immutability trigger" for Task 10 at `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:831`, and spec notes that `fiscal_event_quarantine` carries no immutability triggers at `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:225`. Because `integrity_exception_class` is currently pinned to the single value by the PG CHECK, I do not mark a Task 8-style class trigger as required now. `integrity_exception_reason` remains DB-mutable; I found no current spec line requiring it to be write-once at the database layer, so I am not filing that as a finding.

FK assessment: accepted. Spec §8 defines `conflicting_event_id` as `UUID NOT NULL` at line 509 but does not require a foreign key, and §15.1 says the verifier reports the ID as incident context at lines 689-690. The absence of an FK is therefore not a confirmed violation in this task.

Model assessment: accepted. `$fillable` includes identity/envelope/insert-time fields, including `payload_parse_status` and `conflicting_event_id`, while excluding `integrity_exception_class`, `integrity_exception_reason`, `resolved_at`, and `resolved_by` at `apps/api/app/Modules/Fiscal/Domain/Models/FiscalEventQuarantine.php:92-117`. `$timestamps = false` is set at lines 71-78, and `created_at` is cast at lines 122-135.

Required follow-up: fix the PG merge-gate omission first. The remaining P2s are targeted tests that should be added while touching the same file.
