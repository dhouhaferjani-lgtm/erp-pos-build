# Merge gate — `fix/r2i-infra` (R2-I infra pre-fix)

**Reviewer axes:** general + release/data (adversarial).
**Branch:** `fix/r2i-infra` · **Commit:** `5ec1d9637` · **Diff base:** `a1952aa23`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.fix-r2i-infra`
**Date:** 2026-08-07

## Verdict: **APPROVE-WITH-FIXES**

Both spec deliverables are real, and I confirmed both **independently** (I reproduced the
pre-fix red myself and I broke the harness myself to prove it has teeth — I did not take the
commit body's word for anything). No blocker. Four MINORs and two NITs below; MINOR-1 is a
comment that asserts something demonstrably false plus a non-load-bearing assertion, MINOR-2/3
are undocumented preconditions that will bite the exact lanes this harness was built for
(T-1 / R2-A2). All are ≤15-line edits or ticket material — none requires rework.

Scope is test-only: the commit touches **4 files, all under `apps/api/tests/`**
(`git show --name-only 5ec1d9637`). Zero app code, zero migrations, zero config. Release/data
blast radius on the deploy path is **nil**.

---

## Verification environment

Dedicated PostgreSQL database `autoerp_r2i_gate` on `127.0.0.1:5433` (created for this gate,
dropped afterwards — the developer's `autoerp` DB was never touched):

```
DB_DATABASE=autoerp_r2i_gate DB_CENTRAL_DATABASE=autoerp_r2i_gate \
  php artisan test -c phpunit-pgsql.xml <path>
