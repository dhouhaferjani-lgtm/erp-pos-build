# Codex plan gate r1 — RBAC wave 0a plan rev 1 (gpt-5.6-sol, high, read-only, 2026-09-10)

`git rev-parse --short HEAD`: **`2ca6c1a9c`**

Review base: branch `docs/rbac-audit-2026-09-09`. Local `dev` is now `105b1b22b`; `origin/dev` is `ad1d6ceb1` and does not yet contain merge `6415062b9`.

## BLOCKER

### B-1 — T2’s tombstone classification is self-certifying and violates the accepted spec

The classifier returns `Tombstone` solely because a route key occurs in `TOMBSTONES`; it never proves that the route still returns an unconditional 410 without mutation (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:972-993`). No proposed test in the created-file list checks that behavior (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:45-51`).

The accepted specification explicitly requires a test asserting that all four routes return 410 unconditionally and that any route which ceases doing so re-enters the uncovered count (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1466`). The current 410 implementations are:

- `apps/api/app/Modules/POS/routes.php:187-193`
- `apps/api/app/Modules/POS/routes.php:222-242`
- `apps/api/app/Modules/POS/routes_orders.php:60-66`

As written, changing one of those handlers into a live mutation would remain classified as covered. T2 must add a mechanically coupled four-route tombstone behavior/shape test and make the classifier depend on that verified contract.

### B-2 — T2 is not a Convention-08-compliant CI guard

There are three independent failures:

1. The liveness routes are registered directly into an already-booted router inside the test (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1238-1259`). The accepted spec requires a throw-away service provider which registers them before router boot (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1488`).

2. The fixture test merely expects the planted routes to be classified as `uncovered` (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1261-1271`). It does not prove that the baseline-backed guard actually fails on a newly planted violation. Convention 08 requires a committed new-violation failure case, stale-entry case, and—once owner-pinned—a matched-growth case (`docs/conventions/08-DETECTOR-LIVENESS.md:48-56,94-100`). The proposed one-off manual baseline deletion is not a shipped tamper test (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1469`).

3. The guard is not registered in the always-on `backend-architecture` job. Convention 08 requires detector and liveness proof on the same trigger set (`docs/conventions/08-DETECTOR-LIVENESS.md:58-64`). Deferring registration to 0b leaves the central wave-0a deliverable dark on PR→dev.

The plan must refactor detection into a callable scanner, test an injected new violation through that scanner, use the specified pre-boot provider, and register all new Architecture classes in 0a.

### B-3 — T3 fixes `UsersPage` but knowingly breaks the reachable `RolesPage`

`RolesPage` independently fires both gated reads without a permission guard:

- `GET /roles`: `apps/web/src/features/settings/RolesPage.tsx:73-80`
- `GET /permissions`: `apps/web/src/features/settings/RolesPage.tsx:82-89`

The page is reachable under only `moduleKey="settings"` (`apps/web/src/routes/index.tsx:2343-2351`), and its Settings card is shown without a role permission check (`apps/web/src/features/settings/SettingsPage.tsx:30-42`). `settings` resolves to `settings.view` (`apps/web/src/hooks/usePermissions.ts:96-98`), held by manager while `roles.view` and `users.assign-roles` are admin-only (`apps/web/src/hooks/permissionsMap.generated.ts:251-254,272-277`; `apps/api/database/seeders/RolesAndPermissionsSeeder.php:379-383,582-585,640-655`).

After T3, a manager can still open the page, which makes two guaranteed 403 requests and renders the loading-error surface (`apps/web/src/features/settings/RolesPage.tsx:242-249`). The plan explicitly accepts this in Probe B (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2240`), so it is not an undiscovered edge case.

The grep census found no other production frontend caller of `GET /roles/{id}` or `GET /users/{id}/roles`; the only other relevant caller is this `RolesPage`. `apps/pos/src` has none. T3 must also gate the route/card or its two queries on `roles.view`. Because `lane/w-lot-a-1a` edits `RolesPage.tsx`, `usePermissions.ts`, and `routes/index.tsx`, this requires sequencing on that lane or an explicitly accepted overlapping edit (`lane/w-lot-a-1a:apps/web/src/features/settings/RolesPage.tsx:68-84`; `lane/w-lot-a-1a:apps/web/src/routes/index.tsx:2350-2358`).

## MAJOR

### M-1 — The plan’s HEAD citations and Task 0 red state are wrong

