# Codex plan gate r2 — RBAC wave 0b plan rev 2 (gpt-5.6-sol, high, read-only, 2026-09-10)

`git rev-parse --short HEAD` → **`cc71f0efa`**

## Rev-1 closure table

| Rev-1 finding | Rev-2 disposition |
|---|---|
| **B0b-1 — admin v0 omitted all nineteen additions** | **CLOSED** at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:45-46,547-589,968-999`. The plan correctly separates admin’s nineteen-entry v0 row from the seven keys deployed to `manager`/`general_manager` through `--grant-to`. |
| **B0b-2 — knowingly-red Task 1 commit** | **CLOSED** at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:47,924-1032,3561-3621`. Constant arithmetic and catalogue membership now ship in different commits with task-local run lists. |
| **B0b-3 — lock coverage was file-level, not writer-level** | **NOT CLOSED.** The AST census is materially better, but its row-order test fails against the required W-LOT lane and its `methodSource()` helper can inspect later methods rather than the named method. See BLOCKER-2. The change log’s “CLOSED” claim at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4787` is false. |
| **B0b-4 — frontend callers knowingly issue denied requests** | **NOT CLOSED.** Channels, categories, progression, company onboarding, and the named route guards are planned, but the service-category callers still lack permission guards and the `AddCompanyModal` instruction targets a nonexistent trigger. See MAJOR-3. The “CLOSED” claim at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4788` is false. |
| **B0b-5 — missing admin reported success** | **CLOSED** at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2191-2203,2252-2265,2485-2509`. Apply and dry-run both resolve to `FAILED`, `reason=admin_role_missing`, and non-zero command exit. |
| **B0b-6 — two invocations lacked one success condition** | **CLOSED for ordinary command exits** at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4683-4738`. Invocation 2 is unreachable after invocation 1 fails, failure writes a named marker, and `ok` is written only after both return zero. Abrupt-process status durability remains a minor. |
| **M0b-1 — fleet tests/helpers/provider absent** | **CLOSED** at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1599-1688,1844-1952,1870-1918`. The helper implementations match the real provisioning trait, including its PostgreSQL branches. |
| **M0b-2 — red evidence not executable/test-first** | **PARTLY REJECTED-correctly, partly NOT CLOSED.** Not reordering whole tasks merely to present test files first is acceptable; the execution-discipline note at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4793,4811` is sufficient. It does not excuse the invalid Task 13 red procedure or prose-only tests at `:4032-4068`. |
| **M0b-3 — prose stubs/placeholders** | **NOT CLOSED.** Task 4, Task 5, Task 6, Task 9, and Task 13 still contain implementation or test placeholders. The change log’s “all four remaining methods” and “written-out tests” claim at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4794` contradicts `:2621-2700,3554-3558,3810-3834,4032-4068`. |
| **M0b-4 — task-exact staging and subjects** | **NOT CLOSED for staging; CLOSED for subjects.** Subjects use `Phase 0.2.<task>:`. Staging still says to discover paths later or choose an alternative path at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3651,3783,3834`, contrary to the “every task has an exact `git add`” claim at `:4795`. |
| **M0b-5 — PG/static harness** | **CLOSED** at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:51-56,1941-1952,3260-3268,4068-4072,4427-4446`. Every PostgreSQL PHPUnit command inspected uses `-c phpunit-pgsql.xml`. |
| **Minor — one-start-read wording** | **CLOSED** at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1284`. |
| **Minor — marker omitted mode** | **CLOSED** at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2066-2103`. |
| **Minor — `firstOrCreate` prose mismatch** | **CLOSED** at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2006-2008`. |
| **Minor — unreachable `Failed` result** | **CLOSED** at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2275-2289`. |
| **Citation — stale ref pins** | **CLOSED.** The ref table at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:25-35` still matches the current tips. |
| **Citation — stale `UserController` lines** | **CLOSED** at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2693-2700`. |
| **Citation — T2 counting route** | **CLOSED** at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3760-3767`; the route is at `lane/t2-receipt-spine:apps/api/app/Modules/Inventory/Presentation/routes.php:307-308`. |
| **Citation — seeder dev/lane differences** | **CLOSED in principle** at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:35,242-253,4804`; executing agents are told to rederive after merge. |
| **Cross-plan — rename map, legacy baseline, fleet runner/provider, scanner names, seeder branch, convention 09** | **CLOSED or REJECTED-correctly.** Rev 2 preserves those contracts at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4817-4823`. |
| **Cross-plan — wave-1 `DryRunRollback` and stopgap deletion alignment** | **NOT CLOSED, deferred to wave 1’s pending fix round.** This is not counted against the wave-0b verdict because the user expressly excluded wave 1 from this round. |
| **Cross-plan — lock test inheritance contract** | **NOT CLOSED.** The current W-LOT private-helper proof is valid, but the wave-1 re-pin instruction overgeneralizes it. See MAJOR-6. |

