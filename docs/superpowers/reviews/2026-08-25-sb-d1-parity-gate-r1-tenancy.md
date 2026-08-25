# Session B · lane D-1 — `pg_constraint` enum↔CHECK parity gate — TENANCY/FLEET lens, round 1

- **Lane / branch:** `fix/sb-d1-pg-constraint-parity-test` · worktree `.worktrees/sb-d1-check-parity`
- **Commit reviewed:** `70adfcb2e` — 9 new files, all under `apps/api/tests/Architecture/**`, +2045/−0, **zero production files**.
- **Reviewer:** tenancy-authz-reviewer (tenancy half of the dual gate). Fiscal half:
  `docs/superpowers/reviews/2026-08-25-sb-d1-parity-gate-r1-fiscal.md` — its verified items (write-bomb ruling,
  COVERED spot-checks, parser honesty F-2/F-3/F-4) are **not re-litigated here**.
- **Evidence DB:** my own throwaway `autoerp_sbd1rev_test` on PG 5433, created for this review and **dropped at the end**.
- **Brief:** `docs/sessions/session-B-2026-08-23/BRIEF-D1-pg-constraint-parity-test.md`

## Verdict

**VERDICT: spec ✅ + quality APPROVED-with-conditions (tenancy lens). Do not merge on this lens alone.**

The tenant/central split is **real, mechanical and exactly as claimed** — I reproduced 32 central / 242 tenant
tables, zero overlap, zero unknown, and confirmed both blind-spot classes are handled loudly. The writer's
name guard genuinely cannot be pointed at a real tenant DB. The model derivation reconciles **247 = 247 with
zero silent skips**. My own live tamper (a tenant COVERED CHECK dropped) fires correctly.

Three things are weaker than the artifact says, none of them blocking a test-only commit:
the population has an **undisclosed silent hole with a live tenant instance** (T-1), the testing migration path
is a **UNION of both trees rather than a faithful tenant DB** and the stated reason for excluding central is not
the real one (T-2), and **central CHECK regressions are completely unguarded** — I proved this by dropping a
central CHECK and watching the gate stay green (T-3).

### Conditions on merge (all artifact/LEDGER, no code required in this lane)

1. **T-1 must be disclosed** in `EnumBackedColumnRegistry`'s KNOWN DERIVATION LIMITS and named in the register,
   because it moves the burn-down denominator (244 → 245 tenant columns, baseline 190 → 191).
2. **T-2's reason sentence must be corrected** in both the test docblock and the generated register.
3. **T-4 ruling (below) goes on the LEDGER as a hard precondition** for the first CHECK-adding batch.

## (1) CENTRAL vs TENANT split — VERIFIED, claim exact

`MigrationTableScopeMap::map()` (`apps/api/tests/Architecture/Support/MigrationTableScopeMap.php:45-63`) reads
`database/migrations/*.php` as CENTRAL and `database/migrations/tenant/*.php` as TENANT via three regex
recognisers (`:88-102`). Driven live on this worktree:

```
tables: central=32 tenant=242 total=274
colliding=[]
entries total=264  tenant=244  central=20  unknown=0
```

- **32 / 242, no overlap — the claim is exactly right** (it is a count of *tables*, not of migration files;
  the declaring-file counts are 24 central / 216 tenant, so do not confuse the two).
- **Declared in BOTH trees → loud.** `collidingTables()` (`MigrationTableScopeMap.php:68-77`) is asserted empty
  at `EnumCheckParityTest.php:263-267`. Live value `[]`.
- **Declared in NEITHER → loud.** `SCOPE_UNKNOWN` is asserted empty at `EnumCheckParityTest.php:255-261` with a
  message that correctly forbids waiving it by widening the assertion. Live value `[]`.
- **Raw `DB::statement('CREATE TABLE …')` — none exists.** `grep -rniE "CREATE TABLE" database/migrations`
  returns exactly one hit, a comment at
  `database/migrations/tenant/2026_03_11_700000_make_menu_category_items_polymorphic.php:41`. So the recogniser
  set is complete against today's tree, and a future raw create on an enum-backed table lands in UNKNOWN → fails.