```

Six throwaway probe test classes were written, run, and deleted; `git status` verified empty
after each batch. Worktree is byte-clean apart from this record.

---

## 1. The retargeted test — VERIFIED

### 1a. Green on live PG (their 36/36 claim)

```
tests/Feature/Treasury/BankStatementAggregateSchemaTest.php  →  Tests: 36 passed (148 assertions), 101.86s
tests/Feature/Accounting/BackfillPurchaseStampDutyAccountTest.php →  Tests: 9 passed (34 assertions), 35.63s
```
Claim confirmed exactly. Also green on the default sqlite config
(`migration down order is fk safe and reapply is clean` ✓, 16 passed / 20 pgsql-skipped).

### 1b. Pre-fix red reproduced independently

I ran the **verbatim pre-fix body** (`migrate:rollback --step 6 --path …` + the five
`assertFalse(hasTable(...))`) as a throwaway test on live PG:

```
FAILED … > pre fix body is red
Failed asserting that true is false.
```

Same message the commit body reports. The mechanism is confirmed by inspection:
`Migrator::getMigrationsForRollback()` takes the last N rows ordered
`batch desc, migration desc` — under `RefreshDatabase` everything is batch 1, so `--step 6`
now targets `2026_07_31_950000` … `2026_08_07_100000`, none of which touch bank statements.
The `--path` argument does **not** filter the rollback set; it only resolves files, and an
unresolved migration is silently skipped with exit 0. The old test was already not proving
what it claimed. Red→green evidence stands.

### 1c. Mechanism: manual `down()`, not `--path`/`--batch` — and the order is genuinely proven

`ProvesTenantMigrationRoundTrip.php:105-127` requires the migration files and calls
`down()`/`up()` directly. It never touches `artisan migrate` or the `migrations` bookkeeping
table, so it is structurally immune to anything landing after the target migrations. This is
the right mechanism for the stated problem.

**FK-safe order is really proven** — probe A: I re-declared the array with `110003`
(allocations) before `110002` (lines), so the reverse pass drops `bank_statement_lines`
first. It failed on live PG at `ProvesTenantMigrationRoundTrip.php:110`:

```
SQLSTATE[2BP01]: Dependent objects still exist: cannot drop table bank_statement_lines
DETAIL: constraint bank_statement_line_allocations_bank_statement_line_id_foreign …
```

So the harness drives real DDL in the declared reverse order and a wrong order is caught.
(Caveat: it surfaces as a raw `QueryException`, not a readable assertion failure — acceptable.)

### 1d. The `110007` exclusion is CORRECT

`database/migrations/tenant/2026_07_19_110007_add_checkpoint_flag_to_repository_movements.php:11-20`
adds a plain `boolean recorded_behind_checkpoint` to `repository_movements` and nothing else.
The dependency direction runs the other way — `bank_statement_line_allocations.repository_movement_id`
→ `repository_movements` (`110003:20-22`, `restrictOnDelete`) — so dropping the aggregate's
tables never requires `110007` to be rolled back first. Excluding it neither breaks FK order
nor removes coverage. Verified by reading the file and by the green PG run.

### 1e. Does it still prove what the original proved?

| Original proved | New test |
|---|---|
| rollback of the (nominal) aggregate migrations succeeds | ✅ stronger — targets the real 7 by name, and probe A shows the ordering assertion has teeth |
| the 5 aggregate tables are absent post-rollback | ✅ `:673-677` |
| re-apply restores them | ✅ `:664-672`, and twice (repeat cycle) |
| **re-apply via the real migrator** (`artisan migrate` re-running rolled-back migrations) | ❌ **lost** — see MINOR-1 |

Additionally verified by probe C that the round trip restores non-table artifacts too: the
`reject_bank_statement_match_execution_mutation()` plpgsql function dropped by `110004.down()`
is back (`pg_proc` count = 1) after the harness finishes, and the `matching_window_days`
column is back. Not asserted by the test, but not a regression (the original asserted neither).

---

## 2. The harness — API soundness

### 2a. `requireTenantMigrations` / repeated `require` of anonymous classes — SAFE

I probed this directly rather than reasoning about it. Requiring the same migration file 3×
in one process:

- **without opcache:** distinct mangled names (`…:10$21`, `…:10$22`), distinct instances, no fatal
- **with `opcache.enable_cli=1`:** identical mangled name (`…:10$21`) reused, still distinct
  instances, no redeclaration fatal

Probe F ran `requireTenantMigrations()` 40× in one test: green. `$a === $b` is false in both
modes, so no instance state leaks between calls. This is also exactly what Laravel's own
`Migrator` does (`$this->files->getRequire($path)`). **No risk.**

### 2b. The rollback→apply→rollback→reapply sequence — correct proof, correct final state

The sequence (`:107-127`) is: assert-applied (pre-condition) → down → assert-reverted → up →
assert-applied → down → assert-reverted → up → assert-applied. For DDL this is the right
idempotency shape: a back-to-back double `up()` legitimately errors on `CREATE TABLE` /
`ADD COLUMN`, so "the round trip repeats" is the correct proxy. Agreed with the design.

**RefreshDatabase interplay is clean** — probe B/C: a test that round-trips, followed by a
later test method in the same class asserting all 5 tables + the column + the trigger function,
both green. The harness leaves the DB in the applied state, and PostgreSQL's transactional DDL
means `RefreshDatabase`'s per-test rollback restores the baseline for the next test regardless.
No leak into the shared test database.

### 2c. `ReversibleTenantMigration` interface

Correct diagnosis: `Illuminate\Database\Migrations\Migration` genuinely declares neither
`up()` nor `down()`. The interface is the right minimal fix for static analysis. See NIT-1/NIT-2.

---

## 3. The irreversible-no-op prover — restoration CONFIRMED, teeth CONFIRMED

**Restoration is byte-for-byte, proved at the blob level (not by eyeballing a diff):**

```
git rev-parse a1952aa23:…/2026_08_07_100000_backfill_purchase_stamp_duty_account.php → 32c730810b792c0e5b6f4ee4c7367e47b621a118
git rev-parse 5ec1d9637:…/2026_08_07_100000_backfill_purchase_stamp_duty_account.php → 32c730810b792c0e5b6f4ee4c7367e47b621a118
```

Identical SHA. `git diff a1952aa23..5ec1d9637 -- <that file>` empty; `git diff HEAD -- <that
file>` empty; the commit's file list contains only the 4 test files. The temporary destructive
`down()` left **zero** residue.

**Demo test rerun:** `BackfillPurchaseStampDutyAccountTest` 9/9 on live PG (above) and 9/9 on
sqlite, including
`test_the_backfill_declares_an_irreversible_no_op_down_via_the_round_trip_harness`.

**Teeth verified independently of their method** (probe E). I did not re-do their
"temporarily break the migration" experiment; instead I pointed
`assertTenantMigrationIsIrreversibleNoOp()` at `110007`, which has an idempotent `up()` but a
genuinely **destructive** `down()` (drops the column), and asserted the column survives:

```
FAILED … probe e no op prover catches destructive down
col after down() — declared irreversible no-op, schema/data must be unchanged
Failed asserting that false is true.
  2 tests/Traits/ProvesTenantMigrationRoundTrip.php:156
