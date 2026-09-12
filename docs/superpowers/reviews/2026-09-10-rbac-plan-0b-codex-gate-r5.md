# Codex plan gate r5 — RBAC wave 0b plan rev 5 (gpt-5.6-sol, high, read-only, 2026-09-10)

Reviewed only `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md` REV 5 against the accepted rev-9 specification, settled A-1 move, current code, and the new `categories.view` ruling. I did not review the wave-1 plan.

`git rev-parse --short HEAD`: **`2d3faa28b`**

Current refs:

- `dev`: `33796cc08`
- `lane/w-lot-a-1a`: `a7010fe4d`
- `lane/t2-receipt-spine`: `208449350`

## Rev-1 closure table

| Round-1 finding | Rev-5 disposition |
|---|---|
| B0b-1 — `admin` v0 omitted all nineteen additions | **CLOSED at rev-2 anchor.** `admin` carries all nineteen; `manager` and `general_manager` carry the then-settled seven. The new catalogue ruling changes the non-admin rows, handled separately below. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:561-629` |
| B0b-2 — Task 1 knowingly committed a red catalogue assertion | **CLOSED at rev-2 anchor.** The catalogue-dependent assertion lands with Task 6, and every commit is required to run its own complete list. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6113-6174`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7775-7777` |
| B0b-3 — file-substring writer coverage did not prove method coverage | **CLOSED at rev-2 anchor and freshly verified.** The AST census returns exactly 20 writers on current `dev` and 23 on W-LOT, with no missing or extra fixture rows. `LOCK_INHERITED_FROM` is proven through tree-wide caller enumeration, not waived. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2981-4159` |
| B0b-4 — backend gates left reachable frontend callers issuing known 403s | **NOT CLOSED as dispatch text.** Rev 5 finds and assigns all 87 rows, but it still repeatedly orders the executor to leave the catalog namesake and three callers untouched, while its later instructions require those callers to be guarded. See M5-1. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:165,172`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5164-5187`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5348-5379`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7726,7799,7910` |
| B0b-4b — `/services/categories` versus `/service-categories` | **CLOSED.** The backend canonical path is used, including category PUT→PATCH. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4990-4993`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5263-5278` |
| B0b-5 — missing `admin` reported success | **CLOSED.** Apply and dry-run retain `FAILED`, `reason=admin_role_missing`, and nonzero command exit. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2289-2310`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2532-2548`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7784` |
| B0b-6 — two deploy invocations lacked an atomic success condition | **CLOSED.** The wrapper writes `pending`, atomically records either failed half, and writes `ok` only after both commands exit zero. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7949-8028` |
| M0b-1 — fleet helpers, migrations-behind case, and provider absent | **CLOSED.** The three helpers, readiness case, and command provider are supplied and assigned to green commits. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8171` |
| M0b-2 — red evidence sequencing | **REJECTED-correctly for wholesale resequencing.** The execution-discipline requirement—write each test before its implementation and capture the real red—is sufficient; interleaving every supplied method and test body would not improve the task graph. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8172`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8190` |
| M0b-3 — prose stubs and placeholders | **NOT CLOSED.** Task 6 still promises three test files without bodies, and Task 13 calls two deliberately unsupplied helpers and writes location scope to the wrong model. See M5-2 and M5-3. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5493-5494,6104,6238-6241`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7210-7217,7246` |
| M0b-4 — staging and commit subjects not task-exact | **CLOSED.** Task staging is explicit and production subjects use `Phase 0.2.<task>:`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6234-6246`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8174` |
| M0b-5 — PG commands and static-analysis scope used the wrong harness | **CLOSED.** PG commands consistently name `phpunit-pgsql.xml`; the final analysis scope covers the touched modules and tests. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7265-7268`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8175` |
| Four round-1 minors | **CLOSED.** Start-read wording, `mode=`, create-only prose, and generic failure construction are corrected. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8176-8179` |
| Round-1 citation findings | **PARTLY CLOSED.** UserController and seeder distinctions are corrected, but the T2 branch has advanced materially and the plan’s “current” T2 pin/diffstat/CI anchor are now stale. See minor 1. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:37,252-262`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8180-8183` |
| Round-1 cross-plan contracts | **CLOSED for rev 5.** Baseline, fleet, lock census, wrapper deletion, provider, rename map, and inherited-lock shapes are explicit. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8192-8236` |

The rev-1→rev-2 change log is accurate for admin v0, no-known-red commits, the AST census, missing-admin classification, wrapper semantics, fleet machinery, commit subjects, and PG harness. Its unqualified claim that “every placeholder is replaced” is false in REV 5 because Task 6 and Task 13 again contain incomplete recipes. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:25`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5493-5494,7246`

## Rev-4 closure table

| Round-4 finding | Rev-5 disposition |
|---|---|
| B4-1 — missed `ServicePicker`, `ServiceDetailPage`, and both Add-Service links | **CLOSED for the exact missing surfaces.** They appear in the mechanical census, implementation instructions, and staging manifest. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:169-172`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4956,4994-4995,5025-5026`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6234-6242` |
| M4-1 — `ServiceForm` sent PUT to a PATCH-only route | **CLOSED for production implementation.** The plan changes `api.put` to `api.patch`; backend W-LOT exposes only PATCH. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5447-5453`; `lane/w-lot-a-1a:apps/api/app/Modules/Service/Presentation/routes.php:28-35`; `lane/w-lot-a-1a:apps/web/src/features/services/ServiceForm.tsx:132-143` |
| M4-2 — Vitest bodies did not compile; Task 13 remained abbreviated | **NOT CLOSED.** Imports were repaired, but the new bodies still contain compile/runtime-invalid fixtures and locators, three promised files have no bodies, and Task 13 remains non-runnable. See M5-2 and M5-3. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5493-5498,5727-5742,5872-5875,5922-5940,6084-6097,6791,7246` |
| M4-3 — no category DELETE 204 assertion and no positive nav assertion | **CLOSED for the exact finding.** Admin performs DELETE and asserts 204; admin also asserts all four newly guarded nav children visible. Manager asserts PATCH and both delete statuses. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6187-6209,6215,6217` |
| Minor 1 — writer split said 9/11 instead of 7/13 | **CLOSED.** The corrected enumeration matches the fresh AST result. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8071` |
| Minor 2 — handback count disagreed with staged Vitest count | **CLOSED arithmetically.** The handback now says nine files, although three still lack bodies. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7719`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8072` |
| Minor 3 — `PermissionRegistrar` prose contradicted its mutable setup property | **CLOSED.** `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6832-6838`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8073` |

The rev-4→rev-5 change log is accurate for B4-1, M4-1, M4-3, and all three minors. Its M4-2 closure claim is false: “six complete Vitest files,” “nine pairs across nine files,” and “three complete classes” do not match the supplied recipes. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8065-8073`

