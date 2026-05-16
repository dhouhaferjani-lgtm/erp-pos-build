# Phase 1 Task 9 — `create_fiscal_event_projections_table` + `FiscalEventProjectionRow` model — Opus review

**Date:** 2026-05-16
**Reviewer:** Opus (headless review gate)
**Scope:** Task 9 from `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md` (lines 750–796) — server PostgreSQL `fiscal_event_projections` table + Eloquent model + feature test.
**Base SHA:** `73066080` (Task 8 round-2 BLOCKER closure)
**Head SHA:** `61444f56` (feat(fiscal): create_fiscal_event_projections_table migration + model)
**Branch:** `feat/pos-fiscal-event-engine-phase1`
**Diff vs. base:** 3 files added, 312 lines.
- `apps/api/database/migrations/2026_05_14_100003_create_fiscal_event_projections_table.php` (+91)
- `apps/api/app/Modules/Fiscal/Domain/Models/FiscalEventProjectionRow.php` (+89)
- `apps/api/tests/Feature/Fiscal/FiscalEventProjectionsTableTest.php` (+132)

**Verdict:** **APPROVE-WITH-MINOR-EDITS** — 0 BLOCKER, 0 P1, 2 P2, 1 nit. Edits are flagged; reconciliation is the parent session's call. None are correctness defects in the shipping schema; they are coverage gaps and a one-line ergonomics note.

The migration is a faithful, column-by-column realization of spec §7.5; the Eloquent model is a typed, minimal-surface mirror of the mutable projection-state table with intentional `$timestamps = true`, no immutability-pattern leakage from Task 7/8, casts that resolve to the `ProjectionStatus` enum that already exists from Task 1's scaffolding, and the deliberate `…Row` suffix matching spec §7.5 + the model's own PHPDoc explanation. The test is a genuine RED→GREEN (verified by removing the migration and re-running — RED). PHPStan level 8 is clean on all three files; Pint is clean; no file outside the Task 9 scope was touched.

