# C-QR0a — tenancy/migration gate r1 (fiscal-authority schema, SCHEMA ONLY, MIGRATION-BEARING)

| | |
|---|---|
| Lane | C-QR0a — fiscal-authority schema, lane 3 of 16, Session C document-lifecycle-dimensions program |
| Branch / worktree | `feat/sc-qr0a-authority-schema` · `.worktrees/sc-qr0a-authority-schema` |
| Reviewed SHA | `135140a87` (fix round r1 on `f7488c02d`; code commits `ffd0e1b7a`, `62e97cb49`) |
| Base | `351802aac` |
| dev at gate time | local `dev` = `bd03bd8cb` (moved from `9075bacf5` DURING this gate) · `origin/dev` = `c6d6308ae` |
| Gate | `tenancy-authz-reviewer`, round 1 |
| Lens | db-per-tenant migration safety · tenant isolation · CI allowlist · TS regeneration · manifest union |
| Inputs | BRIEF-C-QR0a · SPEC §1/§2.3 r11 · fiscal gate r1 `2026-08-25-sc-qr0a-gate-r1-fiscal.md` · handback `2026-08-25-sc-qr0a-handback.md` · CLAUDE.md rule 21 + `feedback_push_dev_autodeploys_migrations` |
| Probes | throwaway PG 16 on 5433: `autoerp_test_scqr0a_tn`, `autoerp_test_scqr0a_probe`, `autoerp_test_scqr0a_stale` — ALL DROPPED; worktree restored and verified clean at `135140a87` |

---

## VERDICT

**ACCEPT-WITH-CONDITIONS** — merge-blocking: **YES**, on exactly one condition, and it is
**inherited and orchestrator-owned**: fiscal condition 1 (manifest union) is still open by design,
and dev has moved again under it. **This lens found NO new merge-blocking defect.**

The migration is the safest shape available under db-per-tenant: four `ADD COLUMN … NULL`
statements, no default, no NOT NULL, no CHECK, no index, no backfill, no data read. Verified by
`information_schema` / `pg_constraint` on a real migrated PostgreSQL 16 database, not from the
handback. Idempotent under re-run, `down()` clean and itself idempotent, abort loud and strictly
before any DDL. Nothing outside the two Eloquent casts references the new columns anywhere in
`apps/api/app`, `apps/api/database`, `apps/api/routes`, `apps/web/src`, `apps/pos/src`. No central
table, no connection pin, no cross-tenant query, no route, no permission, no middleware, no
`can:` guard, no module gate, no queued job, no money/quantity surface. Fiscal r1 conditions 2 and
3 are **both confirmed by execution** — no fiscal re-gate is needed.

---

## Legs (all executed in this gate; nothing taken from the handback)