## BLOCKER

### B2-1 — T2 is an undeclared whole-wave entry dependency

The plan says only Tasks 8 and 9 are to be split when T2 is absent, and even mislabels them as “Task 9” and “Task 10” at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:223-230`. The actual T2-dependent tasks are Task 8 and Task 9 at `:3760-3772,3803-3809`.

At the current tips, T2 is not an ancestor of `dev`, yet its diff overlaps at least seven wave-0b paths:

- `.github/workflows/ci.yml`, which wave 0b edits in Task 12 at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3958-3995`; T2 has its live PG-filter hunk at `lane/t2-receipt-spine:.github/workflows/ci.yml:1141-1145`.
- `apps/api/app/Http/Middleware/RequireAnyPermission.php`, planned at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3803-3834`; T2 owns its response message at `lane/t2-receipt-spine:apps/api/app/Http/Middleware/RequireAnyPermission.php:21-30`.
- `apps/api/app/Modules/Inventory/Presentation/routes.php`, planned at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3760-3783`; T2 owns the counting route at `lane/t2-receipt-spine:apps/api/app/Modules/Inventory/Presentation/routes.php:307-308`.
- `apps/api/database/seeders/RolesAndPermissionsSeeder.php`, repeatedly edited by wave 0b, while T2 adds transfer keys/grants at `lane/t2-receipt-spine:apps/api/database/seeders/RolesAndPermissionsSeeder.php:196-202,602-605`.
- `apps/api/tests/feature-lane-manifest.json`, recomputed in Task 14 at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4414-4419`; T2 owns current ceilings and Inventory membership at `lane/t2-receipt-spine:apps/api/tests/feature-lane-manifest.json:8-10,834-842`.
- `apps/web/src/hooks/permissionsMap.generated.ts`, regenerated at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4421-4425`; T2 owns new transfer entries at `lane/t2-receipt-spine:apps/web/src/hooks/permissionsMap.generated.ts:118-123`.
- `docs/glossary.md`, edited in Task 11 at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3928-3937`; T2 has glossary additions at `lane/t2-receipt-spine:docs/glossary.md:80-92`.

This also violates rev 2’s own reviewer rule: “Any hit is a blocker” at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4629-4631`.

Required correction: either make T2 ancestry a whole-wave entry condition or expand the split to every overlapping task/path and specify recombination arithmetic. The current “split only Tasks 8/9” instruction is unsafe.

### B2-2 — the supplied lock-coverage test is red against the mandatory W-LOT entry tree

W-LOT must merge before Task 1 starts at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:223-230`. Its `LotActionPermissionDelta::provisionGeneralManager()` performs `lockForUpdate()` without either required `orderBy()` at `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:139-157`.

Rev 2 classifies that method under `LOCK_INHERITED_FROM` at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3008-3013`, then applies the row-order assertion to all locked and inherited methods and requires at least two `orderBy()` calls at `:3197-3225`. Therefore Task 4 cannot reach its promised green state without either:

- changing W-LOT-owned production code that the plan does not list as a Task 4 modification, or
- narrowing the row-order assertion to queries whose cardinality can exceed one and documenting the `provisionGeneralManager()` exception.

There is a second proof defect: `methodSource()` finds the method signature and then returns roughly another 4,000 bytes, potentially including later methods at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3228-3235`. A later correctly ordered query can therefore satisfy the regex for the named method. The test’s AST census is method-level; its decisive row-order assertion is not.

## MAJOR

### M2-1 — the equality snapshot is read twice, contradicting its own contract and test

For each `--grant-to` role, `EnsurePermissionsRunner` reads current permissions at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2205-2228`, then calls `grantMissing()`, which reads them again at `:2331-2355`.

That contradicts:

