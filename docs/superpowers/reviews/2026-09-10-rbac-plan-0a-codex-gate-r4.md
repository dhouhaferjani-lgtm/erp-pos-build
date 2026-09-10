# Codex plan gate r4 — RBAC wave 0a plan rev 4 (gpt-5.6-sol, high, read-only, 2026-09-10)

`git rev-parse --short HEAD`: `fc0f124b6`

| Ref | Current tip | State |
|---|---:|---|
| `dev` | `630afa86f` | contains S-1 `6415062b9` |
| `lane/w-lot-a-1a` | `52f5ad796` | 83 files, +4,714/−663 |
| `lane/t2-receipt-spine` | `951a7637e` | 100 files, +13,144/−546 |
| `lane/t1-transfers-edge` | `86273346a` | ancestor of `dev` |
| `origin/dev` | `ad1d6ceb1` | does not contain S-1 |

Working tree remained unchanged.

## Rev-3 closure table

| Rev-3 finding | Round-4 disposition |
|---|---|
| B-1 — 0a-4 relocation lacked accepted-spec authority | **CLOSED.** Amendment A-1 explicitly moves 0a-4 to 0b-15, supersedes only the relevant half of the r9 Preserve row, and records 152/146/298 after 0a and 152/142/294 after 0b-15 at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:9-38`. |
| B-2 — tombstone fixture still contained placeholders | **CLOSED.** All four exact token-normalised sources are supplied at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:985-1010` and match `dev:apps/api/app/Modules/POS/routes.php:187-194,222-229,231-243` and `dev:apps/api/app/Modules/POS/routes_orders.php:60-67`. |
| M-1 — T5 Promotion example differed from T4 | **CLOSED.** The complete post-T4 file at `wave-0a.md:3056-3088` matches the proposed T4 edit and correctly leaves the two GET routes ungated; its exactness claim at `:3090` is now true. |
| M-2 — O-2 told the owner to decrement real class counts | **NOT CLOSED.** Rev 4 correctly retains per-group counts and replaces decrementing with recomputation, but its runtime/static-gate transition is still wrong; see M-5. |
| m-1 — invalid multi-ref `rev-parse --short` | **CLOSED.** One-ref loop at `wave-0a.md:17` and programme `:349`. |
| m-2 — hunk intersection off by one | **CLOSED.** Exact old-side arithmetic appears at `wave-0a.md:126-130` and the Task 6 duplicate. |
| m-3 — three typed tombstone closures claimed | **CLOSED.** Corrected to two at `wave-0a.md:1218-1224`. |
| m-4 — checklist citation began at the closing fence | **CLOSED.** Corrected to `docs/conventions/03-AUTHORIZATION.md:178-184` at `wave-0a.md:3025,3141`. |
| Citation audit | **PARTIAL.** Prior citations were repaired, but A-1’s inserted lines left several spec anchors stale; see Citation audit. |

## BLOCKER

None.

The accepted A-1 staging is binding. I do not re-raise the r9 “all six” ruling: the correct states are now 152 writes / 146 reads / 298 keys after 0a, then 152 / 142 / 294 after 0b-15.

## MAJOR

### M-1 — Phase 0 overlap check A is not an executable command

The loop body contains the literal shell placeholder:

```sh
for p in <every File Structure path EXCEPT .github/workflows/ci.yml>; do
```

