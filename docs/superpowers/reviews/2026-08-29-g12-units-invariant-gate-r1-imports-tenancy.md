# G-12 Units invariant — adversarial gate r1 (imports + tenancy)

**Lane:** `feat/g12-units-invariant`  
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/g12-units`  
**Fork / reviewed HEAD:** `903c021411eb77b8bf7927a07fa96397b28c9611` (dirty, uncommitted)  
**Review mode:** source read-only; reviewed `git status --short`, tracked diff from the fork, and every untracked file.  
**VERDICT: CHANGES**

## Findings register

| ID | severity | file:line | finding | change |
|---|---|---|---|---|
| G12-R1-01 | **BLOCKER** | `apps/api/database/migrations/tenant/2026_08_30_100300_ensure_units_visible_per_company.php:22-87`; `apps/api/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migration.php:14-19`; `apps/api/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:253-258,448-450` | The migration catches every `Throwable` **inside Laravel's default outer migration transaction**, after calling the non-idempotent bare-create seeder directly at `:75`. If a non-database exception occurs after some inserts, `up()` returns and the outer transaction can commit a half-state. If PostgreSQL aborts the transaction on a statement error, the swallowed exception can leave the seed rolled back/transaction unusable while `runUp()` proceeds to record the migration because `up()` returned. Either outcome defeats the one-shot 19-unit/5-category invariant and automatic retry. The catch also logs only `warning` with the message at `:82-86`, dropping the exception object/stack; that is not acceptable observability for a swallowed fleet-migration bug. The existing migration tests cover success, half-state, and re-run, but never a mid-seed failure (`UnitsInvariantTest.php:22-119`). | Make the migration explicitly non-transactional at the framework level, then put **only the canonical seeder call** in an explicit `DB::transaction(...)` so a caught seed failure rolls back atomically without poisoning an outer PG transaction. Keep the fleet no-throw rule, but emit `Log::error('units.visibility_migration_failed', ['exception' => $exception, ...])`. Add a failure-injection test proving: no throw, error-level log retains the exception, no partial rows remain, and import refusal still protects the tenant. |
| G12-R1-02 | **MAJOR (frontend-owned follow-on)** | `apps/web/src/features/import/api/queries.ts:107-131`; `apps/web/src/lib/api.ts:83-92,314-361`; backend source `apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:123-134` | The backend returns an actionable coded 422, but the live wizard does not display its message. The interceptor returns the `AxiosError`; `useCreateImport.onError` toasts `error.message`, so the operator sees Axios's generic **“Request failed with status code 422”**, not **“seed them in Settings → Units before importing.”** The API is loud; the current UI is not actionable. This lane is explicitly backend-only, so the finding must not be “fixed” by adding an `apps/web` diff to G-12. | Book this in the wizard-owning F1/G-6b follow-on: type the error as `unknown`, toast `getErrorMessage(error)`, and add a wizard test for the `units_not_seeded` envelope/message. G-12 should remain backend-only. |

## Contract verification

### Provisioning and canonical set

- `UnitsProvisioningService::visibleActiveUnitCount()` is the one application-method predicate (`UnitsProvisioningService.php:14-30`): active rows where `tenant_id IS NULL OR tenant_id = $company->tenant_id`. `hasVisibleUnits()` delegates to it. The migration intentionally freezes the same historical predicate inline at `2026_08_30_100300...php:42-51`.
- `provisionForCompany()` seeds only when **both** tables are globally empty (`UnitsProvisioningService.php:39-43`), calls `UomSeeder` directly rather than duplicating its data (`:43`), returns without writes when visible units exist (`:35-37`), and logs/returns on half-state without throwing (`:50-54`).
- The canonical source creates 19 units and 5 categories; `pc` is created at `database/seeders/UomSeeder.php:181-201`. It is asserted in both provisioning and migration tests (`UnitsProvisioningTest.php:91-94,150`; `UnitsInvariantTest.php:40-42`).
- No `company_id` column, index, model fillable, or unit-scope write was added. The only `company_id` occurrences in the new service/migration are log text/context. RUL-7 option (b) remains the follow-on G-13 lane.
- Successful calls are sequentially idempotent: a populated visible set returns before seeding. Half-state is logged and untouched. The canonical data is not duplicated in G-12.

### Every company-creation path

- In-tenant create: `CompanyController::store()` wraps creation and all provisioning in `DB::transaction` (`CompanyController.php:80-191`) and calls units immediately after company tax at `:185-188`. A seeding exception therefore rolls back the new company and its dependent rows. That is correct: committing a company without the required reference set would violate the invariant.
- Registration initializer: `TenantInitializationService::initializeForNewRegistration()` passes the company into reference-data seeding (`TenantInitializationService.php:60-84`), which delegates to the same service at `:239,261-264`.
- Database-per-tenant registration reaches it through `TenantProvisioningService::provisionForRegistration()` at `TenantProvisioningService.php:195-197`; its catch compensates and rethrows on failure at `:219-223`.
- Shared-DB registration reaches it inside `AuthController`'s transaction (`AuthController.php:355-362,483-492,516`).
- The new test directly pins the initializer path and `pc` (`UnitsProvisioningTest.php:128-150`); the two outer call chains were additionally confirmed by reading the concrete calls above. Company route creation and its existing event/tax behavior passed by path.

### Migration behavior and staging safety

- Guards exist for all three tables and for a non-empty `companies` table (`2026_08_30_100300...php:24-33`). After those pre-provisioning guards, the census is unconditional per company, logs `units.empty_for_company`, then logs/echoes the aggregate (`:35-65`).
- It seeds only when there are empty companies **and** both reference tables are fully empty (`:67-75`); half-state is census-logged and left untouched. A successful re-run sees `empty=0` and writes nothing. `down()` is a logged forward-only no-op (`:90-97`).
- Deliberate brief-over-spec drift is present: spec M8 says “never seeds”; the dispatched G-12 brief overrides it to seed fully empty provisioned tenants while preserving “never refuses.”
- The supplied local census is **15 tenant databases / 17 companies**: **8 databases / 9 companies** are fully unit-less and seedable; **0 half-states**. This is local evidence, not a claim about staging counts.
- On push, every staging tenant runs the migration. Populated tenants get census-only/no writes; fully empty provisioned tenants get the canonical 19 units/5 categories once and a `units-seeded company_id=...` log for each covered company; half-states get empty-company warnings but no mutation; pre-provisioning/missing-table databases return. Under a staging fleet shaped like the local census, 8 databases covering 9 companies would be seeded.
- Intended abort path: none for ordinary caught `Throwable`; process death/OOM/logger failure remains outside that guarantee. **As coded, G12-R1-01 means a database/seeder failure is not safely atomic or reliably observable and may leave the migration recorded without a complete seed. Do not promote this migration until fixed.**

### Import refusal, vocabulary, and tenant authorization

- `ImportType::Products` is the only current type with optional column `unit` (`ImportType.php:106-130`; Products at `:125`, CompositeItems at `:129` without it). Both checks derive the gate from `getOptionalColumns()` and do not hardcode Products.
- Upload refusal occurs after type deprecation and before storage, parsing, or job creation (`ImportController.php:114-156`). Exact body:

  ```json
  {
    "error": {
      "code": "units_not_seeded",
      "message": "No units of measure are configured for this company; seed them in Settings → Units before importing",
      "details": { "company_id": "<uuid>" }
    }
  }
  ```

  This is the same existing coded-envelope shape as `IMPORT_NOT_EXECUTABLE` (`ImportController.php:512-521`): top-level `error` with `code`, `message`, and `details`, HTTP 422.
- Worker re-verification is immediately after the tenant-pinned company lookup and before processing (`ProcessImportJob.php:100-122`). It uses the required leading token `units_not_seeded:` and `failJob()` persists it in `error_message` (`:293-299`).
- Rule 20 is respected: the worker never reads `CompanyContext`; it explicitly loads `Company` with both serialized tenant and company IDs (`ProcessImportJob.php:100-103`) and passes that entity to the service (`:111-117`). The test clears `CompanyContext` before `handle()` (`UnitsNotSeededRefusalTest.php:127-140`).
- HTTP tenancy/authz is unchanged and sound: imports require the API/auth/token-claim/`imports.manage` route stack (`ImportServiceProvider.php:65-80`); the API group includes `CompanyContextMiddleware` (`bootstrap/app.php:143-148`), which validates active company access before setting context (`CompanyContextMiddleware.php:122-145`).
- `ImportErrorCode` is in the specified namespace/location with only `UnitsNotSeeded = 'units_not_seeded'` and an exhaustive no-default `isJobLevel()` match (`ImportErrorCode.php:5-21`). Its docblock records G-12 creation under §3.1d and the later G-6a/G-4/G-2/G-8/G-1/G-5 case additions (`:7-11`). G-6a must union its cases, not replace this file.

### Five edited existing fixtures

- `ImportReExecutionGuardTest.php`: unit fixture plus new `handle()` dependency only; no assertion changed.
- `OpeningBalancesImportBatchTest.php`: new `handle()` dependency only; no assertion changed (the type has no `unit` column, so no seed is necessary).
- `ProcessImportJobStatusTest.php`: unit fixture plus new `handle()` dependency only; no assertion changed. The failing Parties assertion is still the same assertion, shifted from base line 213 to worktree line 218.
- `ProductPlacementImportTest.php`: unit fixture plus new `handle()` dependency only; no assertion changed.
- `ProductsImportPipelineTest.php`: adds the unit fixture, plus PHPStan-oriented query typing/null-safety and a numeric-string decimal helper. The decimal helper rejects null/non-numeric values before the same `bccomp(..., 2) === 0` assertion; surrounding row assertions still fail if a row is absent. No behavioral assertion was weakened, but this file was not changed **only** to seed units.
- Worktree five-file run: 54 tests / 418 assertions, exactly one failure at `ProcessImportJobStatusTest.php:218` (Parties historical-document count, expected 1, got 0). Main checkout `dev` at `3d296ab3145acdaaed61afc6c85a1d9733a9db86` reproduces the same failure at its line 213 (4 tests / 11 assertions, one failure). The main checkout has advanced beyond fork `903c02141`; therefore the fresh runtime proof is “present on current dev and unchanged by G-12,” not a claim that a detached execution of the literal fork SHA was performed. The G-12 diff itself changes only fixture/dependency wiring in that test.

## Verification command outputs

| command | result |
|---|---|
| `./vendor/bin/phpunit tests/Feature/Uom/UnitsInvariantTest.php tests/Feature/Uom/UnitsProvisioningTest.php tests/Feature/Import/UnitsNotSeededRefusalTest.php` | **PASS** — 14 tests, 67 assertions |
| Five edited import fixtures, all five paths in one PHPUnit invocation | **KNOWN INHERITED RED** — 54 tests, 418 assertions, 1 Parties count failure; all other tests passed |
| Main `dev`: `./vendor/bin/phpunit tests/Feature/Import/ProcessImportJobStatusTest.php` | **SAME RED** — 4 tests, 11 assertions, 1 identical Parties count failure |
| `./vendor/bin/phpunit tests/Feature/Company/CreateCompanyTest.php tests/Feature/Company/CompanyCreationEventsTest.php tests/Feature/Company/CompanyStoreTaxTest.php` | **PASS** — 21 tests, 99 assertions |
| `php artisan test -c phpunit-pgsql.xml --filter='/\\(UnitsInvariantTest\|UnitsNotSeededRefusalTest)::/'` | **PASS** — 9 tests, 46 assertions |
| `./vendor/bin/phpstan analyse --no-progress <15 touched PHP paths>` | **PASS** — no errors |
| `./vendor/bin/pint --test <15 touched PHP paths>` | **PASS** — `{"result":"pass"}` |
| `php tools/feature-lane-manifest-check.php` | **PASS** — 1459 Feature classes / 74 groups; 1200 parked/gated; every filter anchored and uniquely matched |
| Manifest arithmetic and on-disk count | **PASS** — fork Uom 6 / Import 18 / gated 1197; worktree and disk Uom 8 / Import 19 / gated 1200 |
| `php vendor/bin/deptrac analyse` | Baseline exit 1: 183 violations, 13442 uncovered, 14296 allowed. JSON inspection found **zero violation entries for any touched production class**, including no new Import→Uom edge |
| CI filter + YAML | **PASS** — `UnitsInvariantTest` and `UnitsNotSeededRefusalTest` are anchored at `.github/workflows/ci.yml:1081-1084`; Symfony YAML parse succeeds |
| `git diff --check 903c02141` | **PASS** — no whitespace errors |

No full suite was run. No source file in either checkout was modified, stashed, committed, or reset by this review.

---

# G-12 Units invariant — adversarial gate r2 (re-check after fix round 1)

**Lane:** `feat/g12-units-invariant`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/g12-units` (HEAD `903c021411eb77b8bf7927a07fa96397b28c9611`, still DIRTY/uncommitted — not committed, modified, stashed or reset by this gate)
**Comparison checkout:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api` on `dev` @ `c3b733a4445baf7eae42a7976490fed3bc2ff699` (read-only)
**VERDICT: CHANGES**

## r2 disposition of the r1 register

| r1 ID | r1 severity | r2 disposition |
|---|---|---|
| G12-R1-01 | BLOCKER | **RESOLVED (with a MINOR residual).** Mechanism (b) of the orchestrator's accepted set was implemented: a nested `DB::transaction` (savepoint) inside the migrator's outer transaction. All four acceptance conditions are proven ON POSTGRESQL — see §"R1-01 acceptance matrix". Residual: the OUTER catch still logs at `warning` with the message only (see G12-R2-02). |
| G12-R1-02 | MAJOR (frontend-owned) | **UNCHANGED and correctly deferred.** `git status --short` in the worktree lists zero `apps/web/` paths; the lane stayed backend-only as required. Remains booked to G-6b/G-10. |

## R1-01 acceptance matrix (orchestrator's conditions)

Mechanism verified in framework source, not assumed:
- `vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:448-451` — `up()` IS wrapped in `$connection->transaction($callback)` when the grammar supports schema transactions (PG does) and `$migration->withinTransaction` is true (default). So the seed call is at transaction level 2.
- `vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php` `performRollBack()` — at level > 0 with savepoint support it emits `ROLLBACK TO SAVEPOINT trans{n}`, which is precisely what recovers a PostgreSQL transaction from an aborted statement. Mechanism (b) is real, not nominal.

| condition | where proven | PG result |
|---|---|---|
| mid-seed failure leaves ZERO `units` and ZERO `unit_categories` | `tests/Feature/Uom/UnitsInvariantTest.php:123-145` (`assertSame(0, …)` on both tables at :132-133) | PASS |
| the migration does not throw | same test — `runMigration()` at `:206-219` calls `up()` directly and the test completes; census echo `…empty=1 seed_failed=1` asserted at `:141-144` | PASS |
| an error-level log carries the exception OBJECT | `UnitsInvariantTest.php:134-140` asserts `Log::error('units.seed_failed', ['exception' => $failure, 'tenant' => …])` against the exact injected instance; emitted at `2026_08_30_100300_ensure_units_visible_per_company.php:81-84` | PASS |
| a re-run seeds fully | `UnitsInvariantTest.php:147-164` — second `up()` yields 19 units / 5 categories and echoes `units-seeded company_id=` | PASS |
| `CompanyController::store` — provisioning failure must NOT roll back company creation (nested tx + log), and import-time `units_not_seeded` then catches it — **test present?** | **YES**: `tests/Feature/Uom/UnitsProvisioningTest.php:180-220` — real `POST /api/v1/companies` returns 201, company row survives, both unit tables are 0, `units.seed_failed` logged at error with the exception, then `POST /api/v1/imports` type=products returns 422 `error.code=units_not_seeded` with `error.details.company_id`, and `ImportJob::count() === 0`. Nested boundary at `UnitsProvisioningService.php:52-64`. | PASS |

## Verification command outputs (all run by me in the worktree unless stated)

| command | result |
|---|---|
| `./vendor/bin/phpunit tests/Feature/Uom/UnitsInvariantTest.php tests/Feature/Uom/UnitsProvisioningTest.php tests/Feature/Import/UnitsNotSeededRefusalTest.php` | **PASS — 17 tests, 85 assertions** (r1 was 14/67; +3 tests are the fix-round failure-injection pins) |
| Five touched fixtures in one invocation (`ImportReExecutionGuardTest` `OpeningBalancesImportBatchTest` `ProcessImportJobStatusTest` `ProductPlacementImportTest` `ProductsImportPipelineTest`) | **54 tests, 418 assertions, 1 failure** — `ProcessImportJobStatusTest.php:218` `test_parties_job_posts_ar_opening_batch_after_async_row_loop`, `Failed asserting that 0 is identical to 1` |
| **INHERITED-RED CHECK** — main checkout `dev` `c3b733a44`: `./vendor/bin/phpunit tests/Feature/Import/ProcessImportJobStatusTest.php` | **RED ON DEV TOO — 4 tests, 11 assertions, 1 failure**, same test, same `0 !== 1`, at dev line 213. **The "inherited red" claim is CONFIRMED. Not a G-12 regression.** |
| `./vendor/bin/phpunit tests/Feature/Company/CreateCompanyTest.php tests/Feature/Company/CompanyCreationEventsTest.php tests/Feature/Company/CompanyStoreTaxTest.php` | **PASS — 21 tests, 99 assertions** |
| PostgreSQL (127.0.0.1:5433, dedicated DB `autoerp_g12_r2`): `php artisan test -c phpunit-pgsql.xml --filter='/(UnitsInvariantTest\|UnitsNotSeededRefusalTest\|UnitsProvisioningTest)::/'` | **PASS — 17 passed, 85 assertions, 36.38s.** (The prompt's literal `\\(` was a broken regex; corrected to a real alternation group so all three classes actually matched. All 17 named tests are listed green in the run output, including `mid seed failure is logged and leaves both tables empty`, `failed seed is retried to completion on the next up`, and `unit seed failure rolls back only units and company creation still succeeds`.) |
| `./vendor/bin/phpstan analyse --no-progress <15 touched paths>` | **PASS — `[OK] No errors`** |
| `./vendor/bin/pint --test <15 touched paths>` | **PASS — `{"result":"pass"}`** |
| `php tools/feature-lane-manifest-check.php` | **PASS (exit 0)** — 1459 Feature classes / 74 groups; 1200 parked; every `--filter` entry anchored and uniquely matched against 1859 classes |
| manifest arithmetic | consistent: `gated_ceiling` 1197→1200, `Import` 18→19, `Uom` 6→8 (+3) |
| `php vendor/bin/deptrac analyse` | **BASELINE — 183 violations**, 13444 uncovered, 14296 allowed (baseline is 183: no change) |
| deptrac JSON inspection | 183 error entries parsed; **0 name any touched class** (`UnitsProvisioningService`, `ImportErrorCode`, `CompanyController`, `ProcessImportJob`, `ImportController`, `TenantInitializationService`); **0 entries mention `Uom` at all** — no new Import→Uom edge |
| **`./vendor/bin/phpunit tests/Feature/Jobs/ScheduledJobTenantIsolationTest.php` (worktree)** | **RED — 4 tests, 10 assertions, 1 failure** at `:152` |
| **same file on main checkout `dev` `c3b733a44`** | **GREEN — OK (4 tests, 12 assertions)** |

## Five touched fixtures — fixture-only or assertion changes?

Read the full `git diff` of all five.

- `ImportReExecutionGuardTest.php` (+`provisionForCompany` in `setUp` @94, +2nd `handle()` arg @256-260) — **fixture-only.**
- `OpeningBalancesImportBatchTest.php` (+2nd `handle()` arg @418-422) — **fixture-only.** No unit seeding, correct: the type has no `unit` optional column.
- `ProcessImportJobStatusTest.php` (+`provisionForCompany` in `seedJob` @92, +2nd `handle()` arg @125-131) — **fixture-only.** The failing Parties assertion is byte-identical to dev's, shifted 213→218.
- `ProductPlacementImportTest.php` (+`provisionForCompany` @42, +2nd `handle()` arg @186-192) — **fixture-only.**
- `ProductsImportPipelineTest.php` — **NOT fixture-only.** Beyond the unit fixture @126 it carries PHPStan-driven edits: `ImportJob::findOrFail(...)` → `ImportJob::query()->whereKey(...)->firstOrFail()`, a new `assertDecimalEquals(string $expected, ?string $actual, …)` helper replacing 7 inline `bccomp((string) $x, '…', 2)` calls, and null-safe `?->` on 7 row assertions. I audited each for weakening:
  - The helper **strengthens**: it `self::fail()`s on null/non-numeric before running the same `bccomp($actual, $expected, 2) === 0`. Still bcmath at scale 2 on a decimal string — no float, rule 19 intact.
  - The `?->` rewrites are individually vacuous-pass-capable (`assertNull($rows[2]?->warnings)` and `assertFalse((bool) $rows[1]?->is_valid)` both pass if the row is ABSENT). In every case I checked, an adjacent NON-null-safe assertion in the same test still hard-fails on an absent row (`assertSame('matched', $rows[2]->data[...])` @ProductsImportPipelineTest:704; `assertArrayHasKey('category_name', $rows[1]->errors ?? [])` @:1209 which receives `[]` and fails; `assertSame(1, $rows[1]?->row_number)` @:1211 which fails on null). So no net coverage was lost — but this is unrequested scope in a units lane and the `?->` pattern is one deleted neighbour away from being a silent vacuous pass.

## Findings register (r2)

| ID | severity | file:line | finding | change |
|---|---|---|---|---|
| **G12-R2-01** | **BLOCKER (new regression introduced by this lane)** | `apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php:74-77`; caller `apps/api/tests/Feature/Jobs/ScheduledJobTenantIsolationTest.php:136-139,152-154` | The fix round widened `ProcessImportJob::handle()` from one required parameter to two (`ImportService $importService, UnitsProvisioningService $unitsProvisioning`). The lane updated the five Import fixtures but **missed a sixth caller outside `tests/Feature/Import/`**: `ScheduledJobTenantIsolationTest.php:137` still calls `$instance->handle($this->app->make(ImportService::class))` with ONE argument. PHP raises `ArgumentCountError` **before the method body executes**, and the test's blanket `catch (\Throwable) {}` at `:138` swallows it — so `handle()` never runs, the query log records no `import_jobs` SELECT, and the tenant-isolation assertion at `:152-154` fails. **Proven, not inferred: GREEN on dev `c3b733a44` (4 tests, 12 assertions) vs RED in the worktree (4 tests, 10 assertions, 1 failure).** This is exactly the regression class the gate instruction asked me to hunt. It is also the worst kind: the broken test is the tenant-isolation guard proving the import worker only ever SELECTs `import_jobs` scoped by `tenant_id` — a cross-tenant data-leak guard, silently disarmed. It escaped the lane's own verification because the lane only ran the five files it edited plus its three new ones, and `tests/Feature/Jobs` sits in `feature-lane-platform-misc/Jobs`, which is PARKED behind `vars.SELF_HOSTED_RUNNER_READY` — so no live CI job would have caught it either. | One-line fixture fix: pass the second argument at `ScheduledJobTenantIsolationTest.php:137` — `$instance->handle($this->app->make(ImportService::class), $this->app->make(UnitsProvisioningService::class));` — plus the `use App\Modules\Uom\Application\Services\UnitsProvisioningService;` import. Re-run the file and confirm it returns to **4 tests / 12 assertions, OK** (assertion count must go back UP to 12; 10 means `handle()` still isn't running). While there, consider narrowing that `catch (\Throwable)` to the exceptions it actually means to tolerate, since it is what made this failure mode invisible. |
| **G12-R2-02** | MINOR (residual of G12-R1-01) | `apps/api/database/migrations/tenant/2026_08_30_100300_ensure_units_visible_per_company.php:95-100` | The fix added the required error-level, exception-bearing log to the SEED path (`:81-84`), but the OUTER catch-all still emits `Log::warning('units.visibility_migration_failed', ['error' => $exception->getMessage()])` — message only, no exception object or stack. r1's remedy asked for `Log::error(..., ['exception' => $exception, …])` here too. This catch covers the census/guard block (`:25-74`), which on PostgreSQL can abort the outer migrator transaction; `up()` then returns normally, the migrator commits (a no-op rollback on an aborted PG tx) and `Migrator::runUp` records the migration as run at `Migrator.php:255`. So a census-phase failure is recorded as a successful migration with only a warning-level, stackless breadcrumb. No writes are at risk (the only writes are inside the savepoint), so this is observability, not corruption. | Upgrade `:96-98` to `Log::error('units.visibility_migration_failed', ['exception' => $exception, 'tenant' => $tenantId])`, matching `:81-84`. |
| **G12-R2-03** | MINOR | `apps/api/tests/feature-lane-manifest.json` Uom note (`classes` 6→8); `.github/workflows/ci.yml:1084` | Of the three new classes, only `UnitsInvariantTest` and `UnitsNotSeededRefusalTest` were appended to the live `backend-test-pgsql --filter` allowlist. `UnitsProvisioningTest` is left BY PATH in the `feature-lane-inventory/Uom` lane, which is PARKED behind `vars.SELF_HOSTED_RUNNER_READY` — so it executes on **no CI event today**. That is the class carrying the single most load-bearing new proof: `test_unit_seed_failure_rolls_back_only_units_and_company_creation_still_succeeds` (`:180-220`), the whole reason G12-R1-01 was allowed to close on mechanism (b). The manifest note's justification ("route-level and driver-agnostic, so it remains BY PATH") is a fair convention, but it leaves the R1-01 acceptance proof ungated. | Append `|UnitsProvisioningTest` to the same `--filter` at `ci.yml:1084` and record the raise in the Uom manifest note, exactly as was done for the other two. One word. |
| **G12-R2-04** | MINOR / NOTE | `apps/api/tests/Feature/Uom/UnitsInvariantTest.php:190-204`; `tests/Feature/Uom/UnitsProvisioningTest.php:237-251` | Both failure injections throw a synthetic `RuntimeException` from a `DB::listen(QueryExecuted)` handler — i.e. AFTER the INSERT succeeded. That proves savepoint rollback for a PHP-level exception, but never exercises a genuine PostgreSQL statement error (`SQLSTATE`, unique violation, etc.), which is the realistic mid-seed failure and the one that actually puts PG into the aborted-transaction state the savepoint exists to recover from. The mechanism is sound (verified in `ManagesTransactions::performRollBack`), so this is a coverage note, not a defect. | Optional hardening for a follow-on: inject a real unique-constraint collision on `units.code` mid-seed and assert the same four outcomes. |
| **G12-R2-05** | MINOR / NOTE | `apps/api/tests/Feature/Import/ProductsImportPipelineTest.php` (7 sites, e.g. `:706,1130,1208-1211`) | Null-safe `?->` in `assertNull(...)` / `assertFalse((bool) ...)` positions is vacuous-pass-capable. Verified harmless TODAY because a neighbouring non-null-safe assertion hard-fails on an absent row in every case, but the guard is incidental, not designed. Also, these PHPStan-driven rewrites are unrequested scope for a units-invariant lane. | Leave as-is for this lane; prefer `assertNotNull($rows[$n]);` on its own line before the null-safe assertions in whichever lane next touches this file. |
| **G12-R2-06** | NOTE (accepted by design) | migration `:76-88`; `Migrator.php:249-255` | Because `up()` deliberately never throws, a seed failure still gets the migration RECORDED as run — `tenants:migrate` will never retry it. Recovery is only via the company-creation provisioning path or a manual `UomSeeder`, and the `units_not_seeded` refusal is the fence in the meantime. The `test_failed_seed_is_retried_to_completion_on_the_next_up` pin calls `up()` directly, so it proves idempotent retry of the METHOD, not of the migrate COMMAND. The lane's own fix notes already state this caveat. Recorded here so it is not rediscovered as a surprise on staging. | No change requested. Ensure the staging runbook says: if any tenant logs `units.seed_failed`, the operator must re-run seeding for that tenant explicitly. |

## Contract re-verification (deltas from r1 only)

- **Backend-only boundary held.** `git status --short` in the worktree lists 16 paths, **zero under `apps/web/`**. G12-R1-02 was not "fixed" by scope creep.
- **Nested-boundary placement.** `UnitsProvisioningService.php:45-52` runs `hasVisibleUnits()` and the two `count()` probes OUTSIDE the try/catch; only `:53-64` is protected. A throw from those three read queries would still propagate into `CompanyController`'s outer `DB::transaction` and roll back the company. Practically unreachable (they are plain counts against tables the guards already proved exist) and not worth a finding, but noted for the record.
- **Import gating unchanged and still correct.** `ImportController.php:123-133` refuses BEFORE storage/parse/job creation (proven by `assertSame(0, ImportJob::query()->count())`, `UnitsNotSeededRefusalTest.php:99`); `ProcessImportJob.php:111-120` re-checks in the worker with no `CompanyContext` (`UnitsNotSeededRefusalTest.php:129` clears it) using the explicitly loaded tenant-pinned `Company`. Both derive the gate from `$type->getOptionalColumns()` rather than hardcoding Products, and `parties` (no `unit` column) is proven NOT refused (`:112-118`). Route still behind the `imports.manage` stack — untouched by this lane.
- **Precision contract (rule 19).** No float touches money or quantity in any touched file: the only numeric comparison added is `bccomp($actual, $expected, 2)` on decimal STRINGS (`ProductsImportPipelineTest` helper). No `(float)`, no `parseFloat`, no no-arg `getScale()`. PHPStan (which carries `ForbidFloatCastOnDecimalProperty` / `ForbidHardcodedBcmathScale`) is clean on all 15 paths.

## What to fix before merge

Add the missing second argument at `tests/Feature/Jobs/ScheduledJobTenantIsolationTest.php:137` (G12-R2-01) and re-run that file to **4 tests / 12 assertions OK** — the lane silently broke a green-on-dev cross-tenant isolation guard; then optionally close G12-R2-02 (error-level outer log) and G12-R2-03 (`|UnitsProvisioningTest` in the ci.yml filter), both one-liners.

No full suite was run. No file in either checkout was modified, stashed, committed or reset by this gate; the only side effect was creating the throwaway PostgreSQL database `autoerp_g12_r2`.

---

# G-12 Units invariant — adversarial gate r3 (final re-check after fix round 2)

**Lane:** `feat/g12-units-invariant`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/g12-units` (HEAD `903c021411eb77b8bf7927a07fa96397b28c9611`, still DIRTY/uncommitted — this gate did not commit, modify, stash or reset anything)
**Fix notes reviewed:** `scratchpad/lane-g12-summary.md` §"Fix round 2"
**Scope:** narrow re-check of the r2 register only (G12-R2-01/-02/-03) plus the required command battery. No full suite.
**VERDICT: PASS**

## r3 disposition of the r2 register

| r2 ID | r2 severity | r3 disposition |
|---|---|---|
| **G12-R2-01** | BLOCKER | **RESOLVED — proven by execution.** `tests/Feature/Jobs/ScheduledJobTenantIsolationTest.php:138-141` now passes both arguments; `:19` adds `use App\Modules\Uom\Application\Services\UnitsProvisioningService;`. Worktree run: **OK (4 tests, 12 assertions)** — the assertion count went back UP from r2's 10 to 12, which is the required proof that `handle()` actually executes and the `import_jobs` SELECT-shape assertions run. The `git diff` of that file is **exactly two hunks, fixture-only**: the `use` line and the second argument. No assertion text changed. |
| **G12-R2-02** | MINOR | **RESOLVED.** Migration `2026_08_30_100300_ensure_units_visible_per_company.php:96-100` is now `Log::error('units.visibility_migration_failed', ['exception' => $exception, 'tenant' => $tenantId])` — error level, exception OBJECT (not `->getMessage()`), matching the seed-path log at `:81-85`. `$tenantId = ''` is hoisted to `:24` above the `try`, so it is always defined in the catch (previously it could have been undefined if the throw came from the `Schema::hasTable` guards at `:27-35`). PHPStan clean confirms no undefined-variable path. |
| **G12-R2-03** | MINOR | **RESOLVED.** `.github/workflows/ci.yml:1084` filter now ends `…|UnitsInvariantTest|UnitsNotSeededRefusalTest|UnitsProvisioningTest)::/`, and `:1081-1082` carries the 3-class parked-lane comment. The Uom manifest note no longer claims BY-PATH-only; it records the fix-round-2 append and states *why* (`UnitsProvisioningTest` carries the R1-01 acceptance proof). Regex verified live, not asserted — see below. |
| G12-R1-02 | MAJOR (frontend-owned) | **UNCHANGED, correctly deferred.** `git status --short` still lists **zero `apps/web/` paths**. Stays booked to G-6b/G-10. |
| G12-R2-04 / -05 / -06 | MINOR / NOTE | **Unchanged, as accepted.** No change was requested for this lane. Carried forward. |

## CI filter — regex actually executed, not eyeballed

Extracted the literal `--filter` payload from `ci.yml:1084` (5126 chars) and ran `preg_match` against synthetic FQCN::method strings:

| probe | result |
|---|---|
| pattern compiles | `preg_last_error_msg() = "No error"` |
| `Tests\Feature\Uom\UnitsProvisioningTest::test_foo` | **1 (matched)** |
| `Tests\Feature\Uom\UnitsInvariantTest::test_foo` | **1 (matched)** |
| `Tests\Feature\Import\UnitsNotSeededRefusalTest::test_foo` | **1 (matched)** |
| negative control `Tests\Feature\Import\SomeOtherTest::test_foo` | **0 (not matched)** |
| `Symfony\Component\Yaml\Yaml::parseFile('.github/workflows/ci.yml')` | **OK — 26 jobs parsed** |

Note for the record: the leading `\\` in the pattern is *correct and load-bearing*, not the broken escape it looks like — it matches the namespace-separator backslash immediately before the class name, which is what anchors each entry. The negative control proves the anchoring works.

## Missed-caller sweep (the r2 blocker class)

Grepped `apps/api/app` + `apps/api/tests` for every reference to `ProcessImportJob`, then read the `->handle(` call site in each referencing file. **Six direct `handle()` callers exist; all six now pass two arguments:**

| file:line | args |
|---|---|
| `tests/Feature/Jobs/ScheduledJobTenantIsolationTest.php:138-141` | 2 ✅ (the r2 fix) |
| `tests/Feature/Import/ProcessImportJobStatusTest.php:128-131` | 2 ✅ |
| `tests/Feature/Import/ImportReExecutionGuardTest.php:259-262` | 2 ✅ |
| `tests/Feature/Import/OpeningBalancesImportBatchTest.php:421-424` | 2 ✅ |
| `tests/Feature/Import/ProductPlacementImportTest.php:189-192` | 2 ✅ |
| `tests/Feature/Import/UnitsNotSeededRefusalTest.php:131-134` | 2 ✅ |

The only production dispatch is `ImportController.php:579` `ProcessImportJob::dispatch($job->id, $companyId, $tenantId)` — constructor args only; `handle()` is container-resolved by the queue worker, so the widened signature is injected. **Zero old-arity callers remain.** The lane's fix-round claim that `:137` was the ONLY miss is confirmed independently.

Residual note (no change requested): the blanket `catch (\Throwable)` at `ScheduledJobTenantIsolationTest.php:142` is still what made the r2 breakage invisible. Narrowing it stays a good idea for whichever lane next touches that file — it is the reason a hard `ArgumentCountError` presented as a soft assertion failure.

## Verification command outputs (all run by me in the worktree)

| command | result |
|---|---|
| `./vendor/bin/phpunit tests/Feature/Jobs/ScheduledJobTenantIsolationTest.php` | **PASS — OK (4 tests, 12 assertions)** — matches dev; r2's 4/10/1F is gone |
| `./vendor/bin/phpunit tests/Feature/Uom/UnitsInvariantTest.php tests/Feature/Uom/UnitsProvisioningTest.php tests/Feature/Import/UnitsNotSeededRefusalTest.php` | **PASS — OK (17 tests, 85 assertions)** — expected 17/85 ✅ |
| PostgreSQL (127.0.0.1:5433, throwaway DB `autoerp_g12_r3`): `php artisan test -c phpunit-pgsql.xml --filter='/\\(UnitsInvariantTest\|UnitsNotSeededRefusalTest\|UnitsProvisioningTest)::/'` | **PASS — 17 passed (85 assertions), 39.17s** — expected 17/85 ✅. All 17 listed green, including `mid seed failure is logged and leaves both tables empty`, `failed seed is retried to completion on the next up`, and `unit seed failure rolls back only units and company creation still succeeds` |
| `./vendor/bin/phpstan analyse --no-progress <16 touched PHP paths>` | **PASS — `[OK] No errors`** |
| `./vendor/bin/pint --test <16 touched PHP paths> --format=json` | **PASS — `{"result":"pass"}`, exit 0** |
| `php tools/feature-lane-manifest-check.php` | **PASS — exit 0.** 1459 Feature classes / 74 groups; every group has a disposition; every declared lane present in ci.yml; every `--filter` entry anchored and uniquely matched against 1859 classes. 1200 parked, 1 debt — both at their declared ceilings |
| ci.yml YAML parse + filter regex probes | **PASS** — see table above |
| `git status --short` | **18 paths, all lane files** — see audit below |

## `git status --short` audit — 18 paths, zero strays

Modified (12): `.github/workflows/ci.yml`; `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php`; `.../Import/Application/Jobs/ProcessImportJob.php`; `.../Import/Presentation/Controllers/ImportController.php`; `.../Tenant/Application/Services/TenantInitializationService.php`; the five Import fixtures (`ImportReExecutionGuardTest`, `OpeningBalancesImportBatchTest`, `ProcessImportJobStatusTest`, `ProductPlacementImportTest`, `ProductsImportPipelineTest`); `tests/Feature/Jobs/ScheduledJobTenantIsolationTest.php`; `apps/api/tests/feature-lane-manifest.json`.

Untracked (6): `app/Modules/Import/Domain/Enums/ImportErrorCode.php`; `app/Modules/Uom/Application/Services/` (directory — verified by `ls` to contain **exactly one** file, `UnitsProvisioningService.php`, so it is 1 path not a bag); `database/migrations/tenant/2026_08_30_100300_ensure_units_visible_per_company.php`; `tests/Feature/Import/UnitsNotSeededRefusalTest.php`; `tests/Feature/Uom/UnitsInvariantTest.php`; `tests/Feature/Uom/UnitsProvisioningTest.php`.

Every path is a declared G-12 artifact. **No unexpected file. No `apps/web/`. No build artifact, no session/scratch file, no vendor churn.**

## Manifest arithmetic (unchanged from r2, re-read)

`gated_ceiling` 1197→1200; `Import.classes` 18→19 (`UnitsNotSeededRefusalTest`); `Uom.classes` 6→8 (`UnitsInvariantTest`, `UnitsProvisioningTest`). Both notes preserve their prior text under `=== prior note ===`. Checker exit 0 confirms the arithmetic. The merge-time union with G-7/G-3a (Import 23 / gated 1203 on current dev → Import 24 / Uom 8 / gated 1206) remains the merger's job, as recorded in the lane summary.

## Precision contract (rule 19) — re-checked on the r3 delta

The fix round touched three files: one test fixture line, one `Log::error` call, one CI filter string. **No money or quantity value is involved in any of them.** No `(float)`, no `parseFloat`, no bare no-arg `getScale()` introduced. PHPStan (carrying `ForbidFloatCastOnDecimalProperty` / `ForbidHardcodedBcmathScale`) is clean on all 16 paths.

## Carried-forward items (not blockers; recorded so they are not lost)

- **G12-R1-02** — the wizard still toasts Axios's generic "Request failed with status code 422" instead of the backend's actionable `units_not_seeded` message. Frontend-owned; must land in G-6b/G-10.
- **G12-R2-06** — a seed failure still leaves the migration RECORDED as run (`up()` deliberately never throws), so `tenants:migrate` will not retry it. **Staging runbook must say: if any tenant logs `units.seed_failed`, re-run seeding for that tenant explicitly.** The `units_not_seeded` import refusal is the fence in the meantime.
- **G12-R2-04 / -05** — failure injection is PHP-level (`DB::listen`) rather than a real PG `SQLSTATE`; the `?->` rewrites in `ProductsImportPipelineTest` are vacuous-pass-capable but incidentally guarded. Both optional hardening for a follow-on lane.
- **Inherited red** — `ProcessImportJobStatusTest.php:218` (Parties historical-document count, expected 1 got 0) is RED on `dev` too and was re-confirmed as such in r2. Not a G-12 regression; not re-run in r3 (out of the narrow r3 scope, and unchanged by fix round 2, which touched no Parties path).

## What to fix before merge

Nothing. All three r2 findings are closed with executed proof; the lane is merge-ready pending the merger's manifest-union arithmetic and the staging runbook line for `units.seed_failed`.

No full suite was run. No file in either checkout was modified, stashed, committed or reset by this gate; the only side effect was creating the throwaway PostgreSQL database `autoerp_g12_r3`.