- the “snapshot ONCE” contract at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2217-2220`;
- the seven-key eligibility rationale at `:215`;
- the proposed test requiring exactly one permission-join read at `:3248`.

Pass the captured `$current` into `grantMissing()` or calculate `$missing` from that snapshot. As written, the supplied implementation and its test cannot both pass.

### M2-2 — Task 4 is not an executable implementation recipe

Despite the change-log claim that all methods are written out, Task 4 still contains:

- a placeholder constructor dependency list and role payload at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2613-2628`;
- omitted checks and response expressions in `update()` and `destroy()` at `:2635-2668`;
- an `assignRole()` closure that uses uncaptured/undefined `$tenantId` and `$roleName`, plus placeholder resolution and response code, at `:2671-2686`;
- only prose for `removeRole()` at `:2689`;
- only a citation table and prose for both `UserController` writers at `:2693-2700`;
- a reset-command lock call without a supplied constructor/import change in the subsequent reset instructions.

These are precisely the execution ambiguities round-1 M0b-3 rejected. A copy/paste implementation would not compile, and an executor must redesign the methods rather than apply the plan.

### M2-3 — the frontend census still omits the service-category permission guards

The task declares `service-categories.view/create/update` at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3421-3423`, but its sixteen-row guard census at `:3451-3468` contains no service-category permission row.

At the required post-W-LOT tree:

- the service list route is guarded only by `moduleKey="services"` at `lane/w-lot-a-1a:apps/web/src/routes/index.tsx:1573-1585`;
- the service-category page route is also guarded only by `moduleKey="services"` at `lane/w-lot-a-1a:apps/web/src/routes/index.tsx:1599-1609`;
- `ServiceCategoryListPage` runs its query on tenant/company presence alone and exposes POST/PATCH/DELETE mutations at `lane/w-lot-a-1a:apps/web/src/features/services/ServiceCategoryListPage.tsx:55-98`;
- `ServiceListPage` queries categories with only tenant/company enablement at `lane/w-lot-a-1a:apps/web/src/features/services/ServiceListPage.tsx:46-53`;
- `ServiceForm` does the same at `lane/w-lot-a-1a:apps/web/src/features/services/ServiceForm.tsx:68-76`.

The URL repair at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3477-3494` is correct, including PUT→PATCH, but it does not prevent a caller lacking `service-categories.view` from issuing a guaranteed 403 or hide create/update controls from a holder of view alone.

`AddCompanyModal` is also misclassified as a live “second independent create surface.” It accepts `isOpen`, posts directly, and has no internal permission guard at `lane/w-lot-a-1a:apps/web/src/components/organisms/AddCompanyModal/AddCompanyModal.tsx:41-72,123-125`; there is no production instantiation of the component. The instruction “its trigger renders only” at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3467` cannot be executed. Because its inclusion is settled, Task 6 must either guard the component/mutation itself and test it, or identify and guard a real production owner.

The shared `useCategoryTree()` question is likewise left to handback-time choice at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3537`, rather than resolving which caller receives the gate.

### M2-4 — the e2e contract does not cover every UX-visible change

The proposed e2e spec does include explicit zero-5xx and console capture at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3627-3630`. It does not assert:

- `AddCompanyModal` behavior;
- progression-management controls introduced by the task;
- a role that can reach Services but lacks `service-categories.view`, proving that `ServiceListPage`, `ServiceForm`, and the category page suppress their calls;
- an actual admin company POST—the line only says the switcher reaches onboarding at `:3627`.

The final manual probe then contradicts the planned frontend behavior. It expects a manager to attempt category creation and `POST /companies` and observe 403s at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4451-4455`, while the Task 6 e2e contract requires the Categories deep link to deny before loading and the Add-company control to be absent with no request at `:3628`. The probe must assert denial UI plus request absence, not deliberately exercise requests the new guards are meant to suppress.

### M2-5 — several red tests remain prose, invalid, or unstaged

The plan cannot substantiate “each red compiles and fails for the stated reason” because:

- Task 5 describes its route test but does not provide the class/setup/body at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3373-3379`.
- Task 6 requires pairs in unspecified “owning test files” without naming or supplying them at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3554-3558`.
- Task 9 supplies no test body and leaves both the production message and test path unresolved at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3810-3834`, even though the current T2 text is known at `lane/t2-receipt-spine:apps/api/app/Http/Middleware/RequireAnyPermission.php:21-30`.
- Task 13’s “written-out” example calls undefined helpers—`registerTwoCompanies()`, `userHolding()`, and `serviceIn()`—without supplying them at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4032-4057`.
- Task 13’s red instruction says to remove “the grant or the restriction” at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4068`. Removing the grant makes an expected-forbidden assertion remain green for the wrong reason; it does not prove company/location isolation.

M0b-2 does not require wholesale task resequencing. It does require every claimed red fixture to be compilable and to turn red specifically when the target protection is absent.

### M2-6 — the wave-1 inheritance re-pin is not proven by the supplied test

The section correctly reports no existing production signature change at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4813-4823`. Its second re-pin says `TemplateDeltaApplier` should inherit from `PermissionSyncService` at `:4828`.

