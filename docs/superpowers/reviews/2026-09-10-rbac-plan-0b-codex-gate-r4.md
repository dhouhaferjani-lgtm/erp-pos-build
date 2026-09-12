# Codex plan gate r4 — RBAC wave 0b plan rev 4 (gpt-5.6-sol, high, read-only, 2026-09-10)

Reviewed only `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md` REV 4 against the accepted rev-9 specification and settled A-1 amendment. I did not review the wave-1 plan.

`git rev-parse --short HEAD`: **`3bd0f4c78`**

Current refs:

- `dev`: `630afa86f`
- `lane/w-lot-a-1a`: `52f5ad796`
- `lane/t2-receipt-spine`: `951a7637e`

## Rev-1 closure table

| Round-1 finding | Rev-4 disposition |
|---|---|
| B0b-1 — the version-0 oracle omitted all nineteen additions from `admin` | **CLOSED at rev-4 anchor.** `admin` has all nineteen additions; `manager` and `general_manager` each have the settled seven. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:580-622` |
| B0b-2 — Task 1 deliberately committed a known-red catalogue test | **CLOSED at rev-4 anchor.** The catalogue-facing test lands with Task 6, while Task 1 retains only constant arithmetic. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:56`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5173-5233` |
| B0b-3 — the writer census did not prove method-level coverage | **CLOSED at rev-4 anchor.** The exact embedded visitor returns 20 writers on `dev`, 23 on W-LOT, no missing/extra fixture entries, no unresolved-role candidate, and no row-order offender. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2980-3744`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3760-4154` |
| B0b-4 — new gates left live frontend callers issuing known 403s | **NOT CLOSED.** Rev 4 closes the 27 callers it lists but misses live service callers and service mutation controls. See B4-1. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4580-4612`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5310-5327` |
| B0b-4 route mismatch — `/service-categories` versus `/services/categories` | **CLOSED for that exact mismatch.** All six category API calls move to `/service-categories`, including category update PUT→PATCH. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4738-4755` |
| B0b-5 — missing `admin` reported success | **CLOSED at rev-4 anchor.** Apply and dry-run both preserve `FAILED`, `reason=admin_role_missing`, with nonzero command exit. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2289-2310`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2532-2548` |
| B0b-6 — the two-invocation status marker lacked an atomic success condition | **CLOSED at rev-4 anchor.** The wrapper writes `pending`, stops on either failed invocation, atomically records the failed half, and writes `ok` only after both exit zero. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6763-6834` |
| M0b-1 — fleet helpers, migrations-behind coverage and command provider absent | **CLOSED.** The helpers, readiness case and extensible provider are supplied. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1730-1972` |
| M0b-2 — Tasks 1–4 must be wholesale re-sequenced | **REJECTED-correctly.** Per-method interleaving would not improve dispatchability. The execution rule—author and run the test before its implementation—is sufficient once the test recipes themselves are valid. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6898`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6979` |
| M0b-3 — prose stubs and placeholders | **NOT CLOSED.** Task 6 explicitly contains nonexistent imports and a “placeholder”; Task 13 still supplies only one abbreviated method plus a substitution table for three classes. See M4-2. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4878-5166`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5826-6084` |
| M0b-4 — staging and subjects were not task-exact | **CLOSED.** Fifteen `Phase 0.2.<task>:` subjects exist, and Tasks 6/8 now use enumerated candidate manifests rather than a `git status` command. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5271-5295`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5438-5452` |
| M0b-5 — PG/static-analysis commands omitted the repository harness/scope | **CLOSED.** PG legs consistently use `-c phpunit-pgsql.xml`; final static-analysis scope is explicit. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:61-65`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6468-6506` |
| Minor — “one start read” wording | **CLOSED.** The snapshot defines the universe and the later bounded reads are described. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1315-1323` |
| Minor — `markerLine()` omitted `mode` | **CLOSED.** The DTO now renders mode consistently for both command forms. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2100-2154` |
| Minor — create-only prose contradicted `exists()`+`create()` | **CLOSED.** The contract now names the actual implementation and its dry-run/counting rationale. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2039` |
| Minor — generic failures could not construct `Failed` | **CLOSED.** `AdminRoleMissing`, domain-blocked and generic throwable paths are distinct and reachable. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2297-2328` |
| Citation rows — stale refs, W-LOT controller lines, T2 counting route, seeder shifts | **CLOSED.** The plan’s three current pins, ancestry state, diffstats and moved route/controller citations match the current refs. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:29-39`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:236-259` |
| Cross-plan producer contracts | **CLOSED within the reviewed plan.** No production signature consumed by wave 1 is changed; the test-fixture shape and three downstream re-pin notes are explicit. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6981-7025` |

The rev-1→rev-2 change log is correct for catalogue arithmetic, independent commits, missing-admin behavior, fleet support, wrapper semantics, subjects, PG harness and citations. Its claim that every frontend caller is guarded and every placeholder is gone is still false at rev 4.

## Rev-3 closure table

| Round-3 finding | Rev-4 disposition |
|---|---|
| B3-1 — row-order visitor produced 23 false offenders | **CLOSED at rev-4 anchor.** Mechanical execution of the exact supplied class now produces zero offenders and zero unresolved-role candidates on both current trees. The 23 prior records disposition as 10 `NOT_ROLES` and 13 `UNRESOLVED` without a lexical role marker. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3053-3099`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3415-3696`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4120-4154` |
| M3-1 — asserted nav/link hiding was not implemented | **CLOSED for the surfaces that exist.** Four Sidebar children and the ServiceListPage link receive guards and are staged. The Growth-nav and company-nav halves are **REJECTED-correctly** because those nav entries do not exist; the Growth deep-link and CompanySelector affordance remain covered. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4679-4729`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4858-4865`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5273-5279` |
| M3-2 — e2e status/capture contract could not pass | **NOT CLOSED.** POST 201, GET/PATCH 200 and per-actor 5xx/console capture are corrected, but the cited DELETE 204 is never exercised or asserted. See M4-3. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5239-5254` |
| M3-3 — red recipes could not produce the stated red | **NOT CLOSED.** Task 5’s DRAFT/reason/helper correction is sound, and Task 13’s helper payload fields are corrected, but Task 6’s bodies do not compile against either `dev` or W-LOT and Task 13 still lacks three complete test classes. See M4-2. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4290-4506`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4878-5166`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5826-6084` |
| M3-4 — map diff expected 19 instead of 23 | **CLOSED.** The wave-owned count is 19 and the complete pre-wave-dev diff is 23, with the four lane-owned keys separately named. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6445-6466` |
| M3-5 — Tasks 6/8 derived staging from `git status` | **CLOSED.** Both now provide bounded candidate manifests and no operational `git status` command remains. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5281-5295`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5442-5450` |
| Minor 1 — stale AddCompanyModal submit anchor | **CLOSED.** The plan now cites `:326-333`, with `disabled` at `:330`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:164`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4618-4643` |
| Minor 2 — first undeclared catalogue key mislabeled | **CLOSED.** The expected first failure is `services.view`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5233` |
| Minor 3 — second-company probe expected 200 | **CLOSED.** The plan uses 201/`assertCreated()`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5923-5933` |
| Minor 4 — wave-1 signature section overstated | **CLOSED.** It says none in production and one private fixture-shape change. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6981-6992` |
| Round-3 citation audit | **CLOSED.** The plan corrects the register’s erroneous directory prefixes and retains the valid method names/lines. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4120-4124`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6891` |

The rev-3→rev-4 change log is accurate for B3-1, M3-1, M3-4, M3-5 and all four minors. Its M3-2 and M3-3 closure claims overstate what the supplied e2e and test bodies actually implement. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6881-6890`