At requested HEAD, `apps/api/docker/entrypoint.sh:172-176` contains only the central reset. HEAD line 190 is a seeding-error message, not the per-tenant reset. Therefore the proposed Task 0 test first fails because `linesContaining()` finds no per-tenant command (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:221-230`), not because line 190 contains `2>/dev/null` as claimed (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:239-248`).

The claimed command exists only on current local `dev`, at `dev:apps/api/docker/entrypoint.sh:190-191`. Similarly, the S-1 manifest values exist at `dev:apps/api/tests/feature-lane-manifest.json:817-821`, not at HEAD.

The implementation lane is intended to branch from current `dev` (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:85-95`), which would restore the expected red. Rev 2 must nevertheless distinguish `HEAD` from `dev:` citations and refresh the base SHA.

### M-2 — CI residual F-2/R-4 is factually wrong and undercounts the tests

The plans claim that “three” new Architecture classes execute on “no CI event” (`docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:85-94,270-273`; `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2348-2351`).

There are **four** new test classes:

- `EntrypointPermissionCacheResetShapeTest` (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:51`)
- `SelfServiceRouteAllowListTest` (`:46`)
- `SelfServiceRouteShapeTest` (`:47`)
- `RoutePermissionCoverageRatchetTest` (`:48`)

They do execute on `workflow_dispatch`, because the manual full-suite step includes `tests/Architecture` (`.github/workflows/ci.yml:473-501`). They do **not** gate PR→dev because the always-on job names only existing files (`.github/workflows/ci.yml:202,215,228`) and the heavy backend job is skipped there (`.github/workflows/ci.yml:325-330`).

Recommendation: register all four in 0a as a discrete named step after `.github/workflows/ci.yml:228`. Both in-flight lanes edit this file, but their current hunks are far away:

- `lane/w-lot-a-1a`: hunk at current `ci.yml:1133`
- `lane/t2-receipt-spine`: hunks at current `ci.yml:1140-1145`

Thus there is a file-level overlap but no current same-hunk collision. Convention 08 outweighs the plan’s zero-file-overlap preference here.

### M-3 — T3b cannot produce the red evidence in its stated order

The implementation is performed in Step 7 (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1654-1669`), while the two guard tests are not added until Step 9 (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1673-1693`). Step 10 then claims the test should be observed red “before step 7” (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1695-1701`).

That execution history is impossible. Move the tests and existing-fixture repair before the implementation, capture the zero-versus-one failure, then apply the guard.

### M-4 — T5’s proposed convention is not the accepted enforcement standard

The proposed E-1 text is labelled “verbatim” but is not verbatim and is internally confused: it says there is no third state, then calls `authz.self`, tombstones, and the public allow-list “three exceptions” (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2114-2118`). In the accepted E-1, `authz.self` is part of the gated category and the public allow-list is the public category (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1424-1434`). Tombstones are subsequently defined as a special named classification (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1466`).

The proposed checklist then requires every route to carry an action gate or `authz.self`, omitting all 18 public routes and all four tombstones (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2131-2137`). It would teach reviewers to reject valid routes.

T5 must state the classifier categories exactly and distinguish:

- action gates: `can:`, `require.any.permission:`, super/central aliases;
- self-service declarations: exact allow-list plus `authz.self`;
- exact public allow-list;
- four behavior-tested tombstones.

### M-5 — T5 preserves invalid frontend examples and makes false catalogue claims

The plan says the existing `RequirePermission` examples are correct and should remain unchanged (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2126`). They use `reports.view` as a positive gate, nonexistent `accounting.view`, and nonexistent `settings.edit` (`docs/conventions/03-AUTHORIZATION.md:127-140`). At minimum those examples must be replaced with active generated keys.

The assertion that five old keys are absent from the catalogue is also false (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2062,2124`):

- `accounts.manage` exists at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:300` and in the generated map at `apps/web/src/hooks/permissionsMap.generated.ts:6`.
- `reports.view` also exists, although deprecated, at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:326-332` and `apps/web/src/hooks/permissionsMap.generated.ts:239`.
- `settings.edit`, `sales.edit`, `inventory.create`, `treasury.create`, and `accounting.view` are the genuinely invalid examples.

Finally, the rewrite never teaches the wave-1 `permissions:scaffold` workflow. It leaves the slash command’s manual seeder-edit Step 1 unchanged (`.claude/commands/add-permissions.md:5-12`) even though the programme makes `permissions:scaffold` a wave-1 deliverable (`docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:112-115`). The documentation needs an explicit “today versus wave 1” transition.

### M-6 — All seven proposed commit subjects violate repository policy

The repository requires `Phase <major.minor.patch>: <imperative summary>` (`AGENTS.md:15-16`). Every proposed subject instead uses Conventional Commit syntax:

- `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:282`
- `:813`
- `:1483`
- `:1714`
- `:2022`
- `:2179`
- `:2260`

The `git add` lists themselves are explicit and path-scoped (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:279,810,1480,1711,2019,2176,2257`). Only the subject format needs correction.