## BLOCKER

None newly classified. The new `categories.view` ruling is explicitly not treated as a blocker finding, but it remains a mandatory rev-5.1 pre-dispatch amendment.

## MAJOR

### M5-1 — the plan gives mutually exclusive instructions for the catalog category callers

The File Structure and ruling B say the `features/catalog/api/queries.ts` namesake and its three callers are outside scope and must remain untouched. The handback and verification checklist repeat that instruction. Later rev-5 sections instead correctly require `CategoryManagementPage`, `CategorySelect`, and `ProductForm` to pass `enabled: hasPermission('categories.view')` and stage those files. An executor cannot satisfy both instructions. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:165,172`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5164-5187`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5348-5379`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7726,7799,7910`

Rev 5.1 must delete the obsolete “not touched/outside scope/deliberately untouched” wording everywhere and retain the later six-caller implementation. The new catalogue ruling then changes those guards’ permitted outcome, not their necessity.

### M5-2 — the supposedly executable frontend red tests are incomplete and still cannot pass against the real components

Nine test paths are promised and staged, but bodies are supplied only for six. `serviceDetail.permissions.test.tsx`, `ServicePicker.permissions.test.tsx`, and `CategorySelector.permissions.test.tsx` have no implementation at all, while the instruction says every one of the “six files above” must be run red and the handback demands nine red pairs. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5493-5494,5519,6104,6238-6241,7719`

The six written bodies also retain concrete defects:

- `MODULE_FIXTURE` and `RECOMMENDATION_FIXTURE` do not satisfy their prop types. `ModuleReadiness` requires `id`, `description`, `icon`, `readiness_percent`, `stage`, `discount_percent`, and `requirements`; `Recommendation` requires `description`, `priority`, `action_label`, and `action_route`. Moreover, `status: 'available'` makes `ModuleCard` deliberately hide Activate, so the positive test cannot pass even after typing is repaired. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5727-5742,5787-5811`; `lane/w-lot-a-1a:apps/web/src/features/progression/api/types.ts:27-46`; `lane/w-lot-a-1a:apps/web/src/features/progression/components/ModuleCard.tsx:25-30`
- The Categories create locator searches for `categories.actions.create`, but the actual controls use `inventory:categories.new` and `inventory:categories.empty.action`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5652-5664,5684`; `lane/w-lot-a-1a:apps/web/src/features/categories/CategoriesPage.tsx:140-146,163-178`
- The AddCompanyModal submit locator searches for `actions.create`; the component uses `settings:company.modal.createButton`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5867-5877`; `lane/w-lot-a-1a:apps/web/src/components/organisms/AddCompanyModal/AddCompanyModal.tsx:326-333`
- CompanySelector searches for `/company\.a/i`, but its trigger has `aria-label={t('company.select')}`. The plan itself admits the locator is a placeholder. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5922-5940`; `lane/w-lot-a-1a:apps/web/src/components/organisms/CompanySelector/CompanySelector.tsx:65-75`
- The PATCH test waits for any GET, which can be the category request, then clicks submit before the service detail has reset required `code` and `name`. It does not reliably reach the mutation. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6084-6097`; `lane/w-lot-a-1a:apps/web/src/features/services/ServiceForm.tsx:45-60,80-109,438-443`

