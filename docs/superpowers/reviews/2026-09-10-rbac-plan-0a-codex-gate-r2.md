# Codex plan gate r2 — RBAC wave 0a plan rev 2 (gpt-5.6-sol, high, read-only, 2026-09-10)

`git rev-parse --short HEAD`: `72498e83f`

Current comparison refs:

| Ref | Current tip | `dev...ref` shortstat |
|---|---:|---:|
| `dev` | `630afa86f` | — |
| `lane/w-lot-a-1a` | `52f5ad796` | 83 files, +4,714/−663 |
| `lane/t2-receipt-spine` | `951a7637e` | 100 files, +13,144/−546 |
| `origin/dev` | `ad1d6ceb1` | does not contain `6415062b9` |

S-1 commit `6415062b9` is an ancestor of local `dev`, but not `origin/dev`.

## Rev-1 closure table

| Rev-1 finding | Round-2 disposition |
|---|---|
| B-1 — tombstone classification was self-certifying | **NOT CLOSED.** The fixture/classifier coupling exists, but the behavior test invokes each closure once with fixture parameters and observes only the default DB connection. It does not prove “for any parameters,” absence of branches, or absence of non-default/non-DB mutation as claimed at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1055-1059,1088-1129`. |
| B-2 — no convention-08-compliant callable detector/liveness/CI guard | **NOT CLOSED.** The pre-boot provider and CI step are present, but T2 is not compilable under PSR-4, contains two prose placeholders, scans the wrong route universe, and reduces declared classifications to covered/uncovered; see `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:864-878,1348-1400,1441,1537,1875-1891`. |
| B-3 — Identity gates would break reachable RolesPage | **CLOSED** by the settled relocation of T3/T3b to 0b-15. Wave 0a now has no frontend files: `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:5,75,90,2063-2065,2913`. |
| M-1 — HEAD/dev citations and T0 red state | **CLOSED for T0.** T0 now explicitly branches from and cites `dev:` and gives the correct alternate failure for an old base at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:118-131,256-267`. Fresh stale pins and other unqualified citations remain as new findings. |
| M-2 — “no CI event” was false; registration deferred and class count wrong | **CLOSED.** Rev 2 correctly distinguishes manual `workflow_dispatch` coverage from the missing PR→`dev` gate and registers six classes in 0a at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2655-2682`; programme correction at `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:106-117`. |
| M-3 — T3b red/green order impossible | **CLOSED in 0a by removal** at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2063-2065,2916`. The claimed correction inside the separate 0b plan is outside this review’s scope. |
| M-4 — T5 did not state the accepted enforcement classifications | **CLOSED.** The five classifier outcomes and action-gate standard are now stated at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2487-2501,2534-2548`. |
| M-5 — invalid catalogue examples and missing scaffold transition | **NOT CLOSED.** The old examples and transition were corrected, but the replacement canonical example introduces three new nonexistent keys at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2475-2484`, and line 2530 misstates the frontend permission type. |
| M-6 — commit subjects violate repository policy | **NOT CLOSED.** Five subjects are now valid, but `Phase 0.1.6a` and `Phase 0.1.6b` are not numeric `<major.minor.patch>` subjects: `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:35,2699,2774`; rule at `AGENTS.md:15-16`. |
| M-7 — UI work omitted Playwright | **CLOSED by removal.** No `apps/web` file remains in 0a, so the E2E obligation correctly moved with 0b-15: `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:86-90,2743`. |
| m-1 — stale programme pins | **NOT CLOSED.** They were refreshed during rev 2 but are stale again at current tips: `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:17`; `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:33-36,214-225`. |
| m-2 — wrong Promotion/Uom rationale | **CLOSED.** Rev 2 now distinguishes eleven routes with no check from the four controller-checked UoM routes: `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2141-2144,2241-2246,2266-2289`. |
| m-3 — T3 write count | **CLOSED by removal** at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2063-2065,2923`. |
| m-4 — snippets contradicted the no-`mixed` rule | **CLOSED.** The rule now permits only the narrowed `json_decode` annotation and the closure filters use `string\|object`: `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:31,1718-1720,2200-2205`. |
| m-5 — Pint commands were broader than owned files | **CLOSED per task**, where explicit paths are now used. A new final-gate pathspec defect remains at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2738-2741`. |
| Citation audit | **NOT CLOSED.** Several old citations remain, and the change log’s “ALL APPLIED” claim is false: `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2926`. |
| Rejected — route arithmetic was wrong | **REJECTED correctly.** The revised 152/146/298 and later 142/294 arithmetic is sound for API routes. The new failure is that the proposed scanner does not limit itself to those routes. |
| Rejected — allow-lists, T1 alias shape, T4 mappings, and manifest disjointness | **REJECTED correctly.** Those conclusions remain supported by code, subject to the new implementation defects below. |

