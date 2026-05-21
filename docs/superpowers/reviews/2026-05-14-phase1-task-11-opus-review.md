# Phase 1 Task 11 — `pos_receipts.canonical_bytes` + `fiscal_event_id` UNIQUE FK — Opus review

**Date:** 2026-05-16
**Reviewer:** Opus (headless review gate)
**Scope:** Task 11 from `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md` (lines 847–897) — `pos_receipts` adds `canonical_bytes BYTEA NULL` + `fiscal_event_id UUID NULL UNIQUE FK fiscal_events(id)`; legacy chain columns (`fiscal_hash`, `previous_hash`, `chain_sequence`) retained as backward-compatible mirror columns.
**Base SHA:** `2e8aec56` (Task 10 — `fiscal_event_quarantine`)
**Head SHA:** `ddc42d5c` (feat(fiscal): add canonical_bytes + fiscal_event_id to pos_receipts; chain columns become mirrors)
**Branch:** `feat/pos-fiscal-event-engine-phase1`
**Diff vs. base:** 4 files, +223 / -1 line.
- `apps/api/database/migrations/2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php` (+80)
- `apps/api/app/Modules/POS/Domain/Receipt.php` (+8 — `$fillable` only)
- `apps/api/tests/Feature/Fiscal/PosReceiptsCanonicalBytesTest.php` (+131)
- `.github/workflows/ci.yml` (+4 / -1)

**Verdict:** **APPROVE-WITH-MINOR-EDITS** — 0 BLOCKER, 0 P1, 3 P2, 2 nits. None are correctness defects in the shipping migration or the production model edit; they are coverage gaps (one is the same write-once-on-classification analogue Task 10 P2-3 surfaced), one false-positive RED on the `$fillable` test, and one missing PHPDoc note about the Task 8 BEFORE-DELETE-trigger interaction that makes the FK's no-ON-DELETE-clause sound.

The migration is a faithful realization of plan §847–884 + spec §7.5 + §13: the two new columns are added with the spec'd types (`BYTEA` via `$table->binary()`, `UUID` via `$table->uuid()`), both nullable for legacy-row compatibility, with the UNIQUE constraint on `fiscal_event_id` named per the locked-in `<table>_<purpose>_<kind>` convention and the FK declared PG-only via raw `ALTER TABLE`. The legacy chain columns are correctly **not dropped** — the migration PHPDoc records the "mirror columns" contract Task 21's `PosCoreReceiptProjection` will populate. The Receipt model adds exactly two `$fillable` entries with a PHPDoc justification block explaining the Phase 1 §7.5 linkage role; no cast is required for `BYTEA` (raw string at the Eloquent boundary) and no cast for `UUID` (already a string). PHPStan level 8 is clean on all three files (verified); Pint is clean (verified). The test ships six methods covering column existence, model fillable, unique-index introspection, FK existence (PG-only), BYTEA shape (PG-only), and UUID nullability (PG-only) — six tests, ten assertions, three PG-only skipped on SQLite, matching the implementer's report. The full Fiscal feature suite stays green (49 tests, 32 PG-only skipped on SQLite). Genuine RED→GREEN verified by reviewer (migration moved aside → 2 failures + 3 skipped + 1 pass on the model-only assertion; restored → all green). The CI gate is updated to include `PosReceiptsCanonicalBytesTest` in the `backend-test-pgsql` filter with a matching three-line comment.