Thus Task 6’s red tests do not all compile at the lane tip and do not fail for the claimed missing-guard/PATCH reason.

### M5-3 — Task 13 is still not a complete or executable three-class recipe

The plan calls the classes complete, then explicitly leaves `twoLocationsIn()` and `adjustmentPayloadAt()` undefined. That class cannot complete its stated run or PHPStan check without unplanned implementation. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6791,7210-7217,7246-7268`

Two further code-level defects make the supplied fixture fail before its intended assertions:

- `serviceIn()` omits required `code`, supplies invalid `pricing_type='fixed'`, and supplies a three-decimal `base_price`; the real request requires `code`, one of `flat_rate|hourly|percentage`, and at most two decimal places. Its `assertCreated()` will observe 422. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6917-6928`; `lane/w-lot-a-1a:apps/api/app/Modules/Service/Presentation/Requests/CreateServiceRequest.php:23-45`; `lane/w-lot-a-1a:apps/api/app/Modules/Service/Domain/Enums/PricingType.php:14-18`
- The stock test writes `allowed_location_ids` to `User`. The field and array cast belong to `UserCompanyMembership`, which `LocationContext` reads. `User` has no such field or cast, so this either attempts an invalid user-column update or leaves scope unrestricted. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7213-7235`; `lane/w-lot-a-1a:apps/api/app/Modules/Company/Domain/UserCompanyMembership.php:22-26,55-86`; `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Domain/User.php:81-97,115-126`; `lane/w-lot-a-1a:apps/api/app/Modules/Company/Services/LocationContext.php:194-206,224-238`

Every shown request does carry `X-Company-Id`; that part of the rev-5 claim is correct. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6860-6868,6921-6927,7219-7235`

### M5-4 — Playwright is not exhaustive for rev-5’s UX-visible additions