**The 20 central columns are correctly REPORTED-not-asserted — but the stated reason is wrong, and asserting
them would NOT produce a false MISSING.** I ran the real analyzer over the 20 central entries against the
migrated schema:

```
tally = {"MISSING": 11, "COVERED": 9}
central tables in this DB: all 13 PRESENT
```

All 13 central tables **exist in the test DB with their real CHECKs**, because `migrate` in testing runs the
central tree too (see T-2). Nine of the twenty would come out COVERED — `impersonation_*` — and eleven MISSING
for the genuine reason that they have no CHECK. So excluding central is a **scope choice**, not a technical
necessity, and the reason strings —
`EnumCheckParityTest.php:37-41` ("*they migrate through a different path*"),
`MigrationTableScopeMap.php:12-15`, and the generated
`tests/Architecture/baselines/enum-check-parity-register.md:14` ("*different migration path — out of scope*")
— are **not honest about why**. See finding **T-2** and **T-3**.

## (2) DB-PER-TENANT correctness — RULING

**Ruling: the testing path applies the tenant tree in full and unmodified, but it applies it into a database
that ALSO carries the entire central tree. The test DB is a UNION, not a faithful `tenant_<uuid>` DB. Today
this cannot fake a verdict; the invariant that makes that true is unasserted.**

Evidence:

- Tenant tree, testing: `app/Providers/AppServiceProvider.php:222-229` —
  `loadMigrationsFrom(database_path('migrations/tenant'))`, gated on `environment('testing')`.
- Tenant tree, production: `config/tenancy.php:195-199` —
  `'--path' => [database_path('migrations/tenant')], '--realpath' => true`.
- **Same directory, same 545 files, no subdirectories** (`find database/migrations -mindepth 2 -type d` is
  empty), and neither path recurses. So the tenant DDL is identical between the two paths. ✅
- **But** the default `migrate` in testing also runs `database/migrations/*.php` — the central tree — into the
  same database. That is why all 13 central tables were PRESENT above.

Why it does not fake a verdict **today**, verified rather than assumed:

- **No central migration touches a tenant table.** Every table reached by `Schema::table(...)` /
  `DB::statement(... ALTER TABLE ...)` in `database/migrations/*.php` is central:
  `admin_audit_logs`, `billing_payments`, `impersonation_grants`, `plans`, `tenant_subscriptions`, `tenants`.
  So the union cannot inject a phantom CHECK onto a tenant table ⇒ **no false COVERED from this vector.**
- **One tenant migration is guarded on a central table's existence** —
  `database/migrations/tenant/2025_11_30_133000_migrate_tenant_data_to_companies.php:39` and `:119`
  (`if (! Schema::hasTable('tenants')) return;`). In a real tenant DB that guard short-circuits; in the union
  test DB the body runs. Its own comment at `:33-38` already documents this exactly. The body is **DML only**
  (`Tenant::where(...)` → create companies/locations/memberships), so it emits no DDL and cannot move a
  `pg_constraint` row. No other tenant migration is guarded on a central table.

**`TENANCY_DB_PER_TENANT=false` (`apps/api/phpunit.xml:49`, `force="true"`) is NOT relevant to the DDL.** The
flag only feeds `config/tenancy_resolver.php:30`, which gates the Stancl bootstrappers at
`app/Providers/TenancyServiceProvider.php:50,56` (and backup/health/provisioning commands). It does not select
migration paths. The test therefore sees the tenant tree's DDL exactly; what it additionally sees is central
DDL, which the scope map then filters out of the assertion set. Note that **CI's own PG jobs make the same
union explicit** — `treasury-spine-pgsql` sets `DB_DATABASE` **and** `DB_CENTRAL_DATABASE` to the same
`autoerp_treasury_test` (`.github/workflows/ci.yml:1161-1162`), as does `t6-phase0b-pgsql` (`:1094-1095`).