## BLOCKER

### B4-1 — the “every affected frontend caller” census still omits live service callers and controls

Task 6 says its 27-row census is exhaustive and that every affected caller is guarded. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4580-4612`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4769`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5310-5327`

A fresh production-only search on W-LOT finds additional affected surfaces:

- `ServicePicker` sends `GET /services?...` when opened, gated only by search state, `disabled`, tenant and company—not `services.view`. It is reachable from `DocumentLineEditor` behind only the Workshop module and from the workshop-bundle labor form without a service permission check. `lane/w-lot-a-1a:apps/web/src/components/molecules/pickers/ServicePicker.tsx:98-108`, `lane/w-lot-a-1a:apps/web/src/features/documents/components/DocumentLineEditor.tsx:1261-1287`, `lane/w-lot-a-1a:apps/web/src/features/workshop-bundles/components/organisms/BundleComponentFormModal.tsx:335-343`
- `ServiceDetailPage` is reachable with `services.view`, but exposes an unconditional edit link and DELETE mutation. Task 6 gates PATCH/DELETE on `services.update`, so a view-only user can still activate a control known to be refused. `lane/w-lot-a-1a:apps/web/src/features/services/ServiceDetailPage.tsx:60-63`, `lane/w-lot-a-1a:apps/web/src/features/services/ServiceDetailPage.tsx:168-182`; route reachability remains `services.view` at `lane/w-lot-a-1a:apps/web/src/routes/index.tsx:1612-1620`.
- `ServiceListPage` exposes two unconditional “Add Service” links although `/services/new` requires `services.create`. `lane/w-lot-a-1a:apps/web/src/features/services/ServiceListPage.tsx:122-128`, `lane/w-lot-a-1a:apps/web/src/features/services/ServiceListPage.tsx:223-231`; route guard at `lane/w-lot-a-1a:apps/web/src/routes/index.tsx:1587-1597`.

None appears in the census or staging manifest. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4584-4612`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5273-5279`

This reopens round-1 B0b-4: dispatching the task as written ships a live query that can 403 and visible service controls whose required authority the UI already knows.

## MAJOR

### M4-1 — the manager service-edit e2e cannot pass because `ServiceForm` still uses PUT

The backend exposes only `PATCH /services/{service}`. `lane/w-lot-a-1a:apps/api/app/Modules/Service/Presentation/routes.php:31-32`

The live form sends PUT. `lane/w-lot-a-1a:apps/web/src/features/services/ServiceForm.tsx:132-143`

Rev 4 fixes PUT→PATCH only for service-category update and never mentions the service update call. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4744-4749`

