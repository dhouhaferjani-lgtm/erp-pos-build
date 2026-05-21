# Phase 1 Task 10 — `create_fiscal_event_quarantine_table` + `FiscalEventQuarantine` model — Opus review

**Date:** 2026-05-16
**Reviewer:** Opus (headless review gate)
**Scope:** Task 10 from `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md` (lines 799–843) — server PostgreSQL `fiscal_event_quarantine` non-admissible-envelope partition + Eloquent model + feature test.
**Base SHA:** `61444f56` (Task 9 — `fiscal_event_projections`)
**Head SHA:** `2e8aec56` (feat(fiscal): create_fiscal_event_quarantine_table migration + model)
**Branch:** `feat/pos-fiscal-event-engine-phase1`
**Diff vs. base:** 3 files added, 435 lines.
- `apps/api/database/migrations/2026_05_14_100004_create_fiscal_event_quarantine_table.php` (+138)
- `apps/api/app/Modules/Fiscal/Domain/Models/FiscalEventQuarantine.php` (+137)
- `apps/api/tests/Feature/Fiscal/FiscalEventQuarantineTableTest.php` (+160)

**Verdict:** **APPROVE-WITH-MINOR-EDITS** — 0 BLOCKER, 0 P1, 3 P2, 2 nits. None are correctness defects in the shipping schema; they are coverage gaps, one index-leading-column tuning question, and a write-once-on-classification reasoning gap that should be documented or addressed in Task 19 / the resolver task — not redone here.

The migration is a faithful, column-by-column realization of spec §8 (lines 481–514): all 29 spec columns present with the spec'd types, nullability honored, mirrored verbatim from `fiscal_events`'s envelope-side columns (Task 7) where appropriate. The Eloquent model correctly applies the Task 9 boundary-discipline lesson: classification columns (`integrity_exception_class`, `integrity_exception_reason`) and lifecycle columns (`resolved_at`, `resolved_by`) are excluded from `$fillable`, with explicit PHPDoc justification. The Phase 1 single-value CHECK constraint pins `integrity_exception_class = 'sequence_conflict'` at the DB layer, mirroring the `IntegrityExceptionClass::isAdmissibleToLedger()` enum partition (only `SequenceConflict` returns false → goes here). Hash-format CHECKs mirror Task 7. The partial index targets unresolved incidents. PHPStan level 8 is clean on all three files; Pint is clean; no file outside Task 10 scope was touched. Genuine RED→GREEN verified (migration moved aside → 1 failure + 2 errors + 1 skipped; restored → 4 tests, 35 assertions, 1 PG-only skipped on SQLite).

The Task 8 round-1 / round-2 BLOCKER class — a write-once invariant silently routable around via reclassification — was checked-for-by-analogy. The Phase-1 single-value CHECK currently forecloses the BLOCKER (only one valid value, so reclassification is impossible). The reasoning to defer write-once-trigger enforcement to a later phase is sound, but the assumption is undocumented and warrants explicit capture — see P2-3.

---

## Verification performed

| Check | Result | Evidence |
|---|---|---|
| Three (and only three) files in the commit | ✓ | `git show --stat 2e8aec56` → `FiscalEventQuarantine.php`, `2026_05_14_100004_create_fiscal_event_quarantine_table.php`, `FiscalEventQuarantineTableTest.php`. No Task 1–9 files touched. |
| Test passes after migration (GREEN) | ✓ | `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalEventQuarantineTableTest.php` → `OK, but some tests were skipped! Tests: 4, Assertions: 35, Skipped: 1.` SQLite in-memory driver; the one skip is the PG-only CHECK test, as expected. |
| Test fails before migration (genuine RED) | ✓ | Temporarily moved `2026_05_14_100004_…` out of `database/migrations/` and re-ran: `Tests: 4, Assertions: 1, Errors: 2, Failures: 1, Skipped: 1`. Column-existence failed at `Schema::hasTable('fiscal_event_quarantine')`; the two insert-based tests errored on missing table; the PG-only CHECK test skipped (SQLite). Restored → all green. |
| PHPStan level 8 clean on new files | ✓ | `./vendor/bin/phpstan analyse <three files> --no-progress` → `[OK] No errors`. |
| Pint clean on new files | ✓ | `./vendor/bin/pint --test <three files>` → `{"result":"pass"}`. |
| `IntegrityExceptionClass` enum already declares `SequenceConflict` + `isAdmissibleToLedger()` partition | ✓ | `apps/api/app/Modules/Fiscal/Domain/Enums/IntegrityExceptionClass.php:11–17` — 5 cases; `isAdmissibleToLedger()` returns `$this !== self::SequenceConflict`. The migration's single-value CHECK pins exactly the case that returns `false`. |
| Cross-table consistency vs Task 7 hash-format CHECK | ✓ | `2026_05_14_100001_create_fiscal_events_table.php:124–125` declares `current_hash ~ '^[0-9a-f]{64}$'` and `previous_hash ~ '^[0-9a-f]{64}$'`; Task 10's migration `:113–121` mirrors verbatim with table-prefixed names (`fiscal_event_quarantine_current_hash_format`, `fiscal_event_quarantine_previous_hash_format`). |
| Naming convention `<table>_<purpose>_<kind>` | ✓ | All three named constraints + the partial index (`fiscal_event_quarantine_class_phase1_allowed`, `fiscal_event_quarantine_current_hash_format`, `fiscal_event_quarantine_previous_hash_format`, `fiscal_event_quarantine_unresolved_idx`) follow Task 7's locked-in convention. |

