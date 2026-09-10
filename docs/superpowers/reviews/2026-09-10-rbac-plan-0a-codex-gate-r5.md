# Codex plan gate r5 — RBAC wave 0a plan rev 5 (gpt-5.6-sol, high, read-only, 2026-09-10)

Declared HEAD: `f7c2805d3`.

HEAD advanced from `24682a0d0` during the audit, but `git diff 24682a0d0..f7c2805d3` showed no change to either reviewed plan or any wave-0a application path. The unrelated existing modification to `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md` was not reviewed or touched.

Re-anchored refs:

- `dev`: `630afa86f`
- `lane/w-lot-a-1a`: `52f5ad796`
- `lane/t2-receipt-spine`: `951a7637e`
- S-1 merge `6415062b9` is an ancestor of local `dev`.

## Rev-4 closure table

| R4 item | R5 result | Evidence |
|---|---|---|
| M-1 — non-executable overlap loop | CLOSED | The literal 32-path array at wave plan `:129-161` exactly matches the File Structure table minus `.github/workflows/ci.yml`. The command prints `paths: 32` and `claimed=0`; see `:126-176`. |
| M-2 — missing Composer dependencies | CLOSED | Dependency bootstrap and binary/version checks are now before the first red at `:108-124`. `Tests\\` autoload-dev and optimized autoloading are valid in `apps/api/composer.json:60-66,109-112`. |
| M-3 — incomplete tenant recovery | CLOSED | The failure text now distinguishes targeted diagnosis from the required full-fleet rerun at `:348-366`. |
| M-4 — invalid regeneration comparison | CLOSED, with one stale handback bullet | The operative check correctly hashes working-tree bytes at `:2989-3003`. The handback still requests the superseded empty `--numstat` output at `:3509`; see MINOR m-1. |
| M-5 — runner flip conflated runtime and static state | CLOSED | The five-step removal/recompute procedure at programme plan `:296-297` matches the checker’s declaration-based behavior in `apps/api/tools/feature-lane-manifest-check.php:704-721,765-819`. |
| m-1 — stale programme revision labels | CLOSED | Current labels and lane tips are consistent at programme plan `:42-55,276,374`. |
| m-2 — stale accepted-spec anchors | CLOSED for the cited spec anchors | The repaired anchors now land on the intended E-1/D4/liveness/tombstone text. Three newly shifted cross-plan anchors remain; see MINOR m-2. |
| m-3 — historical manifest-overlap statement | CLOSED | Spec `:2240` now records the manifest overlap as historical/consumed. The live overlap is only `.github/workflows/ci.yml`. |
| Preserve register | CLOSED | The binding items remain intact at wave plan `:3716,3758`. |

## BLOCKER

None.

## MAJOR

None.

## MINOR

### m-1 — Handback requests evidence the corrected regeneration procedure expressly forbids

Wave plan `:3509` still asks for “an empty baseline `--numstat`”. The authoritative procedure at `:2990-3003` replaced that comparison with before/after `git hash-object` values and `IDENTICAL`, because `git diff --numstat` compares the working tree with the index rather than the two regeneration states.

Dispatch correction: replace the `--numstat` request with:

- the expected regeneration failure message;
- `before=… after=…` and `IDENTICAL`;
- `298 152 146`.

### m-2 — Three cross-plan citations shifted when the programme plan gained lines

| Stale citation | Current target |
|---|---|
| Wave plan `:126` cites programme plan `:107` for the entry condition | Programme plan `:122` |
| Wave plan `:2580` cites programme plan `:93` for “red captured first” | Programme plan `:123` |
| Wave plan change log `:3749` repeats programme plan `:107` | Programme plan `:122` |

The cited policies exist; only the line numbers are stale.

### m-3 — UoM File Structure description says four gates, but the plan adds five

Wave plan `:86` lists five route lines—`:17,18,19,23,26`—but calls them “the four existing uom gates.” Task 4 correctly specifies five gates at `:2748-2764`, matching the five seeded keys:

- `uom.view`
- `uom.create`
- `uom.edit`
- `uom.delete`
- `units.manage`

Dispatch correction: change “four” to “five”.

### m-4 — Missing-baseline failure text is not exact PHPUnit 11 output

The planned assertion uses `assertIsString(false)` in the test code, while wave plan `:2584-2588` predicts:

`Failed asserting that false is true.`

Under the repository’s PHPUnit 11 harness, the type assertion reports that `false` is not of type string. The first failure still occurs for the stated reason—the baseline file is absent—so this is evidence wording, not a test-design failure.

Dispatch correction: capture the observed assertion text rather than requiring the quoted second line verbatim.

### m-5 — Handback says “both UI probes” after defining three

Wave plan `:3492-3502` explicitly defines admin, manager, and viewer probes and calls the pass condition “all three probes.” The handback list at `:3510` asks for “both UI probes”.