| # | Leg | Result |
|---|---|---|
| L-1 | Lane tests, sqlite (`phpunit.xml`, by path, all three files `ls`-confirmed present) | **27 tests, 60 assertions, 7 skipped, OK** |
| L-2 | Lane tests, PostgreSQL 16 (`phpunit-pgsql.xml`, throwaway `autoerp_test_scqr0a_tn`) | **27 tests, 104 assertions, 0 skipped, OK** |
| L-3 | `information_schema` shape on a real migrated PG DB (`autoerp_test_scqr0a_probe`, full `artisan migrate`) | 4/4 columns `is_nullable=YES`, `column_default` NULL, `varchar(32)` ×3 + **`jsonb`** ×1 |
| L-4 | `pg_constraint` CHECK scan + `pg_indexes` scan referencing the new columns | **0 rows** each — no CHECK, no index |
| L-5 | Idempotence + rollback by direct `up()`/`down()` invocation on PG | `down()` → 4 absent; `up()`×2 → 4 present; `down()`×2 → 4 absent. No error either way |
| L-6 | **F-4 abort probe** — dropped `country_document_settings`, dropped `documents.fiscal_authority_status`, called `up()` | Threw `RuntimeException` naming `country_document_settings` and carrying the `new_columns_present` hint; `documents.fiscal_authority_status` **ABSENT** after the refusal |
| L-7 | Executed census, real tenant data (clone of a stale tenant with 1456 documents) | `{"settings_rows":0,"document_rows":1456,"posted_documents":442,"authority_scope_documents":845,"new_columns_before":0,"new_columns_after":4}` — real counts, and 0→4 distinguishable from 4→4 (both observed) |
| L-8 | **Partially-migrated fleet probe** — cloned `tenant019fbe86…` (stuck at `2026_08_06_100000`) and migrated the clone forward | `create_country_document_settings_table` DONE, then `add_fiscal_authority_columns_unactivated` DONE; `new_columns_present = 4`. **No abort.** |
| L-9 | Local fleet census (13 tenant DBs, read-only) for the abort population `recorded ∧ ¬table` | **0 tenants**. 7 = `false/false` (prerequisite pending → creates then extends), 6 = `true/true` |
| L-10 | Consumer grep (`fiscal_authority_*`, `policy_expertise_status`, all 4 PHP symbols) across api app/database/routes + web + pos + shared | Only `Document.php`, `CountryDocumentSettings.php`, the migration, and `generated.d.ts`. **No reader.** |
| L-11 | Central-boundary check: `$connection` pin on either model; new columns on any central migration | None; migration lives only in `database/migrations/tenant/` |
| L-12 | CI allowlist — both classes present, anchored, uniquely matched | `ci.yml:1019`; manifest checker asserts *"every `--filter` entry is anchored and uniquely matched against 1828 test classes"* → **PASS** |
| L-13 | `backend-test-pgsql` trigger condition | `ci.yml:588` — `workflow_dispatch ∨ base_ref==main ∨ base_ref==dev ∨ (push ∧ ref==refs/heads/main)`. **Does NOT run on push→dev.** S-14 leg owed |
| L-14 | `ci.yml` YAML validity | Parses; 26 jobs; 1 filter step |
| L-15 | Manifest checker, lane branch | OK — 1431 Feature classes / 74 groups; parked **1180** (= committed `gated_ceiling`) |
| L-16 | Manifest checker, current local `dev` `bd03bd8cb` | OK — 1436 Feature classes / 74 groups; parked **1183** |
| L-17 | TS regeneration (rule 7): re-ran `CACHE_STORE=array php artisan typescript:transform` on the lane HEAD | `Transformed 529 PHP types` → **`git status` empty, `git diff --stat` empty**. Committed file is exactly what the transform produces; no hand edit |
| L-18 | Attribution of the 7 non-lane `generated.d.ts` entries | All 5 checked enums exist at base `351802aac` — inherited drift, correctly attributed to other lanes (handback R-3) |
| L-19 | PHPStan level 8, touched paths (8 files) | `[OK] No errors` |
| L-20 | Pint `--test`, touched paths (11 files) | `{"result":"pass"}` |
| L-21 | deptrac, lane vs current dev | **183 violations on both** — lane introduces zero |
| L-22 | `enforce_document_immutability()` body read live from `pg_proc` | Field-by-field allowlist, not row-wise. Adding a column changes nothing about existing sealed-document behaviour — no regression |
| L-23 | Rule-19 / rule-20 / PG-UUID surfaces | None. No money, no quantity, no scale resolver, no queued job, no `latestOfMany`/`ofMany`, no uuid-column comparison |
| L-24 | Rule-12 surfaces (routes / `can:` / `module:` / permission seeder) | **Zero touched files.** No route group, no permission, no module gate in the diff |

---

## Findings

### [IMPORTANT — MERGE-BLOCKING, inherited + orchestrator-owned] T-1 · `apps/api/tests/feature-lane-manifest.json:9` — the committed `gated_ceiling` is stale a third time

Committed `1180`; base `351802aac` was `1178`; the fiscal gate measured dev at `1182` and computed a
merge value of `1184`; **current local `dev` `bd03bd8cb` measures `1183` (L-16), so the merge value is
now `1185`**. `groups.Document.classes` is `86` on dev and `88` on the lane; the union stays `88`.
The lane branch's own checker is green at `1180` (L-15) because that is this branch's true parked
count — the number only goes wrong at the squash. Merging as committed makes the merge commit
CI-red on `FeatureLaneManifestCheckerTest`.

This is fiscal condition 1, deliberately not actioned by the lane (its own Document note says the
numbers are *"RE-DERIVED BY THE ORCHESTRATOR AT MERGE"*), and it is correct that the lane did not
guess. It remains merge-blocking. dev has now moved under this union **four times**; re-derive at
the squash commit itself, not from this record.

### [IMPORTANT — no code change requested; record it] T-2 · `apps/api/docker/entrypoint.sh:141-145` + `RollingTenantMigrationCommand.php` — the new abort blocks the whole TAIL of a failing tenant's migration queue, and the deploy still reports success

The F-4 refusal is right and I verified it fires cleanly (L-6). Its blast radius under the staging
auto-deploy needs to be on the record, because two mechanisms compose:

1. `RollingTenantMigrationCommand::migrateSingleTenant()` shells `tenants:migrate` per tenant. If
   this migration throws, **that tenant's whole pass aborts** — so it also never receives
   `2026_08_25_120000_add_count_movement_markers_to_counting_items`,
   `2026_08_25_120000_retype_sales_discount_accounts_as_contra_revenue`,
   `2026_08_25_140000_seed_count_correction_gl_posting_default`, or anything added later. The tenant
   is frozen at this migration until an operator intervenes. (Other tenants are correctly isolated —
   the command is continue-and-collect-errors and returns `FAILURE`.)
2. `entrypoint.sh:141` swallows that non-zero exit: `if … tenants:migrate-rolling --force; then …
   else echo "  Tenant migrations: [completed with per-tenant errors - check logs]"; fi`. **The
   container boots green.** Nothing pages. The only signal is a line in the deploy log.

Realistic exposure is low and I measured it rather than assuming: the abort needs the state
`2026_08_10_120000` *recorded* ∧ `country_document_settings` *absent*, and **0 of 13 local tenant
databases are in it** (L-9). The 7 stale tenants (at `2026_08_06`/`08_07`/`08_08`) have the
prerequisite **pending**, and Laravel migrates in filename order, so `2026_08_10_120000` runs first
in the same pass — proven end-to-end on a clone in L-8, which reached `new_columns_present = 4`
with no abort. The dangerous state is a restored snapshot or a hand-edited `migrations` table, and
the staging/production fleet is **unverified** (the census is local-only, handback R-2).

Not a defect in the migration — the alternative (half-apply and record as run) is strictly worse and
is exactly what fiscal F-4 closed. But the promotion carrying this must read the `tenants:migrate`
section of the staging deploy log rather than trusting a green boot.

### [MINOR] T-3 · `.github/workflows/ci.yml:997` and the `groups.Document` manifest note — "Four of its six cases" is wrong on both numbers

Both justifications say *"Four of its six cases `markTestSkipped` on SQLite"*.
`StagedDeploymentBootTest` has **8** test methods and **6** call `skipUnlessPostgres()` (counted in
the file; corroborated by L-1, where the three files skip 7 total = 6 here + 1 in
`AuthoritySchemaUnactivatedStateTest::test_the_canonical_form_is_comparable_with_sql_equality`).
The *argument* is stronger than stated, not weaker — but these comments are the standing record of
why a parked class was allowlisted, and the next person to audit the allowlist will check the count.

### [MINOR] T-4 · `packages/shared/types/generated.d.ts` — 7 inherited entries ride along, and a parallel regenerating lane will conflict

The regeneration correctly picks up `PosVatRefusalReason`, `FiscalPeriodReopenRefusalCode`,
`AllocationRefusalReason`, `AllocationTreatment`, `HistoricalOpeningSide`,
`CategoryResolutionOutcome` and `StockMovementReferenceType += batch_ledger_repair`. All five I
checked exist at base `351802aac` (L-18), so these are other lanes' un-regenerated output, not
contamination, and hand-trimming would violate rule 7. Two consequences for the orchestrator: any
other in-flight lane that also regenerates will conflict on this file, and dev's
`generated.d.ts` was stale before this lane — worth a sweep rather than letting the next lane
inherit the same tail.

### [MINOR] T-5 · handback §8 R-1 — stale text contradicted only 200 lines later

§8 R-1 still reads *"this lane did NOT add them to the `backend-test-pgsql --filter` allowlist …
Recommend the gates rule on whether it should be allowlisted"*; the §"Residuals after fix round r1"
tail correctly closes it. A reader who stops at §8 gets the pre-fix picture. Cosmetic.

---

## Fiscal gate r1 conditions 2 and 3 — CONFIRMATION BY EXECUTION

**Condition 2 (`json` → `jsonb`): CONFIRMED — YES.**
`information_schema.columns` on a real migrated PostgreSQL 16 database (L-3) reports
`country_document_settings.fiscal_authority_types` → `data_type = jsonb`, `udt_name = jsonb`,
`is_nullable = YES`, `column_default` NULL. The migration says so at
`2026_08_25_000100_add_fiscal_authority_columns_unactivated.php:127` (`$table->jsonb(...)`) with the
42883 rationale at :121-126. Two PG-only tests execute the consequence and both pass on PG (L-2):
`StagedDeploymentBootTest::test_the_authority_types_column_is_jsonb` and
`AuthoritySchemaUnactivatedStateTest::test_the_canonical_form_is_comparable_with_sql_equality`,
the latter proving `WHERE fiscal_authority_types = ?::jsonb` both matches the canonical order and
does NOT match the reversed one — the byte-for-byte comparison claim is now executable, which is
what F-2 asked for.