---

## Spec §8 column-by-column conformance

The §8 schema block (lines 481–514) declares 29 columns. The migration produces all 29, in the same logical order, with matching types and nullability:

| # | Spec §8 line | Spec column / type / nullable | Migration line | Migration produces | Verdict |
|---|---|---|---|---|---|
| 1 | 482 `id UUID PK` | UUID PK | `:32` | `uuid('id')->primary()` | ✓ |
| 2 | 484 `tenant_id UUID NOT NULL` | UUID NOT NULL | `:35` | `uuid('tenant_id')` (NOT NULL default) | ✓ |
| 3 | 485 `company_id UUID NOT NULL` | UUID NOT NULL | `:36` | `uuid('company_id')` | ✓ |
| 4 | 486 `terminal_id UUID NOT NULL` | UUID NOT NULL | `:37` | `uuid('terminal_id')` | ✓ |
| 5 | 487 `operator_id UUID NOT NULL` | UUID NOT NULL | `:38` | `uuid('operator_id')` | ✓ |
| 6 | 488 `envelope_event_id UUID NOT NULL` | UUID NOT NULL | `:42` | `uuid('envelope_event_id')` | ✓ |
| 7 | 489 `event_type VARCHAR(64) NOT NULL` | VARCHAR(64) NOT NULL | `:45` | `string('event_type', 64)` | ✓ |
| 8 | 490 `event_version SMALLINT NOT NULL` | SMALLINT NOT NULL | `:46` | `smallInteger('event_version')` | ✓ |
| 9 | 491 `signature_version VARCHAR(64) NOT NULL` | VARCHAR(64) NOT NULL | `:47` | `string('signature_version', 64)` | ✓ |
| 10 | 492 `claimed_sequence_number BIGINT NOT NULL` | BIGINT NOT NULL | `:50` | `bigInteger('claimed_sequence_number')` | ✓ |
| 11 | 493 `event_time_device TIMESTAMPTZ NOT NULL` | TIMESTAMPTZ NOT NULL | `:51` | `timestampTz('event_time_device')` | ✓ |
| 12 | 494 `business_date DATE NOT NULL` | DATE NOT NULL | `:52` | `date('business_date')` | ✓ |
| 13 | 495 `last_server_time_seen TIMESTAMPTZ` | TIMESTAMPTZ nullable | `:53` | `timestampTz(...)->nullable()` | ✓ |
| 14 | 496 `reference_event_id UUID` | UUID nullable | `:57` | `uuid(...)->nullable()` | ✓ |
| 15 | 497 `reference_document_id UUID` | UUID nullable | `:58` | `uuid(...)->nullable()` | ✓ |
| 16 | 498 `source_event_class VARCHAR(255)` | VARCHAR(255) nullable | `:59` | `string(..., 255)->nullable()` | ✓ |
| 17 | 499 `source_event_id UUID` | UUID nullable | `:60` | `uuid(...)->nullable()` | ✓ |
| 18 | 500 `previous_hash CHAR(64) NOT NULL` | CHAR(64) NOT NULL | `:63` | `char('previous_hash', 64)` | ✓ |
| 19 | 501 `current_hash CHAR(64) NOT NULL` | CHAR(64) NOT NULL | `:64` | `char('current_hash', 64)` | ✓ |
| 20 | 503 `canonical_bytes BYTEA NOT NULL` | BYTEA NOT NULL | `:69` | `binary('canonical_bytes')` — translates to `BYTEA` on PG, `BLOB` on SQLite | ✓ |
| 21 | 504 `raw_envelope JSONB NOT NULL` | JSONB NOT NULL | `:70` | `jsonb('raw_envelope')` | ✓ |
| 22 | 505 `payload_parse_status VARCHAR(16)` | VARCHAR(16) nullable | `:71` | `string('payload_parse_status', 16)->nullable()` | ✓ |
| 23 | 507 `integrity_exception_class VARCHAR(32) NOT NULL` | VARCHAR(32) NOT NULL | `:78` | `string('integrity_exception_class', 32)` | ✓ |
| 24 | 508 `integrity_exception_reason TEXT NOT NULL` | TEXT NOT NULL | `:79` | `text('integrity_exception_reason')` | ✓ |
| 25 | 509 `conflicting_event_id UUID NOT NULL` | UUID NOT NULL | `:85` | `uuid('conflicting_event_id')` | ✓ |
| 26 | 510 `server_received_at TIMESTAMPTZ NOT NULL` | TIMESTAMPTZ NOT NULL | `:87` | `timestampTz('server_received_at')` | ✓ |
| 27 | 511 `resolved_at TIMESTAMPTZ` | TIMESTAMPTZ nullable | `:90` | `timestampTz(...)->nullable()` | ✓ |
| 28 | 512 `resolved_by UUID` | UUID nullable | `:91` | `uuid('resolved_by')->nullable()` | ✓ |
| 29 | 513 `created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()` | TIMESTAMPTZ NOT NULL default NOW() | `:94` | `timestampTz('created_at')->useCurrent()` | ✓ |