The proof only rejects additional callers in the helper’s own file at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3155-3166`; it does not reject callers in another class/file and does not assert that the helper is private. That is sufficient for the present W-LOT helpers because both are private and called from `execute()` at `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:45-89,139-180`. It is not a general proof for a separate `TemplateDeltaApplier` class with multiple external entry points.

The re-pin must say either that every external entry point is represented and proven, or that `TemplateDeltaApplier` acquires the lock itself. This finding concerns the accuracy of wave 0b’s signature/re-pin section, not a review of the wave-1 plan.

## MINOR

1. The global constraint says most task subjects still use `feat(...)`/`test(...)`, although the task-local subjects are already in final `Phase 0.2.<task>:` form. Correct the stale sentence at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:57`.

2. “Two additions wave 1 should consume” introduces a three-item list at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4825-4829`.

3. The Growth route citations stop one line before the enclosing route closes: exact blocks are `dev:apps/web/src/routes/index.tsx:3202-3220` and `lane/w-lot-a-1a:apps/web/src/routes/index.tsx:3209-3227`, versus `:3202-3219` / `:3209-3226` in the plan at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3462`. The substantive child-route citations remain recognizable.

4. `RolesPage` moved under W-LOT from dev queries at `dev:apps/web/src/features/settings/RolesPage.tsx:73-89` to lane lines `lane/w-lot-a-1a:apps/web/src/features/settings/RolesPage.tsx:68-83`. Task 13b still gives only the dev range at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4122`, despite saying lane citations were remeasured.

5. The wrapper preserves normal exit semantics, but it does not clear a previous `permissions_ensure=ok` before starting and writes markers directly rather than by temporary-file rename at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4702-4735`. An abrupt termination can therefore leave a stale healthy marker or a truncated file. Write `pending` before invocation 1 and atomically rename each final marker.

6. Phase 0’s split instruction calls 0b-12/0b-13 “Task 9 and Task 10” at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:230`; they are Task 9 and Task 8 at `:3760,3803`.

## Citation audit

- Refs remeasured:

  - `dev` → `630afa86f`
  - `lane/w-lot-a-1a` → `52f5ad796`
  - `lane/t2-receipt-spine` → `951a7637e`
  - neither open lane is an ancestor of `dev`
  - W-LOT diffstat remains 83 files, 4,714 insertions, 663 deletions
  - T2 diffstat remains 100 files, 13,144 insertions, 546 deletions

  These agree with `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:25-35`.

- Fresh writer enumeration:

  - `dev` produces exactly **20** writer methods.
  - W-LOT produces exactly **23**: the same logical set plus `LotActionPermissionDelta::{execute,provisionGeneralManager,synchronizeSeededRole}`.
  - There are **no missing or extra writer methods on W-LOT** relative to the plan’s pre-Task-4 statement at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2736-2740`.
  - Comparing the post-W-LOT-shaped fixture literally to dev, dev-only entries are `RolesAndPermissionsSeeder::{createPermissions,createRoles}`; fixture-only replacements are `RolesAndPermissionsSeeder::{createPermissionsFrom,createLegacyRoles}`, plus the three W-LOT delta methods. `EnsurePermissionsRunner::run` is a fourth later addition created by Task 4. That evolution makes the final partition 24 discovered writers; `RoleController::destroy` is deliberately a declared non-discovered locked entry at `:2978-2998`.

- `LOCK_INHERITED_FROM` is proven for the two current W-LOT helpers, not waived: `execute()` locks before calling them, both helpers are private, and no other method in that file calls them at `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:45-89,139-180`. The row-order assertion remains independently broken as described in BLOCKER-2.

- Frontend route lines rechecked:

  | Surface | dev | W-LOT |
  |---|---:|---:|
  | Company onboarding | `routes/index.tsx:549-558` | `routes/index.tsx:551-560` |
  | Categories | `routes/index.tsx:1189-1198` | `routes/index.tsx:1191-1200` |
  | Service edit | `routes/index.tsx:1616-1627` | `routes/index.tsx:1623-1634` |
  | Channels group | `routes/index.tsx:2244-2308` | `routes/index.tsx:2251-2315` |
  | Settings roles | `routes/index.tsx:2343-2352` | `routes/index.tsx:2350-2359` |
  | Growth | `routes/index.tsx:3202-3220` | `routes/index.tsx:3209-3227` |
  | RolesPage queries | `RolesPage.tsx:73-89` | `RolesPage.tsx:68-83` |

  The plan’s route-line table at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3451-3468,4122-4124` is mostly correct, subject to the two minor citation fixes above. Non-route Task 6 caller files inspected are byte-identical between dev and W-LOT.

- `/service-categories` is the correct API path, and PATCH is the correct update verb. The repair table at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3477-3492` is accurate; only the permission-guard half is missing.