**Residual risk to record:** the "no central migration mutates a tenant table" property is what keeps the union
honest, and **nothing asserts it**. A future central migration that `ALTER TABLE`s a tenant table would silently
add a CHECK the gate reads as COVERED while no real tenant DB has it — a **false COVERED**, the one failure
mode that makes the gate lie. Cheap fix, in the next touch of these files: assert that the set of tables
mutated by the central tree is disjoint from `MigrationTableScopeMap`'s tenant set.

## (3) Mechanical derivation honesty — model reconciliation

`EnumBackedColumnRegistry::modelClasses()`
(`apps/api/tests/Architecture/Support/EnumBackedColumnRegistry.php:179-209`) maps every `.php` under `app/` to a
PSR-4 class name (`:189-190`) and keeps it if `class_exists` (`:192`) **and** `is_subclass_of(Model::class)`
(`:195`) **and** `ReflectionClass::isInstantiable()` (`:199`). I ran an independent scan (source-level
`class X extends …` + namespace resolution, then the same three predicates checked separately) against it:

| Measure | Value |
|---|---:|
| `modelClasses()` found | **247** |
| independent scan, Model subclasses under `app/` | **247** |
| in independent scan but NOT in `modelClasses()` | **0** |
| in `modelClasses()` but NOT in independent scan | **0** |
| PSR-4 path/namespace mismatches (would silently drop) | **0** |
| abstract / non-instantiable model classes | **0** |
| `unconstructableModels()` (DI-in-constructor skips, `:217-229`) | **0** |
| files declaring >1 `class … extends` (would hide a second model) | **0** |
| files whose class directly extends `Model`/`Pivot`/`MorphPivot`/`Authenticatable` | 244 (+3 extending an intermediate app model = 247) |

**Reconciliation is exact and by name — no model is silently skipped today.** Pivot and Authenticatable
descendants are included (both are `Model` subclasses); `Domain/`, `Infrastructure/` and `app/Models/` layouts
are all reached because the walk is path-based, not namespace-based. The three skip predicates are each a
*potential* silent hole, and all three are empty; the fiscal lens already filed the dead
`unconstructableModels()` docblock claim as F-6, and asserting it `=== []` in the test would close the only one
of the three that can change without a file being added.

### T-1 · enums outside `app/**/Domain/Enums/` — an UNDISCLOSED hole with a LIVE tenant instance

`DOMAIN_ENUM_PATH` (`:79`) plus the `continue` at `:118-120` drop any cast whose enum file is not under a
`Domain/Enums/` directory. I enumerated every such cast across all 247 models:

| Table.column | Enum | Scope | In gate? |
|---|---|---|---|
| **`products.enrichment_status`** | `App\Shared\Enums\EnrichmentStatus` | **tenant** | **NO — silently dropped** |
| `tenants.vertical` | `App\Enums\Vertical` | central | no (central anyway) |

`products.enrichment_status` is a **status column on a tenant table**, governed by a 6-case backed enum
(`app/Shared/Enums/EnrichmentStatus.php:9-14` — `pending, enriching, completed, failed, rejected,
not_enrichable`), and live on my migrated schema the `products` table carries **no value-set CHECK at all**
(its only CHECK is `products_max_discount_percent_range`). It is therefore a genuine uncovered enum-governed
tenant column that the gate does not count and will never fail on.

The registry's KNOWN DERIVATION LIMITS block (`:46-54`) discloses exactly two limits — columns with no enum
cast anywhere, and `AsEnumCollection` (verified: the only array-enum cast is
`workshop_technician_profiles.specialties`). It does **not** disclose the `Domain/Enums` path filter as an
exclusion, and the register never names this column. **The burn-down denominator is understated by one:**
244 → 245 tenant columns, baseline 190 → 191.

## (4) Ratchet semantics under fleet reality — RULING