The e2e contract correctly covers the original pages, category DELETE 204, positive Sidebar navigation, PATCH service update, and per-arm 5xx/console capture. It does not assert rev-5’s newly added CommandPalette category command, InventoryHub category card, the two ServicePicker mount affordances, ServiceDetail edit/delete visibility, or the category pickers on product/coupon/promotion/counting forms. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:169-172,5089-5102,5347-5379`, versus the exhaustive assertion list at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6177-6217`

Some of those can be covered by valid component tests, but the three intended tests are not supplied and CommandPalette/InventoryHub have no named automated assertion. The assertion list therefore cannot support its “every UX-visible change” claim.

## MINOR

1. The T2 pin and diffstat are stale. Current T2 is `208449350`, not `951a7637e`, and current `dev...T2` is 104 files, +13,412/−582, not 100 files, +13,144/−546. Twenty-four paths changed after the pin, including `.github/workflows/ci.yml`; its relevant filter is now around `:1146`, not `:1141-1145`. The same seven wave-overlap paths remain, and the newer changes do not alter the wave’s route arithmetic, so this is a re-pin/citation correction rather than a new entry-condition design. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:37,252-262`; `lane/t2-receipt-spine:.github/workflows/ci.yml:1146`

2. The frontend census contract declares any non-empty table C a blocker, but rev 5 embeds a one-row table C and defers its disposition. The expression can already be resolved: `apiEndpoint` is selected only from quote/order/invoice/purchase-order/delivery-note/credit-note/return-note paths, none in wave 0b. Record that disposition directly and reserve the merge-time reread for verification. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4591-4595,5049-5053,5106`; `lane/w-lot-a-1a:apps/web/src/features/documents/DocumentForm.tsx:97-106,391`

3. The row-order verification says the six-row table contains two multi-row and three single-row queries, totaling five. The Task 4 table is correct: three multi-row queries after the planned Ensure runner plus three single-row queries. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4113-4123,7780`

## Citation audit

### Entry conditions and overlaps

Both required ancestry checks currently exit 1, and the wave-0a ratchet is absent from current `dev`. The plan correctly prohibits Task 1 from starting in that state. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:47-52,243-271`

W-LOT current tip `a7010fe4d` remains code-identical to the pinned `52f5ad796` for `apps/`; its intervening change is documentation-only, so the W-LOT code-line citations remain valid. Current `dev...W-LOT` is 83 files, +4,758/−663. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:36`

T2’s seven overlapping wave paths remain the same, although its current pin and line anchors need refreshing. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:252-266`

### PermissionWriterCensus

Mechanical execution of the exact embedded AST implementation produced no missing or extra fixture rows.

Current `dev` has exactly 20 writer methods:

- `RoleController::{assignRole,removeRole,store,update}`
- `UserController::{store,update}`
- `ResetTenantCommand::handle`
- `TenantInitializationService::assignDefaultRoles`
- two tenant-migration `up()` methods
- `CoffeeShopSeeder::createTestUsers`
- `DatabaseSeeder::createUsers`
- `DemoPharmacySeeder::{seedRoleCoverageUsers,seedTunisiaCashiers}`
- `DemoTenantSeeder::{assignAdminRoleAndMembership,ensureTechnicianUser}`
- `ParapharmacySeeder::createTestUsers`
- `PermissionSeeder::run`
- `RolesAndPermissionsSeeder::{createPermissions,createRoles}`

