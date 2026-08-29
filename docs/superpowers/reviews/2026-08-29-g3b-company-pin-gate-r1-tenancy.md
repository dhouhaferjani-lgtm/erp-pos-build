# Lane G-3b — adversarial gate r1 (tenancy / permissions / module-gating angle)

- **Reviewer:** tenancy-authz-reviewer (adversarial, code-grounded)
- **Date:** 2026-08-29
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/g3b-company-pin` (branch `feat/g3b-import-company-pin`, base `4e7f9a031`, DIRTY — not committed, not modified by this review)
- **Brief:** `docs/sessions/session-G-imports-hardening-2026-08-29/briefs/LANE-G3B-company-pin-entitlement-BRIEF.md` (+ `## r5 patch (gate r4)`)
- **Lane summary reviewed:** `/private/tmp/claude-501/-Users-houssamr-Projects-syneriva-apps-erp/9cdc16cd-491d-4a35-924a-1b1ec9316258/scratchpad/lane-g3b-summary.md`
- **VERDICT: CHANGES** (2 merge-blocking, 1 owner-ruling, 6 minor)

---

## 1. What was verified (claim → evidence)

### 1.1 `ModuleEntitlementCheck` vs `RequireModule` semantics — PARITY on the 403 arm, DRIFT on the pre-checks

| aspect | `RequireModule` | `ModuleEntitlementCheck` | same? |
|---|---|---|---|
| status | `abort(403, …)` `app/Http/Middleware/RequireModule.php:63` | `abort(403, …)` `app/Modules/Import/Application/Services/ModuleEntitlementCheck.php:32` | YES |
| message | `"Module '{$module}' is not enabled for this business type"` `:63` | byte-identical `:32` | YES |
| envelope | Laravel `HttpException` → `{"message": …}` | same | YES — pinned by `tests/Feature/Import/ImportModuleEntitlementTest.php:70` `assertJsonPath('message', …)`, which passes (existing `tests/Feature/Security/CompositeItemsModuleAccessControlTest.php:94` only asserts the status, so the new test is strictly stronger) |
| resolution | `CompanyConfigService::getConfigForTenant($user->tenant)` → `hasModule()` `:52-62` | identical `:25-31` | YES |
| DI | ctor `private readonly CompanyConfigService` `:28-30` | ctor `private readonly CompanyConfigService` `:14-16`, no `app()` | YES (rule 13) |
| null / non-`User` principal | two typed `RuntimeException`s `:43-49` | **absent** — signature is `User $user`, call sites assert via PHPDoc | **NO** → F-5 |

Cross-tenant safety of the resolution path is sound: `Tenant` extends Stancl `BaseTenant`, which pins `vendor/stancl/tenancy/src/Database/Concerns/CentralConnection.php:11` → `tenancy.database.central_connection`, so `$user->tenant` reads the **central** DB even with the default connection swapped to `tenant_<uuid>`. `CompanyConfigService` caches on `GlobalCache` with the tenant id embedded in the key (`app/Services/CompanyConfigService.php:36,50-53`, and the CACHE TOPOLOGY docblock `:22-31`) — no cross-tenant config bleed.

**Module key literal agrees in all three places** (STOP condition 2 not hit): enum `app/Modules/Import/Domain/Enums/ImportType.php:47` `self::CompositeItems => 'CompositeItems'`; SoT `config/verticals.php:92,121,137`; middleware usage `app/Modules/Catalog/Presentation/routes.php:32` `'module:CompositeItems'`.

### 1.2 Route × check enumeration — independently re-derived, matches the summary

All 16 routes in `app/Modules/Import/Providers/ImportServiceProvider.php:66-91` were enumerated from source; the summary's table (rows 20-35) is complete and accurate. All 9 `ImportJob` read sites in the controller are accounted for: `:65` (index, scoped), `:259/:289/:356/:420/:478/:531/:660/:702` (each gains the 409 guard immediately after its 404 arm, then `ensure()`). The r5 additions (`updateOptions`, `errors`, `errorSummary`) are present. `MigrationWizardController::template:151` gains `ensure()`. Ten entitlement surfaces confirmed.

Route middleware (rule 12) is unchanged and correct: `ImportServiceProvider.php:66` = `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'can:imports.manage']`.

### 1.3 Permissions — no new `can:` guard, no seeder work owed