Therefore the manager assertion that service edit succeeds cannot pass as written. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5252`

Task 6 must change this call to `api.patch`, add it to the relevant unit/e2e coverage, and keep `ServiceForm.tsx` in the existing staging manifest.

### M4-2 — Task 6’s “executable” Vitest bodies do not compile, and Task 13 remains abbreviated

Rev 4 calls the five bodies executable while simultaneously instructing the executor to adapt imports, harness names and settling assertions. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4876-4878`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5164-5166`

Mechanical defects include:

- Every body imports `@/test/harness`, which does not exist on `dev` or W-LOT. The actual shared utility is `apps/web/src/test/renderWithProviders.tsx`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4888`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4923`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4989`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5055`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5111`; actual harness at `lane/w-lot-a-1a:apps/web/src/test/renderWithProviders.tsx:1-9`.
- The progression body imports nonexistent `CompanyProfilePage` and `./fixtures`, and counts `mockApiGet` calls even though the real hooks call the separately mocked `progressionApi`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4983-5016`; actual page is `lane/w-lot-a-1a:apps/web/src/features/progression/pages/GrowthPage.tsx:1-8`, and the real neighbouring test mocks `progressionApi` at `lane/w-lot-a-1a:apps/web/src/features/progression/__tests__/tenantScope.test.tsx:23-43`.
- The CompanySelector case uses `<CompanySelector />` without importing it and is embedded after the AddCompanyModal file body despite being staged as a separate file. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5045-5097`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5275-5277`.
- Several negative cases await `getByRole('main')`, which the plan itself labels a placeholder. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4900-4902`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5129-5134`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5164`.
- Task 13 supplies one abbreviated service method, one trait whose final two helpers are in a separate fragment, and a substitution table rather than three complete test classes. It explicitly omits the required company header from its example. The stated three-file PHPUnit command therefore cannot be verified from the supplied recipe. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5826-5854`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5998-6066`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6068-6088`.

Task 5 is not part of this defect: its supplied PHP class parses, creates a DRAFT credit note, attaches only an existing permission, sends `reason`, and can produce the stated pre-seeder 403. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4290-4506`