```

Fails at exactly the intended checkpoint (`:155-156`). The prover has real teeth.

---

## 4. Release/data axis — usability for R2-A2 and T-1

`R2-A2` (numbering schema, `plan:160` "needs R2-I harness") is a **schema-changing migration
with a real `down()`**, which is precisely `assertTenantMigrationRoundTrips()`'s target shape.
It is **not** data-backfill-shaped: the closures are entirely caller-supplied, so nothing about
the API assumes backfill semantics. Usable as-is for the schema half.

**What A2 will still need — state this in the A2 brief:**

1. **Data survival is not proven by this harness, at all.** After the first `down()` the target
   table is gone; the subsequent `up()` runs against an *empty* table. If A2's migration carries
   a backfill (a numbering/identifier contract change almost certainly will), the round trip
   proves the DDL and says nothing about the data step. A2 must seed rows and assert them —
   and see (2).
2. **One `$assertApplied` closure serves all four applied checkpoints**
   (`ProvesTenantMigrationRoundTrip.php:107,117,127`), distinguished only by a free-text context
   string. A data-bearing lane that needs "rows preserved on the pre-condition pass, table empty
   on passes 2–4" has to `str_contains()` the context string. That is a stringly-typed contract.
   Recommended for A2: add an optional `?Closure $assertAppliedAfterReapply` (or pass an enum /
   pass index instead of prose). **MINOR-4.**
3. **`$withinTransaction = false` / `CREATE INDEX CONCURRENTLY` migrations are unusable** with
   this harness under `RefreshDatabase` (PostgreSQL refuses CONCURRENTLY inside a transaction).
   This is stated in prose at the class docblock but there is **no runtime guard** — a caller
   gets an opaque PG error, not a pointed message. The codebase already has such migrations in
   the T2 batch, and a hot-table unique-index change is a plausible A2 shape. **MINOR-3.**
4. **The real migrator is never exercised.** By design the harness never touches the
   `migrations` table, so it cannot catch a migration that round-trips fine under direct
   `up()`/`down()` yet fails under `tenants:migrate` (wrong `$connection`, a `Schema::` call
   resolving on the central connection, batching). For a release/data lane whose deploy runs
   `tenants:migrate`, that gap belongs to **T-1** (PG-mode CI leg), not to this harness. Worth
   an explicit line in the T-1 brief so nobody assumes harness-green ⇒ deploy-safe.
5. `assertTenantMigrationIsIrreversibleNoOp()` requires an **idempotent `up()`** (MINOR-2) —
   fine for backfills, not for A2's schema half. A2 should use the round-trip entry point.

---

## 5. Flagged-not-fixed: `T2MigrationRollbackTest` — CONFIRMED REAL and PRE-EXISTING

Reproduced on live PG on this tree, verbatim:

```
FAILED Tests\Feature\Migrations\T2MigrationRollbackTest > t2 migrations roll back and reapply cleanly
SQLSTATE[2BP01]: Dependent objects still exist: cannot drop table product_variants because other objects depend on it
DETAIL: constraint stock_transfer_lines_variant_id_foreign on table stock_transfer_lines depends on table product_variants
       constraint replenishment_requests_variant_id_foreign on table replenishment_requests depends on table product_variants
  11 database/migrations/tenant/2026_06_02_100003_create_product_variants_table.php:53
  12 tests/Feature/Migrations/T2MigrationRollbackTest.php:115
