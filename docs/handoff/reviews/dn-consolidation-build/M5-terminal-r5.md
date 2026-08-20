# TERMINAL re-review register — M5 round 5, **COMBINED** (lenses: treasury + tenancy-authz)

**Wave:** `dn-consolidation-build` · **Branch:** `codex/dn-consolidation-2026-08-12`
**Round-4 register re-verified:** `M5-terminal-r4-combined.md` (CHANGES-REQUIRED — 2 Important,
7 Minor).
**Delta reviewed:** `f3a56d698..3bb5468ea` — one fix commit (`3bb5468ea`, 10 files) applied by the
parent for BOTH lenses.
**Tip at review:** `3bb5468ea` (expected tip confirmed).
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/dn-consolidation` — read-only
apart from two *temporary, restored* red-proofs of the R4-2 structural closure (§A.2) and this
register.
**Scratch databases:** `autoerp_dn_r5`, `autoerp_dn_r5b` (created for this round; `autoerp_test`
never touched).

Every closure below was re-derived by **executing** code at this tip. Nothing was accepted from the
fix commit's message or from the r4 register's prose.

---

## VERDICT

**ACCEPT.** Zero Important open. Six Minor, all recorded below; two of them are new.

Both r4 Importants are closed **on substance, and I proved the load-bearing one twice by
execution rather than by reading**:

1. **`R4-1` (Pint-red CI) — closed.** `./vendor/bin/pint --test` is `{"result":"pass"}` both on the
   named file and over the whole of `apps/api`. The one failing path r4 measured is gone and no
   other appeared.
2. **`R4-2` (the Important) — the STRUCTURAL closure is real, and it is the right structure.** The
   parent took the option the r4 register called "the second, which closes the class permanently":
   the per-row marker insert now runs inside a nested `DB::transaction` with
   `catch (QueryException) { $counts['unparseable_invoiced_at']++; continue; }`. I did not take
   either half on trust. I removed the guard and watched the `'+20:00'` fixture abort the migration
   with the exact SQLSTATE the r4 probe predicted, and I then removed *only the savepoint* and
   watched the outer transaction poison itself with 25P02 — so both the fixture's decisiveness and
   the savepoint's necessity are measured facts at this tip, not arguments.

No money is wrong. No GL entry, no `journal_lines`, no `payment_repositories.balance`, no treasury
port and no fiscal chain is touched anywhere in this delta — the rule-19 scan over the diff is
empty. The delta is 3 comment-only `app/` edits, 1 migration behaviour change, 4 test edits,
1 workflow comment and 1 handback entry.

---

## A. What I executed at this tip

```bash
psql -h 127.0.0.1 -p 5433 -U autoerp -d postgres -c "CREATE DATABASE autoerp_dn_r5  OWNER autoerp;"
psql -h 127.0.0.1 -p 5433 -U autoerp -d postgres -c "CREATE DATABASE autoerp_dn_r5b OWNER autoerp;"
cd apps/api && DB_HOST=127.0.0.1 DB_PORT=5433 DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret \
  DB_DATABASE=<scratch> DB_CENTRAL_DATABASE=<scratch> CACHE_STORE=array \
  php artisan test -c phpunit-pgsql.xml <paths>