The Task 8 round-1 / round-2 BLOCKER class (a write-once invariant being silently routable around) does **not** have an analogue in Task 9 — this table is **deliberately mutable** by spec §7.5 and the implementation correctly omits any immutability trigger. I verified the absence is intentional (model `$timestamps = true`; no `DB::statement('CREATE … TRIGGER …')`; the migration's own PHPDoc explicitly disclaims chain-truth status).

---

## Verification performed

| Check | Result | Evidence |
|---|---|---|
| Three (and only three) files in the commit | ✓ | `git show --stat 61444f56` → `FiscalEventProjectionRow.php`, `2026_05_14_100003_create_fiscal_event_projections_table.php`, `FiscalEventProjectionsTableTest.php`. No Task 1–8 files touched. |
| Test passes after migration (GREEN) | ✓ | `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalEventProjectionsTableTest.php` → `OK (3 tests, 14 assertions)`. SQLite in-memory driver. |
| Test fails before migration (genuine RED) | ✓ | Temporarily moved `2026_05_14_100003_…` out of `database/migrations/` and re-ran: `Tests: 3, Assertions: 1, Errors: 2, Failures: 1` (column-existence assertion fails at `Schema::hasTable('fiscal_event_projections')`; the two insert-based tests error on missing table). Restored → all green. |
| PHPStan level 8 clean on new files | ✓ | `./vendor/bin/phpstan analyse <three files> --no-progress` → `[OK] No errors`. |
| Pint clean on new files | ✓ | `./vendor/bin/pint --test <three files>` → `{"result":"pass"}`. |
| `ProjectionStatus` enum exists from prior task | ✓ | `apps/api/app/Modules/Fiscal/Domain/Enums/ProjectionStatus.php` declares `Pending = 'pending'`, `Running = 'running'`, `Applied = 'applied'`, `DeadLettered = 'dead_lettered'` — four cases, matching the spec's `VARCHAR(20)` whitelist. |
| Driver-portable composite UNIQUE | ✓ | `Schema::create(...)->unique(['fiscal_event_id','projector_name'], '…')` is laid down via Laravel's Blueprint, **not** raw PG SQL — meaning the constraint exists on both SQLite and PG. The dup-block test fires on SQLite (verified by the green test run on `:memory:` SQLite). |

---

## Spec §7.5 column-by-column conformance

| Spec §7.5 line | Spec type / default / nullable | Migration line | Migration produces | Verdict |
|---|---|---|---|---|
| 436 `id` | `UUID PK` | `:31` | `uuid('id')->primary()` | ✓ |
| 437 `fiscal_event_id` | `UUID NOT NULL REFERENCES fiscal_events(id)` | `:35` (column) + `:70–74` (FK on PG only) | `uuid('fiscal_event_id')` NOT NULL by default; FK added via raw `ALTER TABLE … ADD CONSTRAINT …` gated on `pgsql` | ✓ for PG; SQLite-portable per project precedent |
| 438 `projector_name` | `VARCHAR(64) NOT NULL` | `:41` | `string('projector_name', 64)` | ✓ |
| 439 `projection_status` | `VARCHAR(20) NOT NULL DEFAULT 'pending'` | `:45` | `string('projection_status', 20)->default('pending')` | ✓ |
| 440 `attempts` | `INTEGER NOT NULL DEFAULT 0` | `:47` | `integer('attempts')->default(0)` | ✓ |
| 441 `last_error` | `TEXT` (nullable) | `:48` | `text('last_error')->nullable()` | ✓ |
| 442 `last_attempted_at` | `TIMESTAMPTZ` (nullable) | `:49` | `timestampTz(...)->nullable()` | ✓ |
| 443 `applied_at` | `TIMESTAMPTZ` (nullable) | `:50` | `timestampTz(...)->nullable()` | ✓ |
| 444 `dead_lettered_at` | `TIMESTAMPTZ` (nullable) | `:51` | `timestampTz(...)->nullable()` | ✓ |
| 445 `created_at` | `TIMESTAMPTZ NOT NULL DEFAULT NOW()` | `:55` | `timestampTz('created_at')->useCurrent()` | ✓ |
| 446 `updated_at` | `TIMESTAMPTZ NOT NULL DEFAULT NOW()` | `:56` | `timestampTz('updated_at')->useCurrent()` | ✓ |
| 447 `UNIQUE (fiscal_event_id, projector_name)` | composite UNIQUE | `:60–63` | `$table->unique([...], 'fiscal_event_projections_event_projector_unique')` (inline / driver-portable) | ✓ |

**11 spec columns + 1 UNIQUE invariant, 11 migration columns + 1 inline UNIQUE, zero drift.** Nothing extra; nothing missing; nothing renamed.

Notable correctness points:
- The composite UNIQUE is declared via `$table->unique([...])` inside the `Schema::create(...)` closure, which Laravel translates to a real UNIQUE constraint on **both** SQLite and PostgreSQL. The test's `expectException(QueryException::class)` therefore fires on SQLite (verified). This is exactly the spec's "idempotency contract is the composite UNIQUE" requirement, satisfied portably.
- `attempts` defaults to `0`. The spec wants `NOT NULL DEFAULT 0`. Laravel's `integer('attempts')->default(0)` produces NOT NULL by default. ✓
- `created_at` and `updated_at` both use `useCurrent()` for the DB-level default. The model has `public $timestamps = true`, so Eloquent will manage these on subsequent writes. The DB defaults exist so raw `DB::table()->insert()` paths (e.g. the OutboxIngestor's bulk insert in T1, if it skips Eloquent for performance) don't have to populate them. Sound.
- No `payload`, `canonical_bytes`, or chain columns — this is correct; projection state is logically separate from fiscal truth (spec §7.5 line 431: "`fiscal_events` persists in its own transaction … independent of any projection outcome").

---

## Constraints, indexes, FK conformance

| Spec invariant | Migration evidence | Verdict |
|---|---|---|
| `UNIQUE (fiscal_event_id, projector_name)` — idempotency contract, must work on every driver | `:60–63` inline `$table->unique([...])` | ✓ — portable, fires on SQLite per the green dup-block test |
| FK `fiscal_event_id → fiscal_events(id)` | `:66–74` raw `ALTER TABLE … ADD CONSTRAINT … FOREIGN KEY` gated by `getDriverName() === 'pgsql'` | ✓ on PG; intentionally absent on SQLite per the migration's PHPDoc and the project's driver-portability precedent (Task 7 used the identical PG-gating idiom for partial indexes / CHECKs) |
| Partial index on `projection_status IN ('pending','running')` for the worker dispatcher hot path | `:79–83` raw `CREATE INDEX … WHERE projection_status IN (…)` gated by `pgsql` | ✓ — sound design choice: the long tail of `applied` rows would dominate a full `projection_status` index over time. The partial index keeps the dispatcher's `SELECT … WHERE projection_status IN ('pending','running')` cheap. |
| FK cascade behavior | No `ON DELETE … ON UPDATE …` clause → PostgreSQL default `NO ACTION` is applied; migration PHPDoc claims "RESTRICT is implicit and correct" | ✓ — strictly speaking PG's default is `NO ACTION` (deferrable; checked at end of statement) rather than `RESTRICT` (checked immediately, non-deferrable). For Task 9's purpose the distinction is immaterial: both forbid deleting a `fiscal_events` row that any `fiscal_event_projections` row references, which is the invariant `fiscal_events` is append-only chain truth (and Task 8's `BEFORE DELETE` trigger blocks `fiscal_events` deletes outright anyway). See P2-1 below for a one-line PHPDoc precision nit. |

The named UNIQUE constraint (`fiscal_event_projections_event_projector_unique`), named FK constraint (`fiscal_event_projections_fiscal_event_id_fk`), and named partial index (`fiscal_event_projections_status_pending_idx`) all follow the project's `<table>_<purpose>_<kind>` convention introduced in Task 7. Future `fiscal:verify-event-chain` / projector-dead-letter-view introspection will find them by name. ✓

---

## Mutability premise (Task 7/8 contrast)

This is the highest-risk class of mistake to make at this point in the plan: Task 7 (append-only chain) and Task 8 (immutability triggers) established a strong pattern of "no UPDATE / no DELETE / no TRUNCATE / write-once payload columns / write-once integrity-resolution columns". Task 9 deliberately inverts that — and the implementation honors the inversion correctly.

| Mutability check | Evidence | Verdict |
|---|---|---|
| No `BEFORE UPDATE` / `BEFORE DELETE` / `BEFORE TRUNCATE` trigger function created | Migration `up()` body, lines `28–85`: only `Schema::create(...)`, the FK `ALTER TABLE`, and the partial index `CREATE INDEX` — no `CREATE FUNCTION` / `CREATE TRIGGER` | ✓ |
| Model `public $timestamps = true` | `FiscalEventProjectionRow.php:54` | ✓ — opposite of `FiscalEvent.php:65`'s `$timestamps = false`, matching Task 9 vs Task 7's opposite intent |
| `$fillable` includes the mutable surface (`projection_status`, `attempts`, `last_error`, `last_attempted_at`, `applied_at`, `dead_lettered_at`) | `:62–72` | ✓ — these are exactly the fields the worker (Task 23) and OutboxIngestor (Task 19) will write across the row's lifecycle |
| No write-once enum (`signature_version`, `payload_parse_status`, integrity-resolution stamps) leaked from Task 7/8 | The model imports only `ProjectionStatus` from the enum namespace | ✓ |
| Migration PHPDoc explicitly disclaims chain-truth status | `:21–23` "Unlike `fiscal_events`, this table is mutable — it is NOT chain truth and carries NO immutability trigger. The idempotency contract is the composite UNIQUE on (fiscal_event_id, projector_name)." | ✓ — and worth highlighting as a strength: future readers cloning Task 7 won't accidentally apply the immutability pattern here |

The model's PHPDoc at `:21–24` also explicitly explains why the class is `FiscalEventProjectionRow` (with the `Row` suffix) rather than `FiscalEventProjection` — `FiscalEventProjection` is reserved for the **projector contract interface** introduced in Task 18 (per the projector-registry seam at spec §7.3). Honoring that namespace reservation now is exactly right; the alternative (renaming this model later when Task 18 lands) would be a destructive churn on a model already serialized into the OutboxIngestor T1 transaction.

---

## `FiscalEventProjectionRow` model conformance

| Aspect | Plan / spec requirement | Code evidence | Verdict |
|---|---|---|---|
| Namespace | `App\Modules\Fiscal\Domain\Models` per module convention | `:5` | ✓ |
| Table name | `'fiscal_event_projections'` | `:41` | ✓ |
| Primary key type | UUID (string, non-incrementing) | `:44` `$keyType = 'string';`, `:47` `$incrementing = false;` | ✓ |
| Auto-timestamps | **Enabled** — mutable table | `:54` `$timestamps = true;` (with PHPDoc justification) | ✓ |
| `projection_status` cast | `ProjectionStatus::class` (plan §783) | `:80` | ✓ — enum exists with all four cases (`Pending`, `Running`, `Applied`, `DeadLettered`) matching the `VARCHAR(20)` whitelist |
| `attempts` cast | `'integer'` | `:81` | ✓ |
| Datetime casts | `last_attempted_at`, `applied_at`, `dead_lettered_at`, `created_at`, `updated_at` | `:82–86` | ✓ — all five datetime columns cast |
| `$fillable` is complete for the mutable surface | Every field the OutboxIngestor (Task 19) sets on insert + the worker loop's mutable surface | `:62–72` lists `id`, `fiscal_event_id`, `projector_name`, `projection_status`, `attempts`, `last_error`, `last_attempted_at`, `applied_at`, `dead_lettered_at` — 9 fields | ✓ — `created_at` / `updated_at` are intentionally **not** fillable (Eloquent manages them via `$timestamps = true`) |
| No cross-module imports | Only `App\Modules\Fiscal\Domain\Enums\…` | `:7` | ✓ |
| No `app()` helper | Required by CLAUDE.md rule 13 | (none in file) | ✓ |
| No `mixed` parameters/returns | None present | (none in file) | ✓ |
| Strict types declared | `declare(strict_types=1);` | `:3` | ✓ |
| Final class | Convention for module models | `:38` `final class FiscalEventProjectionRow extends Model` | ✓ |
| `@property` annotations | Comprehensive for IDE / PHPStan inference | `:26–37` covers all 11 columns including `ProjectionStatus` and nullable `Carbon` fields | ✓ |
| `casts()` method (Laravel 11+ convention) | Use the new `protected function casts(): array` form, not the legacy `$casts` property | `:77–88` | ✓ — matches `FiscalEvent.php:138` |

The model is minimal — no relations, no scopes, no business methods. That matches the spec's framing (this is persistence state, not a behavior surface; the behavior is `FiscalEventProjector::apply()` in Task 18+).

---

## Test discipline (plan §759–789)

| Aspect | Required | Actual | Verdict |
|---|---|---|---|
| Test exists at spec'd path | `apps/api/tests/Feature/Fiscal/FiscalEventProjectionsTableTest.php` | Yes | ✓ |
| Uses `RefreshDatabase` | Plan §762 (implicit, by parallel with Task 7) | `:31` | ✓ |
| Column-existence test | Plan §765–768 | `:33–54` — all 11 spec columns asserted | ✓ |
| `test_unique_event_projector_blocks_duplicate` | Plan §770–772 (the explicit dup-block assertion) | `:56–63` | ✓ — calls `insertEvent()`, then `insertProjection()`, then `expectException(QueryException::class)` and `insertProjection()` again with the same `(eventId, 'pos_core_receipt')` |
| Additional `test_unique_key_allows_distinct_projectors_per_event` | Implementer-added — strictly additive | `:65–76` — inserts the same event with two **different** projector names, asserts both rows exist | ✓ — pins the spec invariant that the UNIQUE is composite, not single-column |
| Genuine RED before migration | Reviewer-verified | Verified — moved migration out, ran tests, got `Tests: 3, Assertions: 1, Errors: 2, Failures: 1` with column-existence failing at `Schema::hasTable('fiscal_event_projections')`. Restored → all green. | ✓ |
| GREEN after migration | Implementer claim | Verified — `OK (3 tests, 14 assertions)` on SQLite | ✓ |
| Driver-portable insert helpers (no `NOW()` / `CURRENT_DATE` raw SQL) | Tests run on SQLite per `phpunit.xml` | `insertEvent()` uses `now()->toDateTimeString()`; `insertProjection()` likewise. Comments at `:93` and `:122` explicitly call out the SQLite-driver-portability reason. | ✓ — matches the established Task 7 / Task 8 idiom |
| Helper `insertEvent()` produces a schema-valid `fiscal_events` row | Every NOT NULL column on `fiscal_events` (set in Task 7) is populated | `:83–100` covers `id`, `tenant_id`, `company_id`, `terminal_id`, `operator_id`, `event_type`, `event_version`, `signature_version`, `sequence_number`, `event_time_device`, `business_date`, `server_received_at`, `canonical_bytes`, `previous_hash`, `current_hash` | ✓ — same NOT NULL surface as Task 7's helper |
| Defaults of `fiscal_events` handled implicitly | `signature_status`, `integrity_status`, `payload_parse_status`, `created_at` all have DB-level defaults from Task 7 | Not set by `insertEvent()`, correctly relying on the defaults | ✓ |

---

## Findings

### P2-1 — Missing PG-only test for the FK constraint

**Severity:** P2 (coverage gap, not a correctness defect)
**File:** `apps/api/tests/Feature/Fiscal/FiscalEventProjectionsTableTest.php`

The migration declares a FK from `fiscal_event_projections.fiscal_event_id` to `fiscal_events.id` on PostgreSQL only (`:70–74`). Task 7 / Task 8 set the precedent of guarding every PG-only invariant with a `skipUnlessPostgres()` test, so that a future migration cleanup or refactor cannot silently drop the FK without the PG merge-gate run catching it.

Today the test file has **no** PG-only test for the FK — the orphan-FK insert behavior is not pinned. On SQLite the absence of the FK is intentional (per the migration's design), but on PG the FK is a spec-required invariant (§7.5 line 437: `REFERENCES fiscal_events(id)`).

Concretely: a future migration that names the constraint differently, drops the constraint, or accidentally omits it on a new PG migration would not surface as a test failure in CI. The Task-7-set merge-gate discipline of "every PG-only constraint has a test that fires on PG and skips on SQLite" is the project's locked-in safeguard against schema drift.

**Suggested fix:** Add one test method, mirroring the Task 7 `skipUnlessPostgres()` idiom:

```php
public function test_foreign_key_to_fiscal_events_blocks_orphan_on_postgres(): void
{
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('FK constraint only enforced on PostgreSQL');
    }
    $this->expectException(QueryException::class);
    $this->insertProjection(Str::uuid()->toString(), 'pos_core_receipt');
}
```

This is the same shape as `FiscalEventsTableTest::test_unique_sequence_key_blocks_duplicate_slot()` and `FiscalEventsImmutabilityTest`'s PG-only tests. It costs one method and closes the coverage gap.

**Why P2, not P1:** the FK is declared and will exist in production. The gap is a future-regression-prevention gap, not a present defect. If the parent session prefers to defer this to a follow-up "PG merge gate completeness" sweep, that is sound — but the discipline established in Task 7 is to land the PG-only test in the same commit that lands the PG-only constraint, and Task 9 silently breaks that discipline. Worth flagging explicitly.

### P2-2 — `RESTRICT is implicit` claim in migration PHPDoc is technically imprecise

**Severity:** P2 (documentation precision, no functional impact)
**File:** `apps/api/database/migrations/2026_05_14_100003_create_fiscal_event_projections_table.php:67–69`

The PHPDoc reads:

> Cascading on delete is intentionally NOT set — fiscal_events is append-only (Task 8 immutability triggers block deletes anyway). RESTRICT is implicit and correct.

PostgreSQL's actual default when no `ON DELETE` clause is given is `NO ACTION`, not `RESTRICT`. The two differ in deferrability: `RESTRICT` is non-deferrable and checked immediately; `NO ACTION` is deferrable and checked at end of statement (so circular FKs can be set up in one statement). For Task 9's purpose the distinction is immaterial — both forbid deleting a referenced `fiscal_events` row, which is the actual invariant — but the comment misnames the behavior. A reviewer reading this trying to reason about `SET CONSTRAINTS DEFERRED` semantics in a future Task could be misled.

**Suggested fix:** change `RESTRICT is implicit and correct.` to `PG's default ON DELETE NO ACTION is implicit and correct (delete-blocking is what we want; Task 8's BEFORE DELETE trigger on fiscal_events also forbids deletes upstream).`

**Why P2, not nit:** the prior Task 7 / Task 8 review pattern treats database-semantics misstatements as P2 because they tend to compound — a future migration cargo-culting "implicit RESTRICT" from this comment could end up authoring a real `ON DELETE RESTRICT` clause where deferrability matters (e.g. a future bidirectional FK between projections and a quarantine-resolution table). Tightening it now is cheap.

### Nit-1 — `last_error` column type tradeoff worth a one-line comment

**Severity:** nit (informational)
**File:** `apps/api/database/migrations/2026_05_14_100003_create_fiscal_event_projections_table.php:48`

The migration uses `text('last_error')->nullable()` — matching the spec's `TEXT`. Sound. But operationally, `last_error` will hold queue-job exception messages including stack traces, which on Laravel's queue exhausted-retries can be quite long; some teams cap this at 8 KiB or 64 KiB to prevent a runaway projector from inflating a tablespace. Spec doesn't impose a cap, and PG's `TEXT` is unbounded, so the current choice is correct-per-spec. Worth a one-line PHPDoc note above the column: `// TEXT (unbounded) — queue's failed() handler writes the exception message + truncated stack; if PG tablespace pressure surfaces, cap at 8 KiB in Task 23's worker, not in the schema.`

**Not required**; just a future-debugging convenience.

---

## Implementer deviations checked

The task brief flagged three implicit deviations from the plan's literal Step 1 stub. Reviewed each:

1. **The dup-block test was split into two methods (`test_unique_event_projector_blocks_duplicate` + `test_unique_key_allows_distinct_projectors_per_event`) instead of one combined assertion.** Verdict: **sound, strictly additive.** The plan's stub combined the column-existence assertion with the dup-block assertion in one method. The implementer split them and added a positive case for distinct projectors. This is consistent with the project's "one invariant per test" pattern (`ExternalIdUniquenessTest` splits unique-violation tests by class) and improves diagnosability when the schema regresses. Strict improvement.

2. **FK is PG-only.** Verdict: **sound, matches Task 7 precedent.** The project's `phpunit.xml` uses `DB_CONNECTION=sqlite` / `:memory:`. SQLite supports FKs but Laravel's `Blueprint::foreign()` and the project's existing migrations consistently gate FKs to PG (see `apps/api/database/migrations/*_create_*.php` for the established idiom). The migration's PHPDoc explicitly justifies the gating. The trade-off — FK enforcement is only present on PG — is accepted by the project's testing convention (SQLite for unit-test speed; PG smoke tests + production for FK / CHECK / partial-index enforcement). The one gap is that **no PG-only test for the FK was written** — see finding P2-1 above.

3. **Partial index on `projection_status IN ('pending','running')`.** Verdict: **sound design choice, not in the spec but consistent with §7.5 line 454's worker-dispatcher contract.** The worker only ever scans non-terminal rows (`pending` → `running` → terminal, where terminal is `applied` or `dead_lettered`). The long tail of `applied` rows would dominate a full `projection_status` index over time, wasting space and slowing the dispatcher's hot-path query. The partial index keeps the index small (cardinality bounded by the in-flight projection count, not by historical volume). Named `fiscal_event_projections_status_pending_idx` per the project's `<table>_<purpose>_<kind>` convention. Strict improvement.

---

## Cross-task regression check

| Prior task | Files touched in `61444f56`? | Verdict |
|---|---|---|
| Task 1 (Fiscal module skeleton + RoadmapItem + `ProjectionStatus` enum scaffolding) | No (the model only **reads** `ProjectionStatus::class`) | ✓ no regression |
| Task 2 (`FiscalEventType` enum) | No | ✓ no regression |
| Task 3 (`IntegrityStatus` / `PayloadParseStatus` / `SignatureStatus` enums) | No | ✓ no regression |
| Task 4 (canonical-golden-vectors fixture + PHP hash-only test) | No | ✓ no regression |
| Task 5 (`FiscalEventCanonicalEncoder` TS) | No | ✓ no regression |
| Task 6 (`FiscalIntegrityProvider` / `HashChainIntegrityProvider` + `SignatureProviderInterface`) | No | ✓ no regression |
| Task 7 (`fiscal_events` table + `FiscalEvent` model) | No (the test's `insertEvent()` helper **reads** the Task 7 schema; the FK **references** it) | ✓ no regression — and the implicit dependency on Task 7's NOT NULL surface is correctly mirrored in the helper |
| Task 8 (immutability triggers) | No | ✓ no regression — Task 9 deliberately omits the trigger pattern |

The commit's three-file scope is exactly what Task 9 promises.

---

## Forward-looking notes for Task 10 and Task 19

Two notes for the reviewer of the next tasks that will touch this table:

- **Task 10 (`fiscal_event_quarantine`)** is the *non-admissible-envelope partition* (spec §8). It is also mutable (resolution stamps `resolved_at` / `resolved_by`) and also has no immutability trigger. The Task 9 PHPDoc justification for "mutable table, no trigger" is the template Task 10 should follow. The one difference: spec §8 makes `integrity_exception_class` NOT NULL on the quarantine table (it can only be `'sequence_conflict'` in Phase 1), whereas in `fiscal_events` it's nullable. Watch for that.
- **Task 19 (OutboxIngestor)** is where this table is first **written** — in the T1 transaction, one `pending` row per active projector (per spec §7.5 line 453). The composite UNIQUE means the ingestor's idempotency-on-retry comes for free: a second ingest call for the same envelope hits the `fiscal_events` UNIQUE first (and short-circuits before reaching the projections insert); if a transient failure leaves projection rows half-inserted, the second attempt will hit the projections UNIQUE and surface the dup. This is the right design — but it does mean Task 19's test needs to exercise the half-inserted-projections recovery path explicitly, not just the all-or-nothing happy path.

---

## Recommendation

**APPROVE-WITH-MINOR-EDITS — proceed to Task 10** (`create_fiscal_event_quarantine_table` — the non-admissible-envelope partition for `sequence_conflict` envelopes that physically cannot enter `fiscal_events`).

The edits flagged:
1. **P2-1**: add one PG-only `test_foreign_key_to_fiscal_events_blocks_orphan_on_postgres()` test method using the existing `markTestSkipped('… only enforced on PostgreSQL')` idiom. ~10 lines.
2. **P2-2**: change `RESTRICT is implicit and correct.` to `PG's default ON DELETE NO ACTION is implicit and correct …` in the migration's PHPDoc. One-line edit.
3. **Nit-1**: optional one-line PHPDoc note on `last_error` about queue-failure-message length and where the cap would live. Not required.

Per the parent session's reconciliation policy: these are flagged for the parent session to apply or accept-as-is; this review does not modify source. None of the three findings block Task 10. The migration is correctly producing the schema the spec requires.

The Task 8 round-1 / round-2 BLOCKER class (a write-once invariant being silently bypassable through reclassification) was checked-for-by-analogy and **does not apply** to Task 9: the table is deliberately mutable and has no write-once columns. The Task 9 implementation correctly inverts the Task 7/8 pattern without leaking any of its discipline by accident.

No follow-ups required before Task 10 begins (other than the optional P2 edits above).