**Writer name guard — cannot be pointed at a real tenant DB. VERIFIED.**
`tests/Architecture/Support/write-enum-check-parity-baseline.php:49-52` requires
`^autoerp_[a-z0-9_]*test$` *before* reading anything, and `:45-48` refuses a non-pgsql driver. Real tenant
databases are named `tenant<uuid>` — `config/tenancy.php:62-63` sets `'prefix' => 'tenant'`, `'suffix' => ''` —
which cannot match. Nor can the central DB under any deploy name (`iziposcentral` / `otospexcentral` /
`autoerp_central` / `synerivia_central`), nor the local dev `autoerp` (the pattern requires the `_…test` tail).
The fiscal lens executed the refusal against `synerivia_central` (exit 2, nothing written). The guard checks the
NAME only, not that the schema is fresh — the docblock says so at `:22-24`; acceptable and disclosed.

**Matched growth passes. Disclosure CONFIRMED** at `EnumCheckParityTest.php:43-51`, which is unusually honest:
it names the `DocumentPerActionBaselineRatchetTest` third direction, states plainly that a new uncovered column
plus a hand-added baseline entry in one diff passes, and says arming the pin is an owner action reported as an
owes "*rather than faked with a self-referential pin that the same diff could edit*". That is the right call for
this commit.

**RULING: the owner-pinned protected blob MUST be armed BEFORE the first slice-D CHECK-adding batch starts —
not before this merge.** Reasons, grounded in the DPA precedent:

- The DPA equivalent is a real, **fail-closed** mechanism, not a decorative one:
  `DocumentPerActionBaselineRatchetTest.php:116-126` asserts the repository variable is non-empty *and fails with
  an explicit message when it is unset*, `:128-132` validates it is a git object hash, `:134-141` cross-checks a
  progress-YAML mirror as a tamper signal, and `:143-149` fails closed if the pinned blob is unreachable. A
  parity-gate pin modelled on it is therefore a known-good, already-reviewed shape.
- The threat is specific to slice D's shape. The burn-down batches are the *only* changes that will legitimately
  edit this baseline, and every one of them will regenerate it with the writer. Without a ceiling, "the batch
  closed 8 gaps and quietly absorbed 2 new ones" is indistinguishable from "the batch closed 6", and the only
  defence is a human diffing a 190-line JSON file per batch — precisely the review load the ratchet exists to
  remove.
- Arming it now, while the baseline is a known-good 190 keys derived from a clean dev tree, is the cheapest it
  will ever be: the seed blob is `70adfcb2e:apps/api/tests/Architecture/baselines/enum-check-parity-baseline.json`.
  After the first batch lands, any pin is a pin on an already-mutated artifact.

Ratchet direction itself: `EnumCheckParityAnalyzer::partition()` (`:116-139`) is genuinely shrink-only —
unmatched failure ⇒ `new`, unmatched baseline key ⇒ `stale`, and keys carry the verdict (`:153`) so a
baselined-MISSING column that grows a wrong CHECK produces a key the baseline does not hold. Reproduced live
below.

## (5) Gate verified — every command run BY PATH on my own PG DB (never the full suite)

| Check | Command / method | Result |
|---|---|---|
| Parity gate on PG 5433 | `phpunit tests/Architecture/EnumCheckParityTest.php`, `DB_DATABASE=autoerp_sbd1rev_test` | **OK — 4 tests, 389 assertions**, 11.5 s |
| Liveness on PG 5433 | `phpunit tests/Architecture/EnumCheckParityDetectorLivenessTest.php` | **OK — 18 tests, 35 assertions**, 2.0 s |
| sqlite self-skip | `phpunit tests/Architecture/EnumCheckParityTest.php --display-skipped` (no DB env) | **4 skipped**; message names the driver and the exact pgsql re-run line (`EnumCheckParityTest.php:119-122`) |
| Pint | `./vendor/bin/pint --test tests/Architecture` | `{"result":"pass"}` |
| Feature-lane manifest, from worktree root | `phpunit tests/Architecture/FeatureLaneManifestCheckerTest.php` | **OK — 76 tests, 431 assertions, EXIT=0** |
| Architecture is not a ceiling-bearing group | `FeatureLaneManifestCheckerTest.php:63-64` — only `tests/Feature` is symlinked as the manifest's subject; `Architecture` is copied as an unrelated sibling suite | **CONFIRMED** |
| Baseline reproduced independently | real registry + real `PgValueSetCheckReader` + committed baseline | **NEW=0 STALE=0** |