```

### A.1 Runs — measured, with the brief's expectation alongside

| Run | Expected by the brief | Measured |
|---|---|---|
| `./vendor/bin/pint --test tests/Feature/Document/SalesOrderBillingClaimTest.php` | pass | **`{"result":"pass"}`** ✔ |
| `./vendor/bin/pint --test` (whole `apps/api`) | pass | **`{"result":"pass"}`** ✔ |
| `DeliveryNoteBillingMarkerMigrationTest` alone, PG | "6/60-ish" | **5 passed / 54 assertions** ✔ (see note) |
| The four delta-touched wave files, ONE process, PG (`SalesOrderBillingClaimTest`, `DeliveryNoteToBillQueueTest`, `DeliveryNoteConsolidationTest`, `DeliveryNoteBillingMarkerMigrationTest`) | ~47-52 / ~430 | **47 passed / 423 assertions** ✔ |
| The **exact CI PostgreSQL step, in its shipped order** (`…Concurrency`, `…BillingProjection`, `…BillingClaimService`, `…MarkerMigration`) | — | **37 passed / 404 assertions** ✔ (identical to r4 — no bleed) |
| `phpstan analyse` over the three changed `app/` files | — | **`[OK] No errors`** ✔ |
| rule-19 scan over the non-docs delta | — | **zero matches** ✔ |

**Note on 5/54, honestly stated.** The brief expected ~6/60. The delta adds a *fixture row*, not a
test method, and changes one expected value from `6` to `7`; PHPUnit therefore reports the same
5 tests / 54 assertions as r4. The count is unchanged **because** the proof rides on the aggregate
log assertion plus `assertSame(15, DB::table('delivery_note_billing_marks')->count())` — which is
what makes it a real assertion: a marker row for the `'+20:00'` DN would read 16, and the guard
firing on the wrong row would move `rows_written`. The red direction is proved in §A.2 instead of
being inferred from a count.

### A.2 The two red-proofs of the structural closure (temporary, restored)

`git status --porcelain` was empty before and after; the file was restored with `git checkout --`
and the tree re-verified clean at `3bb5468ea`.

**Variant A — the guard removed entirely** (pre-r4 shape: plain `insert()`, no savepoint, no catch):

```
FAILED  DeliveryNoteBillingMarkerMigrationTest   QueryException
SQLSTATE[22009]: Invalid time zone displacement value: 7 ERROR:
  time zone displacement out of range: "2026-01-01T00:00:00+20:00"
  … SQL: insert into "delivery_note_billing_marks" (…) values (…, 2026-01-01T00:00:00+20:00, …)
  9  database/migrations/.../2026_08_18_000002_…php:111
```

So the `'+20:00'` fixture **is** decisive: it lands, it reaches the INSERT past every enumerated
validator, and without the guard it aborts the migration. This is exactly the residual r4 falsified
by probe, now falsified inside the real migration.

**Variant B — the catch kept but the savepoint removed** (`try { insert } catch (QueryException)`,
no nested transaction):

```
FAILED  DeliveryNoteBillingMarkerMigrationTest   QueryException
SQLSTATE[25P02]: In failed sql transaction: 7 ERROR:
  current transaction is aborted, commands ignored until end of transaction block
  … SQL: select exists (select 1 from pg_class c, pg_namespace n where … relname = 'delivery_note_billing_marks' …)
  11  tests/Feature/Document/DeliveryNoteBillingMarkerMigrationTest.php:198