No route was added; `imports.manage` remains the only guard. It is seeded (`database/seeders/RolesAndPermissionsSeeder.php:532`, inside `permissionNames()`) and granted to `admin` via `syncPermissions(Permission::all())` (`:545`). **No silent-403 trap, no seeder re-sync owed by this lane.**

### 1.4 M4 backfill — same-tenant evidence only, no cross-tenant read

`database/migrations/tenant/2026_08_30_100200_add_company_to_import_jobs.php` lives in `migrations/tenant/`, resolves one connection at `:33` (`DB::connection($this->getConnection())`) and runs **every** query on it (`:107`, `:112`, `:155`). No `central` read, no cross-tenant join. It intentionally does not filter `import_jobs.tenant_id` — correct under db-per-tenant (one tenant per DB). Guards `:36-58` (driver, table, per-column), idempotent index creation `:76-97`, forward-only `down()` `:69-74` with the reason in the docblock. The evidence join `import_rows.imported_entity_id` (uuid, `2025_11_30_150000_create_import_tables.php:44`) → `<target>.id` is uuid↔uuid on both drivers; **empirically green on PostgreSQL**, so the PG uuid-comparison trap is disproven, not merely assumed. `TARGET_TABLES:21-29` keys match the enum backing values exactly, and `opening_balances → journal_entries` is right (`app/Modules/Import/Services/AccountingBalancesPhase.php:276,301` write `$entry->id` into `imported_entity_id`).

### 1.5 Queue context (rule 20)

`ProcessImportJob::handle` re-establishes tenancy via `withTenantContext(...)` (`app/Modules/Import/Application/Jobs/ProcessImportJob.php:78`), whose anchor is UUID-guarded before the central `Tenant::find` (`app/Jobs/Concerns/BindsTenantContext.php:97-106`). Company is re-resolved by the `(tenant_id, company_id)` tuple `:99-102`. The dispatch tuple contract is pinned by `tests/Feature/Import/ImportCompanyPinTest.php:163-190`, and the cross-tenant company rejection by `:192-216`. No currency/scale resolution is touched by this lane, so rule 19's no-arg-`getScale()` trap does not apply.

### 1.6 Cross-COMPANY isolation is proven by test, not asserted

`ImportCompanyPinTest.php:97-107` is a `#[DataProvider]` DENY-path test over all 8 job-loading surfaces (`:83-95`): job created in company A, context switched to B → 409 `IMPORT_COMPANY_MISMATCH`. `:121-145` proves `index` returns own-company + NULL and **never** the sibling's job. `:109-119` proves NULL is readable from both. Real models, `RefreshDatabase`, `RolesAndPermissionsSeeder`, no mocks of the thing under test.

---

## 2. Findings register