at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:109-116`. In `zsh`, that is syntax, not a path list. It therefore cannot prove the no-overlap entry condition that the programme asserts at `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:107`.

Replace it with an exact quoted array containing the 32 non-CI paths listed under Dispatch assessment, then fail if either diff emits output. The current measurement is clean, but the dispatched check itself must be runnable.

### M-2 — The newly-created worktree has no Composer dependencies, so the stated first red is unreachable

Phase 0 creates a fresh worktree at `wave-0a.md:107`, and the first test command immediately invokes `./vendor/bin/phpunit` at `:276-284`. `apps/api/vendor` is ignored at `apps/api/.gitignore:22`; the present audit worktree has no vendor directory, nor does any current `.worktrees/*` checkout. The repository’s own lane harness explicitly stops with “run composer install” at `scripts/run-feature-lane-local.sh:332-334`.

Consequently the first observed error will be `./vendor/bin/phpunit: no such file or directory`, not the planned T0 assertion. Add an explicit dependency bootstrap after worktree creation, matching CI’s `composer install --no-interaction --prefer-dist` at `.github/workflows/ci.yml:159-171`, and verify `./vendor/bin/phpunit --version`, `phpstan`, and Pint before Task 0.

### M-3 — T0’s advertised recovery command leaves the remaining tenants stale

The plan correctly explains that one database failure aborts `Tenancy::runForMultiple()` and leaves every subsequent tenant stale at `wave-0a.md:142-144`. Its proposed failure message nevertheless tells the operator to rerun only:

```text
php artisan tenants:run permission:cache-reset --tenants=<uuid>
```

at `:289-303`.

That repairs only the failed tenant. It does not visit the tenants skipped after the abort, contradicting the failure mode the same task documents. The message must require repairing the failed database and then rerunning the full fleet command; a targeted run can be suggested only as a diagnostic. Otherwise the visibility fix gives an incomplete security recovery procedure.

### M-4 — T4’s byte-identity regeneration check is guaranteed to report a diff

Step 7 manually changes the already-committed baseline by deleting seventeen entries and changing two counters at `wave-0a.md:2911-2920`. Step 8b then regenerates it and runs:

```sh
git diff --numstat -- tests/Architecture/baselines/route-permission-coverage-baseline.json
```

at `:2924-2928`, expecting no output “relative to the step-7 hand edit” at `:2930-2933`.

`git diff` compares the working tree with the index/HEAD, not with an earlier working-tree state. Because the Step 7 edits remain unstaged until `git add` at `:2951-2954`, this command necessarily prints their numstat even when regeneration is byte-identical. The mandated STOP condition at `:2935` therefore fires on a correct implementation.

Capture `git hash-object` immediately before regeneration and compare it with the hash afterward, or save the pre-regeneration file in a temporary location and use `cmp`.

### M-5 — O-2 still conflates flipping the repository variable with removing static gate declarations

Programme O-2 says all gated lanes share `SELF_HOSTED_RUNNER_READY`, so a single variable flip empties the checker’s gated set and stops consulting `gated_ceiling` at `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:282`.

The checker does not read the variable’s runtime value. A lane remains in `$gatedLanes` whenever its manifest `execution_gate` equals the owning job’s full static `if:` expression, at `apps/api/tools/feature-lane-manifest-check.php:704-721`. It then continues enforcing per-group and aggregate ceilings at `:765-819`.

The proposed wording also says to remove the owning job’s matching `if:`. Removing the complete `if:` would make a heavy lane run on PR→`dev`, contrary to the event predicate currently embedded in jobs such as `.github/workflows/ci.yml:1441-1444` and `:2031-2036`.

The precise transition is:

1. Flip the owner variable to activate the jobs.
2. In the cleanup PR, remove each applicable manifest `execution_gate`.
3. Strip only the `vars.SELF_HOSTED_RUNNER_READY == 'true' &&` conjunct from each job, retaining the `workflow_dispatch` / PR-to-main / push-to-main predicate.
4. Remove the temporary duplicate allow-list entry.
5. Recompute `gated_ceiling` from declarations that remain gated; delete it only when the static gated set is empty.

## MINOR

### m-1 — Programme revision labels still identify the wave plan as rev 3

The programme calls this plan rev 3 and points only to gate r2 at `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:42-44`, and its dispatch row again says rev 3 at `:261`. Its handback table correctly says rev 4 at `:359`. Normalize both stale labels to rev 4 / gate r3.

### m-2 — Several accepted-spec line anchors predate the A-1 insertion

The referenced content is substantively correct, but the numeric anchors listed under Citation audit now land on unrelated material.

### m-3 — A-1 and §8 disagree editorially about the consumed manifest overlap

A-1 says the accepted manifest overlap “has since been consumed” at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:38`. The amended §8 still says it “remains the single accepted overlap” and is the current exception at `:2238-2240`, despite the remaining 0a file list excluding the manifest.

The two plans correctly use the current state at `wave-0a.md:42` and programme `:107`; this is a spec editorial inconsistency, not an implementation defect. Update §8’s historical wording without changing the accepted A-1 result.

## Citation audit

All material `path:line` references in both plans were opened against their declared ref. Wrong or stale citations are:

| Plan citation | Problem | Correct location |
|---|---|---|
| `wave-0a.md:64` → spec `:1488` | Lands inside the middleware class example | Spec `:1525` |
| `wave-0a.md:903` → spec `:1466` | Lands in E-1, not the tombstone ruling | Spec `:1503` |
| `wave-0a.md:904` → spec `:1488` | Does not describe the liveness provider | Spec `:1525` |
| `wave-0a.md:920` → spec `:1424-1434` | Does not contain the current enforcement scope | Spec `:1465-1471` |
| `wave-0a.md:920` → spec `:1482-1485` | Does not contain the 1,054-row derivation | Spec `:1519` |
| `wave-0a.md:982` → spec `:1466` | Tombstone fixture note points to E-1 | Spec `:1503` |
| `wave-0a.md:1884` → spec `:1488` | Provider quotation moved | Spec `:1525` |
| Proposed code comment at `wave-0a.md:2274` → spec `:1488` | Would bake a stale source citation into the test | Spec `:1525` |
| `wave-0a.md:3094` → spec `:1424-1434,1460-1466` | Does not cover the full five-way classification it cites | Spec `:1465-1471,1495-1503,1515` |
| Change log at `wave-0a.md:3598` → spec `:1488` | Stale provider anchor | Spec `:1525` |
| Programme `:141` → spec `:1610,1616-1622` | Lands before current D4 | Spec `:1647-1658` |

The remaining material code citations are accurate:

- Alias shape: `apps/api/bootstrap/app.php:113-123`.
- Self-service sites: Identity `routes.php:39-47`, Notification `routes.php:19-30`, SupportAccess `routes.php:49-58`.
- Identity reads: `apps/api/app/Modules/Identity/routes.php:59,61,64,78`.
- UsersPage: import/destructure/query at `UsersPage.tsx:38,102,130-137`; key at `permissionsMap.generated.ts:272`; affected test at `UsersPage.tenantScope.test.tsx:169-176`.
- T4 coordinates: Promotion `:16,19-21`; Uom `:17-19,23,26`; Menu `:18,23,28`; Coupon `:12,14,16,22-23`.
- Seeder keys: `RolesAndPermissionsSeeder.php:101,104-105,108-109,190-194`.
- CI: `.github/workflows/ci.yml:191,202,215,228,325-330,473-501,2769`.

## Rejected false positives

- **“Post-0a must be 152/142/294” is rejected.** A-1 makes post-0a **152/146/298** and post-0b-15 **152/142/294** at spec `:30-36` and §8 `:2228-2242`.

- **The route classifier arithmetic is sound.** Live `php artisan route:list --json` on `dev` produced 1,092 routes, 1,054 in the `api/` universe, 710 gated, 18 public, 7 self-service, 4 tombstones and 315 uncovered: 167 writes + 148 reads. T4 closes 15 writes and 2 reads, yielding 152/146/298. The source CSV has 1,054 rows and 326 pre-reclassification middleware gaps: 177 writes + 149 reads.

- **The exact self-service set is correct:** `GET auth/me`; `POST auth/logout`; `logout-all`; `resend-verification`; `notifications/read-all`; `notifications/{id}/read`; `support-access/sessions/{session}/exit`. Notification lookup is user-scoped at `NotificationController.php:55-58`; support-session ownership is checked at `SessionLifecycleService.php:137-151`.

- **An unrelated route cannot satisfy self-service coverage.** Classification reads the exact allow-list, not the marker. Two-way set equality, the structural test, and the planted unrelated-marker fixture jointly preserve B-7.

- **The four D4 routes are not all simply `can:roles.view`.** The accepted expressions are: `/roles` and `/users/{userId}/roles` use `require.any.permission:roles.view,users.assign-roles`; `/roles/{id}` and `/permissions` use `can:roles.view`, at spec `:1643-1650`.

- **Names-only shaping is deliberately deferred.** 0b-15 gates the reads; wave 2a performs D4 response shaping, as programme `:164-167` states. That staging does not alter D4’s final requirement at spec `:1653-1663`.

- **Adding the complete frontend package to 0a is not lane-safe.** Current W-LOT overlaps `RolesPage.tsx`, `routes/index.tsx`, `usePermissions.ts`, and `permissionsMap.generated.ts`; T2 overlaps `permissionsMap.generated.ts`. `UsersPage.tsx`, its test, `SettingsPage.tsx`, and Identity routes are disjoint, but a UsersPage-only guard is functionally incomplete because `RolesPage` still calls both endpoints unconditionally at `RolesPage.tsx:73-89`.

- **No other production frontend caller was found.** In `apps/web/src`, `/roles` appears only in `RolesPage.tsx:76` and `UsersPage.tsx:133`; `/permissions` only in `RolesPage.tsx:85`. There is no production GET caller for `/roles/{id}` or `/users/{id}/roles`. `apps/pos/src` contains none.

- **The manifest overlap is gone.** Of the 33 remaining 0a files, only `.github/workflows/ci.yml` overlaps either lane. The current lane hunks remain `@@ -1130,7 +1130,7 @@` and `@@ -1138,8 +1138,11 @@`, outside `backend-architecture` lines 143–236. Phase-0 intersection arithmetic is correct.

- **“The new Architecture tests run on no CI event” is rejected.** All Architecture tests run in the manual full-suite job at `.github/workflows/ci.yml:473-501`. None of the six proposed classes gates normal PR→`dev` today because existing named steps at `:202,215,228` do not select them and `backend-test` is skipped at `:325-330`. Registering all six in T6 is required now; deferring registration to 0b-11 is unacceptable. Only the protected-blob environment line belongs to 0b-11.

- **T4 mappings are correct and introduce no key.** All 17 target routes are currently ungated at the cited route lines, and every proposed key already exists at seeder `:101,104-105,108-109,190-194`. `POST /coupons/validate` correctly remains uncovered.

- **T5’s intended convention is correct.** It teaches `can:`/`require.any.permission:` as action gates, the exact self-service marker/allow-list constraint, the five classifier outcomes, generated frontend permissions, and future `permissions:scaffold`, at `wave-0a.md:3032-3205`.

## Preserve

The following accepted and code-verified properties must survive the corrections:

- A-1’s wave split and 152/146/298 → 152/142/294 progression.
- Exact seven-entry self-service allow-list, two-way equality, structural check, and named notification exemption.
- Five exact classifications and the five permitted gating aliases.
- API-universe filtering before classification.
- Separate shrink-only write/read ceilings; growth, stale, and matched-growth liveness cases.
- Four behavior-tested, source-pinned tombstones.
- T4’s 15 writes and two coupon reads, with `POST /coupons/validate` left uncovered.
- No new permission key, migration, seeder edit, feature-manifest edit, or `apps/web` edit in 0a.
- D4’s final names-only shaping in wave 2a.
- All six new Architecture classes registered in `backend-architecture` in this wave.
- Numeric path-scoped commits: `Phase 0.1.0`, `.1`, `.2`, `.4`, `.5`, `.6`, `.7`.
- T5’s exact post-T4 Promotion example.
- No legacy-role, registry, template-delta, token-scope, audit-event, or migration ruling disturbed.

## Owner decisions required

No new product decision is required to correct this plan. A-1 has settled the only immediate scope question.

Owner actions remain:

- O-1: promote S-1 from local `dev` to `origin/dev`.
- O-2: perform the runner activation and subsequent static-gate cleanup using the corrected procedure in M-5.
- OQ-1 through OQ-4 remain later-wave decisions and do not block 0a.

## Dispatch assessment

The PHP snippets themselves are complete: all 18 full PHP fences pass syntax checking; namespaces, `Tests\TestCase`, Laravel traits/helpers, Composer PSR-4 mapping, PHPUnit configuration, PHPStan paths, and Pint paths exist. T0, T1, T2 and T4 reach their stated initial application assertions once dependencies exist. The defects above are operational plan defects, not missing production-class scaffolding.

| Task | Assessment | Exact files |
|---|---|---|
| Phase 0 | **Not dispatchable as written:** replace placeholder loop and install dependencies | no repository files |
| T0 | **Not dispatchable as written:** correct recovery message | `apps/api/docker/entrypoint.sh`; `apps/api/tests/Architecture/EntrypointPermissionCacheResetShapeTest.php` |
| T1 | Implementation content dispatchable after Phase 0 correction | `apps/api/app/Http/Middleware/AllowSelfService.php`; `apps/api/tests/Architecture/Support/SelfServiceRouteRegistry.php`; `apps/api/tests/Architecture/SelfServiceRouteAllowListTest.php`; `apps/api/tests/Architecture/SelfServiceRouteShapeTest.php`; `apps/api/bootstrap/app.php`; `apps/api/app/Modules/Identity/routes.php`; `apps/api/app/Modules/Notification/Presentation/routes.php`; `apps/api/app/Modules/SupportAccess/Presentation/routes.php` |
| T2 | Implementation content dispatchable after Phase 0 correction | `apps/api/tests/Architecture/Support/RouteCoverage.php`; `TombstoneRouteRegistry.php`; `RouteCoverageClassifier.php`; `RouteCoverageReport.php`; `RoutePermissionCoverageScanner.php`; `RouteCoverageRatchetChecker.php`; `RouteCoverageRatchetResult.php`; `LivenessRouteFixture.php`; `LivenessRouteServiceProvider.php`; `apps/api/tests/Architecture/TombstoneRouteBehaviourTest.php`; `RoutePermissionCoverageRatchetTest.php`; `RoutePermissionCoverageRatchetLivenessTest.php`; `apps/api/tests/Architecture/baselines/route-permission-coverage-baseline.json`; `apps/api/tests/Architecture/fixtures/route-permission-coverage-liveness.json`; `route-tombstones.json` |
| T3/T3b | Correctly absent/tombstoned under A-1 | none |
| T4 | **Not dispatchable as written:** repair Step 8b comparison | `apps/api/app/Modules/Promotion/Presentation/routes.php`; `apps/api/app/Modules/Uom/Presentation/routes.php`; `apps/api/app/Modules/Menu/Presentation/routes.php`; `apps/api/app/Modules/Coupon/Presentation/routes.php`; `apps/api/tests/Architecture/RoutePermissionCoverageRatchetTest.php`; `apps/api/tests/Architecture/baselines/route-permission-coverage-baseline.json` |
| T5 | Dispatchable after citation-only edits | `docs/conventions/03-AUTHORIZATION.md`; `.claude/commands/add-permissions.md` |
| T6 CI | Implementation content dispatchable after T2; hunk remains disjoint | `.github/workflows/ci.yml` |
| T6 handback | Depends on corrected full execution | `docs/handoff/HANDBACK-rbac-w0a-2026-09-10.md` |

The remaining file list is disjoint from both active lanes except for the accepted, non-intersecting `.github/workflows/ci.yml` overlap. The feature-lane manifest is no longer a 0a file or overlap.

VERDICT: CHANGES-REQUIRED