**My own live tamper** (driven through the real derivation + real `pg_constraint` read against the **committed**
baseline; note that phpunit cannot host a tamper because `RefreshDatabase` re-runs `migrate:fresh` on every
invocation and wipes it):

| Step | NEW | STALE |
|---|---|---|
| 0 · untouched | 0 | 0 |
| 1 · **TENANT** `ALTER TABLE companies DROP CONSTRAINT companies_discount_floor_mode_valid` (a COVERED column) | **1 — `companies.discount_floor_mode::MISSING`** | 0 |
| 2 · restored | 0 | 0 |
| 3 · **CENTRAL** `ALTER TABLE impersonation_grants DROP CONSTRAINT impersonation_grants_status_check` (a COVERED column) | **0** | **0** ← T-3 |
| 4 · restored (both constraints re-added from the captured `pg_get_constraintdef`; existence re-verified = 1/1) | 0 | 0 |

Row 1 is the tenant direction working. **Row 3 is the tenancy finding**: dropping a live CHECK from a central
authz table is completely invisible to this gate.

## Findings

### [Important] T-1 — a status column on a TENANT table is silently outside the population, and the limit is undisclosed
`apps/api/tests/Architecture/Support/EnumBackedColumnRegistry.php:79` (`DOMAIN_ENUM_PATH`) + `:118-120` (silent
`continue`); disclosure block `:46-54` omits it.
**What's wrong:** `products.enrichment_status` (tenant scope) is cast to `App\Shared\Enums\EnrichmentStatus`
(`app/Shared/Enums/EnrichmentStatus.php:9-14`, 6 backed cases). Because that enum is not under a
`Domain/Enums/` directory, the column never enters the population. Live, `products` has **no value-set CHECK**.
**Why it matters:** the register is the burn-down denominator the owner reads and the batches count down.
Understating it by a real, uncovered, enum-governed tenant status column means that gap is never scheduled and
never fails — the exact silent hole this gate exists to eliminate. It is also the only one of the three skip
predicates that a future change can trip without anyone noticing.
**Fix (artifact + one-line code, next touch):** add the path filter to KNOWN DERIVATION LIMITS and name
`products.enrichment_status`; then either relocate the enum to a `Domain/Enums/` directory (production change,
own lane) or widen the recogniser to `app/**/Enums/`. Widening is preferable — it also pulls `tenants.vertical`
into the central report. Either way the denominator becomes 245 / baseline 191.

### [Important] T-2 — the testing schema is a UNION of both migration trees; the artifact's stated reason for excluding central is not the real one
`apps/api/tests/Architecture/EnumCheckParityTest.php:37-41`,
`apps/api/tests/Architecture/Support/MigrationTableScopeMap.php:12-15`,
`apps/api/tests/Architecture/Support/write-enum-check-parity-baseline.php:110`
(rendered at `baselines/enum-check-parity-register.md:14`) all say central is excluded because it "*migrates
through a different path*". In testing it does not: `app/Providers/AppServiceProvider.php:222-229` adds the
tenant tree **alongside** the default central tree, so `migrate` builds one database holding both — I confirmed
all 13 central tables PRESENT with their real CHECKs, and that asserting the 20 central columns here would
yield 9 COVERED / 11 MISSING, i.e. **no false MISSING**.
**Why it matters:** the false reason hides the real risk shape. The property that actually keeps the union
honest is "*no central migration mutates a tenant table*" — verified true today (central-tree `Schema::table` /
`DB::statement` reach only `admin_audit_logs`, `billing_payments`, `impersonation_grants`, `plans`,
`tenant_subscriptions`, `tenants`) and **asserted nowhere**. The day it breaks, the gate reports a CHECK that
no real `tenant_<uuid>` DB has: a false COVERED, which is worse than any miss. Adjacent, benign, already
self-documented: `database/migrations/tenant/2025_11_30_133000_migrate_tenant_data_to_companies.php:33-40`
is `hasTable('tenants')`-guarded and so runs in the union DB but not in a real tenant DB — DML only, no DDL.
**Fix:** correct the reason to "*central is a different lane's scope; this DB is a union of both trees*", and
add an assertion that the central tree's mutated-table set is disjoint from the tenant scope set.

