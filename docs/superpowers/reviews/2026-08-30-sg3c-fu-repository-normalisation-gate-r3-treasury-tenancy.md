# SG-3c-FU adversarial gate r3 — treasury + tenancy stand-in

Date: 2026-08-30  
Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sg3c-fu`  
Branch/base: `feat/sg3c-fu-repository-normalisation` / `e8d0ac870aa7e574e390372f6dc3ebc16393dabf`  
Review mode: source read-only; only this r3 register was written. No skill/plugin file was written. No full suite was run. PostgreSQL commands used `DB_DATABASE=autoerp_test_g6 DB_CENTRAL_DATABASE=autoerp_test_g6` and ran serially.

The r1 and r2 registers and the supplied summary's `## Fix round 2` were reviewed before the current source and migrations.

## Status

| ID | status | r3 evidence |
|---|---|---|
| SG3CFU-R2-01 | **FIXED** | `payment_instruments.deposited_to_id` is the sixteenth entry in the authoritative `REFERENCE_SURFACES` constant (`RepositoryCensusService.php:19-43`). Both census classification and the immediate apply-time recheck call `isMoneyBearing()` -> `hasReference()` -> that same constant (`RepositoryCensusService.php:133,184-212`; `NormaliseRepositoriesCommand.php:164-200`). The runbook names the 14-table/16-column boundary and deposit destination (`docs/modules/treasury.md:267-281`). The data provider includes `payment_instruments.deposited_to_id` and its fixture writes only that relation; the shared assertions prove exact money-bearing classification, no clean finding, failure exit, `RepositoryTransfer` guidance, no metadata action, identical repository snapshot, and an active surplus (`RepositoryNormalisationTest.php:52-70,271-332,881-1113`, especially `1006-1012`). SQLite and PostgreSQL path runs passed all 16 provider cases. |

No new blocking finding was opened in r3.

## Live-schema boundary audit

The boundary test is schema-derived rather than a second hand-maintained surface list:

- It reads every tenant migration only to collect candidate column-name spellings (`RepositoryNormalisationTest.php:74-82`).
- It enumerates the migrated database with `Schema::getTables()`, then each table's live foreign keys and live column listing (`RepositoryNormalisationTest.php:84-110`).
- A live column is included when its name matches the repository predicate or its live foreign key targets `payment_repositories` (`RepositoryNormalisationTest.php:87-103`).
- It reflects the production `REFERENCE_SURFACES`, sorts both independently built sets, and requires exact equality (`RepositoryNormalisationTest.php:112-122`). A newly migrated `*_repository_id`/`repository_id`/`deposited_to_id` column, or a foreign-key column of any name targeting `payment_repositories`, therefore makes the test fail until the production census is updated.

The predicate was independently reconciled against all tenant-migration matches from `repository_id|deposited_to_id|on('payment_repositories')` and against all occurrences of `payment_repositories`:

| Tenant table | Direct repository column(s) | Predicate result |
|---|---|---|
| `repository_movements` | `payment_repository_id` | matched |
| `payments` | `repository_id` | matched |
| `repository_adjustments` | `payment_repository_id` | matched |
| `bank_statements` | `payment_repository_id` | matched |
| `bank_statement_lines` | `payment_repository_id` | matched |
| `expense_metadata` | `payment_repository_id` | matched |
| `income_metadata` | `payment_repository_id` | matched |
| `payment_methods` | `default_repository_id` | matched |
| `payment_instruments` | `repository_id`; `deposited_to_id` | both matched |
| `instrument_events` | `from_repository_id`; `to_repository_id` | both matched |
| `instrument_remittances` | `bank_repository_id` | matched |
| `statement_import_profiles` | `payment_repository_id` | matched |
| `bank_reconciliations` | `repository_id` | matched |
| `expense_recurrence_templates` | `payment_repository_id` | matched |

**Current predicate misses: none.** The grep also finds N-12's `new_repository_id`, but that is a log payload key at `2026_08_26_100000_backfill_payment_repository_location_n12.php:268`, not a live column. The structural guarantee has one explicit future-schema assumption: a bare UUID that semantically references a repository but has neither a foreign key nor a matching repository-oriented name would not be discoverable from live schema metadata. No such current tenant-migration column was found; `deposited_to_id` is the current exceptional bare UUID and is explicitly matched.

The schema-boundary test passed on both SQLite and PostgreSQL. The PostgreSQL run is material evidence that live foreign-key/schema introspection works on the production database family, not only on SQLite.

## Fix-round-1 regression audit

