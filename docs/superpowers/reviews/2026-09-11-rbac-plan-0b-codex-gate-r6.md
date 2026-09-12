# Codex plan gate r6 — RBAC wave 0b plan rev 6 (gpt-5.6-sol, high, read-only, 2026-09-11)

Reviewed only `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md` REV 6. I did not review the wave-1 plan.

`git rev-parse --short HEAD`: **`ff2a949e9`**

The documentation branch advanced during review through wave-1-only commits; the reviewed wave-0b plan remains commit `960548c60`. The current worktree is clean.

## Rev-1 closure table

| Round-1 finding | REV 6 disposition |
|---|---|
| B0b-1 — admin v0 omitted all nineteen | **CLOSED at rev-2 anchor.** Admin has all 19; the settled rev-6 non-admin distribution is manager/general manager 11, four read roles 1, accountant 0. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:577-673` |
| B0b-2 — knowingly red Task 1 commit | **CLOSED at rev-2 anchor.** Catalogue-dependent coverage lands with Task 6, and every commit has an explicit green run list. `:60`, `:6968-6969`, `:8753-8755` |
| B0b-3 — file-level writer test was not a census | **CLOSED and freshly verified.** The exact AST logic returns 20 writers on current `dev` and 23 on W-LOT, with no missing or extra fixture row. `LOCK_INHERITED_FROM` is proven tree-wide. `:3294-3298`, `:4106-4174`, `:4188-4318` |
| B0b-4 — reachable frontend callers could issue known 403s | **CLOSED for the caller implementation.** The fresh script reports 87 rows, 17 files, zero unassigned, with the one variable endpoint dispositioned. All six category readers are consistently in scope. `:4915-4925`, `:5274-5401`, `:5694-5715`, `:8777` |
| B0b-4b — wrong service-category API path | **CLOSED.** `/service-categories` is canonical and category/service updates use PATCH where required. `:5785-5791`, `:5314-5325` |
| B0b-5 — missing admin reported success | **CLOSED.** Apply and dry-run preserve `FAILED reason=admin_role_missing` and nonzero exit. `:2394-2405`, `:2471-2484`, `:4485` |
| B0b-6 — two invocations lacked atomic success | **CLOSED.** The wrapper records `pending`, atomically records either failed half, and writes `ok` only after both commands succeed. `:8927-9014` |
| M0b-1 — fleet helpers/provider incomplete | **CLOSED.** Helpers, migrations-behind case, and provider are supplied. |
| M0b-2 — weak test-first sequencing | **REJECTED-correctly for wholesale task resequencing.** The execution rule to author and observe each test before its implementation is sufficient. `:8219-8229`, `:9230-9232` |
| M0b-3 — placeholders/stubs | **CLOSED for the original finding.** The nine frontend files and Task 13 helpers now have bodies. A separate semantic Task 13 defect remains as M6-3 below. `:5857-6829`, `:7625-8207` |
| M0b-4 — staging and subjects not exact | **CLOSED.** Explicit path staging and `Phase 0.2.<task>:` production subjects are retained. `:70-71`, `:7054-7062`, `:8794` |
| M0b-5 — wrong PG/static-analysis harness | **CLOSED.** PG commands use `-c phpunit-pgsql.xml`; final analysis scopes are explicit. `:65-69`, `:8228-8231`, `:8784-8792` |
| Four round-1 minors | **CLOSED.** Start-universe wording, marker mode, create-only prose, and generic `Failed` construction remain corrected. `:2260-2283`, `:2494-2508` |
| Round-1 citation rows | **CLOSED at their rev-2 anchors.** UserController, seeder and T2 anchors were repaired. Current `dev` has since advanced through documentation-only commits; see minor 4. |
| Round-1 cross-plan contracts | **CLOSED.** Baseline, fleet, lock census, parser/wrapper deletion, provider and inherited-lock shapes are explicit. `:9234-9289` |

The historical rev-1→rev-2 change log is accurate against the code for the findings it records. Its old seven-key/`--grant-to` deployment language describes rev 2 and has been superseded by the explicit rev-6 8/10/1 ruling and per-key grant map.

## Rev-5 closure table

| Round-5 finding | REV 6 disposition |
|---|---|
| M5-1 — contradictory catalog-caller scope | **CLOSED.** All “untouched/outside scope” instructions were removed; all six category-read callers are guarded. `:168`, `:5694-5715`, `:8777` |
| M5-2 — only six of nine Vitest bodies; invalid fixtures/locators/waits | **CLOSED.** All nine bodies are present. DTOs, `ready` status, i18n keys, CompanySelector label, service-specific GET wait and PATCH assertion match the lane code. `:5857-6829` |
| M5-3 — incomplete Task 13 helpers and invalid fixtures | **NOT CLOSED as an executable three-class recipe.** The named helpers, service payload, and membership location field are repaired, but the service cross-company test asserts the wrong response status. See M6-3. |
| M5-4 — five UX-visible surfaces lacked assertions | **NOT CLOSED.** Every surface is assigned a level, but the resulting ServiceDetail e2e assertion is internally impossible and the service DELETE status is wrong. See M6-1 and M6-2. `:7022-7035` |
| Minor 1 — stale T2/W-LOT measurements | **CLOSED.** T2 is `208449350`; diffstats and CI/counting anchors match current lane tips. `:38-39`, `:270-278` |
| Minor 2 — unresolved census row lacked disposition | **CLOSED.** `DocumentForm.tsx:391` is explicitly outside the gated endpoint set after resolving `apiEndpoint`. `:4917`, `:5403-5415` |
| Minor 3 — row-order arithmetic said 2+3 against six rows | **CLOSED.** It now names three multi-row and three single-row locks. `:4430-4437`, `:8758` |

### Rev-5 `categories.view` ruling

| # | Disposition |
|---:|---|
| 1 | **CLOSED.** `categories.view` reaches admin, manager, general manager, cashier, viewer, technician and operator, not accountant. `:58`, `:612-673` |
| 2 | **CLOSED.** Category mutations reach admin, manager and derived general manager. `:627-663` |
| 3 | **CLOSED functionally.** The constant has the correct 19/11/1/0 rows. Its “seven non-admin templates” prose is arithmetically wrong; see minor 1. |
| 4 | **CLOSED.** Seeder instructions cover manager plus four read roles, with general manager derived and accountant unchanged. |
| 5 | **CLOSED.** The CLI uses a per-key map, parses before the fleet loop, and the runner inverts it before taking one snapshot per affected role. `:2330-2377`, `:2870-2962` |
| 6 | **CLOSED.** Both rehearsals and the wrapper use the 8/11 partition. `:8660-8685`, `:8975-9008` |
| 7 | **CLOSED functionally.** Required role shapes are covered, but the case/template counts are inconsistent; see minor 1. `:4477-4486` |
| 8 | **CLOSED.** Customised read-role behavior and `admin_only=` are included. `:4478-4480` |
| 9 | **CLOSED.** The operative arithmetic is 8 admin-only, 10 manager-only and 1 shared. `:58-59` |
| 10 | **CLOSED.** All six read guards remain; R-0b-10 is struck and R-0b-11 remains. `:5715`, `:8777` |
| 11 | **CLOSED.** Mutation controls have separate create/update/delete guards. `:5720-5730`, `:5990-6048` |
| 12 | **NOT CLOSED.** The five-arm Playwright contract contains contradictory ServiceDetail authority and a wrong DELETE status. See M6-1/M6-2. |
| 13 | **CLOSED.** CategoriesPage and CategorySelector distinguish read authority from mutation authority; all nine bodies are present. `:5916-6068`, `:6757-6829` |
| 14 | **CLOSED.** The role distribution changes no route/key count: 19 keys, 25 write closures, 20 read closures, final 127/126. `:8552-8571`, `:9090` |
| 15 | **NOT CLOSED as a complete editorial sweep.** The detailed signature section is accurate, but stale summary/count statements remain; see MINOR. |

## BLOCKER

None.

## MAJOR

### M6-1 — the ServiceDetail e2e denial is assigned to an actor that holds `services.update`

The third actor explicitly holds `services.view/create/update`. The five-surface table then requires the ServiceDetail Edit/Delete controls to be absent for that same actor, incorrectly claiming it “holds no `services.*`.” A correct implementation will show both controls because they are guarded on `services.update`.

- Actor definition: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7009`
- Contradictory assertion: `:7029`
- Actual control locations: `lane/w-lot-a-1a:apps/web/src/features/services/ServiceDetailPage.tsx:168-182`

