APPROVE-WITH-MINOR-EDITS - The migration/model change matches Task 11's production schema intent, but the idempotency anchor test regressed from the plan and Task 9 pattern by proving schema shape instead of proving duplicate enforcement.

# Adversarial review - POS Phase 1 Task 11
**Reviewer:** Codex
**Review date:** 2026-05-16
**Branch:** feat/pos-fiscal-event-engine-phase1
**Base SHA:** ddc42d5c^
**Head SHA:** ddc42d5c

## Summary table
| Severity | Count |
|---|---:|
| BLOCKER | 0 |
| P1 | 0 |
| P2 | 1 |
| P3 | 0 |
| CLEAN | 8 |

## Findings table
| ID | Severity | Concern | Location |
|---|---|---|---|
| P2-1 | P2 | Duplicate `fiscal_event_id` enforcement is not tested, despite Task 11 specifying an insert-based failure test | `apps/api/tests/Feature/Fiscal/PosReceiptsCanonicalBytesTest.php:51`; `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:866` |

## Executive summary
The Task 11 migration adds `canonical_bytes` as nullable binary and `fiscal_event_id` as nullable UUID plus a named unique constraint, and on PostgreSQL adds the FK to `fiscal_events(id)`. That matches the Task 11 plan at `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:882-884` and the receipt-projection direction in spec §5.0 / §7.5 / §13 at `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:253`, `:429-458`, and `:552-576`.

The only confirmed issue is coverage: the plan's red test requires an actual duplicate insert to raise `QueryException`, and Task 9 uses that pattern for its idempotency unique key. This commit instead checks index metadata only, so a class of "index exists in metadata but duplicate write path is not exercised" regressions is not covered.

## BLOCKER findings

No BLOCKER findings.

## P1 findings

No P1 findings.

## P2 findings

### P2-1 - Duplicate `fiscal_event_id` enforcement is not tested
**Location:** `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:866-874`; `apps/api/tests/Feature/Fiscal/PosReceiptsCanonicalBytesTest.php:51-78`; `apps/api/tests/Feature/Fiscal/FiscalEventProjectionsTableTest.php:56-63`

**Evidence:** Task 11's plan explicitly specifies an insert-based failure test: insert one `pos_receipts` row with a `fiscal_event_id`, then expect `Illuminate\Database\QueryException` on a second insert with the same `fiscal_event_id` at `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:866-874`.

The committed test pivots to metadata inspection only. `test_fiscal_event_id_unique_constraint_exists()` calls `Schema::getIndexes('pos_receipts')`, checks for the `pos_receipts_fiscal_event_id_unique` name, then asserts `unique` and `columns` metadata at `apps/api/tests/Feature/Fiscal/PosReceiptsCanonicalBytesTest.php:51-78`. It never inserts two receipts and never calls `expectException(QueryException::class)`. The commit message also states this directly in the `git show ddc42d5c` output: "UNIQUE + FK contracts validated via schema introspection rather than insert-based testing."

Task 9's comparable idempotency-anchor test does exercise the database behavior: `test_unique_event_projector_blocks_duplicate()` inserts one projection row, then expects `QueryException` on the duplicate `(fiscal_event_id, projector_name)` insert at `apps/api/tests/Feature/Fiscal/FiscalEventProjectionsTableTest.php:56-63`.

**Impact:** The test proves the index was named and reported by the schema API, but it does not prove the write path actually rejects duplicate linkage. Because `pos_receipts.fiscal_event_id` is the durable idempotency anchor for `PosCoreReceiptProjection` per Task 11 at `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:849` and Task 21 at `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:1590-1600`, this leaves the critical duplicate-projection invariant weaker than both the plan and the Task 9 precedent.

**Recommendation:** Add a focused insert-based test that creates the required parent `fiscal_events` row, creates two otherwise-distinct `pos_receipts` rows with the same `fiscal_event_id`, and asserts the second insert raises `QueryException`. Keep the existing metadata test if desired, but do not use it as the only proof of the idempotency constraint.

## P3 findings

No P3 findings.

## CLEAN findings