**29 spec columns, 29 migration columns, zero drift.** Nothing extra, nothing missing, nothing renamed. The migration also adds zero unspec'd columns — disciplined.

Notable correctness points:
- The §8 schema does **not** declare a FK from `conflicting_event_id` to `fiscal_events.id`, and the migration correctly does not add one. The migration's PHPDoc at `:82–84` justifies the omission ("future export-and-purge of resolved quarantine rows doesn't cascade-trip on retained fiscal events, and so the quarantine record is self-contained"). Sound — and consistent with the "self-contained quarantine row" invariant the spec §8 description (line 517) explicitly emphasizes. The forensic linkage exists in data; it's just not enforced as a referential invariant, which would create coupling between the JET-export retention policy on `fiscal_events` and the quarantine table's resolution lifecycle. ✓
- Similarly there is no FK from `envelope_event_id`, `reference_event_id`, `reference_document_id`, `source_event_id`, or `resolved_by` to their semantic targets. Spec §8 doesn't declare any FK. The "self-contained" invariant explains all five. ✓
- `payload_parse_status` is unbounded enum-like text without a CHECK — same as the Task 7 column (`fiscal_events.payload_parse_status`, also `VARCHAR(16)` with a `'pending'` default and no CHECK). The Task 7 review accepted that; consistency is correct here. The `PayloadParseStatus` enum (Task 3) handles validation at the application boundary.

---

## Constraints, indexes, FK conformance

| Spec invariant | Migration evidence | Verdict |
|---|---|---|
| §8 line 507 "`'sequence_conflict'` in Phase 1" | `:102–106` `fiscal_event_quarantine_class_phase1_allowed CHECK (integrity_exception_class = 'sequence_conflict')`, PG-gated | ✓ — exactly the Phase 1 whitelist; future-phase extension path documented in the PHPDoc at `:98–101` |
| Hash format CHECKs (mirror Task 7) | `:112–121` `current_hash ~ '^[0-9a-f]{64}$'` + `previous_hash ~ '^[0-9a-f]{64}$'`, PG-gated | ✓ — verbatim match with Task 7 `fiscal_events` |
| Hot-path admin-resolution index | `:126–130` `fiscal_event_quarantine_unresolved_idx ON fiscal_event_quarantine (terminal_id, claimed_sequence_number) WHERE resolved_at IS NULL`, PG-gated, partial | ✓ on existence — see P2-2 below for an index-leading-column tuning question |
| FK enforcement on the conserved cross-references | None (intentional per spec §8 silence + the "self-contained" invariant at §8 line 517) | ✓ |

The migration follows the project's locked-in PG-only-gating idiom for CHECKs and the partial index. The named constraints all use the `<table>_<purpose>_<kind>` convention (e.g. `fiscal_event_quarantine_class_phase1_allowed`, `fiscal_event_quarantine_unresolved_idx`). Future `fiscal:verify-event-chain` / quarantine-resolution-view introspection will find them by name. ✓

The `_phase1_allowed` suffix on the CHECK constraint name is a quietly excellent choice: when Phase 2 adds new non-admissible classes (e.g. if a `payload_unparseable_when_signed` class emerges), the next migration's most readable signal is "drop `_phase1_allowed`, add `_phase2_allowed` with a wider list, in lockstep with the enum partition update." That's a strict improvement on the abstract `_class_allowed` name. ✓

---

## Mutability premise — Task 7/8 contrast (correctly inverted)

This is the second instance (after Task 9) of a mutable table in the fiscal partition. Spec §8 line 511–512 explicitly declares `resolved_at TIMESTAMPTZ` / `resolved_by UUID` — these columns are written **after** the row exists, by the resolution flow. The implementation correctly inverts the Task 7/8 immutability pattern:

| Mutability check | Evidence | Verdict |
|---|---|---|
| No `BEFORE UPDATE` / `BEFORE DELETE` / `BEFORE TRUNCATE` trigger function created | Migration `up()` body, lines `28–132`: only `Schema::create(...)`, three `ALTER TABLE … ADD CONSTRAINT … CHECK`, and one `CREATE INDEX` — no `CREATE FUNCTION` / `CREATE TRIGGER` | ✓ |
| Model `public $timestamps = false` | `FiscalEventQuarantine.php:78` | ✓ — and it is correct: the migration only declares `created_at` (not `updated_at`), so Eloquent's auto-`updated_at` management would error. Compare Task 9 which has both `created_at` and `updated_at` and therefore correctly sets `$timestamps = true`. The two opposite settings on two consecutive mutable tables reflect the spec's intentional difference in lifecycle column shape, not implementer inconsistency. |
| `$fillable` excludes lifecycle + classification | `:92–117` — `integrity_exception_class`, `integrity_exception_reason`, `resolved_at`, `resolved_by` all omitted | ✓ — the Task 9 lesson is correctly applied |
| Model PHPDoc explicitly explains the `$fillable` partition | `:80–88` | ✓ — strict improvement on Task 9, which justified the partition in the commit message; here the justification lives in the model file itself |

The model's `$fillable` is the **insert-time identity + envelope mirror** (24 fields: the row's persisted shape minus 4 classification/lifecycle columns minus the 1 auto-managed `created_at`). The OutboxIngestor (Task 19) — which lands a quarantine row when it detects a `sequence_conflict` in Step 4 (§7.2) — will need to `forceFill()` or directly assign the classification at the same point it constructs the row. That's the same pattern Task 9 anticipated for the OutboxIngestor's projection-row inserts. Sound.

---

## `FiscalEventQuarantine` model conformance

| Aspect | Plan / spec requirement | Code evidence | Verdict |
|---|---|---|---|
| Namespace | `App\Modules\Fiscal\Domain\Models` per module convention | `:5` | ✓ |
| Table name | `'fiscal_event_quarantine'` (singular, matching spec §8 + migration) | `:63` | ✓ — and worth noting: `fiscal_event_quarantine` is **singular** by design; spec §8 uses singular throughout (line 481 "fiscal_event_quarantine {"). Plural `quarantines` would be wrong. |
| Primary key type | UUID (string, non-incrementing) | `:66` `$keyType = 'string';`, `:69` `$incrementing = false;` | ✓ |
| Auto-timestamps | **Disabled** — table only has `created_at` (no `updated_at`); resolution carries `resolved_at` instead | `:78` `$timestamps = false;` with PHPDoc justification at `:71–77` | ✓ |
| `raw_envelope` cast | `'array'` (per plan §831) | `:130` | ✓ |
| `integrity_exception_class` cast | `IntegrityExceptionClass::class` (per plan §831) | `:131` | ✓ — and `IntegrityExceptionClass::SequenceConflict` resolves on read (verified by the round-trip test at `:97`) |
| `business_date` cast | `'date'` (DATE column) | `:128` | ✓ |
| Datetime casts | `event_time_device`, `last_server_time_seen`, `server_received_at`, `resolved_at`, `created_at` | `:127, 129, 132, 133, 134` | ✓ |
| `event_version` integer cast | SMALLINT column → `'integer'` | `:125` | ✓ |
| `claimed_sequence_number` integer cast | BIGINT column → `'integer'` | `:126` | ✓ — see Nit-2 below for a 32-bit-host edge case worth noting in PHPDoc |
| No cross-module imports | Only `App\Modules\Fiscal\Domain\Enums\…` | `:7` | ✓ |
| No `app()` helper | Required by CLAUDE.md rule 13 | (none in file) | ✓ |
| No `mixed` parameters/returns | None present | (none in file) | ✓ |
| Strict types declared | `declare(strict_types=1);` | `:3` | ✓ |
| Final class | Convention for module models | `:60` `final class FiscalEventQuarantine extends Model` | ✓ |
| `@property` annotations | Comprehensive for IDE / PHPStan inference | `:30–58` covers all 29 columns including the `IntegrityExceptionClass` cast and nullable Carbon fields | ✓ |
| `casts()` method (Laravel 11+ convention) | Use `protected function casts(): array`, not `$casts` property | `:122–136` | ✓ — matches `FiscalEvent.php` and `FiscalEventProjectionRow.php` |

The model is minimal — no relations, no scopes, no business methods. That matches the spec's framing (this is incident-evidence persistence; the behavior surface is the resolution flow, which will be a separate Application-layer class in a later task).

The `canonical_bytes` cast is **not** declared. Eloquent will surface it as a raw PHP `string` (the BYTEA bytes), which is correct — there is no transformation, only verbatim preservation. ✓

---

## Test discipline (plan §808–836)