Repair mechanically by making the third actor hold `services.view` and `services.create`, but not `services.update`, or by introducing a separate service-read-only actor. Its service-category caller coverage does not require `services.update`.

### M6-2 — service DELETE is pinned to 200, but the production controller returns 204

The manager arm requires `DELETE /api/v1/services/{id}` to return 200. The controller returns `response()->json(null, 204)`. The manual probe’s “200/201 throughout” wording repeats the mismatch.

- Wrong assertion: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7010`
- Manual probe: `:8640-8642`
- Production behavior: `lane/w-lot-a-1a:apps/api/app/Modules/Service/Presentation/Controllers/ServiceController.php:168-193`

The e2e cannot pass against correct code until the expected status is changed to 204.

### M6-3 — Task 13’s cross-company service test expects 403 from a deliberately scoped 404 lookup

The test sends company A’s header while addressing a service belonging to company B, then calls `assertForbidden()`. The actor holds `services.update`, so middleware passes. `ServiceController::update()` scopes the service lookup to the active tenant/company and returns `SERVICE_NOT_FOUND`, 404, when the B service is not visible through A.

- Supplied test: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7880-7895`
- Controller behavior: `lane/w-lot-a-1a:apps/api/app/Modules/Service/Presentation/Controllers/ServiceController.php:128-149`