**Condition 3 (CI allowlist): CONFIRMED — YES, with the stated caveat.**
Both classes are named at `.github/workflows/ci.yml:1019`, inside the
`--filter='/\\(…|StagedDeploymentBootTest|AuthoritySchemaUnactivatedStateTest)::/'` alternation, so
each entry is namespace-anchored (`\` before the class name) and terminated by `::`. Uniqueness is
not asserted by eye: `tools/feature-lane-manifest-check.php` passed (L-12/L-15) and its own success
line states *"every `--filter` entry is anchored and uniquely matched against 1828 test classes
across all suites"*. The standard removal comment is present at `ci.yml:989-1005` and the
`groups.Document` manifest note carries the matching FIX ROUND r1 paragraph.
**Caveat, verified independently (L-13):** `backend-test-pgsql`'s `if:` at `ci.yml:588` excludes
`push → dev`, so the two classes arm on PRs and `main` pushes but **not** on a direct dev
promotion. The comment states this accurately. **S-14 dispatch-verification leg is owed on the
promotion carrying this**, since `ci.yml` is a workflow-touching surface.

**No fiscal re-gate is required on conditions 2 or 3.**

---

## Merge-time manifest numbers (the orchestrator must write these at the squash)

Measured this gate, by running the checker on both sides:

| | value | source |
|---|---|---|
| local `dev` `bd03bd8cb` — parked classes | **1183** | `tools/feature-lane-manifest-check.php` on the dev checkout (L-16) |
| local `dev` `bd03bd8cb` — `groups.Document.classes` | **86** | `git show dev:…/feature-lane-manifest.json` |
| lane delta | **+2**, both in `Feature/Document` | `AuthoritySchemaUnactivatedStateTest`, `StagedDeploymentBootTest`; no other group changed |
| **merge-time `gated_ceiling`** | **1185** | 1183 + 2 |
| **merge-time `groups.Document.classes`** | **88** | 86 + 2 |

Committed on the lane: `gated_ceiling` **1180**, `Document` **88**. The fiscal gate's `1184` was
computed against dev `9f2aed21e`/`42f7f8bad`; dev has moved again to `bd03bd8cb`. **`Document` 88 is
stable across all three rounds; only `gated_ceiling` drifts.** Take every other group's entry from
dev verbatim. If dev moves again before the squash — it has moved under this union four times, and
once during this gate — **re-derive at the merge commit; do not copy 1185 from this record.**

---

## Conditions

1. **[MERGE-BLOCKING]** At the squash commit, re-derive and set `gated_ceiling` = dev's measured
   parked count + 2 (**today: 1185**) and `groups.Document.classes` 86 → **88** (T-1; = fiscal
   condition 1, still open). Re-measure rather than copying 1185.
2. **[non-blocking, LEDGER/promotion]** Record against the promotion carrying this: the staging
   deploy will **not** fail if a tenant aborts on this migration — `entrypoint.sh:141-145` swallows
   the rolling command's non-zero exit — and an aborting tenant also stops receiving every LATER
   tenant migration. Read the `tenants:migrate-rolling` section of the deploy log for
   `C-QR0a cannot apply on this tenant`, and re-run that tenant after landing the prerequisite (T-2).
   This composes with, and does not replace, fiscal condition 4's C-QR0b preflight
   (`new_columns_present = 4` per tenant).
3. **[non-blocking, docs]** Fix "Four of its six cases" → "six of its eight cases" at
   `.github/workflows/ci.yml:997` and in the `groups.Document` manifest note (T-3); refresh handback
   §8 R-1 to point at its own fix-round closure (T-5). Docs-only, no re-gate.
4. **[non-blocking, orchestrator]** Expect a `packages/shared/types/generated.d.ts` conflict if any
   parallel lane regenerates; dev's copy was stale by 7 entries before this lane (T-4).

---

## Probe hygiene

`autoerp_test_scqr0a_tn`, `autoerp_test_scqr0a_probe` and `autoerp_test_scqr0a_stale` created on
127.0.0.1:5433 and **all three dropped** (`select datname … like '%scqr0a%'` returns empty). The
stale-fleet probe ran against a `TEMPLATE` **clone**; no real tenant database was written. The
worktree was written once (the rule-7 transform re-run, L-17) and restored: `git status --short`
empty at `135140a87`. The only file written outside the worktree is this record.

**VERDICT: spec ✅ + quality APPROVED (ACCEPT-WITH-CONDITIONS; merge-blocking = condition 1 only, inherited and orchestrator-owned)**

What to fix before merge: re-derive the manifest union at the squash (`gated_ceiling` → dev's parked count + 2, **1185** today; `Document` → **88**) — nothing else blocks.