### M4-3 — the e2e contract cites DELETE 204 but never tests it, and positive nav coverage is incomplete

The preamble correctly records category destroy as 204. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5239`; actual controller return at `lane/w-lot-a-1a:apps/api/app/Modules/Service/Presentation/Controllers/ServiceCategoryController.php:224-226`.

Neither permitted actor performs a category DELETE:

- admin covers GET 200, POST 201 and PATCH 200; `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5241-5246`
- manager covers GET 200, POST 201 and PATCH 200; `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5252`

The category Delete control is one of Task 6’s UX-visible changes, so a negative-only Vitest assertion plus no e2e DELETE leaves both the visible positive control and the 204 route response unproved. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4605`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5153-5159`

Likewise, the e2e checks Channels/Channel Orders/Categories nav absence for manager but does not explicitly require those new nav children to be visible for admin. A broken implementation that hides them for everyone could satisfy the stated nav assertions. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5241-5248`

The per-actor capture itself is correct: response and console listeners are installed before navigation for admin, manager and the custom services-capable actor, with zero 5xx and zero console-error assertions per actor. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5254`

## MINOR

1. The writer-count prose says the 20 `dev` writers split as nine runtime and eleven initialization. The fresh enumeration splits them as **seven runtime and thirteen initialization**. After W-LOT the three additions are one locked writer plus two inherited writers. The fixtures themselves are correct; only the prose arithmetic is wrong. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2974`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3786-3854`

2. The handback asks for “the four vitest zero-versus-one pairs,” while Task 6 defines five feature pairs and stages six files because CompanySelector is separate. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4866-4874`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5275-5277`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6539`

3. Task 5 describes `PermissionRegistrar` as “constructor-injected as private readonly,” but the supplied test declares a mutable private property and resolves it in `setUp()`. The implementation is acceptable; the description should match it. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4330-4337`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4504`

## Citation audit

### Entry conditions and overlaps

Both mandatory ancestry checks still return exit 1. The plan correctly prevents the wave from starting:

- W-LOT is not an ancestor of `dev`.
- T2 is not an ancestor of `dev`.  
  `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:45-50`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:236-243`

The current diffstats match the plan:

- W-LOT: 83 files, +4714/−663.
- T2: 100 files, +13144/−546.  
  `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:34-35`

The seven T2 overlap paths remain correctly enumerated. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:245-255`

The full-wave-0a ratchet precondition also currently fails: the `dev` path log for `RoutePermissionCoverageRatchetTest.php` is empty. The plan correctly says to stop in that state. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:260-264`

### Writer census

Running the exact rev-4 visitor over `app` and `database` produced:

- `dev`: **20** writer methods.
- W-LOT: **23** writer methods.
- Missing from fixture: **none**.
- Extra in fixture: **none**.
- Unresolved-role candidates: **zero**.
- Row-order offenders: **zero**.

The 20 `dev` writers are:

- RoleController: `assignRole`, `removeRole`, `store`, `update`.
- UserController: `store`, `update`.
- ResetTenantCommand: `handle`.
- TenantInitializationService: `assignDefaultRoles`.
- Tenant migrations: both `up` writers.
- CoffeeShopSeeder: `createTestUsers`.
- DatabaseSeeder: `createUsers`.
- DemoPharmacySeeder: `seedRoleCoverageUsers`, `seedTunisiaCashiers`.
- DemoTenantSeeder: `assignAdminRoleAndMembership`, `ensureTechnicianUser`.
- ParapharmacySeeder: `createTestUsers`.
- PermissionSeeder: `run`.
- RolesAndPermissionsSeeder: `createPermissions`, `createRoles`.

W-LOT adds exactly:

- `LotActionPermissionDelta::execute`
- `LotActionPermissionDelta::provisionGeneralManager`
- `LotActionPermissionDelta::synchronizeSeededRole`

and renames the two seeder methods to `createPermissionsFrom` and `createLegacyRoles`. The plan fixtures match these names. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3786-3854`

