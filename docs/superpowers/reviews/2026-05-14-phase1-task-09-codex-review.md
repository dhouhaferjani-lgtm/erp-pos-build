APPROVE-WITH-MINOR-EDITS

# Adversarial review - POS Phase 1 Task 9
**Reviewer:** Codex
**Review date:** 2026-05-16
**Branch:** feat/pos-fiscal-event-engine-phase1
**Base SHA:** 61444f56^
**Head SHA:** 61444f56

## Summary table
| Severity | Count |
|---|---:|
| BLOCKER | 0 |
| P1 | 1 |
| P2 | 1 |
| nit | 0 |

## Executive summary
The Task 9 migration is materially faithful to spec §7.5: every required `fiscal_event_projections` column is present with the expected type family, default, nullability, and composite uniqueness contract; the table is mutable and has no immutability trigger. The `ProjectionStatus` enum exists with the expected four values. The focused Task 9 test ran and did not fail, but it only exercised the portable SQLite surface in this worktree; the PostgreSQL-only FK and partial index are not covered by a Task 9 PG smoke test or by the current PG merge-gate filter.

## Findings

### [P1] PostgreSQL-only FK and partial-index DDL are not exercised by Task 9 tests or the PG merge gate
**File:** apps/api/database/migrations/2026_05_14_100003_create_fiscal_event_projections_table.php:66; apps/api/tests/Feature/Fiscal/FiscalEventProjectionsTableTest.php:33; .github/workflows/ci.yml:335
**Spec ref:** docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md §7.5:437, §7.5:452-454; docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md §Task 9:783
**Evidence:** The FK and worker-status partial index are both created only inside the PostgreSQL branch:

```php
if (DB::connection()->getDriverName() === 'pgsql') {
    DB::statement(<<<'SQL'
        ALTER TABLE fiscal_event_projections
        ADD CONSTRAINT fiscal_event_projections_fiscal_event_id_fk
        FOREIGN KEY (fiscal_event_id) REFERENCES fiscal_events(id)
    SQL);

    DB::statement(<<<'SQL'
        CREATE INDEX fiscal_event_projections_status_pending_idx
            ON fiscal_event_projections (projection_status)
            WHERE projection_status IN ('pending', 'running')
    SQL);
}
```

The Task 9 test file checks column existence and the portable composite unique key only (test lines 33-75). It never verifies that PostgreSQL rejects an orphan `fiscal_event_id`, never inspects `pg_indexes` / `pg_constraint`, and never exercises the partial-index predicate. The PG CI gate is a hard-coded filter that includes Task 7 and Task 8 fiscal tests but not `FiscalEventProjectionsTableTest`:

```bash
php artisan test \
  --filter="VoucherLedgerTest|VoucherLedgerAppendOnlyTest|VoucherSchemaTest|FiscalHardeningE2ETest|FiscalEventsTableTest|FiscalEventsImmutabilityTest"
```

This is the same recurring pattern called out in the Task 8 Codex review: a PostgreSQL-specific fiscal invariant is not covered by the default SQLite run and is absent from the PG merge gate (docs/superpowers/reviews/2026-05-14-phase1-task-08-codex-review.md:22-42). Here the production migration could lose the FK/index branch or break its raw SQL and the required Task 9 test would still pass locally.

**Fix:** Add a PG-only Task 9 smoke test that asserts (1) orphan projection insert fails on PostgreSQL, (2) `fiscal_event_projections_fiscal_event_id_fk` exists, and (3) `fiscal_event_projections_status_pending_idx` exists with predicate `projection_status IN ('pending', 'running')`. Add `FiscalEventProjectionsTableTest` to the `backend-test-pgsql` filter or replace the hard-coded filter with discovery that includes the new test.

### [P2] `projection_status` is mass-assignable despite being the projection state-machine field
**File:** apps/api/app/Modules/Fiscal/Domain/Models/FiscalEventProjectionRow.php:62
**Spec ref:** docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md §7.5:439, §7.5:452-454
**Evidence:** The model exposes `projection_status` in `$fillable`:

```php
protected $fillable = [
    'id',
    'fiscal_event_id',
    'projector_name',
    'projection_status',
    'attempts',
    'last_error',
    'last_attempted_at',
    'applied_at',
    'dead_lettered_at',
];
```