```

**Pre-existing, proved by construction rather than by a second checkout:** both offending
migrations exist at the base commit
(`git ls-tree a1952aa23` → `2026_06_09_120000_add_variant_id_to_stock_transfer_lines.php`,
`2026_07_10_100000_create_replenishment_requests_table.php`), and `5ec1d9637` touches only 4
files, **none** of which `T2MigrationRollbackTest` reads, extends, or requires (it extends the
unmodified `Tests\TestCase` and requires 16 migration files, all untouched). This commit cannot
have caused it. Same bug class as the one this lane fixes: a hardcoded 16-file list at
`T2MigrationRollbackTest.php:42-59` that new FK-adding migrations invalidate. Good first
consumer of the new harness, exactly as the lane says.

**Extra hazard for the orchestrator's ticket (not noted by the lane):**
`T2MigrationRollbackTest` deliberately does **not** use `RefreshDatabase`
(`:19-25` — CONCURRENTLY cannot run in a transaction). When it fails mid-rollback it leaves the
shared PostgreSQL test database in a **partially rolled-back state**, so anything running after
it in a full PG suite inherits the damage. That makes this red more expensive than a normal
one and argues for fixing it before T-1 turns the PG leg CI-required.

---

## 6. Static analysis / style — claims VERIFIED

- **Pint:** `{"result":"pass"}` on all 4 touched/new files. ✅
- **Official PHPStan gate:** `phpstan.neon` `paths: app/` — tests are out of scope, so "gate
  unaffected" is true but weak by construction. ✅ (accurately characterised in the commit body)
- **Ad-hoc L8 over the 4 files:** 7 errors, **0 in new code**. Both new trait/interface files
  are clean. All 7 are on pre-existing lines, confirmed by `git blame`
  (blame SHAs `91d9814b1`, `ebe85487d`, `9f8134698` — none is `5ec1d9637`):
  `BankStatementAggregateSchemaTest.php:86,131,402,435,452,617` (nullable `PaymentRepository` /
  `BankStatement` from `->repository` / `->statement` relations) and
  `BackfillPurchaseStampDutyAccountTest.php:140` (`nullsafe.neverNull`). The lane's claim that
  the ad-hoc pass found only pre-existing findings **plus** one real defect in the new harness
  (fixed via `ReversibleTenantMigration`) is accurate. ✅

---

## Findings

### MINOR-1 — the trailing `Artisan::call('migrate')` is vacuous, and its comment claims something false
`apps/api/tests/Feature/Treasury/BankStatementAggregateSchemaTest.php:680-688`

The comment says the call proves `migrate` "stays a clean no-op rather than trying to re-run
them against tables that already exist". It cannot prove that: the harness never removes the
bookkeeping rows, so `Migrator::run()` computes zero pending migrations by construction — it
never *could* try to re-run them.

Probed (probe D): I dropped `bank_statement_match_executions` by hand *without* touching the
`migrations` table, then ran the identical call:

```
[PROBE] exit=0 output=INFO  Nothing to migrate.
[PROBE] table back? false
```

Exit 0 with a table missing. The assertion cannot fail for any reachable state.

Second-order: this is also where the **only real coverage loss** vs. the original sits — the
old test's final `migrate` genuinely re-applied through the migrator (its rollback *had*
removed the bookkeeping rows). That path is no longer covered anywhere.

**Fix:** either delete the block and drop the now-misleading comment, or replace it with
something with teeth — e.g. delete the 7 `migrations` rows for `AGGREGATE_MIGRATIONS`, then
assert `migrate` re-applies them (exit 0 + rows restored + tables present), which restores the
lost coverage without reintroducing any `--step` fragility.

### MINOR-2 — `assertTenantMigrationIsIrreversibleNoOp()` has an undocumented "up() must be idempotent" precondition
`apps/api/tests/Traits/ProvesTenantMigrationRoundTrip.php:130-153`

The method opens with `up()` on an already-applied baseline and then calls `up()` a second
time. Both only work if the migration's own `up()` is self-guarding. Pointed at `110006`
(no `hasColumn` guard) it dies before reaching any of its own assertions:

```
SQLSTATE[42701]: Duplicate column: column "matching_window_days" … already exists
  12 tests/Traits/ProvesTenantMigrationRoundTrip.php:149