The W-LOT visitor records five current role locks:

| Method | Result |
|---|---|
| `LotActionPermissionDelta::resolveCatalogueRoles` | multi-row, ordered |
| `LotActionPermissionDelta::provisionGeneralManager` | single-row |
| `RoleController::assignRole` | single-row |
| `UserController::store` | single-row |
| `UserController::update` | multi-row, ordered |

Sources: `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:111-116,142-143`; `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:379`; `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:266,388`.

The planned EnsurePermissionsRunner query is the sixth post-wave chain and is also ordered. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4106-4115`

`LOCK_INHERITED_FROM` is proven, not waived. Both helpers are private; the tree-wide caller enumeration finds only `execute`; `execute` acquires at line 54 before calls at lines 78 and 89. `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:45-89,139-180`; proof implementation at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3963-4000`.

### Frontend line audit

The plan’s dev→post-W-LOT line shifts are accurate for its named 27-row set:

| Surface | `dev` | W-LOT/post-lane |
|---|---:|---:|
| Company onboarding guard | `routes/index.tsx:552` | `:554` |
| Categories guard | `:1192` | `:1194` |
| Service-category guard | `:1596` | `:1603` |
| Service-edit guard | `:1620` | `:1627` |
| Channel/ecommerce guards | `:2248,2258,2268,2278,2288,2302` | `:2255,2265,2275,2285,2295,2309` |
| Growth subtree | `:3202-3220` | `:3209-3227` |

Source table: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4584-4612`; current route bodies at `lane/w-lot-a-1a:apps/web/src/routes/index.tsx:551-560,1191-1200,1573-1635,2250-2315,3209-3227`.

Stable component anchors also match: channel queries at `features/channels/pages/ChannelListPage.tsx:21-25`, `ChannelCreateWizard.tsx:13-16`, `hooks/useAggregateChannelOrders.ts:19-23`; category query/controls at `features/categories/CategoriesPage.tsx:27,140-188`; progression queries/controls at `features/progression/hooks/useCompanyProgression.ts:86-105`, `useModuleReadiness.ts:22-28`, `useRecommendations.ts:21-24`, `components/ModuleCard.tsx:27`, `RecommendationCard.tsx:21-22`; company surfaces at `CompanySelector.tsx:113-120` and `AddCompanyModal.tsx:64-123,326-333`; service-category surfaces at `ServiceCategoryListPage.tsx:55-92,187-201,235-270`, `ServiceListPage.tsx:46-53,116-121`, and `ServiceForm.tsx:69-76`. The missed service surfaces are B4-1.

The ModuleKey consequence is correct: Sidebar’s `permission` field is `ModuleKey`, `ModuleKey` is the key set of `MODULE_PERMISSIONS`, and the three raw permission strings would fail typecheck until self-mapped. `lane/w-lot-a-1a:apps/web/src/components/organisms/Sidebar/Sidebar.tsx:104-120`; `lane/w-lot-a-1a:apps/web/src/hooks/usePermissions.ts:64-90,174-182`. Rev 4 supplies precisely those three rows. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4681-4693`

### Route arithmetic, wrapper and downstream notes

The route arithmetic remains correct: 25 write closures and 20 read closures, yielding 152→127 and 146→142→126. The settled A-1 intermediate ceilings—152/146/298 post-0a and 152/142/294 after 0b-15—are consistent with moving those four Identity reads into 0b-15. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6409-6428`

The wrapper exit/status behavior is sound. A failed first or second command exits nonzero and records that half; an interrupted run leaves `pending`; success is recorded only after both commands return zero. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6780-6834`