| R1 ID | r3 status | evidence |
|---|---|---|
| SG3CFU-R1-02 | **REMAINS VERIFIED** | Money-bearing findings are printed before the per-location guard and control failure (`NormaliseRepositoriesCommand.php:139-162`). The PostgreSQL combined pre-index duplicate + non-zero surplus proof still asserts both codes, failure, transfer guidance, and unchanged active rows (`RepositoryNormalisationTest.php:736-853`). |
| SG3CFU-R1-03 | **REMAINS VERIFIED** | Both repository metadata updates reassert `tenant_id`, `company_id`, and repository id (`NormaliseRepositoriesCommand.php:279-296`). The mismatched-tenant reflection proof remains at `RepositoryNormalisationTest.php:623-654`. |
| SG3CFU-R1-05 | **R2 RULING REMAINS VERIFIED** | Apply takes the PostgreSQL company advisory transaction lock and repository row locks (`NormaliseRepositoriesCommand.php:120-128,299-304`), then rechecks through the same exhaustive service predicate immediately before each action (`NormaliseRepositoriesCommand.php:164-200`; `RepositoryCensusService.php:184-212`). The injected late-reference proof still refuses and leaves the safe active (`RepositoryNormalisationTest.php:567-621`). The previously accepted residual non-participating-writer window remains documented at command lines 31-34. |
| SG3CFU-R1-06 | **REMAINS VERIFIED** | Every completed per-company acting path logs `repositories.normalised`, including per-location skip and ordinary completion (`NormaliseRepositoriesCommand.php:157-159,218-220,311-332`). No-op and refused-only exact contexts remain pinned at `RepositoryNormalisationTest.php:491-565`. |

## Verification outputs

All suites were invoked by explicit path, one process at a time. No full suite was run.

| Check | Result |
|---|---|
| SQLite `php artisan test tests/Feature/Treasury/RepositoryNormalisationTest.php` | **PASS** — 26 passed, 1 PostgreSQL-only skipped, 236 assertions; duration 12.28s |
| PostgreSQL `DB_DATABASE=autoerp_test_g6 DB_CENTRAL_DATABASE=autoerp_test_g6 php artisan test -c phpunit-pgsql.xml tests/Feature/Treasury/RepositoryNormalisationTest.php` | **PASS** — 27 passed, 252 assertions; duration 25.58s |
| PostgreSQL `DB_DATABASE=autoerp_test_g6 DB_CENTRAL_DATABASE=autoerp_test_g6 php artisan test -c phpunit-pgsql.xml tests/Feature/Treasury/PaymentRepositoryLocationTest.php` | **PASS** — 5 passed, 17 assertions; duration 15.60s |
| `./vendor/bin/pint --test` | **PASS** — `{"result":"pass"}` |
| PHPStan on all 11 touched Treasury production PHP files | **PASS** — `[OK] No errors` using the worktree's `phpstan.neon` |
| `php tools/feature-lane-manifest-check.php` | **PASS** — 1489 Feature classes in 74 groups; every disposition/lane/filter valid and each filter uniquely matched against 1896 test classes |
| Manifest requested values | **PASS** — `gated_ceiling: 1227`; `groups.Treasury.classes: 124` |
| `git diff --check` | **PASS** — exit 0, no output |
| Migration/web/POS diff and untracked audit | **PASS** — no lane file under `apps/api/database/migrations`, `apps/web`, or `apps/pos` |
| Test-artifact audit | **PASS** — no `apps/api/autoerp_test_*`; post-test `git status --short` exactly matched the initial dirty worktree status |

An initial pre-test artifact probe was issued from `apps/api` with the redundant relative path `apps/api` and printed `find: apps/api: No such file or directory`; it did not affect the immediately following SQLite test. The probe was repeated correctly from the worktree root before final adjudication and returned no artifact.

## Read-only, tenancy, STOP-condition, and artifact audit

- Census path: no create/insert/upsert/update/save/delete, schema, transaction, movement, journal, or `DB::statement` call exists in the census command, service, DTOs, or enums. The SELECT-only query-listener proof passed on SQLite and PostgreSQL.
- Reference safety: all 16 references flow through one production list. Apply-time checks do not duplicate or narrow that list.
- Tenancy: both final metadata updates retain the r2 `tenant_id` + `company_id` + repository-id write boundary; the regression proof passed on both databases.
- Normaliser writes: only `is_active`, `gl_account_id`, and `updated_at` can be changed (`NormaliseRepositoriesCommand.php:279-296`). There is no balance write, repository `location_id` write, delete, repository movement creation, journal-entry/line creation, or GL posting. The `gl_account_id` change is the intended canonical-safe metadata link, not a ledger write.
- N-12 stand-in: `BackfillLocationAttributionCommand.php:82-149` reads repository attribution and writes NULL `location_id` only on `payment_instruments` and `payments`; it never writes `payment_repositories.location_id`.
- Schema/constraints: the lane contains no migration, index, or constraint change. The requested PostgreSQL location/partial-unique path passed.
- Web/POS/artifacts: no lane web or POS change and no database file artifact. The worktree remained dirty in exactly its pre-gate set; no source file was modified by this review.

No STOP condition was hit.

## VERDICT

**PASS**

SG3CFU-R2-01 is closed across census, apply-time recheck, documentation, and table-driven proof. The live-schema equality guard covers every current tenant-migration repository reference, the fix-round-1 items requested for regression remain verified, all required serial path checks are green, and the treasury/tenancy STOP conditions remain intact.