### [Important] T-3 — central-database CHECK regressions are wholly unguarded, and 9 of them are authz-bearing
Proven live (§5 row 3): `DROP CONSTRAINT impersonation_grants_status_check` ⇒ `NEW=0 STALE=0`. Central entries
are filtered out before analysis at `EnumCheckParityTest.php:99-102` and
`EnumBackedColumnRegistry.php:168-174`.
**Why it matters (tenancy/authz):** nine of the twenty central columns are COVERED today and every one of them
is on the support-access / impersonation privilege path — `impersonation_grants.status`, `.type`,
`impersonation_elevations.status`, `impersonation_sessions.access_level`, `.end_reason`, and the four
`impersonation_*_events.event_type` / `.outcome` columns (`enum-check-parity-register.md:295+`). Those CHECKs
are the last DB-level defence on a grant-state machine that gates cross-tenant access. Losing one is invisible
to every gate in this repo.
**Fix:** not this lane — this lane is correctly scoped to tenant. LEDGER row: a central-scope sibling gate,
runnable against a migrated central DB, is owed. The 20-row central table in the register is the ready-made
population.

### [Important] T-4 — no anti-growth ceiling (disclosed) — ruling: arm it before slice D, not before this merge
Disclosure is honest and complete at `EnumCheckParityTest.php:43-51`. Ruling and rationale in §(4). The DPA
precedent to copy is `DocumentPerActionBaselineRatchetTest.php:114-149` (fail-closed on unset variable, hash
shape check, progress-YAML mirror as tamper signal, fail-closed on unreachable blob). Seed blob:
`70adfcb2e:apps/api/tests/Architecture/baselines/enum-check-parity-baseline.json`.

### [Minor] T-5 — `super_admins.role` is the highest-value instance of the disclosed "no enum cast anywhere" limit and is not named
`app/Models/SuperAdmin.php:41-49` — `casts()` returns `is_active`, `last_login_at`, `created_at`, `updated_at`
and **not** `role`, although `app/Models/Enums/SuperAdminRole.php` exists. Live, `super_admins.role` is a plain
`character varying` and the table has **zero** CHECK constraints. The registry discloses this defect class at
`EnumBackedColumnRegistry.php:46-52` using `bank_reconciliations.status` as the example; the register repeats
that example at `enum-check-parity-register.md:150-154`. Neither names a privilege column on the central auth
table. Central scope, and closing it needs a production cast — correctly outside this lane. LEDGER residual.

### [Minor] T-6 — the scope map does not scan `database/migrations/manual/`
`MigrationTableScopeMap.php:52,55` glob only `migrations/*.php` and `migrations/tenant/*.php`. `manual/` today
holds one DML backfill plus a README, so nothing is missed; and a future `Schema::create` there would land in
`SCOPE_UNKNOWN` and fail loudly (`EnumCheckParityTest.php:255-261`), which is the right direction. Worth one
sentence in the class docblock so the omission reads as deliberate.

### [Minor] T-7 — the gate cannot be tamper-tested through phpunit, and nothing says so
`EnumCheckParityTest.php:65` uses `RefreshDatabase`, which re-runs `migrate:fresh` on every phpunit invocation,
so any hand-planted or hand-dropped CHECK is wiped before the assertion runs. Both this review and the fiscal
one had to drive the pure pipeline from a script to demonstrate the ratchet on a live schema. That is an
operational fact the burn-down lanes will hit on day one (they will want to verify their new CHECK fires the
STALE arm). Document the script-driven recipe next to the writer.

## CI reachability statement (report only — S-14, no `.github/**` file touched)