### M-7 — UI verification omits the repository-required Playwright run

Because T3b changes React UI behavior, the repository requires `pnpm --filter @autoerp/web test:e2e` before merge (`AGENTS.md:12-16`). The plan runs selected Vitest files, typecheck, lint, and manual probes, but no Playwright suite (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1695-1701,2231-2242,2278-2281`).

Add the required E2E command or record an explicitly approved skip with the required screenshots.

## MINOR

### m-1 — Programme “today” pins are stale

The programme says current `dev` is `d64db9a0e` (`docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:189-197`). Actual current local refs are:

- `dev`: `105b1b22b`
- `lane/w-lot-a-1a`: `5d847b2ba`
- `lane/t2-receipt-spine`: `d7123fa30`

The lane tips and shortstats remain correct: W-LOT is 83 files, +4,674/−663; T2 is 100 files, +13,066/−495. Refresh only the `dev` pin and measurement wording. The wave plan already requires remeasurement if `dev` advanced (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:85-95`).

### m-2 — T4’s Promotion/Uom rationale cites the wrong checks

The plan claims `PromotionController.php:122,146,170,194` are the existing `store`/`update` checks (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1751,1895-1899`). Those are the `destroy`, `activate`, `pause`, and `archive` methods—the methods being gated. The real existing checks are:

- `apps/api/app/Modules/Promotion/Presentation/Requests/StorePromotionRequest.php:13-18`
- `apps/api/app/Modules/Promotion/Presentation/Requests/UpdatePromotionRequest.php:13-18`

The claim that all fifteen writes have “no check at ANY layer” is also false (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2022-2029`). Four UoM routes already check permissions in the controller:

- `apps/api/app/Modules/Uom/Presentation/Controllers/UomController.php:50-52`
- `:168-170`
- `:205-207`
- `:259-261`

The route gates are still correct under E-2; fix the rationale and commit body.

### m-3 — T3 says “three write routes” while naming five

The untouched Identity writes are `POST /roles`, `PATCH /roles/{id}`, `DELETE /roles/{id}`, and POST/DELETE user-role assignment—five routes, not three (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1611-1616,1630-1634`).

### m-4 — New snippets violate the plan’s own “no mixed” rule

The plan prohibits `mixed` in every new PHP file (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:27`) but introduces it at:

- `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1308`
- `:1582`
- `:1859`

These compile, but the plan must either remove those annotations/types or narrow the global rule.

### m-5 — Pint commands are broader than the touched files