| ID | severity | file:line | finding | change |
|---|---|---|---|---|
| **F-1** | **Important (merge-blocking)** | `.github/workflows/ci.yml:1096`; `apps/api/tests/feature-lane-manifest.json` (Import group note) | Only `ImportJobCompanyBackfillMigrationTest` was appended to the live PG `--filter` allowlist. `ImportCompanyPinTest` and `ImportModuleEntitlementTest` were **not**, and the `Import` lane is PARKED behind `vars.SELF_HOSTED_RUNNER_READY` — the manifest note the lane itself wrote says "Both run BY PATH; the Import lane remains parked." So the **entire cross-company-isolation and module-entitlement regression net executes on no CI event**. Sibling lane G-7 named its 5 classes in exactly this filter for exactly this reason (same note, "the feature lane is PARKED, so that filter is the only live gate that can run them"). | Append `\|ImportCompanyPinTest\|ImportModuleEntitlementTest` inside the same anchored `/\\(…)::/` filter, and update the Import group note to say so. One-line change. |
| **F-2** | **Important (owner ruling or fix)** | `ImportController.php:531-543,640`; `ImportService.php:65` | A NULL-company job passes the 409 guard from **any** company (`companyMismatch()` `:935-947` returns null on NULL), then `execute` dispatches with `$companyId` taken from the *current* context (`:640`) — so an operator in company B can execute a legacy import authored under company A and write A's file into **B's** data. `company_id` is written in exactly one place, `ImportService.php:65` (createJob); nothing stamps it afterwards, so the job stays `unattributed:true` in both companies' history and the mis-attribution is unrecoverable. This is precisely the population M4 must abstain on (a pre-execution job has zero `imported_entity_id` evidence → NULL by `:127`), so backfill can never shrink it. Spec-sanctioned ("NULL readable from any company") but the spec ruled on *reads*; `execute` is a **write**. | Either (a) stamp `company_id = $companyId` on a NULL job at the moment `execute` succeeds — attribution at the point of the write, which also makes it invisible to the sibling afterwards; or (b) refuse `execute` on a NULL-company job with a typed 409/422 and require re-upload. Record the owner ruling either way. |
| **F-3** | **Important** | `MigrationWizardController.php:172` → `MigrationWizardService.php:435,452-455`; `MigrationWizardController.php:69` → `MigrationWizardService.php:30,500-504` | Rule 12 requires vertical-exclusive **routes AND fields** to be module-gated. `GET /migration-wizard/status` returns a `composite_items` count and `GET /migration-wizard/order` returns the CompositeItems type label/description to a tenant with the module **disabled**, with no `ensure()`. The summary (rows 30/35) calls both "generic metadata" — that judgement is wrong for `status`, whose `composite_items` key is a module-exclusive field, and `order` is the very list that drives the FE wizard tile set, so the backend keeps advertising a type it then 403s on. | Filter `order` through `ImportType::requiredModule()` + `hasModule` and drop the `composite_items` key from `status` when the module is off; or obtain an explicit owner exception and correct the summary's "generic metadata" wording. |
| **F-4** | Minor | `MigrationWizardController.php:84-101` → `MigrationWizardService.php:78-83` | **`dependencies/{type}` judged NOT a leak.** For `composite_items` it returns only a products-count-derived warning; nothing composite-specific beyond echoing the caller's own type string. Leaving it ungated is defensible; the summary's classification is right here. Two dead locals though: `$user` `:86-87` and `$companyId` `:88` (also `$companyId` `:174` in `status`) — pre-existing, note only. | No change required for the gating; optionally drop the dead locals in the owning lane. |
| **F-5** | Minor | `ModuleEntitlementCheck.php:18` vs `RequireModule.php:43-49`; call sites e.g. `ImportController.php:271-273`, `MigrationWizardController.php:149-151` | `ensure()` drops RequireModule's two pre-checks (`$user === null`; `! $user instanceof User` — "requires a tenant user, not a super admin"). Call sites substitute a `/** @var User $user */` PHPDoc assertion, which is a claim, not a check. A non-`User` principal (super admin / support-access token) yields an opaque `TypeError` instead of the middleware's self-describing `RuntimeException`. **No auth bypass** — both paths are 500 — but the brief's contract was "reproduces `RequireModule::handle()` :41-66 exactly". | Accept `?Authenticatable` and reproduce both `RuntimeException`s verbatim, so the two gates are behaviourally interchangeable. |
| **F-6** | Minor | `ImportController.php:65-72` | `GET /api/v1/imports` carries no `ensure()`, so an unentitled company still sees its `composite_items` job rows (`type`, `original_filename`) in history; only the detail surfaces 403. Consistent with the brief's ten-surface list, so not a spec breach — but it is the residual field-level exposure, and G-6b's FE hiding must not be relied on as the fix. | Record as an accepted residual, or add `ensure()`-based row filtering in G-6b's lane and say so. |
| **F-7** | Minor | lane summary lines 15-16 | Stress item (5): the both-layers half is **not explicitly tracked**. Line 16 records "no `apps/web`" as a held boundary and line 15 lists route carry-overs by owner, but nothing names "hide the composite import tile on the FE (`hasModule('CompositeItems')`)" as the outstanding rule-12 half owned by G-6b. It is tracked implicitly by the brief, not by the deliverable a reader will consult. | Add one carry-over line to the summary naming G-6b as owner of the FE `hasModule('CompositeItems')` gate for the import wizard tile. |
| **F-8** | Minor | `app/Modules/Import/Application/Services/ModuleEntitlementCheck.php:5,10` | Import-module-local class depending directly on `App\Services\CompanyConfigService`. **Deptrac verified clean** — 183 violations / 0 errors, matching the summary's stated baseline, so no gate breach and STOP condition (deptrac) was correctly not hit. But the capability is generic; the next module needing in-controller module gating duplicates it. The brief itself named `app/Shared/` as the alternate placement. | Follow-up, not a blocker: promote to `app/Shared/` when a second consumer appears. |
| **F-9** | Minor | `tests/Feature/Migrations/ImportJobCompanyBackfillMigrationTest.php:73-75`; migration `:69-74`, `:131` | (a) `down()` is never executed by any test. (b) The idempotency assertion counts census **lines** (`substr_count === 2`) but not that the second run attributed **0**; an accidental re-attribution to the same company would still satisfy `:72`. (c) Observed in both runs: the empty ambiguous census serialises as `"per_job":[]` (PHP empty array), not the object shape the brief contracts — a log consumer typing it as an object breaks on a clean tenant. | Assert the second run's `attributed` count is 0; add a `down()` smoke test; cast `per_job` with `(object)` / `JSON_FORCE_OBJECT` for the empty case. |