```

The comment's savepoint reasoning at `:104-106` is therefore **correct and load-bearing**, not
decoration: swallow the exception without a savepoint and the next statement in the migration dies
of 25P02 anyway.

### A.3 The savepoint claim, verified in vendor code (not from memory)

| Link in the chain | Evidence |
|---|---|
| the migration's `up()` runs inside a transaction | `vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:448-450` — `supportsSchemaTransactions() && $migration->withinTransaction ? $connection->transaction($callback)`; the migration does not set `withinTransaction = false` |
| PostgreSQL qualifies | `Schema/Grammars/PostgresGrammar.php:18` `protected $transactions = true;` → `Grammar.php:533 supportsSchemaTransactions()` |
| the nested `DB::transaction` is a SAVEPOINT, not a second transaction | `Concerns/ManagesTransactions.php:157-159` — `elseif ($this->transactions >= 1 && supportsSavepoints()) { $this->createSavepoint(); }` |
| a failure rolls back **to the savepoint** | `ManagesTransactions.php:107` `$this->rollBack()` → `:300-310 performRollBack` → `compileSavepointRollBack('trans'.($toLevel + 1))` |
| the outer transaction survives | proved directly by the green 5/54 run and by Variant B going red only when the savepoint is removed |

**A safe asymmetry worth recording:** `ManagesTransactions.php:88-101` converts a concurrency error
inside the nested transaction into `Illuminate\Database\DeadlockException`, which extends
`PDOException`, **not** `QueryException` — so a deadlock/serialization failure is *not* swallowed by
the catch and still aborts the migration. That is the correct direction (a deadlock is not a dirty
row) and it is a real property of the shipped code, not an accident I am rationalising.

### A.4 The `continue` disposition matches the enumerated path, and `invoiced_at` stays NOT NULL

| path | counter | marker row |
|---|---|---|
| enumerated (`safeInvoicedAt()` returns null, `:87-89`) | `unparseable_invoiced_at`++ | none (`continue` before the insert) |
| structural (`catch (QueryException)`, `:121-125`) | `unparseable_invoiced_at`++ | none (`continue` before `rows_written`++) |

The column is `$table->timestampTz('invoiced_at')` at `:47` — **NOT NULL**, and
`markerTableIsComplete()` pins `'invoiced_at' => false` (non-nullable) at `:302`. Nothing in this
delta relaxes it, and no marker row is written with a NULL or a fabricated `invoiced_at`. Confirmed
by the green run: 15 marker rows, `unparseable_invoiced_at => 7`.

---

## B. Round-4 findings — closure verified one by one

| r4 id | Sev | Status at `3bb5468ea` | Verified by |
|---|---|---|---|
| `R4-1` | **Important** | **CLOSED** | `pint --test` on the file **and** repo-wide: both `{"result":"pass"}` |
| `R4-2` | **Important** | **CLOSED on substance** (residual doc minor `R5-1`) | Variant A (22009 abort) + Variant B (25P02) red-proofs + vendor-code chain §A.3 + 5/54 green |
| `R4-3` | Minor | **CLOSED** | `SalesOrderToDeliveryNoteConverter.php:193` now cites `SalesOrderBillingClaimTest::test_a_lock_order_violation_surfaces_as_a_500_class_alert_over_http`, which exists at `SalesOrderBillingClaimTest.php:890`. `grep -rn DocumentConversionIntegrityDispositionTest` returns hits only inside the r4 register itself |
| `R4-4` | Minor | **CLOSED** | `grep -rn "safeInvoicedAt():\|safeInvoiceId():" apps/api` → **zero hits**. All four citations are symbol-ized (`DeliveryNoteBillingState.php:32-33`, `DocumentData.php:196`) |
| `R4-5` | Minor | **CLOSED** | `DeliveryNoteConsolidationTest.php:786` is now `->where('company_id', $this->company->id)` with the reason inline; `$this->company` is `private Company $company` at `:51`, assigned at `:66`. Green in 47/423 |
| `R4-6` | Minor | **CLOSED** | `DeliveryNoteToBillQueueTest.php:294-299` now states what the assertion catches (a controller-side key typo) **and** what it does not (both sides typo'd identically; a missing translation). That matches the framework probe r4 ran |
| `R4-7` | Minor | **CLOSED on substance** (residual `R5-2`) | `ci.yml:639-642` adds the PG-only rationale for `DeliveryNoteBillingMarkerMigrationTest`; the claim ("SQLite accepts what timestamptz rejects") is exactly what Variant A demonstrates |
| `R4-8` | Minor | **CLOSED** | `grep -n invoicedAtYearZero` → **zero hits**; the new fixture at `:188` is likewise unassigned |
| `R4-9` | Minor | **PARTIAL** — handback entry landed; `progress.yaml` untouched and the PHPStan breakdown is wrong (`R5-3`, `R5-4`) | file reads + phpstan runs |

### B.1 The PHPStan pre-existence note, spot-verified as the brief asked

Run at tip, explicit file:

```
$ ./vendor/bin/phpstan analyse database/migrations/tenant/2026_08_18_000002_…php
 113  document.deliveryNoteBilling.directWrite
 167  missingType.iterableValue
 175  nullsafe.neverNull
 254  missingType.iterableValue
 [ERROR] Found 4 errors
```

Run on the **pre-edit blob** (`git show f3a56d698:…` extracted to a scratch path):

```
 …  document.deliveryNoteBilling.directWrite
 145 missingType.iterableValue
 153 nullsafe.neverNull
 224 missingType.iterableValue
 [ERROR] Found 4 errors