```

Benign for the intended data-backfill audience, but the method docblock never states it, and
the failure mode is an opaque PG error rather than "this entry point needs an idempotent up()".

**Fix:** one docblock line stating the precondition; optionally catch and rethrow with the
pointed message.

### MINOR-3 — no runtime guard for `$withinTransaction = false` / CONCURRENTLY migrations
`apps/api/tests/Traits/ProvesTenantMigrationRoundTrip.php:26-36` (prose only)

The class docblock warns that such migrations need `T2MigrationRollbackTest`'s
non-transactional pattern instead, but nothing enforces it. A future lane (plausibly R2-A2)
that points the harness at a CONCURRENTLY migration gets
`CREATE INDEX CONCURRENTLY cannot run inside a transaction block` with no hint about the
documented alternative.

**Fix (cheap, in `requireTenantMigrations`):** if `($migration->withinTransaction ?? true) === false`,
fail with a message naming the T2 pattern.

### MINOR-4 — single `$assertApplied` closure blocks data-bearing consumers (R2-A2)
`apps/api/tests/Traits/ProvesTenantMigrationRoundTrip.php:100-127`

See §4.2. Not a defect for this lane's two consumers; it is the concrete thing A2 will trip
over. **Ticket, not an in-lane fix.**

### NIT-1 — `ReversibleTenantMigration` is an interface living in the `Tests\Traits` namespace
`apps/api/tests/Traits/ReversibleTenantMigration.php:5`
A `Tests\Support` / `Tests\Contracts` namespace would be conventional. Cosmetic.

### NIT-2 — the name contradicts one of its two uses
The interface is called `Reversible…` yet is the declared type for the migration passed to
`assertTenantMigrationIsIrreversibleNoOp()`. Cosmetic; a neutral `TenantMigrationContract`
would read better.

### NIT-3 — `@var ReversibleTenantMigration` is an unchecked cast
`apps/api/tests/Traits/ProvesTenantMigrationRoundTrip.php:70-72`
`require` returns `mixed`; the docblock silences PHPStan without any runtime check. A wrong
file yields "call to undefined method" instead of a clear failure. An `instanceof Migration`
assertion would cost one line. Cosmetic.

---

## Merge recommendation

Merge is **safe** — test-only, both deliverables verified with independent teeth probes, no
production surface. Recommend landing MINOR-1 (delete-or-replace + comment correction) and the
two one-line doc/guard edits for MINOR-2/MINOR-3 **before the harness acquires consumers**,
since T-1 and R2-A2 will be reading these docblocks as the contract. MINOR-4 and the
`T2MigrationRollbackTest` red (plus its non-transactional shared-DB hazard) go to the
orchestrator's ticket.

---
---

# Fix-round re-verify — commit `658d93af4`

**Scope:** narrowed to MINOR-1 / MINOR-2 / MINOR-3 only, per the coordinator. Everything
verified in the first round above stands and was not re-litigated.
**Environment:** fresh disposable database `autoerp_r2i_gate2` (created for this pass, dropped
after) — never the shared `autoerp`. Six throwaway probe methods written, run, deleted;
`git status` verified clean.

## Verdict: **CLEAR TO MERGE**

All three MINORs are genuinely closed. One **new cosmetic MINOR-5** (the MINOR-2 guard covers
the wrong `up()` call for the harness's own default baseline) — non-blocking, 3-line follow-up.

### Full-file re-runs

| Config | Result |
|---|---|
| live PG (`autoerp_r2i_gate2`, `phpunit-pgsql.xml`), both files | **45 passed (196 assertions)** — `migration down order is fk safe and reapply is clean` ✓, `…irreversible no op down…` ✓ |
| sqlite (default `phpunit.xml`), both files | **25 passed / 20 pgsql-skipped (149 assertions)** — both target tests ✓ |

No shared-DB collision on my side (I never pointed a run at `autoerp`).

### MINOR-1 — CLOSED, and the §1e coverage loss is RESTORED

`BankStatementAggregateSchemaTest.php:680-724`. The block now tears the 7 aggregate migrations
back down, deletes their `migrations` rows, runs the real migrator, then asserts the 5 tables,
`matching_window_days`, and (PG-only) the `reject_bank_statement_match_execution_mutation()`
trigger function come back.

**My original sabotage probe, re-run against the new block** — torn-down schema, bookkeeping
rows left intact (the exact vacuity condition where the OLD block passed):

```
[M1-C] exit=0 out=INFO  Nothing to migrate.
FAILED … m1 without bookkeeping delete now fails
statement_import_profiles should exist after the real migrator re-applies
Failed asserting that false is true.
```

**It now fails where it previously passed.** The bookkeeping delete is load-bearing, and the
new assertions have teeth.

**Post-migrate sabotage** (reproducing the lane's own claim independently): drop
`bank_statement_match_executions` after `migrate` returns →
`bank_statement_match_executions should exist after the real migrator re-applies / Failed
asserting that false is true`. Confirmed.

**Control** (the new block verbatim, unsabotaged): `INFO Running migrations.` — the migrator
does *real work*, and afterwards all 5 tables + the column + `pg_proc` count 1 + **7 restored
bookkeeping rows** are present. So the block genuinely exercises the `tenants:migrate` deploy
path from a torn-down state. **The one coverage regression flagged in §1e ("re-apply via the
real migrator") is fully restored**, and without reintroducing any `--step` fragility. The
replacement comment (`:680-687`) is now accurate.

### MINOR-2 — CLOSED as documented; guard covers the *second* `up()` only → **new MINOR-5**

The PRECONDITION docblock (`ProvesTenantMigrationRoundTrip.php:161-167`) is exactly right and
is the part that carries the weight.

**Their probe reproduced** — `110006` in NOT-APPLIED state (column dropped first):

```
FAILED … m2 not applied state  AssertionFailedError
assertTenantMigrationIsIrreversibleNoOp() requires an idempotent up() — calling up() a second
time on '2026_07_19_110006_…' threw Illuminate\Database\QueryException: SQLSTATE[42701]…
  at tests/Traits/ProvesTenantMigrationRoundTrip.php:183