### CLEAN-1 - Production-model fillable ripple is not a confirmed current break
**Location:** `apps/api/app/Modules/POS/Domain/Receipt.php:132-190`; `apps/api/database/factories/ReceiptFactory.php:32-52`; `apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptRequest.php:46-113`; `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:289-312`; `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:556-592`

`Receipt::$fillable` now includes `canonical_bytes` and `fiscal_event_id` at `apps/api/app/Modules/POS/Domain/Receipt.php:183-190`, but both new database columns are nullable in the migration at `apps/api/database/migrations/2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php:47-53`. The factory does not provide either field at `apps/api/database/factories/ReceiptFactory.php:32-52`, so the new nullable columns should not break existing factory-created receipts.

I did not find a current controller path that passes arbitrary request data into `Receipt::create()` or `Receipt::update()`. `StoreReceiptRequest` validates a fixed allowlist that excludes both new fields at `apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptRequest.php:46-113`, `ReceiptController::store()` passes selected validated values into `ReceiptCreationService::createReceipt()` at `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:289-312`, and `ReceiptCreationService` constructs an explicit receipt attribute array that excludes both new fields at `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:556-592`.

### CLEAN-2 - `fiscal_event_id` being fillable matches this task's plan and does not contradict Task 9's actual model pattern
**Location:** `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:851-854`; `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:882-884`; `apps/api/app/Modules/Fiscal/Domain/Models/FiscalEventProjectionRow.php:56-78`; `apps/api/app/Modules/POS/Domain/Receipt.php:183-190`

Task 11 explicitly says to add `fiscal_event_id` and `canonical_bytes` to the `pos_receipts` model `$fillable` at `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:851-854` and repeats it in Step 3 at `:882-884`. Task 9's row model also marks `fiscal_event_id` fillable as an insert-time identity field at `apps/api/app/Modules/Fiscal/Domain/Models/FiscalEventProjectionRow.php:56-78`; its "not fillable" rule applies to lifecycle state columns, not the event identity.

### CLEAN-3 - Multiple-NULL unique semantics are documented, but duplicate non-NULL enforcement is the P2 above
**Location:** `apps/api/database/migrations/2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php:37-39`; `apps/api/database/migrations/2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php:52-53`

The migration intentionally allows legacy rows by making `fiscal_event_id` nullable at `apps/api/database/migrations/2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php:52` and documenting multiple NULLs under the unique constraint at `:37-39`. The concern that remains is not NULL semantics; it is the missing duplicate non-NULL behavior test described in P2-1.

### CLEAN-4 - FK `NO ACTION` is consistent with append-only `fiscal_events`
**Location:** `apps/api/database/migrations/2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php:56-65`; `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php:25-27`; `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php:57-62`; `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php:262-270`

The Task 11 FK omits `ON DELETE`, so PostgreSQL uses `NO ACTION`, and the migration comments state the intended delete-blocking invariant at `apps/api/database/migrations/2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php:56-65`. That is consistent with Task 8's `fiscal_events` immutability contract: the migration says `BEFORE DELETE` and `BEFORE TRUNCATE` always raise at `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php:25-27`, implements the delete exception at `:57-62`, and installs the delete/truncate triggers at `:262-270`. Nullifying `pos_receipts.fiscal_event_id` on fiscal-event deletion would weaken that append-only linkage.

### CLEAN-5 - Migration `down()` order is correct for PostgreSQL
**Location:** `apps/api/database/migrations/2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php:69-78`

The rollback drops the PostgreSQL FK first at `apps/api/database/migrations/2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php:71-72`, then drops the unique constraint at `:75-76`, then drops `fiscal_event_id` and `canonical_bytes` at `:77`. That ordering avoids dropping a constrained column before its dependent constraints.

### CLEAN-6 - Code uses the real `chain_sequence` column despite plan/spec wording drift
**Location:** `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:847-849`; `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:882-884`; `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:253`; `apps/api/database/migrations/2026_01_08_190637_create_pos_receipts_table.php:39-40`; `apps/api/database/migrations/2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php:29-35`