| Aspect | Required | Actual | Verdict |
|---|---|---|---|
| Test exists at spec'd path | `apps/api/tests/Feature/Fiscal/FiscalEventQuarantineTableTest.php` | Yes | ✓ |
| Uses `RefreshDatabase` | Per Task 7/8/9 precedent | `:32` | ✓ |
| Column-existence test covers all 29 spec columns | Plan §812–821 lists 23 columns (a subset — the plan's stub is illustrative, not exhaustive) | `:34–81` — all 29 spec columns asserted, grouped by category in comments (Identity / Tenancy + actors / Envelope-mirrored metadata / Cross-event references / Chain coordinates / Conserved envelope / Incident metadata / Server-controlled) | ✓ — **strict improvement on the plan's stub** (which would have shipped a 23-of-29 column-coverage gap that future schema-drift would have evaded) |
| Test of model casts round-trip | Implementer-added | `:83–98` — inserts `raw_envelope` as JSON string + `integrity_exception_class` as raw string, reads back via Eloquent, asserts array-decoded + enum-decoded values | ✓ — pins both the JSONB→array cast and the VARCHAR→enum cast |
| Test of Phase 1 CHECK constraint on non-`sequence_conflict` | Per the migration's added invariant | `:100–106` — `skipUnlessPostgres()`, then `expectException(QueryException::class)`, then attempt to insert `time_anomaly` | ✓ — uses the `IntegrityExceptionClass::TimeAnomaly->value` literal `'time_anomaly'`, which is one of the four "admissible to ledger" enum cases and therefore correctly NOT in the Phase 1 quarantine whitelist; the CHECK fires on PG |
| Test of resolution columns defaulting to NULL on insert | Implementer-added | `:108–116` | ✓ — pins that the resolver flow (yet to be implemented) **must** write `resolved_at`/`resolved_by` to mark resolution; a bug that left them populated at insert time would surface as an assertion failure |
| Genuine RED before migration | Reviewer-verified | Verified — moved migration out, ran tests, got `Tests: 4, Assertions: 1, Errors: 2, Failures: 1, Skipped: 1`. Column-existence failed at `Schema::hasTable('fiscal_event_quarantine')`; the two insert-based tests errored on missing table; the PG-only CHECK test skipped. Restored → all green. | ✓ |
| GREEN after migration | Implementer claim | Verified — `OK (4 tests, 35 assertions, 1 skipped)` on SQLite | ✓ |
| Driver-portable insert helper | Tests run on SQLite per `phpunit.xml`; no `NOW()` / `CURRENT_DATE` raw SQL | `insertQuarantineRow()` uses `now()->toDateTimeString()` and `now()->toDateString()`; PHPDoc comment at `:134` calls out the SQLite-portability reason | ✓ — matches Task 7/8/9 idiom |

---

## Findings

### P2-1 — Missing PG-only test for hash-format CHECKs

**Severity:** P2 (coverage gap, not a correctness defect)
**File:** `apps/api/tests/Feature/Fiscal/FiscalEventQuarantineTableTest.php`

The migration declares two PG-only hash-format CHECKs on `current_hash` and `previous_hash` (`:112–121`), mirroring the Task 7 pattern. Task 7's test suite includes `FiscalEventsTableTest::test_current_hash_format_check_rejects_non_hex_on_postgres()` (and the same for `previous_hash`) — Task 7's locked-in discipline of "every PG-only CHECK has a PG-only test that fires on PG and skips on SQLite."

Task 10 ships the CHECKs without the matching tests. A future migration that names the constraint differently, drops the constraint, or accidentally omits it on a new PG migration would not surface as a test failure in CI. This is the same coverage-discipline gap that Task 9's review flagged (P2-1 on the FK constraint), and the same suggested resolution applies.

**Suggested fix:** Add two test methods using the existing `skipUnlessPostgres()` helper:

```php
public function test_current_hash_format_check_rejects_non_hex_on_postgres(): void
{
    $this->skipUnlessPostgres();
    $this->expectException(QueryException::class);
    $this->insertQuarantineRow(['current_hash' => 'NOT-A-HEX-' . str_repeat('z', 54)]);
}

public function test_previous_hash_format_check_rejects_non_hex_on_postgres(): void
{
    $this->skipUnlessPostgres();
    $this->expectException(QueryException::class);
    $this->insertQuarantineRow(['previous_hash' => 'UPPER' . str_repeat('A', 59)]);  // uppercase is rejected
}
```

The second test also implicitly pins the case-sensitivity invariant (the regex `^[0-9a-f]{64}$` rejects uppercase hex — which is the SoT canonical-bytes invariant). Worth doing both. Total: ~14 lines.

**Why P2, not P1:** the CHECKs are declared and will exist in production. The gap is a future-regression-prevention gap, not a present defect. The Task 7 discipline of "land the PG-only test in the same commit as the PG-only constraint" is the lockable rule; Task 10 silently breaks that discipline for these two CHECKs. Worth flagging explicitly.

### P2-2 — Partial index leading column is `terminal_id`, not `tenant_id` — diverges from the cross-table query convention

**Severity:** P2 (index-leading-column tuning question; not a correctness defect)
**File:** `apps/api/database/migrations/2026_05_14_100004_create_fiscal_event_quarantine_table.php:126–130`

The partial index reads:

```sql
CREATE INDEX fiscal_event_quarantine_unresolved_idx
    ON fiscal_event_quarantine (terminal_id, claimed_sequence_number)
    WHERE resolved_at IS NULL
```

The brief flagged this as worth examining. Two observations:

1. **The cross-table convention is tenant-leading.** Task 7's `fiscal_events` hot-path UNIQUE is `(tenant_id, terminal_id, sequence_number)` (`2026_05_14_100001_create_fiscal_events_table.php:91–94`). Every tenant-scoped query in this codebase (Spatie multi-tenancy, the `SetPermissionsTeam` middleware in `routes.php`) carries `tenant_id` as the outermost predicate; the established convention is that indexes lead with `tenant_id` so the planner can prune tenants first.

2. **For the admin-resolution view specifically, the lead column choice depends on the query shape.** Two plausible shapes:
   - **Admin browses unresolved incidents across all their terminals**: `WHERE tenant_id = ? AND resolved_at IS NULL ORDER BY created_at DESC`. With the current index, the planner falls back to bitmap-or on the per-terminal partial index — workable but suboptimal at scale.
   - **Verifier (§15) checks unresolved incidents for one terminal**: `WHERE terminal_id = ? AND claimed_sequence_number IN (...) AND resolved_at IS NULL`. The current index is optimal.

The verifier-hot-path framing (the migration's PHPDoc comment at `:122–125`) is what justifies `terminal_id`-leading; the admin-resolution-view framing would prefer `tenant_id`-leading. The PHPDoc cites both use cases but only optimizes for one.

The spec doesn't dictate the index shape. The current choice is defensible — `terminal_id` is high-cardinality within a tenant, and a `terminal_id` lookup naturally also pins the tenant (terminals are tenant-scoped via FK on `pos_terminals.tenant_id`, though the FK isn't on this table). But it does diverge from the project's locked-in tenant-leading convention.

**Suggested fix (one of two):**
- Option A — keep `terminal_id`-leading, but add a one-line PHPDoc note: `// terminal_id leads (not tenant_id, as elsewhere) because the verifier (§15) lookup is always per-terminal; admin-list-all-incidents is the rarer query and is acceptable as a bitmap-or scan.`
- Option B — change to `(tenant_id, terminal_id, claimed_sequence_number) WHERE resolved_at IS NULL`. Slightly larger index, optimal for both query shapes. Three keys is the same shape as Task 7's `fiscal_events_tenant_terminal_sequence_unique` UNIQUE — full cross-table consistency.

**Why P2, not P1:** the index will work for the verifier's hot path as written. The divergence-from-convention deserves an explicit decision (or a one-line justification) because future-Claude reading this in Task 19 / the resolver task is going to wonder why this one index breaks the tenant-leading pattern. A 1-line PHPDoc closes the gap.

### P2-3 — Write-once on `integrity_exception_class` is currently enforced only by the Phase 1 single-value CHECK; the trigger-based contract is deferred without a captured rationale

**Severity:** P2 (deferred-invariant clarity; not a present defect)
**Files:** `apps/api/database/migrations/2026_05_14_100004_create_fiscal_event_quarantine_table.php` + `apps/api/app/Modules/Fiscal/Domain/Models/FiscalEventQuarantine.php`

This is the Task 8 round-2 BLOCKER analogue. Task 8 round-2's BLOCKER (closed at `73066080`) was that `fiscal_events.integrity_exception_class` could be reclassified post-insert — silently routing a `canonical_parse_failure` row through resolution flows designed for a different class. The fix was a BEFORE UPDATE trigger that pins `integrity_exception_class` as write-once: once non-NULL, it cannot change.

The Task 10 quarantine row's `integrity_exception_class` has the same risk: a malicious or buggy resolver code path could `UPDATE fiscal_event_quarantine SET integrity_exception_class = 'some_other_class' WHERE id = ?`, bypassing class-specific resolution guards. **However**, in Phase 1 the column is also pinned by a single-value CHECK (`= 'sequence_conflict'`). Reclassification is impossible — there's only one valid value to set it to, and that's the value it already has.

**The question is what happens when Phase 2 widens the CHECK.** The migration PHPDoc at `:98–101` acknowledges the future-phase extension path but only mentions extending the CHECK and the enum partition. It does not mention adding a write-once trigger. That's the gap:

- **Today (Phase 1)**: single-value CHECK is sufficient — reclassification is impossible.
- **Phase 2 (when a second non-admissible class is added)**: the single-value CHECK is widened to a multi-value `IN (...)` whitelist, and reclassification becomes **possible** without a trigger. The Task 8 BLOCKER pattern then applies to this table.

The decision to defer the write-once trigger is sound — it would be premature optimization in Phase 1 to add a trigger that constrains a value the CHECK already constrains. But the deferral is **silent**: a Phase 2 implementer widening the CHECK won't automatically know they need to also add a write-once trigger.

**Suggested fix:** Add a one-line note to the migration PHPDoc immediately after `:101`:

```
// Phase 2 note: when this CHECK is widened to admit additional non-admissible
// classes, this column also needs a write-once BEFORE UPDATE trigger
// (cf. fiscal_events_immutability migration), because reclassification would
// then become possible and would silently bypass class-specific resolution
// guards (Task 8 round-2 BLOCKER pattern).
```

And/or capture it as a Phase 2 task in the project plan when Phase 2 begins.

**Why P2, not P1:** there is no present-day bypass — the CHECK forecloses it. The risk is entirely future-phase. But Task 8's BLOCKER was hard-won; allowing it to silently re-emerge in a future phase would be a regression of the lesson. A one-line PHPDoc note is cheap and propagates the discipline forward. The reviewer of Task 10's brief explicitly asked this question, which is itself evidence the answer is non-obvious.

(There is a separate question of whether the resolver — once written — should be **forbidden** from mutating `integrity_exception_class` via mass-assignment. The current model already addresses this: the column is **excluded from `$fillable`**, so `update()` calls from a controller can't touch it. The resolver code can only modify it via `forceFill()` or a direct `setAttribute()`, which is auditable in code review. Sound boundary discipline.)

### Nit-1 — `event_version` SMALLINT range not surfaced in the PHPDoc

**Severity:** nit (informational)
**File:** `apps/api/app/Modules/Fiscal/Domain/Models/FiscalEventQuarantine.php:37`

PHP integer is 64-bit on every supported platform, but the underlying column is `SMALLINT` (16-bit signed, range −32 768 to 32 767). The model's `@property int $event_version` doesn't surface that range. If a future event-version migration ever crosses 32 767 (extremely unlikely — single events would need versions in the tens of thousands), the PG `SMALLINT` insert would error opaquely. A one-line PHPDoc note like `@property int $event_version SMALLINT, −32 768..32 767` would help future readers. Strictly cosmetic.

### Nit-2 — `claimed_sequence_number` BIGINT range on 32-bit hosts

**Severity:** nit (informational)
**File:** `apps/api/app/Modules/Fiscal/Domain/Models/FiscalEventQuarantine.php:39` + `:126`

The column is `BIGINT` (64-bit). The Eloquent cast is `'integer'`. On 64-bit PHP hosts (which is every supported platform — Composer's `composer.json` for Laravel 12 requires `php: "^8.2"`, and `int` is 64-bit), this is safe up to PHP_INT_MAX (~9.2 × 10^18). On a hypothetical 32-bit host the cast would silently truncate values above 2.1 × 10^9. The project doesn't support 32-bit hosts, so this is not a defect — but the cast-to-`'integer'` choice (vs. cast-to-`'string'` for very large BIGINT columns) is a deliberate tradeoff. Worth a one-line comment in the model: `// 'integer' cast: 64-bit PHP is required; sequence numbers stay well below PHP_INT_MAX in practice.` Strictly cosmetic.

---

## Implementer deviations checked

The task brief flagged several implicit deviations from the plan's literal Step 1 stub. Reviewed each:

1. **Test column-existence list expanded from 23 to 29 columns.** Verdict: **sound, strict improvement.** The plan's Step 1 stub (lines 812–821) lists 23 columns. The implementer wrote a 29-column assertion list (the full spec §8 surface). The plan's stub was illustrative-not-exhaustive (it omits `signature_version`, `last_server_time_seen`, `reference_event_id`, `reference_document_id`, `source_event_class`, `source_event_id`); shipping the stub as-written would have left a 6-column coverage gap that future schema-drift could have evaded. The full 29-column assertion is the right call.

2. **`$fillable` excludes lifecycle + classification (Task 9 lesson applied).** Verdict: **sound, the right application of the Task 9 P2.** The Task 9 review's P2 was that boundary discipline on mutable tables requires excluding lifecycle and write-once classification from `$fillable`. The Task 10 model applies that lesson explicitly with a PHPDoc justification at `:80–88` — the right propagation of the lesson and a strict improvement on Task 9's commit-message-only justification.

3. **`$timestamps = false` (vs Task 9's `true`).** Verdict: **sound, correct difference.** Task 9's table has both `created_at` and `updated_at` per spec §7.5; Task 10's has only `created_at` per spec §8 (resolution carries its own `resolved_at` timestamp instead of an auto-managed `updated_at`). The two settings on two consecutive mutable tables reflect the spec's intentional difference, not implementer inconsistency. The model PHPDoc at `:71–78` explicitly justifies the choice.

4. **Test of resolution-columns-default-to-NULL.** Verdict: **sound, additive coverage.** The plan doesn't mandate this test, but pinning the invariant ("resolution must be a write — it cannot be the default state") prevents a future migration from accidentally adding a `DEFAULT NOW()` to `resolved_at` and silently breaking the resolution semantics.

5. **PG-only CHECK test only covers the Phase 1 single-value enforcement, not the hash-format CHECKs.** Verdict: **gap — see P2-1.** This is the one test-coverage gap.

---

## Cross-task regression check

| Prior task | Files touched in `2e8aec56`? | Verdict |
|---|---|---|
| Task 1 (Fiscal module skeleton + RoadmapItem + `ProjectionStatus` enum scaffolding) | No | ✓ no regression |
| Task 2 (`FiscalEventType` enum) | No | ✓ no regression |
| Task 3 (`IntegrityStatus` / `PayloadParseStatus` / `SignatureStatus` enums) | No | ✓ no regression |
| Task 4 (canonical-golden-vectors fixture) | No | ✓ no regression |
| Task 5 (`FiscalEventCanonicalEncoder` TS) | No | ✓ no regression |
| Task 6 (`FiscalIntegrityProvider` + signature provider seam) | No | ✓ no regression |
| Task 7 (`fiscal_events` table + `FiscalEvent` model) | No (the test's `insertQuarantineRow()` helper is independent — no FK or cross-row dependency on `fiscal_events`) | ✓ no regression |
| Task 8 (immutability triggers on `fiscal_events`) | No | ✓ no regression — Task 10 deliberately omits the trigger pattern |
| Task 9 (`fiscal_event_projections`) | No | ✓ no regression — and the boundary-discipline lesson is correctly propagated |
| `IntegrityExceptionClass` enum read-only dependency | Yes, the model `use`s the enum (`:7`) and casts to it; no enum modification | ✓ correct — the enum's `isAdmissibleToLedger()` partition is reused as the conceptual contract |

The commit's three-file scope is exactly what Task 10 promises.

---

## Forward-looking notes for Task 11 and Task 19

Three notes for the reviewer of the next tasks that will touch this table:

- **Task 11 (`pos_receipts.canonical_bytes` + `fiscal_event_id` mirror)** does **not** depend on `fiscal_event_quarantine` directly — `pos_receipts` is a projection of `fiscal_events` (the admissible partition), not of the quarantine partition. The quarantine row's `envelope_event_id` is the device-claimed `fiscal_events.id`, but the `fiscal_events` row that won the slot has a **different** `id` (per spec §8 line 488 — "the device-claimed fiscal_events.id, for forensics"). So a quarantine row never gets a `pos_receipts` projection. Future projection-side code needs to honor this asymmetry.

- **Task 19 (OutboxIngestor)** is where this table is first **written** — in Step 4 of the §7.2 ingestion pipeline, when a `sequence_conflict` is detected at the `INSERT` time on `fiscal_events_tenant_terminal_sequence_unique`. The ingestor must:
  - Catch the unique-violation `QueryException` from the `fiscal_events` insert.
  - Look up the existing `fiscal_events` row that won the slot (to populate `conflicting_event_id`).
  - Construct a `FiscalEventQuarantine` row via `forceFill()` (because `integrity_exception_class` + `_reason` are not in `$fillable`).
  - Persist it in a **separate** transaction from the (failed) `fiscal_events` insert — the failed insert's transaction is already rolled back.
  - Emit the admin alert + the `CHAIN_BREAK_DETECTED` event (spec §9).
  
  Worth a one-line note in the Task 19 implementation that the quarantine insert is **never** part of the same transaction as the `fiscal_events` insert it replaces — the unique-violation aborts the original transaction.

- **The resolver task (post-Phase 1)** will need an explicit decision on whether `resolved_at`/`resolved_by` can be **un-resolved** (e.g. a resolver mistake gets reverted). The current schema allows it (no CHECK, no trigger), and the model's exclusion of these from `$fillable` only blocks mass-assignment, not direct `setAttribute()` / `forceFill()`. The Phase 1 single-value CHECK on `integrity_exception_class` foreshadows the same question for the resolution stamps — worth a deliberate decision at that point.

---

## Recommendation

**APPROVE-WITH-MINOR-EDITS — proceed to Task 11** (`add_canonical_bytes_and_fiscal_event_id_to_pos_receipts` + convert chain columns to mirrors).

The edits flagged:
1. **P2-1**: add two PG-only `test_*_hash_format_check_rejects_non_hex_on_postgres()` tests using the existing `skipUnlessPostgres()` helper. ~14 lines total.
2. **P2-2**: either add a one-line PHPDoc note justifying `terminal_id`-leading on `fiscal_event_quarantine_unresolved_idx`, or widen the index to `(tenant_id, terminal_id, claimed_sequence_number)` for cross-table consistency with Task 7. One-line edit either way.
3. **P2-3**: add a one-line PHPDoc note to the migration above `:102` capturing the Phase 2 obligation: widening the `integrity_exception_class` CHECK requires also adding a write-once BEFORE UPDATE trigger (Task 8 round-2 BLOCKER pattern). One-line edit.
4. **Nit-1, Nit-2**: optional one-line PHPDoc range notes on `event_version` SMALLINT and `claimed_sequence_number` BIGINT casts. Not required.

Per the parent session's reconciliation policy: these are flagged for the parent session to apply or accept-as-is; this review does not modify source. None of the four findings block Task 11. The migration is correctly producing the schema spec §8 requires.

The Task 8 round-2 BLOCKER class (write-once invariant silently routable around via reclassification) was checked-for-by-analogy and **is currently foreclosed** by the Phase 1 single-value CHECK — but the foreclosure is implicit. P2-3 surfaces the implicit assumption so it doesn't silently lapse in Phase 2.

No follow-ups required before Task 11 begins (other than the optional P2 edits above).