```
Named error fires. ✅

**But in APPLIED state** — which is the harness's own documented baseline
(`:26-28`: "Requires the consuming test to already have a fully migrated tenant schema
(`RefreshDatabase`, or an equivalent baseline) before calling either entry point") — the
guard does **not** fire:

```
FAILED … m2 applied state  QueryException
SQLSTATE[42701]: Duplicate column: column "matching_window_days" … already exists
  12 tests/Traits/ProvesTenantMigrationRoundTrip.php:177
```

The try/catch wraps only the **second** `up()` (`:180-190`); the **first** `up()` at `:177` is
unguarded. Under the documented baseline the migration is already applied, so the *first* call
is the re-run — meaning the opaque error the fix set out to replace still surfaces on the
**default** path, and the named message fires only on the non-default one.

**MINOR-5 (new, cosmetic, non-blocking):** wrap the `:177` call in the same try/catch (or
extract a shared `upOrFailPrecondition()` used by both). ~3 lines. Not a blocker: the docblock
precondition now exists, and the failure is loud either way — only the message quality differs.

### MINOR-3 — CLOSED, verified against a real CONCURRENTLY migration

`ProvesTenantMigrationRoundTrip.php:75-83`. Probed with
`database/migrations/tenant/2026_06_02_100008_add_variant_id_to_product_batches.php`
(`public $withinTransaction = false;` at `:12`, `CREATE UNIQUE INDEX CONCURRENTLY` at `:27,:30`):

```
FAILED … m3 concurrently guard  AssertionFailedError
Tenant migration '2026_06_02_100008_add_variant_id_to_product_batches.php' declares
$withinTransaction = false (a CREATE INDEX CONCURRENTLY migration, most likely) — this harness
runs up()/down() directly under RefreshDatabase's transaction, which PostgreSQL refuses for
CONCURRENTLY statements. Use the non-transactional require-and-call pattern from
T2MigrationRollbackTest instead.
  at tests/Traits/ProvesTenantMigrationRoundTrip.php:76
```

Fires at require time, before any DDL, with the T2 pointer. Exactly as specified. `$withinTransaction`
is declared `public $withinTransaction = true` on the base `Illuminate\…\Migration`, so the
`=== false` check is always defined — no `??` needed. ✅

### Gates re-run on the fix round

- **Pint:** `{"result":"pass"}` on all 4 files. ✅
- **Ad-hoc PHPStan L8:** still exactly **7 errors, 0 in new code** — the same pre-existing
  lines as round 1 (`BankStatementAggregateSchemaTest.php:86,131,402,435,452,617`,
  `BackfillPurchaseStampDutyAccountTest.php:140`). The new `Migration` import and the
  `Migration&ReversibleTenantMigration` intersection types introduce nothing. ✅

## Open after this round (none blocking)

- **MINOR-5** (new, above) — guard the first `up()` too.
- **MINOR-4** — single `$assertApplied` closure for all four applied checkpoints; the concrete
  thing R2-A2 will trip over. Ticket.
- **NIT-1/2/3** — interface namespace/naming, unchecked `@var` cast. Cosmetic.
- **`T2MigrationRollbackTest` red** (pre-existing, confirmed round 1) plus its
  non-transactional shared-DB hazard. Orchestrator's ticket.
- **R2-A2 briefing points** from §4 are unchanged and still owed to that lane — in particular
  that the harness proves **no data survival**, and that MINOR-3's guard now makes the
  CONCURRENTLY exclusion a hard, loud boundary rather than a prose warning.
