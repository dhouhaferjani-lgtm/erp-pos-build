---
status: review
promotion_ready: false
blocking_decision: none
promotion_blocker: parked CI lane coverage guard and orchestrator acceptance
---

# W-LOT-A-1a handback — legacy-role ruling implemented, 2026-09-10

Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w-lot-a-1a`.
Branch: `lane/w-lot-a-1a`.
Original DISPATCH_SHA: `4373ba2f60ac6c303e90e4ae47236285f05e648c`.
Ruling-resume SHA: `3046ca87204f69dc6819a73ac60a56a0d68bbe78`.
Planning SHA: `c76435df4a98193dfacb80ad9c25839169179509`.
The original named-symbol census is retained in ignored `docs/sessions/wlota1a/census.txt`.

Authority: `CODEX-PROMPT-WLOTA-1a-ruling-legacy-null-team-roles-2026-09-09.md`, supplied by the owner on 2026-09-10. **No ownership adoption or re-homing.** This replaces the ownership question in the previous handback.

No merge, origin push, Dokploy call, deployment, activation, or fleet operation occurred. The environment kept `LOT_ACTION_PERMISSIONS_ENFORCE=false`. PHPUnit sets Laravel configuration true only in enforcement test cases and their isolated worker processes. Every PG command pins both `DB_DATABASE` and `DB_CENTRAL_DATABASE` to `autoerp_test_w`, port 5433. The real registration test creates and removes its own physical tenant database through the application's database manager; it asserts the canonical database name no longer exists after cleanup. No full PHPUnit or Vitest suite ran.

## Delivered

- The delta resolves each edited catalogue role with `name/guard` plus `(tenant_id IS NULL OR tenant_id = current tenant)`. It preserves existing IDs, team values, custom grants, and assignments. A mixed NULL/scoped match returns `FAILED reason=ambiguous_legacy_role_collision` before any partial write. NULL-team and scoped unmarked `general_manager` rows both fail closed. Only the new GM receives the provisioning marker and its mandatory tenant team.
- Apply and verify use the same resolution. The transactional advisory lock covers read, write, and verification. Team restoration precedes cache invalidation, including exception paths. The existing exact-marked-GM seeder branch stays before legacy synchronization; it skips synchronization altogether and preserves the real NULL-team catalogue. Both mandatory flag-off preservation cases now pass against that catalogue.
- Schema coverage explicitly allows an unmarked NULL-team manager while retaining marked-row rejection. Matrix coverage compares every legacy role's grants against hashes derived from the actual dispatch-base seeder, then verifies the complete canonical catalogue, fresh provisioning, and reruns.
- Added real `tenants:run` string-`1` apply/verify tests; exact missing-schema/unmarked/mixed-collision CLI failure markers; two-team discrimination; one marker per team; role identity after rejected API edits/rerun; and a real registration HTTP → buffered Artisan seeder → stderr marker test.
- Added barrier-controlled, bounded PostgreSQL process races for first apply and assignment versus scope narrowing. Workers use committed disposable schema copies of test fixtures, real application code and, for assignment/narrowing, the HTTP kernel. Both workers must reach the DB lock barrier. Results are one `APPLIED` plus one `ALREADY_APPLIED`, or one HTTP 200 plus one invariant-specific 422; no restricted GM final state survives. Temporary schemas are removed in `finally`.
- The runtime writer census scans executable PHP tokens across `app/`: three dynamic assignment sites require the guard/team/transaction boundaries; two other sites assign only literal `admin`. The sole direct pivot reference is classified as the existing read-only role-user count.
- Roles response coverage exercises marked and same-name unmarked roles with separate tenant actors and index/show wire keys. This does not claim the deferred query-wide team isolation exists.
- The post-activation browser half now creates company B, B1/B2, matching-SKU lots across companies, identifiable B2 stock/history, restricted viewer/manager and unrestricted GM fixtures, duplicate-create state comparisons, and UI/action assertions. It contains no mocked API responses. It was **not run locally**, as required. The pre-activation browser half remains separate and passed against the worktree's Vite bundle with response fixtures.

The explicitly deferred RoleController annotation issue is recorded in [the requested ticket](../superpowers/tickets/2026-09-09-role-controller-team-scope-annotations.md): controller lines 158, 189, 236, 280, with line 206 distinguished as static-create team stamping. No controller scope policy was changed by this ruling follow-up.

## Verification

Evidence lives in ignored `docs/sessions/wlota1a/` and is local development evidence, not staging proof.

| Check | Result | Evidence |
| --- | --- | --- |
| Consolidated Task-1/Task-2 PostgreSQL files, schema, boundary/census, exporter | **75 tests, 0 errors, 0 failures**; final run 3,778 assertions (race polling makes assertion counts timing-dependent) | `ruling-final-pg.txt` |
| Both mandatory marked-tenant preservation cases | Green within consolidated run; real legacy seeder fixtures | `ruling-final-pg.txt` |
| Real registration HTTP/stderr case | Green; standalone 1 test / 7 assertions, also green in consolidated run | `ruling-registration.txt` |
| Concurrent first apply and assignment/narrowing | Both green, also included in final consolidated run | `ruling-concurrency-next.txt`, `ruling-final-pg.txt` |
| SQLite schema | 9 tests / 43 assertions | `ruling-schema-sqlite.txt` |
| Changed runtime PHPStan | No errors | `ruling-phpstan.txt`, `ruling-preflight.txt` |
| Pint / whitespace | Passed | `ruling-pint.txt`, `ruling-preflight.txt`, `git diff --check` |
| Six Task-6 Vitest files | 87 tests passed | `ruling-web-vitest.txt` |
| `pnpm typecheck`, `pnpm typecheck:e2e` | Passed | `ruling-web-typecheck.txt`, `ruling-typecheck-e2e.txt`, `ruling-preflight.txt` |
| `pnpm lint` | Exit 0, including audits and tool/rule liveness tests; existing warnings remain | `ruling-lint-complete.txt` (tool output was truncated), task transcript |
| Pre-activation Chromium browser | 1 test passed | `ruling-browser-pre.txt` |
| React Doctor, changes since resume SHA | 100/100, no issues (one changed web test file) | `ruling-react-doctor-scoped.txt` |
| Shared DTO and permission generation | Regenerated; byte-identical to committed artifacts; two independent map exports identical | `ruling-preflight.txt`, `ruling-artifacts.txt` |
| Scoped `./scripts/preflight.sh` | **Not green:** stops at parked CI lane coverage guard | `ruling-preflight.txt` |

The first lint attempt was terminated (143); the separate retry of the full `pnpm lint` command completed with exit 0. The unpinned React Doctor default compared the branch to `origin/main` and included unrelated historical changes; it is not regression evidence. The pinned resume comparison above is the relevant result.

Artifact SHA-256:

- `generated.d.ts`: `ba8e862a6398b9ca04f6693090a4d5c91a79fa6eee9304ce5a1138f121e7b874`.
- `permissionsMap.generated.ts`: `2017732065366f5b75491dcde572f56cd31be8ad4fb32c4e1c990214e1286dff`.

## Red-first record and limits

| Test / area | First actual captured failure | Green command / evidence |
| --- | --- | --- |
| `RoleProvisioningSourceSchemaTest` (initial implementation) | Migration file did not exist | PG consolidated command below; SQLite schema command below |
| `BatchActionPermissionsTest` / module-boundary test (initial implementation) | Missing middleware/binding; forbidden persistence imports | PG consolidated command below |
| `BatchReadLocationScopeTest` (initial implementation) | Other-location stock, empty-scope and zero-stock visibility assertions | PG consolidated command below |
| `BatchTraceReaderContractTest` (initial implementation) | Missing shared-reader binding | PG consolidated command below |
| `LotActionPermissionDeltaTest::test_real_legacy_catalogue_keeps_null_teams_ids_assignments_and_custom_grants` and both marked-tenant preservation cases | `RoleAlreadyExists: A role admin already exists for guard sanctum` prevented the expected successful delta | `ruling-red-pg.txt` → PG consolidated command below |
| NULL-GM and mixed-catalogue collision tests | Legacy admin collision occurred before the expected `FAILED` result | `ruling-red-pg.txt` → PG consolidated command below |
| `test_tenants_run_apply_string_one_selects_apply_mode` | Output lacked `mode=APPLY outcome=APPLIED reason=canonical_delta_applied` | `ruling-command-matrix-red.txt` → PG consolidated command below |
| `test_tenants_run_verify_string_one_selects_verify_mode` | Legacy admin collision prevented apply/setup and verify assertion | Same command/log pair |
| CLI NULL-GM / mixed legacy collision tests | Output lacked the exact `unmarked_general_manager_collision` / `ambiguous_legacy_role_collision` marker | Same command/log pair |
| Web action/role/server-authority tests (initial implementation) | Unexpected edit controls, protected PATCH submission, missing batches ModuleKey, decimal `.toFixed` failure | `web-red.txt` → six-file Vitest command below |

Do not retroactively claim every coverage addition was production-red-first. The original handback already disclosed later-added trace/assignment/route/sidebar coverage. This follow-up's allowed-unmarked schema test, exact legacy hash pin, seeded/marker/writer coverage of already-working behavior, and browser specification are additional evidence, not invented production-red results. The first matrix fixture incorrectly expected legacy cashier to lack batch-view; the actual shipped seeder already grants it, so that fixture was corrected. The first process-race harness needed `pg_stat_clear_snapshot()` to observe both workers at the barrier; that was a test-harness failure. The registration test's first logger mock lacked an initialized LogManager; the corrected proxy observes the real registration path. These are not runtime feature failures.

## Promotion blocker and reviewer caveats

Preflight's CI lane-manifest guard rejects the test classes introduced by the original dispatch implementation:

| Parked group | Actual classes | Ceiling |
| --- | ---: | ---: |
| BatchExpiry | 21 | 17 |
| Console | 18 | 16 |
| Identity | 39 | 32 |
| Migrations | 15 | 14 |
| All gated lanes | 1,264 | 1,250 |

No new test class was added in this ruling follow-up; it extends existing dispatch classes. The manifest, its ceilings, and execution gates are unchanged. The orchestrator must resolve actual CI execution coverage under its promotion policy; this handback does not raise ceilings to conceal the guard failure. Checks after that preflight stop are not claimed to have run through preflight. Required targeted checks were run independently as recorded above.

The orchestrator still owns the three-reviewer acceptance, strict historical TDD evidence disposition, the deferred RoleController ticket, post-activation browser execution and all staging proof. Original Task-1 denied-mutation tests compare reservations/history/GL snapshots but populate stock most strongly; reviewers should retain the previous handback's caveat about the depth of those fixtures. No reviewer ACCEPT or promotion approval is claimed.

## Resume and exact local commands

Stay in this worktree. Source `docs/sessions/wlota1a/test-env.sh` at its root; it is ignored local configuration and must not be committed or copied to staging.

```bash
cd apps/api
./vendor/bin/phpunit -c phpunit-pgsql.xml \
  tests/Feature/BatchExpiry/BatchActionPermissionsTest.php \
  tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php \
  tests/Feature/BatchExpiry/BatchExpiringLocationScopeTest.php \
  tests/Feature/BatchExpiry/BatchTraceReaderContractTest.php \
  tests/Architecture/BatchTraceabilityModuleBoundaryTest.php \
  tests/Feature/Identity/LotActionSeededRoleMatrixTest.php \
  tests/Feature/Identity/RolesAndPermissionsSeederMarkedTenantTest.php \
  tests/Feature/Identity/LotActionPermissionDeltaTest.php \
  tests/Feature/Identity/GeneralManagerAssignmentTest.php \
  tests/Feature/Identity/GeneralManagerRoleProtectionTest.php \
  tests/Feature/Identity/RoleIndexResponseContractTest.php \
  tests/Feature/Identity/GeneralManagerAssignmentWriterCensusTest.php \
  tests/Feature/Console/LotActionReseedMarkerTest.php \
  tests/Feature/Console/ApplyLotActionPermissionDeltaCommandTest.php \
  tests/Feature/Migrations/RoleProvisioningSourceSchemaTest.php \
  tests/Feature/Console/ExportFrontendPermissionsMapCommandTest.php