```

**The pre-existence claim is TRUE**: same four identifiers, same four constructs, only the line
numbers move with the inserted block. And the scoping claim is true as well — `phpstan.neon:6-7` is
`paths: [app/]`, and `ci.yml:141` invokes `./vendor/bin/phpstan analyse --level=8` with **no path
argument**, so no CI lane ever analyses `database/migrations/`. The composition stated in the
handback is nevertheless wrong — see `R5-4`.

---

## C. Findings (delta only) — six Minor, none blocking

### `R5-1` — **[MINOR]** migration `…000002_create_delivery_note_billing_marks_table.php:210` — the "by construction" sentence `R4-2` asked to be corrected is still there, now contradicted 28 lines above it by this delta's own honesty note

The delta added an accurate HONESTY NOTE at `:182-188` ("*this validator is an ENUMERATION, not a
proof … it cannot see shapes like ±16:00…±23:59 offset displacements*"), and I verified both of its
factual claims: PG's upper year bound really is far past 9999 (r4 inserted `'10000-01-01'`
successfully) and the offset residual is real (Variant A). But `:210` still reads, absolutely:

```
 * every value this method accepts is now, by construction, a value PostgreSQL accepts,
```

So one docblock now asserts a proposition and refutes it. **Why I am not blocking on it:** the ask
had two halves and the load-bearing half — close the class — was delivered in the stronger of the
two forms the r4 register offered, and the correcting note is placed *before* the stale sentence, so
a top-down reader meets the truth first and reads `:209-217` as the F-6/R2-4 history paragraph it
is. **Fix:** delete "by construction" from `:210` (it is one clause), or move the F-6/R2-4 history
under the honesty note.

### `R5-2` — **[MINOR]** `.github/workflows/ci.yml:635-643` — the inserted rationale splits a sentence and re-attributes the new file to the wrong register

The block now reads:

```
# DeliveryNoteBillingProjectionTest and DeliveryNoteBillingClaimServiceTest
# + DeliveryNoteBillingMarkerMigrationTest (M5-terminal tenancy F-R3-2): its
#   backfill regressions … are PG-only …
# were added by the M5-terminal r2 register (treasury R2-2). Before that …
```

The four inserted lines land between the subject and its verb, so "*were added by the M5-terminal r2
register (treasury R2-2)*" now appears to cover all three files. It does not: the migration test was
added by `F-R3-2` in the r3 round, which the same inserted line correctly says. The *content* is
right and is the durable half of `F-R3-2` — only the placement misleads. **Fix:** move the four new
lines below "…`by construction.`" as their own paragraph.

### `R5-3` — **[MINOR]** `docs/handoff/progress/dn-consolidation-build.progress.yaml` — `R4-9`'s second half was not delivered; the promoter-facing YAML still ends at round 2

The delta added the handback entry (`HANDBACK-…md:1178-1180`, one line per round) but did not touch
`progress.yaml`. That file's `terminal_gate` block stops at `terminal_gate_r2` / `fix_round_2`
(`:167-186`): there is no `terminal_gate_r3`, no `terminal_gate_r4`, no `fix_round_3`/`_4`, and its
`registers:` lists index only the r1 and r2 registers — so the machine-readable record does not
point at `M5-terminal-tenancy-authz-r3.md`, `M5-terminal-treasury-r3.md`,
`M5-terminal-r4-combined.md` or this register, and carries none of the r3/r4 counts. Every earlier
round in this wave landed both artefacts. **Fix:** two `terminal_gate_r{3,4,5}` blocks plus
`fix_round_{3,4}`, in the form `fix_round_2` already uses, carrying this round's measured
5/54 · 47/423 · 37/404.

### `R5-4` — **[MINOR]** `docs/handoff/HANDBACK-dn-consolidation-build-2026-08-12.md:1180` — the PHPStan note the promoter is meant to trust states the wrong composition

```
… reports 4 pre-existing-class findings (1 chokepoint-rule + 3 missingType) …
```

Measured at tip: **1 chokepoint-rule + 2 `missingType.iterableValue` + 1 `nullsafe.neverNull`**
(§B.1). The total (4) and the pre-existence claim are both correct — the breakdown is not. This is a
small instance of exactly the failure mode this wave has been gated on for four rounds: a
promoter-facing number that does not survive being re-derived. **Fix:** `1 chokepoint-rule +
2 missingType + 1 nullsafe`.

### `R5-5` — **[MINOR]** migration `:106-108` — the `catch (QueryException)` is broader than the comment's claim; every *enumerable* non-`invoiced_at` failure class is closed, but the comment's stated reason is not the load-bearing one

The comment justifies the count attribution with "*`invoiced_at` is the only raw-derived value left
at this point (invoice_id is uuid-verified, ids come from the model)*". I tested that claim against
every failure class the INSERT at `:113-119` can actually raise:

| class | reachable? | why |
|---|---|---|
| 23505 PK on `delivery_note_id` | **no** | the table is created in this same `up()` at `:43` and only reached when `! Schema::hasTable` (`:33`); `chunkById(…, 'id')` yields each `documents.id` once |
| 23503 FK `delivery_note_id → documents.id` | **no** | the id came from `documents` in this transaction |
| 23503 FK `invoice_id → documents.id` | **no** | `safeInvoiceId():269-278` SELECTs the row in the same transaction before returning it |
| 23503 FK `company_id → companies.id` | **no** | same source row |
| 22001 on `invoiced_via` varchar(32) | **no** | `billingLane()` returns a `DeliveryNoteBillingLane` value; the longest is `pre_post_delivery` (17) |
| **23502 NOT NULL on `company_id`** | **no — but not for the stated reason** | `documents.company_id` was added **nullable** (`2025_11_30_130000_add_company_id_to_existing_tables.php:67`) and only made `nullable(false)` by `2025_11_30_134000_make_company_id_required.php:39`, which always runs before this migration. "*ids come from the model*" is not what closes it; a schema constraint eight months upstream is |
| unexpected / infrastructure SQLSTATE | **yes** | counted as `unparseable_invoiced_at` and skipped |

So the attribution **is** sound today, and I want to be explicit that the r4 fix is a net safety
gain here rather than a new hole: before it, a 23502 would have *aborted* `tenants:migrate`. The
residual is the last row — an unexpected SQLSTATE would be silently folded into a counter named for
timestamps, on the survey number `OI-12` tells the promoter to read. A sustained infrastructure
fault still aborts (the surrounding `chunkById` SELECTs are outside the `try`), and a deadlock
escapes as `DeadlockException` (§A.3), so the exposure is narrow. **Fix (record or narrow):** either
say "any residual per-row insert failure" instead of naming `invoiced_at` as the only cause, or
gate the catch on the SQLSTATE class (`22*` plus `23502`) and let anything else abort.

### `R5-6` — **[MINOR, NEW FAMILY]** migration `:111-125` — the savepoint is per **row**, so the backfill now consumes one PostgreSQL subtransaction XID per delivery note inside a single transaction

Measured, not recalled — on the same PG 16.10 instance the tests run against:

```sql
BEGIN; INSERT …;                     -- xmin 5618030   (parent)
SAVEPOINT s1; INSERT …; RELEASE …;   -- xmin 5618031
SAVEPOINT s2; INSERT …; RELEASE …;   -- xmin 5618032
SAVEPOINT s3; INSERT …; RELEASE …;   -- xmin 5618033
```

Each write-performing savepoint takes its own subtransaction XID. A backfill of *N* stamped delivery
notes therefore opens *N* subtransactions in one transaction. Past 64 per backend PostgreSQL marks
the snapshot suboverflowed, and every *concurrent* backend then falls back to `pg_subtrans` lookups
for visibility — the classic subtransaction-overflow degradation, on a database that is serving the
tenant while `tenants:migrate` runs on the auto-deploying branch.

Honest sizing: this is one-time per tenant, it is a *performance* property and not a correctness
one, the correctness it buys (never aborting a tenant's deploy) is worth more, and at current tenant
sizes it is invisible. It is recorded because it is new with this delta, it scales with row count
rather than with dirtiness, and nobody has to accept it to get the fix. **Fix (optional):** insert
the chunk inside **one** savepoint and fall back to the per-row savepoint loop only for a chunk that
raised — happy-path cost drops from *N* subtransactions to *N*/100 (`BACKFILL_CHUNK_SIZE = 100`)
while the guarantee is unchanged.

---

## D. Residuals looked at and deliberately NOT raised

- **`R2-5`** (`postgresPayloadObjectSql()` normalising a non-object payload base) — unchanged by this
  delta, still recorded-not-fixed with a named owner. Correct disposition; not re-raised.
- **`DocumentData.php:133-144` unguarded `(string)` casts** and the **`HandlesDocuments` raw
  `partner_id`/`product_id` bindings** — both present at the wave base `60df88a01`, both already
  carried as repo-wide tickets. Not delta defects.
- **`DeliveryNoteConsolidationTest.php:366, :433`** absolute `delivery_note_billing_marks` counts —
  the same latent class as the now-closed `R4-5`, never named by any register, not introduced here,
  and green in both one-process runs.
- **The retained year bound at `:244`** — now redundant (a year-zero shape would be caught by the
  structural guard anyway) but harmless defence in depth, and it keeps the two year-zero fixtures
  attributed to the enumerated path. The handback calling it "superseded" is fair.
- **Authorization / rule 12** — the delta touches no `routes.php`, no middleware, no
  `config/verticals.php`, adds no endpoint and changes no permission. No gating drift.
- **Module boundaries (rule 6)** — no cross-module import added; the three `app/` edits are
  comment-only.

---

## E. Disposition — stated per lens

### E.1 TREASURY lens — **all r4 treasury items CLOSED**

`R4-1` (Pint/CI), `R4-3` (converter citation), `R4-5` (scoped count), `R4-6` (honest i18n comment)
all closed and re-derived by execution. `R2-5` stays recorded-not-fixed with its owner. No money,
GL, treasury balance, payment repository or fiscal artefact is touched anywhere in the delta.
**Treasury lens disposition: ACCEPT.**

### E.2 TENANCY-AUTHZ lens — **the Important CLOSED structurally; record residuals only**

`R4-2` closed on substance and red-proven in both directions; `R4-4` (citations), `R4-7` (CI
rationale), `R4-8` (unused fixtures) closed; `R4-9` partial (`R5-3`, `R5-4`). No tenant can now be
left without `delivery_note_billing_marks` by a dirty `payload.invoiced_at` of any shape — that is
the per-tenant divergence `F-6` existed to prevent, and after four rounds of enumeration it is
closed by a structure instead of a list.
**Tenancy-authz lens disposition: ACCEPT.**

### E.3 Findings, by severity

| id | Sev | One-liner |
|---|---|---|
| `R5-1` | Minor | the "by construction" sentence survives at migration `:210`, now contradicted by this delta's own honesty note above it |
| `R5-2` | Minor | the `ci.yml` rationale insert splits a sentence and re-attributes the migration test to the r2 register |
| `R5-3` | Minor | `progress.yaml` still ends at round 2 — no r3/r4/r5 gate blocks, no register pointers, no counts |
| `R5-4` | Minor | handback PHPStan breakdown says "3 missingType"; measured is 2 missingType + 1 nullsafe |
| `R5-5` | Minor | the `QueryException` catch is broader than its comment claims; every enumerable class is closed, but by a schema constraint the comment does not name |
| `R5-6` | Minor | **new family** — one subtransaction XID per backfilled row (measured); >64 suboverflows the snapshot for concurrent backends |

---

**What to fix before merge:** nothing blocking. Take `R5-4` and `R5-3` if you take anything — a
wrong number and a stale index in the promoter-facing record are the two residuals that can still
mislead a human, and both are edits to files no test reads. `R5-1` and `R5-2` are one-clause
comment corrections; `R5-5` and `R5-6` are record-or-improve.

**VERDICT: ACCEPT**