W-LOT has exactly 23: the same semantic set, with seeder methods renamed to `createPermissionsFrom` and `createLegacyRoles`, plus `LotActionPermissionDelta::{execute,provisionGeneralManager,synchronizeSeededRole}`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3793-3861,4109-4112`

`LOCK_INHERITED_FROM` is proven. Both helpers are private; `callersOf()` finds only `execute`, which acquires at line 54 before calls at lines 78 and 89. `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:45-89,139-180`; `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3970-4006`

The fresh W-LOT role-lock enumeration is:

| Method | Classification |
|---|---|
| `LotActionPermissionDelta::resolveCatalogueRoles` | multi-row, ordered |
| `LotActionPermissionDelta::provisionGeneralManager` | single-row |
| `RoleController::assignRole` | single-row |
| `UserController::store` | single-row |
| `UserController::update` | multi-row, ordered |
| planned `EnsurePermissionsRunner::resolveCatalogueRoles` | multi-row, ordered |

`lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:111-116,142-143`; `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:379`; `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:266,388`; `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4113-4123`

### Frontend census and line audit

I executed the exact embedded `census_0b.py` logic against current W-LOT via a read-only Git-backed virtual filesystem. The fresh output is byte-identical to the pasted table:

- 87 rows
- 46 API + 41 navigation
- 17 files
- 0 unassigned
- 1 unresolved
- SHA-256 for both outputs: `e3a178195acc9772369c27bfe6023b64890bb24aea718e1dc41d913e7df01b0b`

`docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4603-5079`

The principal dev→W-LOT route anchors remain correct:

| Surface | `dev` | W-LOT |
|---|---:|---:|
| Company onboarding | `routes/index.tsx:552` | `:554` |
| Categories | `:1192` | `:1194` |
| Service categories | `:1596` | `:1603` |
| Service edit | `:1620` | `:1627` |
| Channels/ecommerce | `:2248-2308` | `:2255-2315` |
| Roles | `:2346` | `:2353` |
| Growth | `:3202-3220` | `:3209-3227` |

`docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:163`; `lane/w-lot-a-1a:apps/web/src/routes/index.tsx:551-560,1191-1200,1573-1635,2250-2315,2348-2358,3209-3227`

Stable caller anchors also match:

- Channels: `lane/w-lot-a-1a:apps/web/src/features/channels/pages/ChannelListPage.tsx:21-25`, `ChannelCreateWizard.tsx:13-16`, `hooks/useAggregateChannelOrders.ts:19-23`
- Categories and transitive readers: `features/categories/CategoriesPage.tsx:27,140-188`, `components/CategoryForm.tsx:26`, `components/CategorySelector.tsx:47`, `features/catalog/pages/CategoryManagementPage.tsx:19`, `components/catalog/CategorySelect.tsx:38`, `features/inventory/ProductForm.tsx:191`
- Progression: `features/progression/hooks/useCompanyProgression.ts:86-105`, `useModuleReadiness.ts:22-28`, `useRecommendations.ts:21-24`, `components/ModuleCard.tsx:27`, `RecommendationCard.tsx:21-22`
- Companies: `components/organisms/CompanySelector/CompanySelector.tsx:65-120`, `AddCompanyModal/AddCompanyModal.tsx:64-123,326-333`
- Services: `features/services/ServiceCategoryListPage.tsx:55-92,186-202,235-270`, `ServiceListPage.tsx:46-64,116-128,223-231`, `ServiceForm.tsx:69-87,132-143`, `ServiceDetailPage.tsx:54-62,168-182`, `components/molecules/pickers/ServicePicker.tsx:101-108`
- Navigation: `components/organisms/CommandPalette/useCommandPalette.ts:73`, `features/inventory/pages/InventoryHubPage.tsx:41-46`, `components/organisms/Sidebar/Sidebar.tsx:147-148,217,276-277`

### PATCH, wrapper, arithmetic, and downstream contracts

The production PATCH correction is complete in scope: backend W-LOT defines `PATCH /services/{service}` only, and rev 5 changes the form call, test double, unit assertion, verification grep, and e2e status. The unit recipe itself still needs the wait/submit correction identified in M5-2. `lane/w-lot-a-1a:apps/api/app/Modules/Service/Presentation/routes.php:31-35`; `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5447-5453,5942-5975,6084-6097,6215,7772`

The wrapper’s exit and status-file semantics are sound: same-directory temporary file plus rename, initial `pending`, explicit nonzero failure on either invocation, and `ok` only after both succeed. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7955-8028`