The Task 10 P2-3 lesson (write-once on classification columns has no trigger because the Phase 1 single-value CHECK forecloses reclassification) does not directly apply here — there is no classification column on `pos_receipts`. But the **analogous question for the new columns** — "can `fiscal_event_id` or `canonical_bytes` be mutated after insert?" — surfaces P2-3 below: the existing `enforce_receipt_immutability` trigger (Task 7's `pos_receipts` migration, lines 144–190) declares an explicit whitelist of "void-related" mutation paths and rejects anything else with a `RAISE EXCEPTION`. The two new columns are **not** in the void whitelist, so they are silently caught by the trigger's "all other updates blocked" branch — meaning the projector (Task 21) will fail to INSERT the linkage if it tries to UPDATE an existing `pos_receipts` row. The intended flow is "insert a fresh projection row" — UPDATE was never the contract — so the trigger interaction is **benign**, but it should be explicit in the migration PHPDoc.

---

## Verification performed

| Check | Result | Evidence |
|---|---|---|
| Four (and only four) files in the commit | ✓ | `git show --stat ddc42d5c` → migration, Receipt model edit, new feature test, ci.yml. No Task 1–10 schema files touched. |
| Test passes after migration (GREEN) | ✓ | `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/PosReceiptsCanonicalBytesTest.php` → `OK, but some tests were skipped! Tests: 6, Assertions: 10, Skipped: 3.` SQLite in-memory driver; the three skips are PG-only (FK / BYTEA / UUID introspection), as expected. |
| Test fails before migration (genuine RED) | ⚠ partial — see Nit-1 | Temporarily moved `2026_05_14_100005_…` out of `database/migrations/` and re-ran: `Tests: 6, Assertions: 4, Failures: 2, Skipped: 3.` `test_pos_receipts_gains_canonical_bytes_and_fiscal_event_id_columns` failed (column missing); `test_fiscal_event_id_unique_constraint_exists` failed (index missing); the three PG-only tests skipped (SQLite); but `test_model_fillable_includes_canonical_bytes_and_fiscal_event_id` **passed** — `$fillable` is a static property on the model, independent of the schema. That is a false GREEN on RED — Nit-1 below. Restored migration → all six green. |
| PHPStan level 8 clean on changed files | ✓ | `./vendor/bin/phpstan analyse <three files> --no-progress` → `[OK] No errors`. |
| Pint clean on changed files | ✓ | `./vendor/bin/pint --test <three files>` → `{"result":"pass"}`. |
| Full Fiscal Feature suite still green | ✓ | `./vendor/bin/phpunit tests/Feature/Fiscal/` → `Tests: 49, Assertions: 101, Skipped: 32`. No regression from Task 7–10. |
| No other code reads / writes the two new columns yet | ✓ | `grep -rn "fiscal_event_id\|canonical_bytes" app/Modules/POS/ --include="*.php"` returns only the Receipt model edit. Task 21 (`PosCoreReceiptProjection`) is where the columns will be written. |
| No existing test asserts the exact `Receipt::$fillable` shape | ✓ | `grep -rn "getFillable" tests/` → only `tests/Unit/Treasury/PaymentRepositoryEntityTest.php` (unrelated table) and Task 11's new test. The two-entry `$fillable` extension does not regress any prior test. |
| `Schema::getIndexes()` exposes the unique index on SQLite | ✓ | `test_fiscal_event_id_unique_constraint_exists` runs on SQLite and fires three assertions (`assertContains` + `assertTrue($index['unique'])` + `assertSame(['fiscal_event_id'], …)`). The `--testdox --filter` run confirms 3 assertions in this single test, not 1 — the `foreach` block does match and execute. |
| Cross-task regression vs Task 10 | ✓ | None — Task 10 is `fiscal_event_quarantine`; Task 11 only touches `pos_receipts`, `Receipt.php`, the new test, and ci.yml. The Task 10 P2-3 (write-once classification) lesson is correctly absent here — `pos_receipts` has no classification column. |

---

## Plan §847–884 + Spec §7.5/§13 conformance

| Plan / spec requirement | Migration evidence | Verdict |
|---|---|---|
| `pos_receipts.canonical_bytes BYTEA NULL` | `:47` `$table->binary('canonical_bytes')->nullable();` — translates to `BYTEA` on PG, `BLOB` on SQLite (Laravel default behavior; confirmed by Task 10's identical idiom for `fiscal_event_quarantine.canonical_bytes` and Task 7's `fiscal_events.canonical_bytes`) | ✓ |
| `pos_receipts.fiscal_event_id UUID NULL` | `:52` `$table->uuid('fiscal_event_id')->nullable();` | ✓ |
| UNIQUE on `fiscal_event_id` | `:53` `$table->unique('fiscal_event_id', 'pos_receipts_fiscal_event_id_unique');` — named per locked-in convention | ✓ |
| FK `fiscal_event_id → fiscal_events(id)` | `:60–65` PG-only raw `ALTER TABLE pos_receipts ADD CONSTRAINT pos_receipts_fiscal_event_id_fk FOREIGN KEY (fiscal_event_id) REFERENCES fiscal_events(id)` | ✓ |
| Legacy chain columns retained as backward-compatible mirrors | Migration `up()` does not drop `fiscal_hash` / `previous_hash` / `chain_sequence`; PHPDoc `:28–34` explicitly documents the mirror-column contract | ✓ — plan §884 "Do not drop" honored |
| `fillable` adds `fiscal_event_id` + `canonical_bytes` to Receipt model | `Receipt.php:183–190` — both entries present, PHPDoc block at `:183–188` explains the Phase 1 §7.5 role and the nullable-for-legacy reasoning | ✓ |
| Migration documents the projector idempotency role | `:22–27` "This UNIQUE is the idempotency guard `PosCoreReceiptProjection::apply()` (Task 21) relies on" with the exact `where('fiscal_event_id', $event->id)->exists()` shape | ✓ — framing is faithful to spec §7.5 line 454 ("each projection job runs `projector.apply(fiscalEvent)` in **its own transaction**, idempotently (keyed on `(fiscal_event_id, projector_name)`)") |
| UNIQUE-with-multiple-NULLs explicit | `:36–38` "Multiple legacy rows with NULL `fiscal_event_id` are permitted: the UNIQUE constraint accepts multiple NULLs (standard ANSI behavior on PostgreSQL)" | ✓ — the implicit semantics are made explicit, future maintainers won't have to look it up |
| Constraint naming convention | `pos_receipts_fiscal_event_id_unique` + `pos_receipts_fiscal_event_id_fk` follow Task 7/9/10's `<table>_<purpose>_<kind>` convention | ✓ |

The migration matches plan §847–884 and spec §7.5/§13 with one substantive interpretive choice: the brief noted the implementer chose **schema-introspection** over **insert-based** testing for the UNIQUE constraint, and the PHPDoc justification at `:53–58` cites "~6 FK parent rows" as the seeding cost. Reviewed: see "Test discipline" below.

---

## UNIQUE-with-multiple-NULLs semantics

The plan's Step 1 stub at §866–874 sketches an insert-based duplicate-detection test: insert a row with `fiscal_event_id = $event`, then insert a second row with the same value, expect a `QueryException`. The implementer chose schema-introspection instead — see "Test discipline" below. **The multi-NULL semantics are independently sound:**

| Driver | Multi-NULL on UNIQUE | Spec |
|---|---|---|
| PostgreSQL | Allowed | SQL:2003 — "two null values are considered to be distinct" |
| SQLite | Allowed | Default behavior; `WHERE fiscal_event_id IS NOT NULL` not needed |

The migration relies on this by not creating a partial unique index (`WHERE fiscal_event_id IS NOT NULL`). The PHPDoc at `:37–38` explicitly cites the ANSI behavior. **This is correct.** Legacy `pos_receipts` rows that pre-date the rebuild keep their `fiscal_event_id = NULL`, and the UNIQUE does not reject them — which is the migration's whole "backward compatibility" claim. ✓

There is one observable consequence worth flagging: a **non-partial UNIQUE index** with many NULL rows is slightly larger on disk than a partial unique index `WHERE fiscal_event_id IS NOT NULL` would be. For Phase 1, where legacy rows are the vast majority and new (post-Task 21) rows are growing slowly, the difference is negligible (UUIDs are 16 bytes; even 10M legacy rows ≈ 160 MB of NULL-entries, which PG stores as a single bit in the index leaf and a header). The choice is sound — partial unique index would be a premature optimization.

---

## FK to `fiscal_events` — no ON DELETE clause

The migration's FK declaration at `:62–65` carries **no `ON DELETE` / `ON UPDATE` clause**. The PHPDoc at `:60–63` justifies this:

> FK to `fiscal_events.id`. ON DELETE / ON UPDATE not declared — PG's default NO ACTION blocks deletes (which is the invariant we want; Task 8's BEFORE DELETE trigger on `fiscal_events` forbids deletes outright upstream of any FK check).