Dispatch correction: change “both” to “all three”.

## Citation audit

Every material path and line citation in both plans was opened against HEAD, local `dev`, or the named lane ref. The wrong/stale citations are limited to the three entries in m-2 and the two prose-count mismatches in m-3/m-5.

Verified citation groups include:

- Middleware alias structure: `apps/api/bootstrap/app.php:113-123`.
- Existing self-service routes:
  - `apps/api/app/Modules/Identity/routes.php:39-47`
  - `apps/api/app/Modules/Notification/Presentation/routes.php:19-30`
  - `apps/api/app/Modules/SupportAccess/Presentation/routes.php:49-58`
- Notification ownership scope: `NotificationController.php:55-58`.
- Support-session ownership checks: `SessionLifecycleService.php:137-151`.
- Identity frontend:
  - `UsersPage.tsx:38,102,130-137`
  - `permissionsMap.generated.ts:272`
  - `UsersPage.tenantScope.test.tsx:169-176`
- Promotion/UoM/Menu/Coupon route lines and every mapped seeder key.
- CI architecture job and command locations at `.github/workflows/ci.yml:143-236`, including the existing commands at `:202,215,228`.
- Composer, PHPUnit, PHPStan, Pint, feature-lane checker, convention-document and accepted-spec citations.

All complete proposed PHP files were extracted and passed `php -l`. The multi-file PHPUnit form resolves under the repository configuration, and PHPStan accepts the specified level and paths. Pint’s PHAR could not unpack temporary files in this read-only gate sandbox; its planned invocation and binary path are nevertheless correct after the plan’s dependency bootstrap.

Commit subjects are numeric and conform to the repository convention at wave plan `:40,384,917,2644,3026,3284,3383,3521`. Each commit uses explicit `git add` paths and both required trailers.

## Rejected false positives

### Post-0a is not 152/142/294

The live router booted successfully. Re-derived under spec §4.4.3:

- 1,092 live routes
- 1,054 routes in the API universe
- 710 gated
- 18 public
- 7 self-service
- 4 tombstones
- 315 uncovered: 167 writes and 148 reads

Task 4 closes 15 writes and 2 reads, producing the accepted A-1 post-0a state:

- 152 writes
- 146 reads
- 298 baseline keys

Wave 0b-15 closes the four Identity reads, producing:

- 152 writes
- 142 reads
- 294 baseline keys

There is no baseline JSON at HEAD yet; it is created by this wave. Requiring the new post-0a baseline to contain 294 entries would contradict amendment A-1 at programme plan `:34,121,156` and wave plan `:32,2987-3005`.

### The six Architecture classes are not absent from every CI event

They are absent from the currently enumerated PR→`dev` architecture command. However, the existing whole Architecture directory is run on `workflow_dispatch`. The accurate defect is that the classes do not presently gate the normal PR→`dev` event.

Deferring registration to 0b-11 would violate the same-lane detector-liveness rule. Wave 0a correctly registers all six now; 0b-11 owns only the protected-blob environment line.

### The four Identity reads are not all simple `roles.view` gates

Accepted D4 requires:

- `GET /roles`: `roles.view` **or** `users.assign-roles`
- `GET /roles/{id}`: `roles.view`
- `GET /permissions`: `roles.view`
- `GET /users/{id}/roles`: `roles.view` **or** `users.assign-roles`, with names-only shaping later in wave 2a

The programme plan states this correctly at `:156`. Moving the routes and frontend guards together to 0b-15 is consistent with amendment A-1.

### Adding `apps/web` to 0a would not be lane-safe

Current lane overlaps are:

- W-LOT: `RolesPage.tsx`, `permissionsMap.generated.ts`, `usePermissions.ts`, `routes/index.tsx`
- T2 receipt spine: `permissionsMap.generated.ts`

`UsersPage.tsx`, `SettingsPage.tsx`, and the Identity routes themselves are disjoint, but the complete D4 change is not. Keeping all frontend files out of 0a is correct.

Production caller search found no additional callers that would break:

- `/roles`: only `RolesPage` and `UsersPage`
- `/permissions`: only `RolesPage`
- no production GET caller of `/roles/{id}`
- no production GET caller of `/users/{id}/roles`
- no relevant callers in `apps/pos/src`

### The feature-lane manifest overlap is gone

The exact 32 non-CI paths are disjoint from both current lane tips. Neither the manifest nor any `apps/web` file appears in the remaining 0a list.

`.github/workflows/ci.yml` is the sole accepted file-level overlap. Its lane hunks remain remote from wave 0a’s insertion point:

- W-LOT: `@@ -1130,7 +1130,7 @@`
- T2: `@@ -1138,8 +1138,11 @@`
- 0a insertion: inside the architecture job after current line 228

## Preserve

The following binding items remain preserved:

- `can:` is the sole ordinary action gate; `require.any.permission:` is retained only for explicit any-of cases.
- The seven-entry self-service allow-list is exact and checked with two-way set equality.
- The notification `{id}` ownership exemption remains named and bounded.
- Classification is derived from the allow-list, not merely from the presence of `authz.self`.
- An unrelated route carrying `authz.self` cannot satisfy the allow-list test or escape as self-service.
- The exact 18 public routes and four source-pinned tombstones remain separate classifications.
- The scanner filters to the 1,054-route API universe before classification.
- Write/read ceilings are separate, shrink-only, and covered by growth, stale-entry, matched-growth, and liveness cases.
- T4 closes exactly 15 writes and two coupon reads using existing seeded keys.
- `POST /coupons/validate` remains uncovered.
- No permission key, migration, seeder, manifest, or frontend file is added to 0a.
- D4 response shaping remains deferred to wave 2a.
- All six new Architecture classes are registered in 0a’s PR-gating architecture job.
- All seven commits are numeric, path-scoped, and carry both trailers.
- T5 teaches the accepted standard: `can:`, the explicit self-service marker, generated frontend permission map, and future `permissions:scaffold`.

## Owner decisions required

None before dispatch.

Programme O-1 remains an owner-timed promotion of `6415062b9` to `origin/dev`. O-2 is a future runner-transition procedure. O-3 through O-6 concern later waves. None changes 0a’s scope or implementation.

## Dispatch assessment

The plan is executable against HEAD after normal dependency bootstrap. Each red test compiles and reaches the intended defect; the only mismatch is the minor exact PHPUnit wording in m-4. Proposed implementations are complete, use existing namespaces/helpers, and contain no placeholders.

Exact wave-0a task file ownership:

- Task 0:
  - `apps/api/docker/entrypoint.sh`
  - `apps/api/tests/Architecture/EntrypointPermissionCacheResetShapeTest.php`

- Task 1:
  - `apps/api/app/Http/Middleware/AllowSelfService.php`
  - `apps/api/tests/Architecture/Support/SelfServiceRouteRegistry.php`
  - `apps/api/tests/Architecture/SelfServiceRouteAllowListTest.php`
  - `apps/api/tests/Architecture/SelfServiceRouteShapeTest.php`
  - `apps/api/bootstrap/app.php`
  - `apps/api/app/Modules/Identity/routes.php`
  - `apps/api/app/Modules/Notification/Presentation/routes.php`
  - `apps/api/app/Modules/SupportAccess/Presentation/routes.php`

- Task 2:
  - `apps/api/tests/Architecture/Support/RouteCoverage.php`
  - `apps/api/tests/Architecture/Support/TombstoneRouteRegistry.php`
  - `apps/api/tests/Architecture/Support/RouteCoverageClassifier.php`
  - `apps/api/tests/Architecture/Support/RouteCoverageReport.php`
  - `apps/api/tests/Architecture/Support/RoutePermissionCoverageScanner.php`
  - `apps/api/tests/Architecture/Support/RouteCoverageRatchetChecker.php`
  - `apps/api/tests/Architecture/Support/RouteCoverageRatchetResult.php`
  - `apps/api/tests/Architecture/Support/LivenessRouteFixture.php`
  - `apps/api/tests/Architecture/Support/LivenessRouteServiceProvider.php`
  - `apps/api/tests/Architecture/TombstoneRouteBehaviourTest.php`
  - `apps/api/tests/Architecture/RoutePermissionCoverageRatchetTest.php`
  - `apps/api/tests/Architecture/RoutePermissionCoverageRatchetLivenessTest.php`
  - `apps/api/tests/Architecture/baselines/route-permission-coverage-baseline.json`
  - `apps/api/tests/Architecture/fixtures/liveness-routes.json`
  - `apps/api/tests/Architecture/fixtures/route-tombstones.json`

- Task 3/T3b: no 0a files; moved intact to 0b-15.

- Task 4:
  - `apps/api/app/Modules/Promotion/Presentation/routes.php`
  - `apps/api/app/Modules/Uom/Presentation/routes.php`
  - `apps/api/app/Modules/Menu/Presentation/routes.php`
  - `apps/api/app/Modules/Coupon/Presentation/routes.php`
  - `apps/api/tests/Architecture/RoutePermissionCoverageRatchetTest.php`
  - `apps/api/tests/Architecture/baselines/route-permission-coverage-baseline.json`

- Task 5:
  - `docs/conventions/03-AUTHORIZATION.md`
  - `.claude/commands/add-permissions.md`

- Task 6 CI:
  - `.github/workflows/ci.yml`

- Task 6 handback:
  - `docs/handoff/HANDBACK-rbac-w0a-2026-09-10.md`

The five minor corrections are suitable for the dispatch brief and do not require another plan-gate round.

VERDICT: DISPATCH-READY