Route arithmetic remains correct: 25 writes and 20 reads close, yielding final 127/126. Settled A-1 ceilings remain 152/146/298 post-0a and 152/142/294 after 0b-15; the new permission distribution does not change route or catalogue-key counts. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7589-7608`

The “Signature changes for wave 1” assessment is accurate for REV 5: wave 1 consumes no changed production signature; the private list-valued inheritance fixture is the one direct shape change. The three notes—copy marker-mode semantics, reuse the census/list-valued inheritance proof, and delete the wrapper with the stopgaps—are correct. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8192-8236`

## Rejected false positives

- **No missing or extra PermissionWriterCensus method.** The exact 20/23 fixtures match fresh AST enumeration; comments and unrelated `firstOrCreate` calls do not leak into the result. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2981-3861,4109-4112`
- **`LOCK_INHERITED_FROM` is not an exemption.** Private-helper visibility plus tree-wide caller enumeration and acquire-before-call assertions prove it. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3970-4006,8205-8230`
- **No wrapper failure-semantics defect.** The first or second failed invocation cannot be overwritten by success. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7971-8020`
- **No route-arithmetic defect.** The settled A-1 move and final 127/126 ceilings reconcile. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7589-7608`
- **No need to resequence the whole plan for M0b-2.** Test-first execution within each task is the appropriate remedy. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8172,8190`
- **No Playwright obligation for unmounted `AddCompanyModal`.** Its component-level Vitest pair is the right level; inventing a route would prove a test fixture, not the product. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5143-5162,6216`
- **The W-LOT pin is not code-stale.** Its post-pin commit is documentation-only. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:36,8078`
- **The new categories ruling is not classified as a blocker**, per the gate instructions. It is nevertheless a required rev-5.1 amendment before dispatch or execution.

## Preserve

Preserve these rev-5 repairs:

- The supplied, staged `census_0b.py` and byte-identity verification. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4589-5079`
- The method-level AST writer census, three-way classification, monotonic multi-row detection, ascending row-order check, and tree-wide `callersOf()`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2981-4159`
- Admin all-nineteen v0 semantics and unconditional admin provisioning. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:561-610`
- `/service-categories` canonicalization and service update PUT→PATCH. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5263-5278,5447-5453`
- `AdminRoleMissing` classification on apply and dry-run. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8169`
- Wrapper `pending`/`failed:<which>`/`ok` atomicity. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7955-8028`
- Category DELETE 204, positive nav checks, and per-arm response/console capture. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6187-6217`
- Both whole-wave lane ancestry checks and the wave-0a ratchet prerequisite. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:47-52,243-271`
- PG harness usage, exact staging, and `Phase 0.2.<task>:` subjects. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6234-6246,7265-7268,8174-8175`

## Orchestrator ruling application (categories.view)

The fresh W-LOT seeder shows `products.view` on exactly `admin`, `manager`, `cashier`, `viewer`, `technician`, `operator`, and derived `general_manager`; `accountant` does not hold it. `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:97-108,662-667,769-772,805-808,845-848,870-873`

Rev 5.1 must apply all of the following:

1. Change the authoritative template table so `categories.view` belongs to `admin`, `manager`, `general_manager`, `cashier`, `viewer`, `technician`, and `operator`. Keep `accountant` without it. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4553-4575`

2. Apply the ruling’s mutation half: `categories.create`, `categories.update`, and `categories.delete` belong to `admin`, `manager`, and therefore derived `general_manager`; they are no longer admin-only. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4568-4571`

3. Rewrite `WAVE_0B_ADDITIONS` as:

   - `admin`: all 19
   - `manager`: original 7 plus all four `categories.*` keys = 11
   - `general_manager`: the same 11
   - `cashier`, `viewer`, `technician`, `operator`: `['categories.view']`
   - `accountant`: no additions

   `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:587-629`

4. Update `RolesAndPermissionsSeeder` template grants: add all four category keys to manager; add `categories.view` to cashier, viewer, technician, and operator; let general manager derive the updated manager grant set. `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:97-108,664-667,769-772,805-808,845-848,870-873`