Verified:

1. **PG default is `NO ACTION`** (PostgreSQL docs §5.3.5): if not specified, `NO ACTION` is the default for both `ON DELETE` and `ON UPDATE`. `NO ACTION` differs from `RESTRICT` only in deferred-constraint handling — for non-deferred constraints (the default) they are equivalent. ✓

2. **Task 8's BEFORE DELETE trigger** — `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability_triggers.php` ships a `fiscal_events_immutability_trigger` that `RAISE EXCEPTION`s on `TG_OP = 'DELETE'` for `fiscal_events`. Any attempt to delete a `fiscal_events` row would be rejected by the trigger **before** PG even evaluates the `pos_receipts` FK constraint. The FK's `NO ACTION` is belt-and-suspenders. ✓

3. **Order of constraint evaluation** — PG fires `BEFORE` triggers before FK actions. So if someone tried `DELETE FROM fiscal_events WHERE id = ?`, Task 8's trigger fires first → raise exception → transaction aborts. The `pos_receipts.fiscal_event_id_fk` never gets to "what would I do here?". Both layers agree: no fiscal event ever gets deleted, so `pos_receipts` rows pointing at it stay valid. ✓

**Findings:** Sound — but the PHPDoc cites "Task 8's BEFORE DELETE trigger" without naming the migration file or the trigger name. Future-maintainers reading this in isolation can't grep for "Task 8" — see Nit-2.