The plan and spec text refer to `hash_sequence` at `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:847-849`, `:882-884`, and `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:253`, but the actual production receipt column is `chain_sequence` in the original table migration at `apps/api/database/migrations/2026_01_08_190637_create_pos_receipts_table.php:39-40`. The Task 11 migration PHPDoc uses the real column name, `chain_sequence`, at `apps/api/database/migrations/2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php:29-35`. The commit subject in `git show ddc42d5c` says "chain columns become mirrors" and does not introduce the wrong `hash_sequence` name.

### CLEAN-7 - `canonical_bytes` type and size are appropriate for 2-10 KB payloads
**Location:** `apps/api/database/migrations/2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php:43-47`; `apps/api/vendor/laravel/framework/src/Illuminate/Database/Schema/Grammars/PostgresGrammar.php:1097-1099`; `apps/api/vendor/laravel/framework/src/Illuminate/Database/Schema/Grammars/SQLiteGrammar.php:1012-1014`; `apps/api/vendor/laravel/framework/src/Illuminate/Database/Schema/Blueprint.php:1389-1391`; `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:235`

The migration uses `$table->binary('canonical_bytes')->nullable()` at `apps/api/database/migrations/2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php:43-47`. Laravel maps binary to PostgreSQL `bytea` at `apps/api/vendor/laravel/framework/src/Illuminate/Database/Schema/Grammars/PostgresGrammar.php:1097-1099` and SQLite `blob` at `apps/api/vendor/laravel/framework/src/Illuminate/Database/Schema/Grammars/SQLiteGrammar.php:1012-1014`. The Blueprint method accepts an optional length at `apps/api/vendor/laravel/framework/src/Illuminate/Database/Schema/Blueprint.php:1389-1391`, but PostgreSQL `bytea` is variable length, so the 2-10 KB payload size does not require a separate cap. Spec §4 requires server storage as verbatim binary at `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:235`.

### CLEAN-8 - CI PG gate includes `PosReceiptsCanonicalBytesTest`
**Location:** `.github/workflows/ci.yml:334-346`

The PG merge-gate comment names `PosReceiptsCanonicalBytesTest` at `.github/workflows/ci.yml:334-336`, and the hard-coded `php artisan test --filter` includes `PosReceiptsCanonicalBytesTest` at `.github/workflows/ci.yml:345-346`.

### CLEAN-9 - Cross-test isolation risk from the fillable change is not confirmed
**Location:** `apps/api/tests/Feature/Fiscal/PosReceiptsCanonicalBytesTest.php:43-48`; `apps/api/database/factories/ReceiptFactory.php:32-52`; `apps/api/tests/Feature/POS/InstrumentSerialMigrationTest.php:120`; grep output from `grep -rn 'Receipt::factory\|fillable' apps/api/tests/`

The new test is the only receipt-specific test asserting the new `Receipt` fillable entries at `apps/api/tests/Feature/Fiscal/PosReceiptsCanonicalBytesTest.php:43-48`. Existing POS feature tests create receipts through factories or explicit arrays, as shown by the requested grep output for `Receipt::factory` / `fillable`; the factory defaults omit the new nullable fields at `apps/api/database/factories/ReceiptFactory.php:32-52`. One POS migration test explicitly notes it uses model fillable to respect FK constraints at `apps/api/tests/Feature/POS/InstrumentSerialMigrationTest.php:120`, but the new fields being nullable means that usage does not require callers to supply `canonical_bytes` or `fiscal_event_id`.

## Closing summary
Schema assessment: accepted. The migration adds the intended nullable `canonical_bytes` and nullable unique `fiscal_event_id`, with the PostgreSQL FK and rollback ordering aligned to the append-only fiscal-events model.

Model assessment: accepted for this task. The plan explicitly asked for both fields in `$fillable`, and current controller/service request paths do not expose those fields to direct request mass assignment.

Coverage assessment: minor edit required. Add the insert-based duplicate `fiscal_event_id` rejection test from the Task 11 plan, matching the Task 9 behavioral unique-constraint pattern.

## Sign-off
Reviewed commit: `ddc42d5c`

Required before merge: add behavioral duplicate-enforcement coverage for `pos_receipts.fiscal_event_id`.

No source, migration, test, or workflow files were modified by this review.