- The settled amendment arithmetic is correct:

  - post-0a: **152 writes / 146 reads / 298 baseline**
  - post-0b-15: **152 / 142 / 294**
  - final wave 0b: **127 / 126**, from 25 write and 20 read closures

  See `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4117-4118,4393-4408`.

- All inspected task subjects use `Phase 0.2.<task>:`. Every PostgreSQL PHPUnit command uses the PG harness. The contrary prose at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:57` is editorial only.

## Rejected false positives

- **Do not flag 20 versus 23 as a census defect.** Twenty is correct on dev; twenty-three is correct after W-LOT. The method renames and three lane helper writers explain the delta at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2738-2740,3038-3042`.

- **Do not reject current `LOCK_INHERITED_FROM` as a waiver.** It is a real proof for W-LOT’s private helpers at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3137-3168`. The blocker is the row-order assertion, not the current caller relationship.

- **Do not collapse admin and `--grant-to`.** Admin’s nineteen-entry v0 row and the seven manager/general-manager deployment keys are correctly distinct at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:45-46,4715-4732`.

- **Do not reject the wrapper’s ordinary exit flow.** Its two explicit failure arms and final success write are correct at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4709-4738`. The remaining concern is abrupt termination/stale-file hardening.

- **Do not move 0b-15 back into wave 0a.** The settled ceilings and placement are correctly reflected at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4117-4118,4393-4408`.

- **Do not require wholesale task resequencing for M0b-2.** The execution-discipline ruling at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4811` is acceptable. What remains mandatory is replacing invalid or prose-only red fixtures.

- **Do not flag the lock key, NULL-team role resolution, batch middleware composition, `progression.manage`, or canonical service-category path.** Those settled choices are correctly preserved at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:42-49,3421-3434,3477-3494,3711-3742`.

## Preserve

- The nineteen-key admin v0 row and seven-key `manager`/`general_manager` deploy split at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:45-46,4715-4732`.
- `AdminRoleMissing` → `FAILED`, `reason=admin_role_missing`, for apply and dry-run at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2191-2203,2252-2265`.
- The W-LOT advisory key, NULL-team tolerance, no role re-homing, and name/id row order at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:48-49`.
- The fleet helpers, migrations-behind case, and provider placement at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1599-1688,1844-1952`.
- The `/service-categories` URL and PUT→PATCH repair at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3477-3494`.
- The distinct `users.assign-roles` and `roles.view` frontend rules at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4134-4139`.
- The settled 152/146/298 → 152/142/294 → 127/126 arithmetic at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4117-4118,4393-4408`.
- The PG harness and final commit-subject format at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:51-57`.

## Owner decisions required

No new product or authorization-policy decision is required. Q-0b-1 through Q-0b-4 remain settled.

The following are plan corrections, not owner choices:

- reconcile the whole-wave T2 overlap;
- make the supplied coverage test pass against W-LOT without weakening its guarantee;
- complete the writer implementations and red tests;
- specify service-category/AddCompanyModal guards and matching e2e assertions.

The protected route-baseline blob remains an explicit execution-time owner action at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3983-3987`; it is not a new design decision.

## Dispatch assessment

Rev 2 closes the admin-v0 error, missing-admin success path, ordinary two-command deployment semantics, fleet harness omissions, canonical service-category URL, PG invocation format, commit subjects, and most stale citations.

It is not dispatchable because:

1. its T2 split permits work on five additional paths that overlap an unmerged lane, contrary to its own any-hit-is-blocker rule;
2. its generated lock test fails on the mandatory W-LOT entry tree;
3. `EnsurePermissionsRunner` violates its one-snapshot assertion;
4. several runtime-writer implementations and red tests remain placeholders or do not compile as supplied;
5. service-category callers and `AddCompanyModal` are not actually guarded;
6. e2e/manual evidence is incomplete and internally contradictory;
7. the wave-1 inheritance re-pin asserts more than the current proof establishes.

The rev-2 change log must therefore reopen B0b-3, B0b-4, M0b-3, and the staging half of M0b-4 before dispatch.

VERDICT: CHANGES-REQUIRED