- **No CI job runs this gate today.** `grep -n "tests/Architecture\|EnumCheckParity" .github/workflows/*.yml`
  returns no `EnumCheckParity` hit anywhere. The only path that would sweep it in is `ci.yml:490`
  (`tests/Architecture` inside `backend-test`'s partitioned run), and that step is
  `if: always() && github.event_name == 'workflow_dispatch'` (`:477`) on the **sqlite** lane — where all four
  parity tests self-skip. The 18 pure-function liveness cases do execute there; the PG probe does not.
- **`treasury-spine-pgsql` (`ci.yml:1102`) is the correct home for the PG arm.** It provisions a real
  `postgres:16` service (`:1127-1133`) and sets `DB_CONNECTION=pgsql` with
  `DB_DATABASE=DB_CENTRAL_DATABASE=autoerp_treasury_test` (`:1158-1162`) — a name that also satisfies the
  writer's `^autoerp_[a-z0-9_]*test$` guard. Its trigger (`:1117`) fires on PR→dev, PR→main and dispatch, so it
  protects the slice-D burn-down while it is being merged, which is exactly when the ratchet matters.
  Tenancy note: that job's central/tenant DBs are the same database, i.e. the same UNION shape as the local
  test DB — consistent with §(2), so the gate will behave identically there.
- **`backend-architecture` (`ci.yml:143`) is the right home for the pure-function half only.** It already runs
  named Architecture files by path (`:202`, `:215`, `:228`), so
  `EnumCheckParityDetectorLivenessTest.php` belongs there; adding `EnumCheckParityTest.php` there too is
  harmless (it self-skips) and gives a visible "gate exists" signal, but **must not be mistaken for coverage**.
- A third option worth naming: `t6-phase0b-pgsql` (`ci.yml:1086-1100`) is the db-per-tenant job and already uses
  `autoerp_test`, but it selects tests by `--filter` on a class-name list under `phpunit-pgsql.xml`, so wiring
  there is a more invasive edit than `treasury-spine-pgsql`. **Recommend `treasury-spine-pgsql` + `backend-architecture`.**
- **Wiring is owed and unverified. Until it lands this gate protects nothing in CI**, and per T-4 it should be
  an explicit precondition on the first CHECK-adding batch rather than a follow-up.

## Scope check (7)

`git show --stat 70adfcb2e` — 9 files, every one under `apps/api/tests/Architecture/**`, +2045/−0, **zero
production files**. `git status --porcelain` clean at review end; I modified no file in the worktree and touched
nothing on Session A's collision matrix. Neither `.github/**` nor any migration was edited.

## Residuals for the LEDGER (tenancy lens; fiscal lens carries F-1…F-9 separately)

1. **T-1** — disclose the `Domain/Enums` path filter and fold `products.enrichment_status` in; denominator
   244→245, baseline 190→191.
2. **T-2** — correct the "different migration path" reason in the test docblock + the writer's register line
   (`write-enum-check-parity-baseline.php:110`); add the central-mutates-tenant disjointness assertion.
3. **T-3** — a central-scope enum↔CHECK sibling gate is owed; the impersonation/grant CHECKs are authz-bearing
   and currently unguarded in both directions.
4. **T-4** — arm the owner-pinned protected blob (seed
   `70adfcb2e:apps/api/tests/Architecture/baselines/enum-check-parity-baseline.json`) **before** the first
   slice-D CHECK-adding batch.
5. **CI wiring** — `treasury-spine-pgsql` (PG arm) + `backend-architecture` (pure-function arm); S-14 separate act.
6. **T-5** — `super_admins.role`: no enum cast, no CHECK, central auth table. Production change, own lane.
7. **T-6 / T-7** — docblock hygiene: `manual/` omission, and the `RefreshDatabase` tamper-recipe note.

## What to fix before merge

Nothing blocking in the code. **Disclose T-1 and correct T-2's reason sentence in the two artifacts**, and put
**T-3 (central blind spot) + T-4 (arm the pin) + CI wiring** on the LEDGER as hard preconditions for the first
CHECK-adding batch.