Spec §7.5 makes `projection_status` the durable lifecycle field: `pending` is seeded at insert, jobs move it to `running`, success moves it to `applied`, and exhausted retries move it to `dead_lettered`. Making it fillable is not a current runtime crash, but it weakens the model boundary before Tasks 19/23 add service and job code. Any future `FiscalEventProjectionRow::create($input)` / `update($input)` path can mass-assign terminal states and timestamps instead of going through the worker/resume state machine.

**Fix:** Keep insert identity fields (`id`, `fiscal_event_id`, `projector_name`) fillable if needed, and set the initial status via the database default. Move status transitions behind explicit methods or service/job code using targeted assignments / `forceFill()` internally. If the team intentionally wants bulk worker updates through mass assignment, document that exception and add tests around allowed transitions.

## Implementer deviation assessment
Schema vs §7.5: ACCEPTED. The migration creates `id`, `fiscal_event_id`, `projector_name`, `projection_status`, `attempts`, `last_error`, `last_attempted_at`, `applied_at`, `dead_lettered_at`, `created_at`, `updated_at`, and `UNIQUE (fiscal_event_id, projector_name)` in line with spec §7.5 lines 435-448 and Task 9 plan line 783. I found no missing or extra Task 9 columns.

Composite unique portability: ACCEPTED. The unique key is declared through Laravel Schema builder at migration lines 60-63, outside the PostgreSQL-only branch, and the duplicate insert test expects `Illuminate\Database\QueryException` at test lines 56-62. The focused SQLite run confirmed the duplicate insert path is enforced in the default test driver.

FK behavior and orphan risk: ACCEPTED-WITH-NOTE. PostgreSQL `NO ACTION` is semantically correct because `fiscal_events` is append-only and Task 8 blocks delete/truncate on PostgreSQL. Normal rollback order drops Task 9 before Task 7, so `down()` at migration lines 87-90 is safe for a standard rollback. The remaining issue is coverage, not the FK semantics; see the P1.

Partial index planner match: ACCEPTED-WITH-NOTE. I found no implemented `OutboxIngestor` or `ApplyFiscalEventProjectionJob` query in this branch to compare against the partial-index predicate; those are still future plan sections. The current index name is unique in the migration tree, and the predicate is compatible with queries constrained to `projection_status IN ('pending', 'running')` or a single implied value like `projection_status = 'pending'`.

Idempotency hole: NO CONFIRMED FINDING. There is no implemented mass-insert, seeder, `OutboxIngestor`, or recovery command path in this branch that uses `ON CONFLICT DO NOTHING`, `insertOrIgnore`, or `upsert` against `fiscal_event_projections`. Future Tasks 19 and 24 must not treat projection-row conflicts as silent success unless they first prove the existing row is the intended projector row and handle enqueue recovery.

Model field surface: ACCEPTED-WITH-NOTE except for the P2. The enum exists at `App\Modules\Fiscal\Domain\Enums\ProjectionStatus` with `pending`, `running`, `applied`, and `dead_lettered` (ProjectionStatus.php:7-12), and the model casts `projection_status` to that enum (FiscalEventProjectionRow.php:77-86). There are no JSONB columns in this table. `$timestamps = true` matches the migration's `created_at` / `updated_at` columns.

## Test run
Command run from `apps/api`:

```bash
php artisan test --filter FiscalEventProjectionsTableTest 2>&1 | tail -30
```

Observed result: no test failures; PHPUnit reported `Tests: 3 warnings (14 assertions)`. A diagnostic rerun with `--display-warnings` showed each warning was `file_get_contents(.../apps/api/.env): Failed to open stream: No such file or directory` from test bootstrap. That is an environment warning in this worktree, not a Task 9 assertion failure.

## Recommendations
1. Add PostgreSQL coverage for Task 9's FK and partial index, and include it in `.github/workflows/ci.yml`'s PG-backed filter.
2. Narrow `FiscalEventProjectionRow::$fillable` before Tasks 19/23 start using the model, so `projection_status` changes are owned by explicit lifecycle code.
3. When Task 19 inserts projection rows, do not use blind `insertOrIgnore` / `ON CONFLICT DO NOTHING` for the projection table. If conflict handling is needed, load and validate the existing `(fiscal_event_id, projector_name)` row and enqueue/recover explicitly.
4. When Task 23 lands, align dispatcher/recovery queries with the partial-index predicate (`pending` / `running`) and add `EXPLAIN`-level or catalog-level PG smoke coverage if the query becomes a hot path.