---

## 3. Command outputs

All run from `.worktrees/g3b-company-pin/apps/api`. **No full suite was run.**

```
./vendor/bin/phpunit tests/Feature/Import/ImportModuleEntitlementTest.php \
    tests/Feature/Import/ImportCompanyPinTest.php \
    tests/Feature/Import/ImportPermissionGateTest.php
  → OK (18 tests, 71 assertions)                                   [sqlite]

./vendor/bin/phpunit tests/Feature/Security
  → OK, but there were issues! Tests: 113, Assertions: 424,
    PHPUnit Deprecations: 14 (pre-existing, no failures)            [sqlite]

./vendor/bin/phpunit -c phpunit-pgsql.xml \
    tests/Feature/Import/ImportCompanyPinTest.php \
    tests/Feature/Import/ImportModuleEntitlementTest.php \
    tests/Feature/Migrations/ImportJobCompanyBackfillMigrationTest.php
  → OK (20 tests, 87 assertions)                                   [PostgreSQL 127.0.0.1:5433, by path]
    census observed: attributed 0 / ambiguous 1 (both company counts = 1) / none 1

./vendor/bin/phpstan analyse <10 touched paths, level 8>
  → [OK] No errors

./vendor/bin/pint --test app/Modules/Import <migration> tests/Feature/Import tests/Feature/Migrations
  → {"result":"pass"}

php vendor/bin/deptrac analyse --no-progress
  → Violations 183, Skipped 0, Warnings 0, Errors 0  (matches the summary's stated baseline)
```

Manifest arithmetic independently checked: Import `24 → 26` (+2), Migrations `7 → 8` (+1), `gated_ceiling 1206 → 1209` (+3). Consistent.

---

## 4. Verdict

**VERDICT: spec ❌ (F-3 leaves a rule-12 field ungated on a route the lane touched) + quality CHANGES-REQUESTED.**

Merge-blocking: **F-1** (the isolation regression net runs on no CI event — one-line ci.yml fix) and **F-3** (module-exclusive field served to an unentitled tenant). **F-2** needs an owner ruling before merge because it is a cross-company *write* path the spec only ruled on for reads.

**Fix before merge:** append `ImportCompanyPinTest|ImportModuleEntitlementTest` to the PG `--filter` allowlist (F-1), gate the `composite_items` field in `migration-wizard/status` and the CompositeItems entry in `migration-wizard/order` (F-3), and get the owner ruling on executing a NULL-company job from a sibling company (F-2).

## Gate r2 (Codex, imports+tenancy)

- **Date / target:** 2026-08-29; `feat/g3b-import-company-pin` at `33ab24192fae1c462190fcd7084b18f44fcdf6a1`; reviewed with `git diff dev...HEAD`. Source remained unmodified.
- **VERDICT: PASS.** All r1 blockers are resolved; no new import or tenancy/authz defect was found.

### Code-grounded verification