## BLOCKER

### B-1 — The scanner scans all Laravel routes, so its baseline command cannot produce 167/148 or final 298

The binding scope is routes in `apps/api/routes/api.php` and module `routes.php`, not framework/web/dev routes (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1424-1434,1468-1483`). The plan nevertheless passes the entire live collection at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1771-1774`, and the scanner loop has no `api/` filter at `:1378-1396`.

Artisan could not boot in this read-only worktree because `apps/api/vendor/autoload.php` is absent. It did boot from the shared checkout at current local `dev` `630afa86f`:

- Live router: 1,092 routes.
- API universe: 1,054 routes.
- Non-API/framework routes: 38.
- Proposed classification over every live route: **176 writes + 177 reads = 353**.
- Same classification restricted to `uri` beginning `api/`: **167 writes + 148 reads = 315**.

Consequently, after T4’s seventeen closures, the scanner as written would produce **161/175 and 336 keys**, not **152/146 and 298**. The expected command and stop condition at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1985-1993` cannot pass.

The CSV fallback independently confirms the intended API arithmetic: 1,054 rows; 326 uncovered = 177 writes/149 reads; minus four tombstones, six self-service writes, and `GET /auth/me` gives 167/148; T4 gives 152/146 and 298; 0b-15’s four reads give 142 and 294 (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1482-1485`).

Required correction: constrain the scanner’s production scan to the API route universe, add a liveness case proving a non-API route is ignored, and add a post-T4 regeneration check that reproduces 152/146 and 298.

### B-2 — T2 is not compilable or minimally complete as written

The plan creates `Support/RouteCoverageScanner.php` but defines `RoutePermissionCoverageScanner` and imports that class elsewhere (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:870,1348-1400,1555,1823`). Composer maps `Tests\` directly to `tests/` and has optimized autoloading enabled (`apps/api/composer.json:60-66,109-112`). A fresh install skips this non-PSR-4 class; an existing autoloader searches for `RoutePermissionCoverageScanner.php`. The first T2 execution therefore fails with class-not-found, not the stated baseline assertion.

The note saying to rename the file “if PSR-4 refuses” is not executable planning (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1400`). The file must be named correctly in every file list, command, `git add`, and CI-dependent reference.

Two required implementation files are also only prose placeholders:

- `RouteCoverageRatchetResult.php`: `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1441`
- `LivenessRouteFixture.php`: `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1537`

Both are absent from Task 2’s authoritative Files list at `:864-878` and the Created table at `:47-67`, although later PHPStan and `git add` commands name them at `:2010-2017`.

There is a further contract mismatch: the Created table promises `countsByClassification()` at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:54`, but the stated interface omits it at `:890`, and the supplied class ends after `readCount()` at `:1317-1345`.

T2, and therefore dependent T4 and T6, is not dispatchable until all support classes are supplied in full under PSR-4-correct names.

### B-3 — The tombstone test does not prove the binding “unconditional 410 with no mutation” exemption

The plan claims it proves the closures return 410 “for ANY parameters, with no branch” and cannot grow unnoticed (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1055-1059`). Its implementation invokes each closure once with one fixture parameter set and checks one response (`:1088-1123`). A closure conditional on another identifier still passes.

It also observes only `DB::getQueryLog()` on the default connection (`:1102-1128`). A closure can mutate a named database connection, cache, queue, filesystem, or external service and still pass. Merely asserting `Closure` at `:1094-1100` does not make its body branch-free or inert.