Several steps run Pint against all of `tests/Architecture` or all middleware (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:805,1474,1706,2014,2232`). That can rewrite inherited unrelated files and conflicts with the path-scoped discipline at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:30`. Use `pint --test` for verification and exact new/touched files for formatting.

## Citation audit

Wrong or stale citations, with corrected locations:

- `apps/api/docker/entrypoint.sh:190-191` in both plans is false at HEAD; the per-tenant/central pair is at `dev:apps/api/docker/entrypoint.sh:190-191`, while HEAD has only the central reset at `apps/api/docker/entrypoint.sh:172-176` (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:58,107,114,246`; `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:82`).
- T3b says the roles-query `enabled` is at `UsersPage.tsx:126`; the real roles-query guard is `apps/web/src/features/settings/UsersPage.tsx:136` (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1664-1667`).
- `users.assign-roles` and `roles.view` are at seeder lines 379 and 382; the repeated `:379,382-383` range unnecessarily includes `roles.manage` (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1655`; `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:249-252`).
- Promotion checks are in the two FormRequests at line 17, not `PromotionController.php:122,146,170,194` (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1751,1895-1899`).
- The “no CI event” conclusion from `.github/workflows/ci.yml:202,215,228` omits the manual full-suite path at `.github/workflows/ci.yml:473-501` (`docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:85-94`; `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2351`).
- The Common Permissions discussion cites `docs/conventions/03-AUTHORIZATION.md:66-79`; the block is actually `:68-80`, and `settings.edit` is in the frontend example at `:91`, not that block (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2062`).
- The programme’s manifest and CI references describe local `dev`, not HEAD: `dev:apps/api/tests/feature-lane-manifest.json:817-821` and `dev:.github/workflows/ci.yml:1269-1272` (`docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:231-232`).

The remaining material citations were opened and agree with code:

- Alias insertion shape: `apps/api/bootstrap/app.php:3,113-123`.
- Existing self-service routes: `apps/api/app/Modules/Identity/routes.php:39-47`; `apps/api/app/Modules/Notification/Presentation/routes.php:20-30`; `apps/api/app/Modules/SupportAccess/Presentation/routes.php:52-58`.
- Identity reads and response: `apps/api/app/Modules/Identity/routes.php:58-78`; `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:429-437`.
- UsersPage imports/query/test: `apps/web/src/features/settings/UsersPage.tsx:38,102-103,130-137`; `apps/web/src/features/settings/__tests__/UsersPage.tenantScope.test.tsx:78-85,168-175`; generated key at `apps/web/src/hooks/permissionsMap.generated.ts:272`.
- T4 route locations: `apps/api/app/Modules/Promotion/Presentation/routes.php:16,19-21`; `apps/api/app/Modules/Uom/Presentation/routes.php:17-19,23,26`; `apps/api/app/Modules/Menu/Presentation/routes.php:18,23,28`; `apps/api/app/Modules/Coupon/Presentation/routes.php:12,14,16,22-23`.
- T4 keys: `apps/api/database/seeders/RolesAndPermissionsSeeder.php:101,105,108-109,190-194`.
- Test harness: `apps/api/phpunit.xml:7-21`, `apps/api/composer.json:60-66`, `apps/api/tests/TestCase.php:3-9`. All four module test directories named at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2005-2009` exist.
- Manifest scope citation: `apps/api/tools/feature-lane-manifest-check.php:205,328-334`.
- Tenant migration uniqueness citation: `apps/api/database/migrations/2025_11_30_000001_create_tenants_table.php:16-20`.
- Monitoring route citation: `apps/api/routes/api.php:83`.

## Rejected false positives

- **The route arithmetic is correct.** Artisan could not boot because this read-only worktree has no `apps/api/vendor/autoload.php`, so I used the allowed CSV fallback. Its 1,054 rows contain 642 `MIDDLEWARE`, 68 `SUPERADMIN_ONLY`, 18 `PUBLIC`, and 326 stricter-rule uncovered routes: 177 writes and 149 reads (`docs/superpowers/audits/2026-09-09-roles-permissions/02-route-enforcement-sweep.csv:1`; derivation specified at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1482-1485`). Removing four tombstone writes, six self-service writes, and `/auth/me` gives 167/148; closing 15 writes and six reads gives **152/142 and 294 baseline keys** (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1453-1461,1993-2003`).

- **The public allow-list and self-service allow-list are exact.** The public list has the same 18 normalized entries as the CSV; the self-service table is exactly the spec’s seven entries (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:371-379`; `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1460`). Set equality prevents a differently keyed unrelated route from satisfying B-7, and the classifier consults the allow-list rather than the marker (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:470-500,999-1003`). The named notification exception and parameter rule are also present (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:388-401,564-603`).

- **`AllowSelfService` and alias registration are structurally compatible with the repository.** The proposed middleware uses the existing request/closure/response signature and house envelope (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:648-710`; existing envelope at `apps/api/app/Http/Middleware/RequireAnyPermission.php:14-31`). The alias is added through the same `$middleware->alias([...])` map as the current aliases (`apps/api/bootstrap/app.php:113-123`).

- **D4 shaping may remain in wave 2a.** The spec demands the shaped responses (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1606-1626`) but explicitly assigns only the four gates to 0a and shaping to wave 2a (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2193`; `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:120-127`). Residual R-1 accurately preserves that debt (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2348`).

- **The planned T3b files themselves are disjoint.** Neither lane edits `UsersPage.tsx`, `UsersPage.test.tsx`, or `UsersPage.tenantScope.test.tsx`. W-LOT’s relevant overlaps are instead `RolesPage.tsx`, `RolesPage.test.tsx`, `usePermissions.ts`, `permissionsMap.generated.ts`, and `routes/index.tsx`; T2 overlaps only `permissionsMap.generated.ts`. Thus adding the three stated UsersPage files is mechanically safe, although it does not close the separate RolesPage defect (`docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:265-268`).

- **T4’s mappings are complete and introduce no key.** Every listed route exists at the cited line and every gate key exists in the seeder (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1768-1788`; `apps/api/database/seeders/RolesAndPermissionsSeeder.php:99-109,189-194`).

- **The manifest overlap is operationally gone if the plan adds no Feature test.** Both current lanes still edit `apps/api/tests/feature-lane-manifest.json`, but 0a’s four new tests are under `tests/Architecture`, outside the manifest census (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2214-2221`). The manifest is only a conditional contingency at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2210`; taking that branch would reintroduce the overlap and correctly requires stopping.

- **The substantive PHPUnit/PHPStan command shapes are valid.** Namespaces, `Tests\TestCase`, PHPUnit attributes, Laravel route facade, test-suite paths, and the four module directories exist (`apps/api/composer.json:60-66`; `apps/api/phpunit.xml:7-21`; `apps/api/tests/TestCase.php:3-9`). Runtime execution was unavailable only because dependencies are absent from this worktree.

## Preserve

The following accepted and correctly implemented planning decisions must survive rev 2:

- The gate-r9 preserve list, especially all six start-now items and the already accepted S-1 outcome (`docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r9.md:205-210`).
- `can:` as the single action gate, with `require.any.permission:` for true any-of cases and no invented `permission:` middleware (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:21-25`).
- The exact seven self-service entries, two-way set equality, named notification exemption, and classifier dependence on the allow-list rather than the alias (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:371-401,470-500,991-1006`).
- Separate shrink-only write/read ceilings and the final **152/142, 294-key** target (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1145-1198,1997-2003`).
- The four Identity gate expressions exactly as written (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1532-1537,1602-1629`).
- Deferral of D4 response shaping to wave 2a (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1512-1514,2348`).
- All T4 route-to-existing-key mappings and the decision to leave `POST /coupons/validate` uncovered until the POS wave (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1768-1790,1963-1991`).
- Growth and stale enforcement in 0a, with only protected-blob anti-growth deferred to 0b-11 (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1162-1198,1285-1320,2350`).
- Explicit path-scoped `git add` lists and both trailers (`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:30,279,810,1480,1711,2019,2176,2257`).

## Owner decisions required

1. **O-1 remains outstanding.** S-1 is on local `dev` but not `origin/dev`; `origin/dev` does not contain `6415062b9`. Promotion remains owner-timed as recorded (`docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:229-232`).

2. **O-7 must be resolved before full-wave dispatch.** Gate r9 said no additional owner question was required (`docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r9.md:212-221`), but the programme later added frontend scope outside the accepted file list (`docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:237`). The owner must accept the UsersPage addition and choose how to close RolesPage:
   - sequence the RolesPage/route fix after W-LOT;
   - accept a small overlapping frontend edit now; or
   - move the Identity API gates with their frontend closure to a post-W-LOT micro-wave.

   Shipping the known reachable 403 page is not an acceptable alternative.

3. **CI registration needs an explicit overlap exception.** Registering all four Architecture tests in 0a introduces a file-level overlap on `.github/workflows/ci.yml`, but current lane hunks are disjoint from the proposed step location (`.github/workflows/ci.yml:228,1133-1145`). The owner should approve that narrow overlap or sequence the CI-only commit immediately after the active lanes; deferring the tests while calling T2 a CI ratchet is not acceptable.

OQ-1 through OQ-4 remain future-wave decisions and do not block corrected 0a (`docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:233-236`).

## Dispatch assessment

No complete task is dispatchable **as written**, because every task ends in a nonconforming commit subject (`AGENTS.md:15-16`).

Substantive status:

- **Task 0:** implementation is dispatchable after branching from current local `dev`, refreshing its citations/red expectation, and fixing the commit subject.
- **Task 1:** middleware, alias, seven-route application, and exact-set mechanics are substantively ready; CI/liveness corrections and commit format remain.
- **Task 2:** **not dispatchable**. Tombstone verification, pre-boot liveness, committed new-growth tamper coverage, and CI registration are required.
- **Task 3/T3b:** **not dispatchable**. Reorder red/green steps and close or sequence the separate RolesPage break.
- **Task 4:** route mappings and keys are substantively ready; correct the Promotion/Uom rationale, remove `mixed`, narrow formatting paths, add required verification, and fix the commit subject.
- **Task 5:** **not dispatchable**. Its proposed standard, permission catalogue statements, frontend examples, and future scaffold workflow require rewrite.
- **Task 6:** depends on corrected T2/T3/T5, all four CI registrations, the required E2E command, and refreshed current-ref evidence.

VERDICT: CHANGES-REQUIRED