./vendor/bin/phpunit -c phpunit.xml tests/Feature/Migrations/RoleProvisioningSourceSchemaTest.php

cd ../web
pnpm vitest run \
  src/routes/__tests__/BatchRoutePermissions.test.tsx \
  src/features/batches/pages/__tests__/BatchPermissions.test.tsx \
  src/features/batches/pages/__tests__/BatchSeededPermissionMap.test.ts \
  src/features/settings/RolesPage.test.tsx \
  src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx \
  src/hooks/__tests__/usePermissions.moduleAccess.test.tsx
pnpm typecheck
pnpm typecheck:e2e
pnpm lint
pnpm exec playwright test --config ../../docs/sessions/wlota1a/playwright.config.ts \
  --project=chromium --grep 'pre-activation web gating'
```

Preflight was invoked with `PREFLIGHT_SCOPE=paths`, explicit schema/matrix test files (`-c phpunit-pgsql.xml`), the changed PHP runtime/Pint paths, and the same six explicit Vitest files. Keep those scopes; never invoke a full local suite.

For the **orchestrator-only post-activation browser run**, use a disposable activated tenant with BatchExpiry enabled, an unrestricted admin, capacity for company B and three test users, and an exclusive fixture database. Configure the browser base URL for the deployed web. Supply `WLOTA1A_POST_ACTIVATION=1`, `WLOTA1A_API_BASE` ending in `/api/v1`, `WLOTA1A_TENANT_ID`, `WLOTA1A_ADMIN_EMAIL`, `WLOTA1A_ADMIN_PASSWORD`, and `WLOTA1A_TENANT_DATABASE` from the canonical tenant mapping. Standard `PG*` variables/credentials must target that exact tenant database; `psql` must be installed on the runner. The test asserts `current_database()` equality and rejects foreign-tenant user rows.

`WLOTA1A_ON_EVIDENCE` points to an orchestrator-produced normalized copy of the validated §11 ON evidence containing exactly `worker ON`, `api ON`, and `scheduler ON` (one per line). This test consumes that evidence; it neither produces activation proof nor changes configuration. Then run the same spec with `--grep 'post-activation isolation'`. It creates API catalogue/users plus clearly named non-fiscal SQL stock/history fixtures and leaves them in the disposable tenant for evidence review; dispose of that test tenant through the orchestrator's normal cleanup afterward. Never aim it at a real customer tenant. This run is still owed after actual activation.

## Commit groups and source-push ledger

Assemble each group in this order; do not cherry-pick only the follow-up patches onto a tree missing the original implementation:

- Push 2: `aeb594f21bd48b87e12ac02a34608d7b8ffb1020`, then `66c7068ef8bcea1d64b73e484ab5d908fac3230a`.
- Push 3: `892b30110cf0bb7155da0ca7020abb994cdf8ebd`, then `ed1ef81fe9f23a8ac07d6d4930007a4cebefd48e`.
- Push 5: `2a6ba01eab17549ff2433d913fb9ee43a4c90e5d`, then `532fe5627558e9d04d7facc4ab8f23b2b556f5d3`.

Pushes 1 and 4 remain operations-only. Every source file appears once below with the latest source commit carrying it; the groups above preserve full provenance. This handback is a separate review-metadata commit, outside those source groups.

| Push group | File | Latest source commit |
| --- | --- | --- |
| Push 2 | `apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php` | `aeb594f21bd48b87e12ac02a34608d7b8ffb1020` |
| Push 2 | `apps/api/tests/Feature/Migrations/RoleProvisioningSourceSchemaTest.php` | `66c7068ef8bcea1d64b73e484ab5d908fac3230a` |
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
| Push 3 | `apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php` | `ed1ef81fe9f23a8ac07d6d4930007a4cebefd48e` |
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
| Push 3 | `apps/api/tests/Feature/Console/ApplyLotActionPermissionDeltaCommandTest.php` | `ed1ef81fe9f23a8ac07d6d4930007a4cebefd48e` |
| Push 3 | `apps/api/tests/Feature/Console/ExportFrontendPermissionsMapCommandTest.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `apps/api/tests/Feature/Console/LotActionReseedMarkerTest.php` | `ed1ef81fe9f23a8ac07d6d4930007a4cebefd48e` |
| Push 3 | `apps/api/tests/Feature/Identity/GeneralManagerAssignmentTest.php` | `ed1ef81fe9f23a8ac07d6d4930007a4cebefd48e` |
| Push 3 | `apps/api/tests/Feature/Identity/GeneralManagerAssignmentWriterCensusTest.php` | `ed1ef81fe9f23a8ac07d6d4930007a4cebefd48e` |
| Push 3 | `apps/api/tests/Feature/Identity/GeneralManagerRoleProtectionTest.php` | `ed1ef81fe9f23a8ac07d6d4930007a4cebefd48e` |
| Push 3 | `apps/api/tests/Feature/Identity/LotActionPermissionDeltaTest.php` | `ed1ef81fe9f23a8ac07d6d4930007a4cebefd48e` |
| Push 3 | `apps/api/tests/Feature/Identity/LotActionSeededRoleMatrixTest.php` | `ed1ef81fe9f23a8ac07d6d4930007a4cebefd48e` |
| Push 3 | `apps/api/tests/Feature/Identity/RoleIndexResponseContractTest.php` | `ed1ef81fe9f23a8ac07d6d4930007a4cebefd48e` |
| Push 3 | `apps/api/tests/Feature/Identity/RolesAndPermissionsSeederMarkedTenantTest.php` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `docs/glossary.md` | `892b30110cf0bb7155da0ca7020abb994cdf8ebd` |
| Push 3 | `docs/superpowers/tickets/2026-09-09-role-controller-team-scope-annotations.md` | `ed1ef81fe9f23a8ac07d6d4930007a4cebefd48e` |
| Push 5 | `apps/web/e2e/batch-permissions.spec.ts` | `532fe5627558e9d04d7facc4ab8f23b2b556f5d3` |
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
