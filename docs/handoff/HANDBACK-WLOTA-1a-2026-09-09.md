---
status: review
promotion_ready: false
blocking_decision: legacy null-team role ownership
---

# W-LOT-A-1a handback — implementation checkpoint, not promotion approval

Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w-lot-a-1a`.
Branch: `lane/w-lot-a-1a`.
DISPATCH_SHA: `4373ba2f60ac6c303e90e4ae47236285f05e648c`.
Planning SHA: `c76435df4a98193dfacb80ad9c25839169179509`.
The intervening diff was documentation-only. The named-symbol census was rerun and is in local `docs/sessions/wlota1a/census.txt`.

Only Tasks 1, 2, and 6 were edited. No origin push, merge, Dokploy call, deployment, staging activation, or fleet operation occurred. The process environment retained `LOT_ACTION_PERMISSIONS_ENFORCE=false`; PHPUnit cases explicitly set the Laravel config true to exercise enforcement. PostgreSQL used the dedicated `autoerp_test_w` database on port 5433 with both database environment names pinned. Tests ran by file, never the full PHPUnit/Vitest suite. The mandated web lint command runs its own tools tests.

## Blocking plan assumption

The actual legacy seeder uses `Role::firstOrCreate(['name' => ..., 'guard_name' => 'sanctum'])`. That query-builder path does **not** invoke Spatie's static `Role::create()` and creates roles whose configured `tenant_id` is NULL. The delta's explicit tenant predicate finds no existing admin/manager/etc. Creating a tenant-scoped admin with Spatie's static method then raises:

> Spatie\Permission\Exceptions\RoleAlreadyExists: A role `admin` already exists for guard `sanctum`.

This is reproduced using the real legacy seeder in the PostgreSQL fixture, not a manufactured collision. The transaction rolls back. Fresh provisioning with an empty role catalogue works; upgrading the actual legacy catalogue does not. No role IDs, tenant ownership, or grants were migrated to hide this mismatch.

§8.4 permits “only allowed additions and the explicit manager recall removal.” It supplies no rule for adopting/re-homing a null-team legacy role. A question is pending with the owner: authorize adoption only after proving every assignment belongs to the current tenant (preserving IDs and custom grants), or explicitly fail closed and revise the plan. An unmarked `general_manager` remains a non-adoptable collision under either option.

There is a related incorrect assumption in the existing RoleController CrossTenantRoute annotations: ordinary Spatie `Role::with()` / `findOrFail()` queries have no automatic team global scope. The new delta uses explicit tenant predicates, but existing Roles API lookup annotations still overstate their isolation. This needs a reviewed team/global-role policy and a cross-team response/mutation regression test before promotion.

## Implemented behavior

- Additive, self-guarding role marker migration with PostgreSQL check/index and SQLite triggers/index; rollback refuses marked rows. Schema compatibility is checked before accepting existing objects.
- Dormant action middleware, exact BatchExpiry route permissions, scoped stock and attributable-history visibility, four-decimal string aggregates, shared trace contracts with owning Document/POS adapters, and field-compatible trace responses.
- Canonical/legacy matrices; transaction/advisory-lock delta; marker preservation branch before legacy synchronization; buffered-console plus stderr markers; CLI apply/verify modes; marker-derived snake-case RoleData; role protection; assignment guard around creation, update, and dedicated assignment with membership locks and postconditions.
- Batch route/navigation/action gating, server-authoritative batch permissions, generated map and RoleData, marker-derived Roles UI protection and modal-submit backstop, served bundle fingerprint, and a pre-activation browser test.

Task 2 is an incomplete integration because of the legacy-role blocker. Task 6's post-activation browser half is still owed. These commits are checkpoints for review, **not deployable approval**.

## Verification and red-first evidence

Local detailed logs are under ignored `docs/sessions/wlota1a/`. They are not production evidence and contain no staging read-back. The table records actual captured failures; it does not claim every later-added case was written before implementation. In particular, the expanded trace/second-company coverage, several assignment cases, route/map/sidebar tests, and browser case were added after the relevant runtime changes. This does not satisfy the dispatch's strict every-test-red-first requirement; do not describe it as fully TDD-compliant.

| Area / test | First captured failure | Verified run / remaining outcome |
| --- | --- | --- |
| RoleProvisioningSourceSchemaTest | Migration file did not exist (`schema-red-pg.txt`, SQLite equivalent) | Both DB drivers: 8 tests, 40 assertions each; `schema-final-pg.txt`, `schema-final-sqlite.txt` |
| BatchActionPermissionsTest route matrix / flag-off | Missing BatchActionAccess binding and absent `:batches.view` middleware (`action-boundary-red.txt`) | PostgreSQL consolidated run listed below |
| BatchReadLocationScopeTest initial three cases | Other-location stock / empty-scope / zero-stock visibility assertions failed (`read-red-pg.txt`) | Five cases passed; later A2 history and full snapshot additions are covered in final run |
| BatchTraceReaderContractTest | Missing shared-reader binding (`trace-red.txt`) | Expanded real Document/POS data: 3 tests, 23 assertions (`trace-complete-pg.txt`) |
| BatchTraceabilityModuleBoundaryTest | Forbidden persistence imports (`action-boundary-red.txt`) | Boundary check passes |
| LotActionSeededRoleMatrixTest | Missing viewer view; manager still had recall; absent GM key (`matrix-red.txt`) | Static matrix cases pass; full legacy/fresh matrix fixture coverage still owed |
| LotActionPermissionDeltaTest | Missing service (`delta-red-pg.txt`) | Upgrade blocked by actual null-team legacy roles; `delta-green-pg.txt` is misleadingly named and **contains failures** |
| Role protection / assignment / response | Expected 422, received 200; response key mismatch; missing command (`role-api-red-pg.txt`) | Marked-role API tests use explicitly provisioned marked fixtures, independently of failed delta. Six assignment tests passed (21 assertions); three protection tests and response case passed |
| GeneralManagerAssignmentWriterCensusTest | Creation lacked `lockForUpdate()` (`writer-census-red.txt`) | 1 test, 21 assertions (`writer-census-green.txt`); covers the three named controller methods, not a whole-repository writer census |
| LotActionReseedMarkerTest | Added after runtime implementation; no red-first claim | 3 tests, 11 assertions. Fresh empty-catalogue apply and buffered Artisan/stderr verified; real registration-request marker case still owed |
| Web action/role/server-authority tests | Unexpected edit controls; protected submit called PATCH; missing batches ModuleKey; decimal `.toFixed` failure (`web-red.txt`) | All six requested Vitest files: 87 tests passed (`web-final.txt`) |
| Pre-activation browser | Initial harness failed to bootstrap auth; not a production-red assertion (`browser-first.txt`) | 1 Chromium test passed (`browser-next.txt`); actual worktree Vite on port 5198, API response fixtures |

Final consolidated PostgreSQL run: **56 tests / 224 assertions: 7 errors and 2 failures** (`backend-final-pg.txt`). It includes all implemented Task-1 and Task-2 test files, both architecture/census checks, the migration test, and the existing permission exporter test. It intentionally leaves the real legacy-upgrade failures visible. One failure was an existing exporter expectation that omitted general_manager; it was updated for the new canonical grants, and that file then passed separately (3 tests / 19 assertions, `exporter-final.txt`). The remaining seven errors plus one command failure all originate from the legacy admin collision. Task-1 tests, including the expanded A2 trace/duplicate snapshots, passed in the consolidated run.

Other checks:

- Changed backend runtime PHPStan: zero errors (`backend-phpstan-final.json`). Pint and `git diff --check` passed.
- `pnpm typecheck` and `pnpm typecheck:e2e`: passed.
- `pnpm lint`: **passed** (including 213 tools tests).
- React Doctor `--verbose --diff dev`: 88/100, no errors, three high-complexity warnings. Same three component files independently scanned at DISPATCH_SHA/current: 46/100 and 23 issues in both, with the same category counts; no score regression. These isolated-file scores differ from the scoped-project score and must not be conflated. The shared advisory pre-commit hook emitted “React Doctor found staged regressions” while allowing the commit; its detailed output is discarded by that hook. The independent scans above retain the three complexity warnings rather than claiming a warning-free hook gate. No shared hook was edited or bypassed.
- `php artisan typescript:transform` reproduced the generated DTO declaration unchanged. Two independently generated permission-map files matched each other and the working artifact byte-for-byte.
- SHA-256 generated DTO: `ba8e862a6398b9ca04f6693090a4d5c91a79fa6eee9304ce5a1138f121e7b874`.
- SHA-256 permission map: `2017732065366f5b75491dcde572f56cd31be8ad4fb32c4e1c990214e1286dff`.

## Work still owed before acceptance

1. Resolve the legacy NULL-team role ownership policy. Complete the upgrade delta and rerun both mandatory marked-tenant flag-off preservation cases. Confirm explicit failure results and cache invalidation behavior after exceptions/reruns.
2. Prove team discrimination and one marker per team; add real concurrent-first-apply and concurrent-assignment/narrowing tests. Current locking code is not concurrency evidence. The existing team-restoration delta test does not exercise both success and exception paths.
3. Finish RoleIndexResponseContractTest with an unmarked same-name role in another tenant and actual tenant-isolation assertions. Resolve the false Spatie-global-scope annotations/lookup behavior noted above.
4. Add the mandatory real registration HTTP request → buffered seeder → stderr marker test. The current buffered Artisan test is **not** a substitute. Add command wrapper `tenants:run` string-one tests, missing-schema and collision command cases, and exact legacy/fresh seeded-role matrix tests.
5. Complete the writer census beyond the three explicit methods; add role-protection identity/rerun coverage. API fixture tests must not be reported as proof that the blocked delta provisions real legacy tenants.
6. Complete `e2e/batch-permissions.spec.ts` with the post-activation isolation describe/test, real B2 stock/history, duplicate-create snapshots, viewer/GM assertions, and absent hold/request-recall surfaces. The current file contains only the pre-activation half. Do not run post-activation locally with the flag off. The pre-activation test uses mocked API responses and does not claim live backend enforcement-off read-back.
7. Reconcile strict red-first compliance with the reviewer. Existing write-route middleware equality is tested; full write behavior regression and fully populated reservation/GL denied-mutation fixtures remain weaker than the requested proof.
8. Run required targeted checks again after resolving the above, then the orchestrator-owned three-reviewer gate. No reviewer acceptance, full PG lane, full browser lane, staging evidence, or promotion is claimed here.

## Resume recipe

1. Stay in this worktree and branch. Inspect the three source commit groups below and this handback before changing scope.
2. Use the dedicated PostgreSQL lane only. The local ignored `docs/sessions/wlota1a/test-env.sh` pins both DB names and keeps the feature-flag environment false. Do not copy it to production or commit it.
3. Reproduce the blocker with `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/Identity/LotActionPermissionDeltaTest.php tests/Feature/Identity/RolesAndPermissionsSeederMarkedTenantTest.php` after sourcing that environment from the worktree root.
4. Implement the owner-selected policy with real legacy-seeder fixtures. Never adopt an unmarked existing general_manager and never silently move cross-tenant assignments.
5. Run §13 by file. For the local browser harness, from `apps/web`: `pnpm exec playwright test --config ../../docs/sessions/wlota1a/playwright.config.ts --project=chromium --grep 'pre-activation web gating'`. This starts the worktree's own Vite instance instead of reusing another task's port 5173 server.
6. Regenerate both artifacts; require byte identity; retain Push-2/Push-3/Push-5 boundaries in follow-up commits. Stop at review. The orchestrator owns review dispatch, merging, pushing, and all activation/promotion work.

## Source-push ledger

Push 1 and Push 4 are operations only; no source files. Every changed source file is listed exactly once below. This handback is a separate review-metadata commit.

| Push group | File | Commit SHA |
| --- | --- | --- |
| Push 2 | `apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php` | `aeb594f21bd48b87e12ac02a34608d7b8ffb1020` |
| Push 2 | `apps/api/tests/Feature/Migrations/RoleProvisioningSourceSchemaTest.php` | `aeb594f21bd48b87e12ac02a34608d7b8ffb1020` |
| Push 3 | `apps/api/app/Console/Commands/ApplyLotActionPermissionDelta.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Application/Services/LotActionPermissionActivation.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/BatchExpiryServiceProvider.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Domain/Repositories/BatchRepositoryInterface.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Presentation/Middleware/BatchActionAccess.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Presentation/routes.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Modules/Document/Infrastructure/BatchTraceability/DocumentBatchTraceReaderAdapter.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Modules/Document/Providers/DocumentServiceProvider.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Modules/Identity/Application/DTOs/LotActionPermissionDeltaResult.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Modules/Identity/Application/DTOs/RoleData.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Modules/Identity/Application/Services/GeneralManagerAssignmentGuard.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Modules/Identity/Domain/Enums/LotActionPermissionDeltaOutcome.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Modules/Identity/Domain/Enums/RoleProvisioningSource.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Modules/Identity/Domain/Enums/SystemRoleName.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Modules/POS/Infrastructure/BatchTraceability/PosBatchTraceReaderAdapter.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Modules/POS/Providers/POSServiceProvider.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Shared/Contracts/BatchTraceability/BackwardDocumentBatchTraceData.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Shared/Contracts/BatchTraceability/DocumentBatchTraceReader.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Shared/Contracts/BatchTraceability/ForwardDocumentBatchTraceData.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Shared/Contracts/BatchTraceability/ForwardPosBatchTraceData.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/app/Shared/Contracts/BatchTraceability/PosBatchTraceReader.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/config/lot_action_permissions.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/database/seeders/RolesAndPermissionsSeeder.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/tests/Architecture/BatchTraceabilityModuleBoundaryTest.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/tests/Feature/BatchExpiry/BatchActionPermissionsTest.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/tests/Feature/BatchExpiry/BatchExpiringLocationScopeTest.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/tests/Feature/BatchExpiry/BatchTraceReaderContractTest.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/tests/Feature/Console/ApplyLotActionPermissionDeltaCommandTest.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/tests/Feature/Console/ExportFrontendPermissionsMapCommandTest.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/tests/Feature/Console/LotActionReseedMarkerTest.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/tests/Feature/Identity/GeneralManagerAssignmentTest.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/tests/Feature/Identity/GeneralManagerAssignmentWriterCensusTest.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/tests/Feature/Identity/GeneralManagerRoleProtectionTest.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/tests/Feature/Identity/LotActionPermissionDeltaTest.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/tests/Feature/Identity/LotActionSeededRoleMatrixTest.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/tests/Feature/Identity/RoleIndexResponseContractTest.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/tests/Feature/Identity/RolesAndPermissionsSeederMarkedTenantTest.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `docs/glossary.md` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 5 | `apps/web/e2e/batch-permissions.spec.ts` | `2a6ba01eab17549ff2433d913fb9ee43a4c90e5d` |
| Push 5 | `apps/web/src/components/organisms/Sidebar/Sidebar.tsx` | `2a6ba01eab17549ff2433d913fb9ee43a4c90e5d` |
| Push 5 | `apps/web/src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx` | `2a6ba01eab17549ff2433d913fb9ee43a4c90e5d` |
| Push 5 | `apps/web/src/features/batches/pages/BatchDetailPage.tsx` | `2a6ba01eab17549ff2433d913fb9ee43a4c90e5d` |
| Push 5 | `apps/web/src/features/batches/pages/BatchListPage.tsx` | `2a6ba01eab17549ff2433d913fb9ee43a4c90e5d` |
| Push 5 | `apps/web/src/features/batches/pages/__tests__/BatchPermissions.test.tsx` | `2a6ba01eab17549ff2433d913fb9ee43a4c90e5d` |
| Push 5 | `apps/web/src/features/batches/pages/__tests__/BatchSeededPermissionMap.test.ts` | `2a6ba01eab17549ff2433d913fb9ee43a4c90e5d` |
| Push 5 | `apps/web/src/features/settings/RolesPage.test.tsx` | `2a6ba01eab17549ff2433d913fb9ee43a4c90e5d` |
| Push 5 | `apps/web/src/features/settings/RolesPage.tsx` | `2a6ba01eab17549ff2433d913fb9ee43a4c90e5d` |
| Push 5 | `apps/web/src/hooks/__tests__/usePermissions.moduleAccess.test.tsx` | `2a6ba01eab17549ff2433d913fb9ee43a4c90e5d` |
| Push 5 | `apps/web/src/hooks/permissionsMap.generated.ts` | `2a6ba01eab17549ff2433d913fb9ee43a4c90e5d` |
| Push 5 | `apps/web/src/hooks/usePermissions.ts` | `2a6ba01eab17549ff2433d913fb9ee43a4c90e5d` |
| Push 5 | `apps/web/src/routes/__tests__/BatchRoutePermissions.test.tsx` | `2a6ba01eab17549ff2433d913fb9ee43a4c90e5d` |
| Push 5 | `apps/web/src/routes/index.tsx` | `2a6ba01eab17549ff2433d913fb9ee43a4c90e5d` |
| Push 5 | `packages/shared/types/generated.d.ts` | `2a6ba01eab17549ff2433d913fb9ee43a4c90e5d` |