The row-unchanged assertion is correct; the status assertion must be `assertNotFound()` or explicitly accept the repository’s scoped-not-found contract. As written, Task 13 remains red after a correct implementation.

## MINOR

1. There are **six affected non-admin templates**, not seven: manager, general manager, cashier, viewer, technician and operator. Seven is the total affected template count only when admin is included. The plan also describes “ten cases” but lists nine named `EnsurePermissionsCustomisationGuardTest` methods, while the verification checklist still says seven. The functional role loops are correct. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:592-605,1238-1242,4477-4486,8762,9079-9084`

2. Parser coverage does not match its own claim. The parser implements malformed-entry, nameless-key, outside-keys, duplicate-key, no-role, repeated-role and admin refusals, while test 5c covers only outside-keys, duplicate-key, admin and the retired flag. Add cases for malformed input, no role and repeated roles. `:2870-2962,4484,9081`

3. Two summaries were not swept:

   - File Structure still says manager is redirected on “each” Channels/Categories/Growth deep link, contradicting the settled manager category access. `:111`, versus `:7005-7008`
   - The introduction still says the signature section records “no production signature changes at all,” while the accurate detailed section records internal stopgap signature changes that wave 1 does not consume. `:3`, versus `:9234-9247`

4. The plan’s `dev` pin remains `630afa86f`; current `dev` is `33796cc08`. The intervening changes are documentation-only, and the seeder, generated map, manifest and CI file are unchanged, so route arithmetic and the 23-key diff baseline remain valid. Refreshing the displayed tip is editorial. `:37`

## Citation audit

Current refs:

- `dev`: `33796cc08`
- `lane/w-lot-a-1a`: `a7010fe4d`
- `lane/t2-receipt-spine`: `208449350`
- `lane/rbac-w0a`: `ed88aa2ed`

All three entry prerequisites currently fail ancestry: W-LOT, T2 and wave 0a are not ancestors of `dev`. REV 6 correctly prohibits Task 1 from starting. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:49-54,259-287`

Current overlap measurements match the plan:

- `dev...W-LOT`: 83 files, +4,758/−663
- `dev...T2`: 104 files, +13,412/−582
- T2 overlaps exactly seven wave paths, including `apps/api/tests/feature-lane-manifest.json`.
- W-LOT’s post-pin commit remains documentation-only; `apps/` is byte-identical to `52f5ad796`.

### PermissionWriterCensus

Fresh AST enumeration matches exactly.

Current `dev`, 20 methods:

- `RoleController::{assignRole,removeRole,store,update}`
- `UserController::{store,update}`
- `ResetTenantCommand::handle`
- `TenantInitializationService::assignDefaultRoles`
- both tenant-migration `up()` methods
- `CoffeeShopSeeder::createTestUsers`
- `DatabaseSeeder::createUsers`
- `DemoPharmacySeeder::{seedRoleCoverageUsers,seedTunisiaCashiers}`
- `DemoTenantSeeder::{assignAdminRoleAndMembership,ensureTechnicianUser}`
- `ParapharmacySeeder::createTestUsers`
- `PermissionSeeder::run`
- `RolesAndPermissionsSeeder::{createPermissions,createRoles}`

W-LOT has the same semantic set, with the two seeder methods renamed to `createPermissionsFrom`/`createLegacyRoles`, plus:

- `LotActionPermissionDelta::execute`
- `LotActionPermissionDelta::provisionGeneralManager`
- `LotActionPermissionDelta::synchronizeSeededRole`

Missing fixture rows: **none**. Extra fixture rows: **none**.

`LOCK_INHERITED_FROM` is proven rather than waived: both helpers are private; tree-wide `callersOf()` finds only `execute`; `execute` acquires at lane line 54 before calls at 78 and 89. `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:45-89,139-180`; plan `:4137-4144,4283-4318`.

### Frontend census and lane lines

A fresh execution of the embedded script against current W-LOT produced:

- 87 rows: 46 API + 41 navigation
- 17 files
- zero unassigned
- one unresolved expression, `DocumentForm.tsx:391`, already dispositioned

The full per-row lane output is correctly pasted at plan `:5274-5401`.

Principal route anchors:

| Surface | `dev` | W-LOT |
|---|---:|---:|
| Company onboarding | `routes/index.tsx:552` | `:554` |
| Categories | `:1192` | `:1194` |
| Service categories | `:1596` | `:1603` |
| Service edit | `:1620` | `:1627` |
| Channels/ecommerce | `:2248-2308` | `:2255-2315` |
| Roles | `:2346` | `:2353` |
| Growth | `:3202-3220` | `:3209-3227` |