This matters because these four routes are excluded from the ratchet on that proof (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1466`). Current HEAD happens to contain inert closures at:

- `apps/api/app/Modules/POS/routes.php:187-194,222-243`
- `apps/api/app/Modules/POS/routes_orders.php:60-67`

The test needs a static source/AST shape pin for the exact inert response body, or equivalent instrumentation that demonstrably fails when a branch or mutation is planted. The reviewer prompt at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2818-2822` must be true for more than a default-connection SQL mutation.

## MAJOR

### M-1 — Phase 0 necessarily aborts on the accepted CI overlap

Rev 2 explicitly accepts `.github/workflows/ci.yml` as the sole file-level overlap (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:37-39`). Phase 0 nevertheless checks every path and says “Expected: no output at all” and “Any output” requires stopping and moving the item to 0b (`:102-110`).

At current tips both lane checks output `.github/workflows/ci.yml`; their only hunks remain around 1130 and 1138. The programme repeats the same internal contradiction at `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:92`.

Phase 0 must explicitly exempt `ci.yml`, verify only its hunk locations, and retain the existing stop condition if either lane moves into `backend-architecture`.

### M-2 — The final PHPStan gate deterministically fails on inherited Architecture fixtures

Task 6 runs PHPStan over the whole `tests/Architecture` directory at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2738-2741`, despite describing this as analysis over touched paths.

Running that exact command on current `dev` exits 1 with **45 errors in 72 files**, including deliberately invalid detector fixtures such as:

- `apps/api/tests/Architecture/BroadcastFixtures/sample-channel-routes-control-flow-bypass.php:21`
- `apps/api/tests/Architecture/ControllerFixtures/FixtureControllerWithAttribute.php:17-18`

The final command must list the exact created/touched PHP files, as Task 2 already does at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2008-2011`.

The adjacent Pint command is also incomplete: it runs from `apps/api` but uses the cwd-relative pathspec `apps/api/tests/Architecture/*.php`, so the substitution normally expands to nothing (`:2741`). Use explicit files or a top-anchored pathspec.

### M-3 — T5 replaces invalid permission examples with another invalid canonical example

The new canonical route example uses:

- `widgets.view`
- `widgets.create`
- `widgets.delete`

at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2475-2484`. None exists in `RolesAndPermissionsSeeder::permissionNames()` or `apps/web/src/hooks/permissionsMap.generated.ts`. This directly contradicts T5’s own generated-map rule at `:2505-2509,2546-2548`.

Use a real controller and real seeded keys, preferably one of T4’s route groups, so the “canonical” example compiles and can be checked against code.

Line 2530 is also false: an unknown permission is a compile error because `Permission` is `GeneratedPermission | UiAliasPermission` (`apps/web/src/hooks/usePermissions.ts:1-8`), and each side is a closed key union (`apps/web/src/hooks/permissionsMap.generated.ts:312`; `apps/web/src/hooks/uiAliasPermissions.ts:15`).

### M-4 — The liveness fixture does not assert the classifications it declares

The fixture says every record has an exact `expected_classification`, including `gated` (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1447-1483`), and the test says it verifies each exact classification at `:1831-1835`.

The implementation collapses every non-`uncovered` value to the single word `covered` at `:1879-1889`. A gated route misclassified as public, self-service, or tombstone passes. This also explains why the promised `countsByClassification()` contract was never implemented.

The core Convention 08 properties—new violation, stale entry, and same-CI-job execution—are present (`docs/conventions/08-DETECTOR-LIVENESS.md:48-64`). The defect is narrower: the fixture and test overclaim exact five-way classification. Either test `RouteCoverageClassifier::classify()` directly against the declared enum or rename the fixture field and prose to `expected_coverage`.

The matched-growth case itself is correctly an executable record of deferred anti-growth, not a currently failing anti-growth test: owner-pinned matched-growth is required only once the protected blob exists (`docs/conventions/08-DETECTOR-LIVENESS.md:54`).

### M-5 — The programme assigns the wrong permission to the UsersPage guard

The programme says the UsersPage roles query, both RolesPage queries, the `/settings/roles` route, and the Settings card are all guarded by `roles.view` (`docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:124`).

That is inconsistent with D4: `GET /roles` deliberately allows either `roles.view` or `users.assign-roles`, because an assigner needs the role-name list (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1610,1616-1622`). The programme’s own retained analysis identifies the UsersPage fix as `hasPermission('users.assign-roles')` at `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:291-308`.

Correct 0b-15’s wording:

- UsersPage `/roles` query: `users.assign-roles`.
- RolesPage queries, `/settings/roles` route, and Settings card: `roles.view`.

### M-6 — T2’s stated missing-baseline red occurs after the baseline has already been generated

Step 5 generates the baseline at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1985-1993`. Step 6 then describes the expected failure “before the baseline exists” at `:1995-1999`. Following the plan in order never produces that red.

Run the full class before Step 5, capture the missing-baseline failure, then run the generator and the green class. The programme’s blanket handback requirement depends on this ordering (`docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:93`).

## MINOR

- Current ref pins and shortstats are stale. Replace the values at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:17` and `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:33-36,214-225` with `dev 630afa86f`, W-LOT `52f5ad796` at 83/+4,714/−663, and T2 `951a7637e` at 100/+13,144/−546.

- Task 6’s two subjects use `0.1.6a` and `0.1.6b` (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:35,2699,2774`). `AGENTS.md:15-16` specifies numeric `<major.minor.patch>`. Use distinct numeric patches, such as `0.1.6` and `0.1.7`, or combine the commits.

- The anti-growth comments say 0a “cannot touch” `ci.yml` even though Task 6 now does exactly that (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1572-1577,1704-1709`; programme equivalent at `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:98-104`). The real reason for deferral is owner-pinned variable/bootstrap sequencing, not file immutability.

- The workflow validation command masks an `actionlint` semantic failure whenever Python can parse the YAML (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2684-2687`). Branch on `command -v actionlint`; use Python only when it is unavailable.

- The stated “608 `can:` strings” is stale at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2418,2611`. At HEAD there are 621 literal `'can:` occurrences and 598 literal `middleware('can:` occurrences. Define the metric and regenerate it.

## Citation audit

All material `path:line` references in both plans were opened at HEAD, with `git show` used for explicitly ref-qualified claims. Wrong or stale citations:

- The current ref citations are stale at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:17` and `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:33-36,214-225`; current values are listed above.

- The statement that HEAD is older than `dev` for only two files is false because `.github/workflows/ci.yml` also differs (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:19`). In particular, the S-1 CI allow-list exists at `dev:.github/workflows/ci.yml:1271`, not HEAD.

- The Created/Modified table’s bare `apps/api/docker/entrypoint.sh:190` is false under the plan’s own bare-citation convention (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:73`). At HEAD, the central reset is `apps/api/docker/entrypoint.sh:172-176` and line 190 is a seeding failure message; the intended line is `dev:apps/api/docker/entrypoint.sh:190`.

- The programme’s deploy citation has the same problem at `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:95`; it must be `dev:apps/api/docker/entrypoint.sh:190-191`.

- Programme O-2 cites HEAD `.github/workflows/ci.yml:1271` and manifest line 820 without a ref at `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:261`. Both facts are local-`dev` facts: `dev:.github/workflows/ci.yml:1271` and `dev:apps/api/tests/feature-lane-manifest.json:817-821`.

- The retained F-1 citation still says `RolesAndPermissionsSeeder.php:379,382-383`, which includes `roles.manage` at line 383 (`docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:291-297`). The exact keys are at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:379,382`.

- T5 groups `journal.view`, `journal.post`, and `settings.update` under generated-map line 253 (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2528`). Real locations are `apps/web/src/hooks/permissionsMap.generated.ts:131-132` and `:253`.

- The “608” counts at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2418,2611` are stale, as quantified above.

- The change log’s “Citation audit — ALL APPLIED” assertion is therefore false at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2926`.

Material citations confirmed correct:

- Alias-map shape: `apps/api/bootstrap/app.php:3-8,113-123`.
- Self-service routes: `apps/api/app/Modules/Identity/routes.php:39-47`; `apps/api/app/Modules/Notification/Presentation/routes.php:19-30`; `apps/api/app/Modules/SupportAccess/Presentation/routes.php:49-58`.
- Notification ownership scope: `apps/api/app/Modules/Notification/Presentation/Controllers/NotificationController.php:55-58`.
- Identity reads and full current payload: `apps/api/app/Modules/Identity/routes.php:59,61,64,78`; `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:429-437`.
- UsersPage state: `apps/web/src/features/settings/UsersPage.tsx:38,102,130-137`; generated key at `apps/web/src/hooks/permissionsMap.generated.ts:272`.
- RolesPage callers: `apps/web/src/features/settings/RolesPage.tsx:73-89`; route and card exposure at `apps/web/src/routes/index.tsx:2343-2351` and `apps/web/src/features/settings/SettingsPage.tsx:23-42`.
- T4 routes: `apps/api/app/Modules/Promotion/Presentation/routes.php:16,19-21`; `apps/api/app/Modules/Uom/Presentation/routes.php:17-19,23,26`; `apps/api/app/Modules/Menu/Presentation/routes.php:18,23,28`; `apps/api/app/Modules/Coupon/Presentation/routes.php:12,14,16,22-23`.
- T4 keys: `apps/api/database/seeders/RolesAndPermissionsSeeder.php:99-109,189-194`.
- Test harness: `apps/api/phpunit.xml:7-21`; `apps/api/composer.json:60-66`; `apps/api/tests/TestCase.php:3-10`.
- CI topology: `.github/workflows/ci.yml:143-148,191,202,215,228,325-330,473-501,2769`.
- Manifest scope: `apps/api/tools/feature-lane-manifest-check.php:205,328-334`.

## Rejected false positives

- **The revised arithmetic is correct when applied to the API universe.** End-of-0a is 152 writes, 146 reads, 298 keys; 0b-15 lowers reads to 142 and the key count to 294 (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:27,2340-2351`). The blocker is scanner scope, not arithmetic.

- **T1’s seven-entry allow-list is exact.** It matches the accepted set and uses two-way set equality (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:390-420,489-530`). A differently keyed unrelated route cannot satisfy B-7. The named notification exception matches the user-scoped lookup at `NotificationController.php:55-58`.

- **`AllowSelfService` and alias insertion match repository shape.** The middleware signature/envelope at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:667-729` matches `apps/api/app/Http/Middleware/RequireAnyPermission.php:14-31`, and the alias is added to the existing map at `apps/api/bootstrap/app.php:113-123`.

- **T3/T3b’s relocation is correct and 0a has no frontend overlap.** The four API reads remain ungated at HEAD, and D4 names-only shaping may remain in wave 2a (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1606-1626`; programme assignment at `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:148`).

- **No undiscovered production frontend caller was found.** In `apps/web/src` and `apps/pos/src`, `GET /roles` is called only by UsersPage and RolesPage; `GET /permissions` only by RolesPage; no production caller was found for `GET /roles/{id}` or `GET /users/{userId}/roles`. `apps/pos/src` has none.

- **Future 0b-15 frontend overlaps are understood.** Current W-LOT edits `RolesPage.tsx`, `RolesPage.test.tsx`, `usePermissions.ts`, `permissionsMap.generated.ts`, and `routes/index.tsx`; current T2 edits only `permissionsMap.generated.ts`. Neither edits `UsersPage.tsx`, `SettingsPage.tsx`, or Identity `routes.php`. This supports moving the coupled item behind W-LOT.

- **T4’s route-to-key mapping is complete and introduces no permission key.** All seventeen routes and every cited key exist at the code locations listed in the citation audit. `POST /coupons/validate` remains deliberately uncovered at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2211-2223,2325-2329`.

- **The manifest overlap is gone.** No remaining 0a file belongs to `tests/Feature`, and `apps/api/tests/feature-lane-manifest.json` is not edited (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:37,86-90,2720-2730`).

- **Current 0a overlap is only `.github/workflows/ci.yml`.** Both lane diffs contain that file, with W-LOT’s sole hunk at approximately 1130 and T2’s at approximately 1138. No other proposed 0a path overlaps either current lane tip.

- **The six-class CI insertion is structurally valid and belongs in 0a.** `backend-architecture` has `apps/api` as its working directory and no `if:` guard (`.github/workflows/ci.yml:143-149`). The proposed block’s paths and shell syntax fit that context (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2657-2682`). Without this step the new classes would still run on manual full-suite dispatch, but not on PR→`dev`; deferral to 0b-11 would violate Convention 08’s same-lane rule (`docs/conventions/08-DETECTOR-LIVENESS.md:58-64`).

- **T0 and T1’s red tests are compilable and fail for the stated reasons on current `dev`.** T0 sees the suppressed per-tenant reset at `dev:apps/api/docker/entrypoint.sh:190`; T1 sees all seven allow-listed routes without `authz.self`. T4’s first route assertion is also correct once T2 is made compilable. T2 itself is not.

## Preserve

The binding gate-r9 Preserve list remains in force (`docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r9.md:198-210`). In particular, preserve:

- `can:` as the single action gate, `require.any.permission:` only for true any-of cases, and no invented `permission:` alias (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:25-29`).
- Exact seven self-service entries, two-way set equality, the named notification exemption, and classification from the allow-list rather than the marker (`:28,390-420,489-530,1244-1266`).
- The exact 18 public routes and four tombstones as separate classifications (`:1201-1226`; `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1466`).
- Separate shrink-only ceilings: 0a 152/146 and 298 keys; 0b-15 152/142 and 294 keys (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:27,2340-2351`).
- T3/T3b and all frontend changes in 0b-15; D4 payload shaping in wave 2a (`:2063-2065`; `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:124,148`).
- All T4 mappings and the deliberate Coupon validation deferral (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2157-2184,2211-2223`).
- Growth and stale enforcement in 0a; protected-blob anti-growth in 0b-11 (`:1564-1577,1695-1725,2880-2881`).
- Registration of all six Architecture classes in 0a’s always-on backend-architecture job (`:2655-2682`).
- No `apps/web` file in 0a and no feature-lane manifest edit (`:86-90`).
- Explicit path-scoped commits and both trailers, after correcting the two nonnumeric subjects (`:34-35`).

## Owner decisions required

No new owner/product decision is required to correct this plan. The blocker and major fixes are implementation-plan defects under settled behavior.

Outstanding owner items remain:

1. **O-1:** promote `6415062b9` from local `dev` to `origin/dev`; it is still absent from `origin/dev` (`docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:260`).

2. **O-2:** retain the runner-flip cleanup, but correct its citations to `dev:` (`docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:261`).

3. **O-3 through O-6:** the existing service-account, token-TTL, SoD, and production-sync decisions remain future-wave questions and do not block corrected 0a (`:262-265`).

4. **Settled; do not reopen:** T3/T3b remains 0b-15, 0a remains backend-only, and the narrow `ci.yml` file overlap remains accepted (`:266`; `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:37-39`).

## Dispatch assessment

| Task | Assessment |
|---|---|
| T0 | **Dispatchable as written**, once the global Phase 0 overlap instruction is corrected. Its test, implementation, commands, paths, and numeric commit subject are sound. |
| T1 | **Dispatchable as written**, once the global Phase 0 instruction is corrected. Alias shape, seven route applications, red assertion, and path-scoped commit are sound. |
| T2 | **Not dispatchable.** Wrong route universe, PSR-4 filename mismatch, missing full implementations, incomplete report contract, insufficient tombstone proof, collapsed liveness classification, and misordered baseline red. |
| T4 | **Mapping is dispatchable only after corrected T2 lands.** Its routes, keys, first intended assertion, module commands, baseline shrink, and 152/146 target are correct. Add a final regeneration proof for 298 keys. |
| T5 | **Not dispatchable.** Enforcement prose is substantially corrected, but its canonical example introduces nonexistent keys and its Permission typing claim is false. |
| T6 | **Not dispatchable.** The six-class CI step itself is ready, but Phase 0 aborts on its accepted overlap, final PHPStan fails on inherited fixtures, the Pint pathspec omits tests, validation can mask actionlint failures, and both commit subjects require numeric patches. |
| Programme plan | **Not dispatchable as the controlling programme record** until current refs are refreshed and the 0b-15 UsersPage guard is corrected from `roles.view` to `users.assign-roles`. S-1 status, lane-cap arithmetic, 0a/0b entry conditions, frontend sequencing, and owner-owed list are otherwise consistent with current reality. |

VERDICT: CHANGES-REQUIRED