5. Replace the flat `--grant-to` model for the second invocation. The current CLI cannot express the new matrix: granting all 11 keys to all six roles would overgrant ten keys to cashier/viewer/technician/operator. Preserve two fleet invocations by making invocation 2 accept a per-key role map:

   - invocation 1: eight remaining admin-only keys
   - invocation 2: ten keys to manager/general_manager, plus `categories.view` to manager/general_manager/cashier/viewer/technician/operator

   The eligibility snapshot must still be taken once per affected role before any of that role’s additions are written. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:231-237,4167-4179,7696-7709,7997-8014`

6. Update both direct dry-run commands and `scripts/permissions-ensure-0b.sh` to use the new 8-key/11-key grouping and per-key grant map. Retain the wrapper’s current atomic status semantics and adjust invocation labels/comments that say twelve/seven. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7696-7709,7949-8028`

7. Update `LegacyRoleBaselinePredicateTest`, `Wave0bAdditionConstantTest`, catalogue tests, customization-guard tests, fleet tests, idempotency tests, and dry-run assertions to cover all seven affected non-admin role templates. Pin that a pristine manager/general manager receives all 11 additions in one snapshot and each pristine cashier/viewer/technician/operator receives only `categories.view`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:576-580,4167-4179,6113-6174,7776-7786`

8. Add customized-role cases for the four newly affected roles. A customized cashier/viewer/technician/operator must not be silently normalized merely to add `categories.view`; its skipped role must appear in `admin_only=`/the equivalent result detail. Broaden residual R-0b-7 beyond only manager/general_manager. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7941,8030`

9. Replace every “nineteen = seven manager + twelve admin-only” statement with the correct distribution: 8 admin-only keys, 10 manager/general-manager-only keys, and one broadly shared `categories.view` key. Total catalogue keys remain 19. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:56-57,231-235,4553-4575`

10. Keep all six category-read guards. Their product behavior changes from empty/disabled for non-admin product users to enabled for every seeded `products.view` role. Remove residual R-0b-10 because the ruling resolves it; retain R-0b-11 because `CategoryManagementPage` remains unmounted. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5089-5102,5348-5379,7939-7941`

11. Keep mutation controls separately guarded. A viewer/cashier/technician/operator may read categories and use pickers but must not see create/edit/delete/reorder controls; manager/general_manager and admin may. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5383-5392`

12. Rewrite Playwright’s category actors:

   - admin: read and mutate
   - manager: read and mutate; no `/categories` denial
   - at least one read-only `products.view` role: category navigation/read/pickers visible, mutations absent and no mutation request
   - accountant or a custom role lacking `categories.view`: route/navigation/read requests absent

   Add CommandPalette, InventoryHub, and representative category-picker coverage while retaining per-arm 5xx/console capture. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6181-6217`

13. Rewrite CategoriesPage and CategorySelector Vitest matrices to distinguish view-only from mutation authority, and supply valid bodies for all nine promised files. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5484-5519,5578-5684,6238-6241`

14. Keep route and key arithmetic unchanged: 19 new keys, 25 write closures, 20 read closures, post-0a 152/146/298, post-0b-15 152/142/294, and final 127/126. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7589-7608`

15. Update Q-0b-4, Global Constraints, Deploy, verification, residuals, change log, and handback wording together. The production stopgap CLI signature will expand, but wave 1 still consumes no changed production contract because it deletes that command and wrapper; the three existing re-pin notes remain correct. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:45-68,231-237,7945-8030,8192-8236`

## Dispatch assessment

REV 5 is not dispatchable. Its route arithmetic, writer locking, wrapper, canonical API paths, and exact round-4 service fixes are strong, but four major defects remain:

- mutually contradictory category-caller instructions;
- incomplete and invalid frontend red-test recipes;
- a non-runnable Task 13 fixture/test implementation;
- missing automated coverage for several rev-5 UX-visible surfaces.

Separately, rev 5.1 must apply the settled category template distribution above. Even after those edits, execution cannot start until W-LOT, T2, and wave 0a are merged into `dev`; all three preconditions currently fail. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:47-52,243-271`

VERDICT: CHANGES-REQUIRED