Stable W-LOT caller anchors also match:

- Channels: `ChannelListPage.tsx:21-25`, `ChannelCreateWizard.tsx:13-16`, `useAggregateChannelOrders.ts:19-23`
- Six category readers: `CategoriesPage.tsx:27`, `CategoryForm.tsx:26`, `CategorySelector.tsx:47`, `CategoryManagementPage.tsx:19`, `CategorySelect.tsx:38`, `ProductForm.tsx:191`
- Progression: `useCompanyProgression.ts:86-105`, `useModuleReadiness.ts:22-28`, `useRecommendations.ts:21-24`, `ModuleCard.tsx:27`, `RecommendationCard.tsx:21-22`
- Companies: `CompanySelector.tsx:65-120`, `AddCompanyModal.tsx:64-123,326-333`
- Services: `ServiceCategoryListPage.tsx:55-92,186-202,235-270`, `ServiceListPage.tsx:46-64,116-128,223-231`, `ServiceForm.tsx:69-87,132-143`, `ServiceDetailPage.tsx:54-62,168-182`, `ServicePicker.tsx:101-108`
- Navigation: `useCommandPalette.ts:73`, `InventoryHubPage.tsx:41-46`, `Sidebar.tsx:147-148,217,276-277`

The nine supplied Vitest bodies now pass a source/type audit against those lane files and fail on the intended missing guard/PATCH lever. The exception is not one of those bodies: Task 13’s PHPUnit test has the 403/404 mismatch in M6-3.

### Wrapper, arithmetic and signatures

The wrapper’s exit semantics are sound: same-directory temporary marker, atomic rename, initial `pending`, explicit nonzero exit for either failed invocation, and `ok` only after both succeed. `:8927-9014`

The settled arithmetic remains correct:

- post-0a: 152 writes / 146 reads / 298 baseline
- post-0b-15: 152 / 142 / 294
- final: 127 writes / 126 reads
- 19 new keys, 25 write closures and 20 read closures

The detailed “Signature changes for wave 1” section is accurate: wave 0b changes the stopgap runner and CLI internally, but wave 1 consumes no changed production contract because it deletes them. The three re-pin notes—copy marker-mode semantics, reuse the census/list-valued inheritance proof, and delete the wrapper with the stopgaps—remain correct. `:9234-9289`

## Rejected false positives

- No missing or extra writer method exists in the 20/23 census.
- `LOCK_INHERITED_FROM` is not an exemption; caller completeness and acquire-before-call are both asserted.
- The wrapper does not permit a successful second invocation to overwrite a failed first invocation.
- The settled category distribution does not change route or catalogue-key arithmetic.
- W-LOT’s citation pin is not code-stale; its post-pin commit is documentation-only.
- No wholesale task resequencing is required for M0b-2.
- `AddCompanyModal` correctly remains component-tested because it has no production trigger.
- Wave 1 inherits no changed production API despite the internal stopgap signature changes.

## Preserve

Preserve:

- the 20/23 method-level AST census and three-way classification;
- tree-wide, list-valued `LOCK_INHERITED_FROM`;
- admin’s all-nineteen adoption arithmetic;
- the 8/10/1 role distribution and per-key grant map;
- one snapshot per affected role;
- `/service-categories` canonicalization and service PUT→PATCH;
- missing-admin failure on apply and dry-run;
- wrapper `pending`/`failed:<which>`/`ok` atomicity;
- the 87-row frontend census and six category-reader guards;
- all nine supplied Vitest bodies;
- per-arm 5xx/console capture;
- both whole-wave lane ancestry checks plus wave-0a prerequisite;
- PG harness usage, explicit staging and `Phase 0.2.<task>:` subjects.

## Owner decisions required

None. All required repairs are mechanical:

1. make the third e2e actor genuinely lack `services.update`, or add a service-read-only actor;
2. expect 204 for service DELETE;
3. expect 404 in the Task 13 cross-company service test;
4. reconcile the minor counts, parser cases, summaries and current `dev` pin.

## Dispatch assessment

REV 6 is not dispatch-ready: **0 BLOCKER / 3 MAJOR / 4 minor editorial groups**.

The underlying permission catalogue, grant-map safety, locking proof, wrapper, frontend census and route arithmetic are strong. Dispatch is stopped by three executable assertion defects: an actor required both to hold and lack `services.update`, a 200 expectation for a 204 endpoint, and a 403 expectation for a scoped 404 response.

Execution is independently blocked today because W-LOT, T2 and wave 0a have not merged into `dev`.

VERDICT: CHANGES-REQUIRED