“Signature changes for wave 1” is internally accurate: no production contract consumed by wave 1 changes, the only direct shape change is the private list-valued fixture, and the three downstream notes—copy marker mode semantics, reuse the census/list-valued inheritance proof, delete the wrapper with the stopgaps—are correct. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6981-7025`

The review worktree is dirty only in the explicitly excluded wave-1 plan; the shared checkout has two unrelated untracked documentation files. No wave-0b plan path is dirty. No operational `git status`-derived staging list remains in REV 4.

## Rejected false positives

- Do not restore rev 3’s 23 false role-lock offenders. Rev 4’s three-valued classifier produces zero offenders while preserving fail-closed treatment for an unresolved chain in a method mentioning roles. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3435-3455`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4050-4098`
- Do not add a Growth nav entry merely to hide it. No such entry or `/growth` link exists; deep-link denial is the real surface. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4724-4729`
- Do not add a separate company-create nav item. CompanySelector is the existing shell affordance and is already in scope. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4727`
- Do not widen `ModuleKey` or cast the three Sidebar permissions. Self-mapped `MODULE_PERMISSIONS` rows preserve the closed key space. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4681-4693`
- Do not require wholesale Task 1–4 reordering. M0b-2 remains an execution-discipline note; the present failures require completing the recipes, not restructuring the task graph. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6898`
- Do not report the 19/23 map split as inconsistent. Nineteen keys belong to this wave; four belong to the mandatory lanes. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6445-6466`
- Do not alter the wrapper’s three-state `pending`/`failed`/`ok` model. It is correctly fail-closed. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6798-6834`

## Preserve

The next revision must preserve:

- the all-nineteen admin v0 row and seven manager/general-manager additions;
- the no-knowingly-red-commit rule and per-commit run lists;
- the exact 20+3 AST writer census and `LOCKED` / `LOCK_INHERITED_FROM` / `INITIALIZATION_ONLY` partition;
- tree-wide caller enumeration and acquire-before-call inheritance proof;
- the three-valued role-root classifier, monotonic `whereIn`, and explicit ascending order;
- `AdminRoleMissing` as `FAILED`, including dry-run;
- `/service-categories` as canonical;
- Sidebar’s ModuleKey typing and self-mapped permission rows;
- the Growth/company-nav rejected halves;
- the frontend reviewer precondition;
- the atomic two-invocation wrapper and status file;
- the 19/23 generated-map distinction;
- exact `Phase 0.2.<task>:` subjects and enumerated staging manifests;
- PG harness use;
- 25-write/20-read arithmetic and the settled A-1 ceiling move;
- no production wave-1 signature change and all three downstream re-pin notes.

## Owner decisions required

None.

The required repairs are mechanical consequences of already accepted rules:

1. Extend the frontend census and guards to `ServicePicker`, both of its live callers, `ServiceDetailPage`, and both ServiceListPage create affordances.
2. Change the service update call from PUT to PATCH.
3. Replace Task 6’s placeholder bodies with code using the actual neighbouring harness/API mocks and supply complete Task 13 test classes.
4. Exercise and assert DELETE 204, plus positive visibility of the newly guarded nav entries.

M0b-2 remains acceptable as an execution-discipline note. The tasks do not need wholesale re-sequencing.

## Dispatch assessment

REV 4 closes the round-3 AST blocker decisively: the exact visitor is green on both current trees, the writer fixture is exact, role-lock inheritance is proven, and the rev-4 root-classification design behaves as claimed.

The plan is nevertheless not dispatch-ready. Its core “every frontend caller” assertion is still false, one manager service workflow uses the wrong HTTP verb, the promised executable Vitest bodies do not compile against the repository, Task 13 remains abbreviated, and the e2e omits the settled 204/positive-control coverage.

Independently, the wave cannot start today: both W-LOT and T2 ancestry checks fail, and the full wave-0a ratchet precondition is not yet present on `dev`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:45-50`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:260-264`

VERDICT: CHANGES-REQUIRED