`ON UPDATE` is also absent. `fiscal_events.id` is a UUID PK that is **never updated** (the immutability trigger on Task 7's `fiscal_events` rejects UPDATEs to any column except the gated `payload` / `payload_parse_status` write-once flip — and `id` is never gated). The implicit `NO ACTION` is correct here too. ✓

---

## `enforce_receipt_immutability` trigger interaction (analogue to Task 10 P2-3)

This is the **Task 8 BLOCKER class analogue** for `pos_receipts`. `pos_receipts` already has an immutability trigger from its Phase 0 creation migration (`2026_01_08_190637_create_pos_receipts_table.php:144–190`) — `prevent_receipt_modification()` — which blocks all UPDATEs except the explicit "void operation" whitelist. The whitelist is: `is_voided`, `voided_at`, `voided_by`, `void_reason`. Anything else (including the two new columns) triggers the `else` branch:

```plpgsql
ELSE
    -- All other updates blocked
    RAISE EXCEPTION 'Receipt % is fiscally sealed and cannot be modified. ...
END IF;
```

So `canonical_bytes` and `fiscal_event_id` are **write-on-INSERT-only** at the trigger layer — a stronger invariant than write-once. The migration does not add a column-specific trigger because the global trigger already enforces it.

**Implication for Task 21 (`PosCoreReceiptProjection::apply()`):** the projector cannot UPDATE an existing `pos_receipts` row to add the linkage. The Task 21 design must be:

1. **Insert path** (the normal case): when the projector processes a new `SALE_RECEIPT` fiscal event, it `INSERT`s a fresh `pos_receipts` row with `fiscal_event_id` + `canonical_bytes` already populated. The UNIQUE on `fiscal_event_id` is the idempotency guard.

2. **Backfill path** (if any): there is **no** in-place backfill — a legacy `pos_receipts` row with NULL `fiscal_event_id` stays that way forever, because the global immutability trigger forbids the UPDATE. Spec §7.5 + §13 do not call for backfill; the rebuild starts from the cut-over point.

**Finding:** This is **sound**, but the Task 11 migration PHPDoc does not explicitly call out the trigger interaction. A future implementer of Task 21 who hasn't read the Phase 0 migration could assume "the projector backfills the linkage on legacy rows" — which would silently fail at the trigger layer with an opaque "Receipt is fiscally sealed" error. See P2-3.

---

## Model edit — `$fillable` boundary

The Receipt model edit is two entries to `$fillable` with an inline PHPDoc justification block:

```php
// Phase 1 §7.5 — projection-row linkage to `fiscal_events`.
// `canonical_bytes` carries the verbatim canonical encoding from the
// device; `fiscal_event_id` is the UNIQUE FK to the authoritative
// fiscal event and the idempotency anchor for
// PosCoreReceiptProjection (Task 21). Both nullable for backward
// compatibility with rows that pre-date the rebuild.
'canonical_bytes',
'fiscal_event_id',
```

| Aspect | Check | Verdict |
|---|---|---|
| `$fillable` extension only — no other model property changes | Diff: `+8 / -0` on `Receipt.php`; no edits to `casts()`, no edits to relations, no edits to scopes | ✓ |
| `canonical_bytes` cast | None added; BYTEA → raw PHP string at the Eloquent boundary, no transformation needed | ✓ — matches Task 10's `FiscalEventQuarantine` model treatment of `canonical_bytes` |
| `fiscal_event_id` cast | None added; UUID is a string in Laravel | ✓ — matches `Receipt.php:122` (no cast on `tenant_id`/`company_id`/`location_id`/`terminal_id`, which are also UUID FKs) |
| PHPDoc inline | Six-line comment block immediately preceding the two new entries explaining their role | ✓ — strict improvement on "just append two strings to the array"; future readers see why the columns are mass-assignable from the model itself |
| `@property` annotations in the model header | **Not added** for the two new columns — see P2-2 below | gap |
| Boundary discipline (Task 9/10 lesson) | The two new columns are **mass-assignment-permitted**, which is the right boundary: the projector (Task 21) constructs the row via `Receipt::create([...])` with `fiscal_event_id` + `canonical_bytes` supplied at insert time. Unlike Task 10's `integrity_exception_class` (lifecycle-classified, not insertable from a request body), these columns are part of the projector's normal insert payload. ✓ | ✓ |

No existing test asserts the exact `$fillable` shape (`grep -rn getFillable tests/` finds only the unrelated `PaymentRepositoryEntityTest`), so the extension is regression-safe. ✓

---

## Test discipline (plan §856–874)

| Aspect | Required | Actual | Verdict |
|---|---|---|---|
| Test exists at spec'd path | `apps/api/tests/Feature/Fiscal/PosReceiptsCanonicalBytesTest.php` | Yes | ✓ |
| Uses `RefreshDatabase` | Per Task 7/8/9/10 precedent | `:30` | ✓ |
| Column-existence test | `Schema::hasColumn(canonical_bytes)` + `Schema::hasColumn(fiscal_event_id)` + assertions that the legacy chain columns are retained | `:32–41` covers all five columns | ✓ — strict improvement on plan §858–864 stub (which only asserted `canonical_bytes` + `fiscal_hash`); the implementer added `fiscal_event_id` + `previous_hash` + `chain_sequence` for the full mirror-column contract |
| Model fillable test | Implementer-added | `:43–49` `(new Receipt)->getFillable()` then `assertContains` for both new columns | ⚠ false-positive RED — see Nit-1 |
| UNIQUE constraint test (driver-portable) | Plan §866–874 stub uses insert-based duplicate detection | `:51–78` schema-introspection via `Schema::getIndexes('pos_receipts')` | ✓ — see "Schema-introspection vs insert-based trade-off" below |
| FK to `fiscal_events` PG-only test | Implementer-added | `:80–93` `pg_constraint` lookup by name, skips on SQLite | ✓ |
| `canonical_bytes` BYTEA shape PG-only test | Implementer-added | `:95–108` `information_schema.columns` `data_type = 'bytea'` + `is_nullable = 'YES'` | ✓ — matches Task 7/10 PG-only catalog-introspection pattern |
| `fiscal_event_id` UUID nullability PG-only test | Implementer-added | `:110–123` | ✓ |
| `skipUnlessPostgres()` helper | Implementer-added private method | `:125–130` matches Task 7/8/9/10 idiom verbatim | ✓ |
| Genuine RED before migration | Reviewer-verified | Verified — moved migration out, ran tests: `Tests: 6, Assertions: 4, Failures: 2, Skipped: 3` on SQLite. Column-existence + UNIQUE-introspection failed; PG-only tests skipped; **model-fillable test passed without the migration** — Nit-1 below. Restored migration → all six green. | ⚠ partial — see Nit-1 |
| GREEN after migration | Implementer claim | Verified — `OK (6 tests, 10 assertions, 3 skipped)` on SQLite | ✓ |

### Schema-introspection vs insert-based trade-off

The plan §866–874 sketch is insert-based:

```php
$event = $this->insertFiscalEvent();
\DB::table('pos_receipts')->insert($this->minimalReceiptRow(['fiscal_event_id' => $event]));
$this->expectException(\Illuminate\Database\QueryException::class);
\DB::table('pos_receipts')->insert($this->minimalReceiptRow(['fiscal_event_id' => $event]));
```

The implementer chose schema-introspection instead. Reviewed against three options:

**Option A (the plan's stub) — raw-insert with `\DB::table('pos_receipts')->insert(...)`.** Requires building `$this->minimalReceiptRow()` — and `pos_receipts` carries multiple FK constraints to `tenants`, `companies`, `locations`, `pos_terminals`, `users` (the cashier). On SQLite + `RefreshDatabase`, all six FK parents must be present (or FK enforcement must be disabled). The `minimalReceiptRow()` helper would need to either (a) construct or insert all six parent rows (a 30-line helper at minimum), or (b) call `PRAGMA foreign_keys = OFF` on SQLite. Both routes add complexity unrelated to the UNIQUE contract being tested.

**Option B — model factory `Receipt::factory()->create([...])`.** The `ReceiptFactory` (`database/factories/ReceiptFactory.php`) **does** chain through factory dependencies (`Tenant::factory()`, `Company::factory()`, `Location::factory()`, `Terminal::factory()`, `User::factory()`) — so a single `Receipt::factory()->create(['fiscal_event_id' => $fiscalEvent->id])` would seed all FK parents automatically. This would have produced an insert-based test in ~10 lines. **However**, the factory itself depends on the migration state of all five upstream tables, and one of them (`pos_terminals`) is in turn FK'd to `pos_terminal_registrations` and `companies` — the factory dependency graph is wider than it looks. Each factory call boots six additional `RefreshDatabase` cycles for the parents.

**Option C (the implementer's choice) — schema-introspection via `Schema::getIndexes('pos_receipts')`.** Zero FK seeding. Three assertions in one test (index name present, `unique` flag, columns match). Runs in <1 ms on SQLite, <2 ms on PG. Driver-portable (verified — SQLite returns the same index shape Eloquent's Doctrine-backed introspection produces on PG).

**Verdict:** the implementer's choice is **sound**. Option B (factory-based) is the alternative the brief asked about — it would have worked, but it tests **insertion behavior**, not **constraint shape**. The point of the test is to pin that the UNIQUE constraint exists with the right name + columns + uniqueness flag; insertion behavior is a downstream consequence. Schema-introspection tests the *upstream* contract directly. Task 7/10 used the same pattern for similar reasons (information_schema introspection vs insert-based CHECK testing). The PHPDoc justification at `:53–58` cites "~6 FK parent rows" — which is correct, though Option B would have reduced that cost via factory chaining; the framing could be sharper but the choice is right.

There is one minor downside: **schema-introspection does not exercise the duplicate-rejection at runtime.** A scenario where the UNIQUE index existed but PG's planner somehow ignored it (vanishingly unlikely — index hints can't override constraints) would not be caught. A single PG-only test that does `DB::statement` to seed two minimal `pos_receipts` rows with the same `fiscal_event_id` (via `INSERT ... VALUES (...) ON CONFLICT DO NOTHING`-or-not) and asserts the second one fails would close that gap — but it requires Option A or B's FK-parent seeding. **Acceptable as a P2 coverage gap; see P2-1.**

---

## CI gate

| Aspect | Check | Verdict |
|---|---|---|
| `PosReceiptsCanonicalBytesTest` added to `backend-test-pgsql` filter | `ci.yml:346` `--filter="...|PosReceiptsCanonicalBytesTest"` | ✓ |
| Matching comment block | `ci.yml:334–336` three-line comment explaining what the PG-only assertions cover (FK + BYTEA + nullability) | ✓ — matches Task 7/8/9/10 commenting discipline |
| Filter regex shape | Pipe-separated, no leading/trailing whitespace, no regex escape needed (test class name contains only alphanumerics) | ✓ |

The CI gate carries the locked-in Task 7+ pattern. ✓

---

## Findings

### P2-1 — Insert-based duplicate-rejection test missing (Option-B-via-factory path)

**Severity:** P2 (coverage gap, not a correctness defect)
**File:** `apps/api/tests/Feature/Fiscal/PosReceiptsCanonicalBytesTest.php`

The plan's Step 1 stub at §866–874 sketches an insert-based test that proves duplicate `fiscal_event_id` values are rejected at runtime by PG. The shipping test pins the UNIQUE constraint by schema-introspection only — proving the constraint exists, not that it fires. The two are not equivalent: a future migration that defines the UNIQUE on the wrong column, or that drops it and re-adds it as non-unique, would surface as different failure modes in introspection vs. runtime.

The implementer's PHPDoc justification ("~6 FK parent rows make raw inserts impractical") is sound for Option A (raw `DB::table`). It is less convincing for Option B (model factory): `Receipt::factory()->create(['fiscal_event_id' => $event->id])` chains through the FK parents automatically and would have produced a working insert-based test in ~10 lines.

**Suggested fix (PG-only, ~15 lines):**

```php
public function test_duplicate_fiscal_event_id_is_rejected_on_postgres(): void
{
    $this->skipUnlessPostgres();

    // Use the factory chain to seed all FK parents; supply the same
    // fiscal_event_id for both rows.
    $fiscalEventId = (string) \Illuminate\Support\Str::uuid();

    Receipt::factory()->create(['fiscal_event_id' => $fiscalEventId]);

    $this->expectException(\Illuminate\Database\QueryException::class);
    Receipt::factory()->create(['fiscal_event_id' => $fiscalEventId]);
}
```

Caveat: this requires `fiscal_event_id` to NOT have a FK-enforcement step that would reject the synthetic UUID (the FK is to `fiscal_events.id`, and the factory chain does not seed `fiscal_events` rows). On PG, this would fail at the FK *before* the UNIQUE fires — a wrong-class failure (FK violation, not unique violation). Two routes to fix:
- **Route A**: also seed a `fiscal_events` row first (Task 21's `FiscalEventFactory` is presumably the future-coming helper; for now manually insert a minimal valid row).
- **Route B**: skip the FK by using a real fiscal-events row via `DB::table('fiscal_events')->insert([...])` with minimal-valid fields (16 columns + the immutability trigger doesn't fire on INSERT, only UPDATE/DELETE).

Either route is more code than the schema-introspection test. **The gap is genuine but small.**

**Why P2, not P1:** the schema-introspection test pins the structural contract; the runtime-behavior gap is recoverable via the future Task 21 projector tests (which will inevitably try to insert two rows with the same `fiscal_event_id` and assert the failure mode). This is a "land it now or land it then" decision; landing it now would be strictly better but not blocking.

### P2-2 — Receipt model `@property` annotations not extended to the two new columns

**Severity:** P2 (PHPDoc / IDE-affordance gap; not a correctness defect)
**File:** `apps/api/app/Modules/POS/Domain/Receipt.php:36–85`

The Receipt model's class-level PHPDoc declares ~50 `@property` annotations covering every persisted column (`$tenant_id`, `$company_id`, …, `$exchange_group_id`). The two new columns added to `$fillable` (`canonical_bytes`, `fiscal_event_id`) are **not** added to the `@property` list. Future PHPStan-level-8 strict analysis of code that reads `$receipt->fiscal_event_id` or `$receipt->canonical_bytes` will:

- **Today**: work, because Eloquent's `__get()` returns `mixed`-typed dynamic attributes, and Receipt has no class-level `@property` shadow that would flag it.
- **In a future strictness upgrade** (e.g. if `larastan` adds a "every fillable column must have a @property annotation" rule, or PHPStan adds a `noUnknownDynamicProperty` rule): the two unannotated columns would surface as PHPStan errors.

Currently PHPStan level 8 on the Receipt model is clean (verified) — the gap is in IDE autocompletion and future-strictness, not in the present test/lint surface.

**Suggested fix:** Add two lines to the `@property` block at `Receipt.php:36–85` (alphabetical order suggests after `$tenant_id` or near the chain columns):

```php
 * @property string|null $canonical_bytes Verbatim canonical encoding from the device (BYTEA on PG); NULL on legacy rows
 * @property string|null $fiscal_event_id UUID FK to fiscal_events; NULL on legacy rows. UNIQUE — one pos_receipts row per SALE_RECEIPT fiscal event
```

Two lines. Strict improvement on the in-`$fillable` PHPDoc block (which the future-reader of `$receipt->fiscal_event_id` does not see).

**Why P2, not P1:** PHPStan level 8 is clean today; the gap is forward-looking. The Receipt model's existing `@property` discipline is consistent and worth keeping consistent.

### P2-3 — `enforce_receipt_immutability` trigger interaction not documented in the new migration

**Severity:** P2 (deferred-invariant clarity; not a present defect)
**File:** `apps/api/database/migrations/2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php`

The Phase 0 `pos_receipts` creation migration (`2026_01_08_190637_create_pos_receipts_table.php:144–190`) ships `prevent_receipt_modification()` — a `BEFORE UPDATE OR DELETE` trigger that blocks all UPDATEs except the void-operation whitelist. The whitelist is hard-coded in the function body: `fiscal_hash`, `receipt_number`, `total`, `subtotal`, `tax_amount`, `chain_sequence`, `posted_at` — these are checked to ensure they DON'T change during a void. Anything else (including the two new columns Task 11 adds) is silently caught by the trigger's "all other updates blocked" branch.

This is the **Task 8 BLOCKER class analogue** for the projector's behavior — but inverted: rather than a write-once invariant being silently routable around via reclassification, here the new columns are **write-only-on-INSERT** because the global trigger forbids the UPDATE. The projector (Task 21) must therefore:

1. INSERT a fresh `pos_receipts` row with `fiscal_event_id` + `canonical_bytes` already populated. ✓
2. Never UPDATE an existing row to add the linkage. The trigger would `RAISE EXCEPTION 'Receipt % is fiscally sealed and cannot be modified...'`.

The Task 11 migration PHPDoc at `:13–39` does not call out this trigger interaction. The forward-flow consequence (Task 21 must insert, never backfill) is implicit. A future implementer of Task 21 — or a future operator running a backfill SQL — could try `UPDATE pos_receipts SET fiscal_event_id = ? WHERE id = ?` and get an opaque "Receipt is fiscally sealed" error that doesn't mention the column or the linkage role.

**Suggested fix:** Add a one-paragraph note to the migration PHPDoc immediately after the mirror-columns paragraph at `:28–34`:

```
* **Trigger interaction with `enforce_receipt_immutability`** — the Phase 0
* `pos_receipts` migration ships a BEFORE UPDATE trigger
* (`prevent_receipt_modification()`, `2026_01_08_190637_create_pos_receipts_table.php:144–190`)
* that blocks all UPDATEs except the void-operation whitelist. The two new
* columns are NOT in that whitelist, so they are write-only-on-INSERT:
* `PosCoreReceiptProjection::apply()` (Task 21) must INSERT a fresh
* `pos_receipts` row with `fiscal_event_id` + `canonical_bytes` populated
* at insert time. Legacy rows with NULL `fiscal_event_id` cannot be
* backfilled in place — the trigger forbids it. This is the intended
* contract; the rebuild starts from the Phase 1 cut-over point.
```

**Why P2, not P1:** there is no present-day bypass — the rebuild starts from the cut-over and Task 21 will INSERT, not UPDATE. The risk is entirely future-implementer-confusion. A six-line PHPDoc block closes the gap and propagates the discipline forward to Task 21.

(There is a related question for the future: should the `enforce_receipt_immutability` trigger's whitelist be **extended** to allow Task 21's projector to write the two new columns on the first apply but not subsequent ones? This would let the legacy-row backfill work. The answer is **no** — the schema's mirror-column contract (spec §7.5) is "the projector writes the row at projection time, never edits it after" — same as `fiscal_events` itself. Phase 1 explicitly preserves immutability. Worth a sentence in the future-reader's hands.)

### Nit-1 — `test_model_fillable_includes_canonical_bytes_and_fiscal_event_id` passes on RED

**Severity:** nit (test-coverage observation; not a correctness defect)
**File:** `apps/api/tests/Feature/Fiscal/PosReceiptsCanonicalBytesTest.php:43–49`

The model-fillable test asserts `(new Receipt)->getFillable()` contains the two new column names. The `$fillable` array is a **static property of the Receipt model** — it does not depend on the schema being migrated. So when the migration is removed (the RED state) and the test runs, this test **still passes**, because the model's `$fillable` was already edited in the same commit.

Observable from the RED-state output:

```
F.FSSS                                                              6 / 6 (100%)
Failures: 2, Skipped: 3.
```

Two failures (column-existence + UNIQUE-introspection). Three skips (PG-only). One pass (the `.`) — the model-fillable test. So out of six tests, only **four** could conceivably go RED; the model-fillable test cannot.

This is not a defect — it's a test that pins the model edit, separate from the migration edit. They are two atomically-committed changes that together implement Task 11, and pinning each one independently is good test discipline. But it does mean the implementer's reported "6 tests / 10 assertions / 3 PG-only skipped" RED claim is technically: "5 tests can go RED on SQLite; 1 test pins a model property that is migration-independent."

**Suggested fix:** add a one-line PHPDoc note to the test method clarifying its role:

```php
/**
 * Independent of schema state — pins the model's mass-assignment surface.
 * Passes whether or not the migration has run; complements the
 * column-existence test (which is migration-gated).
 */
public function test_model_fillable_includes_canonical_bytes_and_fiscal_event_id(): void
```

**Why nit, not P2:** the test is well-conceived (model + migration are two separate concerns; pinning each independently is correct). The RED→GREEN signal is weaker than "all 6 go RED → all 6 go GREEN", but the truth-table is still correct (model edit pinned + schema edit pinned). A one-line PHPDoc clarifies it.

### Nit-2 — Migration PHPDoc cites "Task 8" without naming the trigger or migration

**Severity:** nit (PHPDoc readability)
**File:** `apps/api/database/migrations/2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php:60–63`

The FK-no-ON-DELETE-clause justification cites "Task 8's BEFORE DELETE trigger on `fiscal_events` forbids deletes outright". A future maintainer who hasn't read the plan document can't grep for "Task 8" in the codebase. The trigger has a real name (`fiscal_events_immutability_trigger`) in a real migration (`2026_05_14_100002_create_fiscal_events_immutability_triggers.php`). Citing those would close the gap.

**Suggested fix:** rewrite `:60–63` as:

```
// FK to `fiscal_events.id`. ON DELETE / ON UPDATE not declared — PG's
// default NO ACTION blocks deletes. The fiscal_events BEFORE DELETE
// trigger (`fiscal_events_immutability_trigger` in
// `2026_05_14_100002_create_fiscal_events_immutability_triggers.php`)
// forbids deletes outright upstream of any FK check, so the FK's
// NO ACTION is belt-and-suspenders.
```

**Why nit:** future-maintainer ergonomics, not correctness.

---

## Implementer deviations checked

The task brief flagged several implicit deviations from the plan's literal Step 1 stub. Reviewed each:

1. **Test column-existence list expanded from 2 (plan stub) to 5 (chain mirrors + 2 new).** Verdict: **sound, strict improvement.** Plan §858–864 asserts `canonical_bytes` + `fiscal_hash`. The implementer asserts `canonical_bytes` + `fiscal_event_id` + `fiscal_hash` + `previous_hash` + `chain_sequence`. The expanded surface pins the "mirror columns retained" contract for all three legacy columns, not just `fiscal_hash`. Strict improvement.

2. **UNIQUE constraint tested by schema-introspection (not insert-based as in plan stub).** Verdict: **sound, with one coverage gap (P2-1).** The implementer's justification (FK parent seeding cost) is correct for Option A (raw insert); less correct for Option B (factory chaining). The schema-introspection approach pins the structural contract directly and runs in both drivers without seed cost — a strict choice in favor of test-shape over test-throughput. P2-1 surfaces the runtime-behavior gap as a coverage observation.

3. **Three PG-only catalog-introspection tests added.** Verdict: **sound, matches Task 7/10 pattern.** FK existence (`pg_constraint`), BYTEA shape (`information_schema.columns.data_type`), UUID nullability (`information_schema.columns.is_nullable`). All three use the locked-in `skipUnlessPostgres()` helper. ✓

4. **Migration PHPDoc records the "mirror columns" contract.** Verdict: **sound, the right framing.** The migration body is "add two columns + UNIQUE + FK"; the PHPDoc tells the future-reader what's not happening (legacy columns retained, not dropped) and why (mirror columns of `fiscal_events`). Without the PHPDoc, a Task 21 implementer might assume the legacy columns are unused and drop them in a follow-up. The PHPDoc closes that gap.

5. **CI gate filter + comment updated in the same commit.** Verdict: **sound, matches Task 7/8/9/10 discipline.** The test is in the PG merge-gate filter from day one; the comment explains what the PG-only assertions cover. ✓

---

## Cross-task regression check

| Prior task | Files touched in `ddc42d5c`? | Verdict |
|---|---|---|
| Task 1 (Fiscal module skeleton + `ProjectionStatus` enum scaffolding) | No | ✓ no regression |
| Task 2 (`FiscalEventType` enum) | No | ✓ no regression |
| Task 3 (`IntegrityStatus` / `PayloadParseStatus` / `SignatureStatus` enums) | No | ✓ no regression |
| Task 4 (canonical-golden-vectors fixture) | No | ✓ no regression |
| Task 5 (`FiscalEventCanonicalEncoder` TS) | No | ✓ no regression |
| Task 6 (`FiscalIntegrityProvider` + signature provider seam) | No | ✓ no regression |
| Task 7 (`fiscal_events` table + `FiscalEvent` model) | No (FK references `fiscal_events.id`, no schema change to Task 7) | ✓ no regression |
| Task 8 (immutability triggers on `fiscal_events`) | No (the FK's no-ON-DELETE-clause leans on Task 8's BEFORE DELETE trigger, but Task 8 itself is unmodified) | ✓ no regression |
| Task 9 (`fiscal_event_projections`) | No | ✓ no regression |
| Task 10 (`fiscal_event_quarantine`) | No | ✓ no regression — and Task 10's `canonical_bytes` BYTEA discipline is correctly reused here |
| Receipt model — production hot file | Yes (`+8 / -0`) | ✓ — only `$fillable` extended, no other property touched. No existing test asserts the exact `$fillable` shape (grep-verified). Factory unaffected (does not reference the new columns). |
| Full Fiscal Feature suite | 49 tests, 32 PG-only skipped on SQLite | ✓ — matches Task 10's post-implementation baseline + Task 11's new 6 tests |

The commit's four-file scope is exactly what Task 11 promises.

---

## Forward-looking notes for Task 21

Three notes for the reviewer of Task 21 (`PosCoreReceiptProjection::apply()`), the consumer of this migration:

- **Idempotency anchor confirmed.** The UNIQUE on `pos_receipts.fiscal_event_id` is the durable, schema-enforced idempotency guard. Task 21's `apply()` opener must be:
  ```php
  if (Receipt::where('fiscal_event_id', $event->id)->exists()) {
      return; // already projected — idempotent re-entry
  }
  ```
  And the subsequent `Receipt::create([...])` populates `fiscal_event_id` + `canonical_bytes` at insert time. The UNIQUE will catch any race condition (two workers racing on the same event) at the DB layer; the early-exit handles the manual-replay path.

- **No backfill of legacy rows.** Legacy `pos_receipts` rows with NULL `fiscal_event_id` stay that way forever — the `enforce_receipt_immutability` trigger forbids the UPDATE. Task 21 must INSERT new rows for new fiscal events, never edit existing ones. P2-3 above asks for this to be made explicit in the migration PHPDoc.

- **`canonical_bytes` is raw bytes, not encoded.** The column's Eloquent value is a raw PHP string (no transformation). Task 21 must pass the verbatim canonical bytes the device produced (i.e. the same bytes that were SHA-256'd to produce `fiscal_events.current_hash`). Encoding it (base64, JSON-escaping, etc.) before insert would break the hash-verifiability contract — the verifier (§15) recomputes `SHA-256(canonical_bytes)` and compares to `current_hash`; encoded bytes would not round-trip.

---

## Recommendation

**APPROVE-WITH-MINOR-EDITS — proceed to Task 12** (`add_origin_and_fiscal_event_id_to_payments` + `PaymentOrigin` enum).

The edits flagged:
1. **P2-1**: add a PG-only insert-based duplicate-rejection test (~15 lines + a small `fiscal_events` seed helper). Closes the schema-vs-runtime coverage gap.
2. **P2-2**: add two `@property` annotations to the Receipt model header for `$canonical_bytes` + `$fiscal_event_id`. Two lines.
3. **P2-3**: add a one-paragraph PHPDoc note to the migration about the `enforce_receipt_immutability` trigger interaction (the rebuild's "insert, never backfill" contract). ~6 lines.
4. **Nit-1**: optional one-line PHPDoc on the model-fillable test clarifying that it's schema-state-independent. Not required.
5. **Nit-2**: optional rewrite of the FK PHPDoc to name the trigger / migration explicitly. Not required.

Per the parent session's reconciliation policy: these are flagged for the parent session to apply or accept-as-is; this review does not modify source. None of the five findings block Task 12. The migration correctly realizes plan §847–884 + spec §7.5 + §13; the Receipt model edit is minimal and disciplined; the test pins the structural contract on both drivers; the CI gate is wired.

The Task 8 BLOCKER class (write-once invariant silently routable around) was checked-for-by-analogy and **does not apply directly** — there is no classification column on `pos_receipts`. The **adjacent question** ("can the new columns be mutated after insert?") is answered by the pre-existing `enforce_receipt_immutability` trigger, which forbids it for any non-void mutation. The migration leans on that trigger without documenting it (P2-3).

The schema-introspection-vs-insert-based test-shape decision is sound — the structural contract is the right thing to pin, and the FK-parent seed cost would have ballooned the test surface for marginal coverage gain. P2-1 surfaces the residual runtime-behavior gap as a coverage observation rather than blocking it.

No follow-ups required before Task 12 begins (other than the optional P2/P3 edits above).