1. **F-1 companion:** `CompositeItemsRoundTripTest.php:225-284` executes the upload/template/execute 403 pins and the disabled-tenant companion now asserts 403. The former entitlement RED-BY-DESIGN skip guards are gone. The sole remaining skip is the pre-existing G-8 precision pin at `:190-212`, not a G-3b entitlement skip.
2. **F-2 G-3a union:** source SHA-256 is computed before storage (`ImportController.php:129-151`); the ZIP path carries it at `:180-182`. `handleProductImagesUpload()` preserves the G-3a disk-relative `$path` and four-anchor dispatch (`:930-964`) while stamping `company_id` and `source_hash` (`:943-953`).
3. **F-3 index contract:** validation covers `page`, `per_page` (max 100), `status`, `type`, and `q`; the query is tenant + `(company OR NULL)`, filtered, newest-first, and returns `{data,meta}` with `unattributed` (`ImportController.php:59-94`). `formatJob()` is byte-identical to current dev (`HEAD :797-819`; `dev :676-698`). Filter, scope, cap, and two-page/newest-first tests are at `ImportCompanyPinTest.php:135-227`.
4. **Adopt-on-execute:** one guarded update sets a NULL `company_id` together with the eligible-status flip, then refreshes and returns the normal typed 409 to a losing sibling (`ImportController.php:621-635`). The winning-company pin and sibling refusal are tested at `ImportCompanyPinTest.php:303-332`.
5. **Rule 12 ruling:** wizard `order` filters through entitlement (`MigrationWizardController.php:69-83`) and `status` removes unentitled typed fields (`:174-190`), pinned both ways at `ImportModuleEntitlementTest.php:109-126`. **Accepted residual:** a legacy CompositeItems row may remain visible in generic import history. This is audit metadata (`type`/filename), not a CompositeItems domain field; all detail/write surfaces remain gated, so it is acceptable under `CLAUDE.md:48-49`. G-6b still owns the FE `hasModule('CompositeItems')` import-tile gate.
6. **RequireModule parity:** `ModuleEntitlementCheck.php:19-60` reproduces the null principal, non-tenant principal, missing-tenant, config resolution, and 403 message behavior of `RequireModule.php:43-63`; null and `SuperAdmin` cases are pinned at `ImportModuleEntitlementTest.php:129-142`.
7. **Rule 20 / M4:** normal and product-image jobs serialize tenant/company anchors (`ProcessImportJob.php:63-68,78-103`; `ProcessProductImageImport.php:56-64,74-109`; dispatches at `ImportController.php:684,964`). M4 is tenant-connection-only, guarded/idempotent, unanimous-evidence-or-NULL, and forward-only (`2026_08_30_100200_add_company_to_import_jobs.php:21-74,99-171`); all seven mappings, abstention, second-run zero attribution, indexes, and `down()` are pinned at `ImportJobCompanyBackfillMigrationTest.php:58-219`.
8. **Manifest / CI:** current dev is ceiling `1212`, Migrations `10`, Import `25`; HEAD is `1215 / 11 / 27` (`feature-lane-manifest.json:9,817-820,862-865`). `.github/workflows/ci.yml:1112` contains one anchored filter line with all three G-3b classes; PHP `preg_match` and Symfony YAML parsing both pass.

### G-6a reconciliation (required before that concurrent lane lands)

- G-3b's current transition is `ImportController.php:621-635`. G-6a replaces the execute transition at its dirty-worktree `ImportController.php:538-548` and owns the guarded claim update in `ImportJobClaimService.php:25-56`; its async duplicate pin is `ImportJobClaimConcurrencyTest.php:621-639`.
- G-6a must fold adoption into that locked claim: pass the current company id, select/guard `company_id`, and set NULL `company_id` plus `status=importing`/claim clocks in the **same** guarded update. On a lost claim, refresh and run `companyMismatch()` before returning `IMPORT_ALREADY_STARTED`, preserving sibling-company `IMPORT_COMPANY_MISMATCH`.
- Do **not** retain G-3b's standalone NULL→Pending update followed by G-6a's separate claim; that would split the atomic attribution/claim transition. Extend G-6a's concurrency test to assert one company wins and the sibling gets the typed company mismatch.

### Command outputs

- **SQLite, by path:** migration `13 passed / 45 assertions`; company pin `28/76`; entitlement `6/47`; companion `10/68` plus the one pre-existing G-8 skip; RoundTrip directory `18/201` plus that skip; ImportInfrastructure + ProcessImportJobStatus + ImportReExecutionGuard + ScheduledJobTenantIsolation + ImportPermissionGate `43/127`; Security `113/424` with 14 pre-existing PHPUnit metadata deprecations. No failures; no full suite run.
- **PostgreSQL, private DB:** every invocation was prefixed `DB_DATABASE=autoerp_test_g2 DB_CENTRAL_DATABASE=autoerp_test_g2`; migration `13/45`, company pin `28/76`, entitlement `6/47`, companion `10/68` plus the same G-8 skip. No failures.
- **Static/structure:** PHPStan on all 25 touched PHP paths: `[OK] No errors`; Pint `--test`: `{"result":"pass"}`; manifest checker: `1474 Feature classes / 74 groups`, anchored entries uniquely matched; CI regex parse/match `OK`; YAML valid. Deptrac: lane `183 violations / 0 errors`, current dev `183 / 0`, and `0` touched violation files.
