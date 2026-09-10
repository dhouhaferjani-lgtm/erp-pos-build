---
status: review
promotion_ready: false
blocking_decision: none
promotion_blocker: orchestrator review and staged acceptance
---

# W-LOT-A-1a handback — fix round 1, 2026-09-10

Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w-lot-a-1a`. Branch: `lane/w-lot-a-1a`.
Original dispatch: `4373ba2f60ac6c303e90e4ae47236285f05e648c`; round-1 base: `04e60530c`; verified source HEAD: `850f7a295bbf53b5ff9ca751d3047ee3134380df`. Historical implementation/ruling evidence remains in the handback at `04e60530c`.

Authority: the owner's `CODEX-PROMPT-WLOTA-1a-fix-round-1-2026-09-10.md`, plan rev 10 §0 Round-9 addendum and the three 2026-09-10 r1 gate registers, read from the main checkout. This handback supersedes the previous consolidated test commands and parked-ceiling blocker.

No merge, push, deployment, activation or fleet operation performed. Legacy NULL-team role identities remain unchanged; technician receives no batches.view. Push 4 delta acceptance must precede Push 5 web; never combine them in an automatic deployment. Post-activation browser execution remains orchestrator-owned and was not run against an activated tenant.

## Fix round 1

| Finding | Resolution | Current source citation |
| --- | --- | --- |
| Tenancy B-1 | Shared race harness skips non-PG drivers; private DB-name assertion removed. Both callers pass on PG and skip only their race on SQLite. | `apps/api/tests/Feature/Identity/LotActionPermissionDeltaTest.php:32` |
| Tenancy B-2 / M-1; inventory I-1 / I-2 / I-3 | Option B retained. All five mutation/create resource paths load scoped stock; unloaded resource throws. Real flag-off HTTP tests assert full payloads and work without batches.view. | `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:105`; `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:24`; `apps/api/tests/Feature/BatchExpiry/BatchActionPermissionsTest.php:43` |
| Tenancy M-2 | Technician remains ungranted, with the owner ruling named beside grants. | `apps/api/database/seeders/RolesAndPermissionsSeeder.php:99` |
| Tenancy M-3 / frontend B-4 | PHP transformer attribute emits Array<string>; generated file regenerated. The RolesPage unsafe spread warning is gone. | `apps/api/app/Modules/Identity/Application/DTOs/RoleData.php:20`; `packages/shared/types/generated.d.ts:1012` |
| Tenancy M-4 | Malformed partner UUID returns 404; malformed product/date filters return 422 using the existing API validation envelope. | `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:106`; `apps/api/tests/Feature/BatchExpiry/BatchTraceReaderContractTest.php:87` |
| Tenancy M-5 / inventory I-5 | Document number is nullable in both DTOs; backward product ID is nullable; adapter casts removed. Forward DTO has no productId field, so no new wire field was invented. Draft/null HTTP coverage passes. | `apps/api/app/Shared/Contracts/BatchTraceability/BackwardDocumentBatchTraceData.php:16`; `apps/api/app/Shared/Contracts/BatchTraceability/ForwardDocumentBatchTraceData.php:11`; `apps/api/tests/Feature/BatchExpiry/BatchTraceReaderContractTest.php:97` |
| Inventory I-4 | Restricted POS foreign-location denial preserves seven tables; unrestricted POS JSON is identical across activation; backward trace discriminates two companies sharing a partner fixture. | `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php:157`; `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php:169`; `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php:272` |
| Frontend B-1 | Committed env-driven harness, isolated port, CI Node 20; exact requested command passes at committed source line 39:3. | `apps/web/playwright.config.ts:4`; `apps/web/e2e/batch-permissions.spec.ts:39` |
| Frontend B-2 | Post-activation suite skips without the explicit opt-in, before any fixture side effects. | `apps/web/e2e/batch-permissions.spec.ts:82` |
| Frontend B-3 | Browser denial loop uses the real /inventory/batches/new route. | `apps/web/e2e/batch-permissions.spec.ts:51` |
| Frontend B-5 | p-4 / rounded-lg / existing hover treatment retained; only base background token used, no composite card elevation. | `apps/web/src/features/settings/RolesPage.tsx:255` |
| Frontend B-6 | Batch totals and expired stock availability are strings; false float documentation removed. List and expiry write-off render 3.1234 strings; expiry submission preserves 3.1234. | `apps/web/src/features/batches/types.ts:38`; `apps/web/src/features/batches/pages/__tests__/BatchPermissions.test.tsx:35`; `apps/web/src/features/batches/pages/ExpiryWriteOffPage.test.tsx:160` |
| Frontend B-7 | Deferred staged-middleware alignment ticket, with factual qualification: create/update already enforce permission via FormRequests. No new Task-1 middleware added. | `docs/superpowers/tickets/2026-09-10-batch-create-update-api-enforcement.md:1` |
| Tenancy N-1 | Flag-independent POS UUID-validation behavior documented in release notes. | `docs/handoff/RELEASE-NOTES-WLOTA-1a-2026-09-10.md:10` |
| Tenancy N-2 | Example environment declares activation false. | `apps/api/.env.example:193` |
| Tenancy N-3 / N-4 | Stderr marker limited to marked/activated paths. Missing tenant/team context takes legacy fallback with a logged reason. Real seeder tests cover all paths. | `apps/api/database/seeders/RolesAndPermissionsSeeder.php:35`; `apps/api/tests/Feature/Console/LotActionReseedMarkerTest.php:135` |
| Tenancy N-5 / frontend fixture minor | Fixture has an ID and satisfies Batch; no undefined-key warning. | `apps/web/src/features/batches/pages/__tests__/BatchPermissions.test.tsx:13` |
| Inventory I-6 | Positive-stock-only picker behavior documented and pinned against a depleted but historically visible lot. | `apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:109`; `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php:215` |
| Inventory I-7 | Boundary ratchet scans every BatchExpiry presentation controller. | `apps/api/tests/Architecture/BatchTraceabilityModuleBoundaryTest.php:13` |
| Inventory I-8 / frontend precision minor | Deferred detail parseFloat and list currency-vs-unit precision ticket; display behavior unchanged. | `docs/superpowers/tickets/2026-09-10-batch-detail-parsefloat-quantities.md:1` |
| Inventory I-9 | Stock lookup resolves the read locations once and passes the result to both readers; null remains unrestricted. | `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:342` |
| Frontend provisioned-copy minor | Marker-derived badge uses roles.provisionedReadOnly in en/fr/ar; legacy system badge retained. | `apps/web/src/features/settings/RolesPage.tsx:276`; `apps/web/src/locales/en/common.json:1096`; `apps/web/src/locales/fr/common.json:1113`; `apps/web/src/locales/ar/common.json:1079` |
| Frontend modal-state minor | Title and comment explicitly identify tampering with the selected object, not a simulated refetch. | `apps/web/src/features/settings/RolesPage.test.tsx:117` |
| Frontend fingerprint minor | Comment explains why the route handle retains the literal in the served bundle. | `apps/web/src/routes/index.tsx:1205` |
| Generated route-manifest follow-up | Preflight detected stale batch permission metadata; regenerated the four rows from source, without changing the generator or routes. Ships in Push 5. | `scripts/factory/manifests/routes-web.yaml:297` |
| Manifest / CI raise | Approved ceilings 21/18/39/15 and total 1264, with truthful parked-lane notes and twelve exact PG selections. Checker exits 0. | `apps/api/tests/feature-lane-manifest.json:9`; `.github/workflows/ci.yml:1133` |

## Verification

59 PostgreSQL tests / 471 assertions passed across twelve files, **one file per invocation, sequentially**. Both DB_DATABASE and DB_CENTRAL_DATABASE were pinned to `autoerp_test_w`, port 5433. The registration test creates and deletes its own disposable physical tenant database through the application manager. No full PHPUnit or Vitest suite ran.

| PostgreSQL file | Result |
| --- | --- |
| `BatchActionPermissionsTest.php` | 6 tests, 34 assertions |
| `BatchReadLocationScopeTest.php` | 9 tests, 54 assertions |
| `BatchExpiringLocationScopeTest.php` | 2 tests, 6 assertions |
| `BatchTraceReaderContractTest.php` | 5 tests, 38 assertions |
| `LotActionReseedMarkerTest.php` | 6 tests, 24 assertions |
| `LotActionPermissionDeltaTest.php` | 12 tests, 122 assertions |
| `GeneralManagerAssignmentTest.php` | 7 tests, 116 assertions |
| `RolesAndPermissionsSeederMarkedTenantTest.php` | 2 tests, 4 assertions |
| `LotActionSeededRoleMatrixTest.php` | 5 tests, 38 assertions |
| `RoleIndexResponseContractTest.php` | 1 tests, 12 assertions |
| `ExportFrontendPermissionsMapCommandTest.php` | 3 tests, 19 assertions |
| `BatchTraceabilityModuleBoundaryTest.php` | 1 tests, 4 assertions |

SQLite (`phpunit.xml`): LotActionPermissionDeltaTest 12 tests / 45 assertions / 1 race skipped; GeneralManagerAssignmentTest 7 tests / 22 assertions / 1 race skipped. Both exit 0; 17 executable tests pass, 2 PG-only races skip.

Six §13 Vitest files ran separately: routes 7, batch permissions 9, seeded map 1, RolesPage 3, Sidebar 56, usePermissions 12 = **88 passed**. Additional changed consumer files: ExpiryWriteOffPage 8 and tenantScope 3 = **99 targeted web regression tests passed**. Preflight additionally ran its fixed detector checks and two fiscal-parity files (29 tests); no full application suite ran. TypeScript and e2e typechecks pass. Scoped ESLint: 0 errors / 32 warnings, including no RoleData unsafe-spread warning. Keys/design-system audits have 0 new / 0 stale; i18n completeness passes its unchanged baseline. Pint --test and PHPStan level 8 pass for touched PHP (PHPStan runtime/seeder paths, Pint includes tests).

React Doctor changed-file report: 89/100 with the RolesPage complexity warning. A same-tool, explicit-file comparison of the original committed RolesPage and the current RolesPage is also 89/100 in both, with the same complexity finding (14 original diagnostics vs 11 current); no score regression. The staged hook's complexity diagnostic was inspected against that baseline rather than suppressed.

Final scoped preflight: **exit 0 — all checks passed**, recorded in `docs/sessions/wlota1a/r1-preflight.txt`. The first run passed through detector-liveness but caught a stale generated route manifest; its four batch permission rows were regenerated and committed before the rerun.

Evidence logs are retained locally under ignored `docs/sessions/wlota1a/r1-*`; no claim of a remote CI run is made. The manifest checker itself exits 0 with the existing parked-lane warning. BatchActionPermissionsTest and RoleProvisioningSourceSchemaTest intentionally remain outside the PG allowlist, per the owner's ruling; no coverage claim is made for those absent selections.

### Committed browser evidence

Verified source HEAD is the SHA above. `apps/web/e2e/batch-permissions.spec.ts` and `apps/web/playwright.config.ts` have no working-copy changes. Using CI's Node 20.19.4 and the committed config:

```bash
export PATH="$HOME/.nvm/versions/node/v20.19.4/bin:$PATH"
export PLAYWRIGHT_PORT=5198
export PLAYWRIGHT_HTML_OPEN=never
pnpm exec playwright test e2e/batch-permissions.spec.ts --project=chromium --grep 'pre-activation web gating'
```

Run from this worktree's `apps/web`. Exact result in `docs/sessions/wlota1a/r1-browser-pre-node20.txt`: **`[chromium] › e2e/batch-permissions.spec.ts:39:3`**, **`1 passed`**. Explicit port uses an isolated Vite instance with strictPort and reuseExistingServer=false. No ignored custom config is used.

The earlier source-line discrepancy is reproducible without any source edit: Node 25.2.1 reports compiled line 88, including with a fresh Playwright cache; Node 20.19.4 reports original line 39. The cold-cache source-map sourcesContent is byte-identical to the committed spec, and line 88 is the test declaration in the generated JavaScript. This supports a local runtime/source-map reporting issue; a mismatched reported line alone did not prove an uncommitted spec was executed. The current accepted evidence uses the correct original-source location.

Post-activation remains opt-in and requires the real ON evidence, disposable activated tenant, canonical DB mapping and credentials described in the committed spec. With no WLOTA1A_POST_ACTIVATION it skips before any side effects; a separate opt-out run reports 1 skipped (r1-browser-post-skipped.txt).

### Reproduce backend and web checks safely

Use the runner's local credentials; set DB_DATABASE and DB_CENTRAL_DATABASE to `autoerp_test_w`, DB_PORT and DB_CENTRAL_PORT to 5433, both hosts to 127.0.0.1, APP_ENV=testing, CACHE_STORE=array and LOT_ACTION_PERMISSIONS_ENFORCE=false. Unset DB_URL / DB_CENTRAL_URL overrides. From `apps/api`, run each named PHP file individually with `./vendor/bin/phpunit -c phpunit-pgsql.xml <one-file>`. For each of the two SQLite files, override DB_CONNECTION=sqlite and both database names to :memory: and use `-c phpunit.xml`.

Run each named Vitest file individually with `pnpm exec vitest run <one-file>` from `apps/web`, then pnpm typecheck, pnpm typecheck:e2e, pnpm audit:keys, pnpm audit:design-system. `php tools/feature-lane-manifest-check.php` runs from `apps/api`.

The local `r1-preflight.sh` records the exact environment used for `./scripts/preflight.sh`: PREFLIGHT_SCOPE=paths, one PG BatchActionPermissionsTest file, one Vitest BatchPermissions file, and touched PHP Pint/PHPStan paths. Do not invoke preflight without those laptop-safe test scopes.

## Red-first evidence and retained behavior

- `r1-scope-red.txt`: restricted update expected 3.1234 but received 94.1234; unloaded resource failed to throw. Both assertions pass after the scoped-load/loud-resource fix.
- `r1-trace-red.txt`: malformed partner UUID produced PG 22P02 / 500; draft document number was empty string instead of null. Both cases pass after validation/nullability fixes.
- `r1-seeder-red.txt`: unmarked seed unexpectedly called stderr; missing tenant context threw instead of taking fallback. Both pass after seeder fixes.
- POS denial/parity and string render tests were green when added; they are regression coverage, not claimed production-red proofs.
- Full flag-off payload fixtures explicitly preserve existing endpoint differences: product-stock omits product metadata, and create does not hydrate database-default booleans before serialization. Those pre-existing differences were not swept into this lane. A created batch's loaded empty stock yields genuine zero strings.

## Frontend consumer census

| Consumer | Disposition |
| --- | --- |
| features/batches/types.ts Batch totals | Changed number to string. |
| ExpiredBatch / ExpiredBatchStock plus comments | Changed to four-decimal string contract; removed false float claims. |
| BatchListPage | Existing formatQuantity accepts strings; new 3.1234 render proof. Currency-vs-unit precision deferred in ticket. |
| ExpiryWriteOffPage | Existing decimal helpers accept strings, including quantity selection/max/guard. New rendered and submission proof retains 3.1234. |
| BatchDetailPage stock levels | Different stock endpoint already declares strings; pre-existing parseFloat deferred in ticket. |
| CreateStockTransferPage | Existing String + bccomp is compatible; per-location availability remains a string. No change required. |
| apps/pos/src | No consumer of these batch-level fields. |
| parts-catalog/types/catalog.ts, catalog/api/compositeItemApi.ts, opening-balances/types/index.ts | Different DTOs/endpoints sharing field names; unchanged. |
| tenantScope hook test fixture | Numeric batch totals replaced with strings so the DTO check is truthful. |

## Release notes and boundaries

`docs/handoff/RELEASE-NOTES-WLOTA-1a-2026-09-10.md` records both adopted flag-independent Push-3 exceptions and the POS UUID-validation change. Two entries were also added to the separate parent repository's `/Users/houssamr/Projects/syneriva/docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md`; that parent-file edit is outside the ERP worktree/commit ledger and remains available for parent-repository review.

A-1b: add staged create/update BatchActionAccess allow-list/routes as ticketed. Existing CreateBatchRequest/UpdateBatchRequest authorize methods already check batches.create/batches.update; the broad review claim that the API enforces neither is inaccurate. This lane adds no middleware on create/update/transfer/write-off. Recall-request eligibility, holds/release/reject behavior and related fiscal/stock writers remain deferred. Persisted auth users without a permissions list fail closed until /auth/me refreshes, the accepted risk remains.

## Commit groups and source-push ledger

Original provenance: Push 2 `aeb594f21`, `66c7068ef`; Push 3 `892b30110`, `ed1ef81fe`; Push 5 `2a6ba01ea`, `532fe5627`. Apply the original implementation before follow-up patches. The earlier review-metadata commits were `3046ca872` and `04e60530c`.

Round-1 commits are grouped by review concern; the ledger below assigns files to the deployment push. The minors commit contains both backend and the web fingerprint comment: assemble by the per-file ledger, never auto-deploy the entire branch. Pushes 1 and 4 remain operations-only. Push 4 acceptance precedes every Push 5 source promotion. The new manifest and CI files are explicitly Push 3.

- `7b870f253 Phase 1.1.1: Validate backward trace inputs and preserve nullable references`
- `1cdf05257 Phase 1.1.2: Declare string permissions in the role DTO`
- `61fd45cf3 Phase 1.1.3: Align batch and role consumers with string contracts`
- `a491b5415 Phase 1.1.4: Scope batch response stock and pin flag-off payloads`
- `a50230cb2 Phase 1.1.5: Preserve role card layout and label provisioned roles`
- `08b5c21cf Phase 1.1.6: Make batch browser gates reproducible and opt-in`
- `7c50e28bf Phase 1.1.7: Close seeder and module-boundary review minors`
- `f2d79c9a8 Phase 1.1.8: Guard PostgreSQL races and arm the approved CI classes`
- `850f7a295 Phase 1.1.9: Regenerate the batch permission route manifest`

| Push | File | Latest source commit |
| --- | --- | --- |
| Push 3 | `.github/workflows/ci.yml` | `f2d79c9a8` |
| Push 3 | `apps/api/.env.example` | `7c50e28bf` |
| Push 3 | `apps/api/app/Console/Commands/ApplyLotActionPermissionDelta.php` | `892b30110` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Application/Services/LotActionPermissionActivation.php` | `892b30110` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/BatchExpiryServiceProvider.php` | `892b30110` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Domain/Repositories/BatchRepositoryInterface.php` | `892b30110` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php` | `892b30110` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php` | `7c50e28bf` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php` | `a491b5415` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php` | `7b870f253` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Presentation/Middleware/BatchActionAccess.php` | `892b30110` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php` | `a491b5415` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Presentation/routes.php` | `892b30110` |
| Push 3 | `apps/api/app/Modules/Document/Infrastructure/BatchTraceability/DocumentBatchTraceReaderAdapter.php` | `7b870f253` |
| Push 3 | `apps/api/app/Modules/Document/Providers/DocumentServiceProvider.php` | `892b30110` |
| Push 3 | `apps/api/app/Modules/Identity/Application/DTOs/LotActionPermissionDeltaResult.php` | `892b30110` |
| Push 3 | `apps/api/app/Modules/Identity/Application/DTOs/RoleData.php` | `1cdf05257` |
| Push 3 | `apps/api/app/Modules/Identity/Application/Services/GeneralManagerAssignmentGuard.php` | `892b30110` |
| Push 3 | `apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php` | `ed1ef81fe` |
| Push 3 | `apps/api/app/Modules/Identity/Domain/Enums/LotActionPermissionDeltaOutcome.php` | `892b30110` |
| Push 3 | `apps/api/app/Modules/Identity/Domain/Enums/RoleProvisioningSource.php` | `892b30110` |
| Push 3 | `apps/api/app/Modules/Identity/Domain/Enums/SystemRoleName.php` | `892b30110` |
| Push 3 | `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php` | `892b30110` |
| Push 3 | `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php` | `892b30110` |
| Push 3 | `apps/api/app/Modules/POS/Infrastructure/BatchTraceability/PosBatchTraceReaderAdapter.php` | `892b30110` |
| Push 3 | `apps/api/app/Modules/POS/Providers/POSServiceProvider.php` | `892b30110` |
| Push 3 | `apps/api/app/Shared/Contracts/BatchTraceability/BackwardDocumentBatchTraceData.php` | `7b870f253` |
| Push 3 | `apps/api/app/Shared/Contracts/BatchTraceability/DocumentBatchTraceReader.php` | `892b30110` |
| Push 3 | `apps/api/app/Shared/Contracts/BatchTraceability/ForwardDocumentBatchTraceData.php` | `7b870f253` |
| Push 3 | `apps/api/app/Shared/Contracts/BatchTraceability/ForwardPosBatchTraceData.php` | `892b30110` |
| Push 3 | `apps/api/app/Shared/Contracts/BatchTraceability/PosBatchTraceReader.php` | `892b30110` |
| Push 3 | `apps/api/config/lot_action_permissions.php` | `892b30110` |
| Push 2 | `apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php` | `aeb594f21` |
| Push 3 | `apps/api/database/seeders/RolesAndPermissionsSeeder.php` | `7c50e28bf` |
| Push 3 | `apps/api/tests/Architecture/BatchTraceabilityModuleBoundaryTest.php` | `7c50e28bf` |
| Push 3 | `apps/api/tests/Feature/BatchExpiry/BatchActionPermissionsTest.php` | `a491b5415` |
| Push 3 | `apps/api/tests/Feature/BatchExpiry/BatchExpiringLocationScopeTest.php` | `892b30110` |
| Push 3 | `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php` | `a491b5415` |
| Push 3 | `apps/api/tests/Feature/BatchExpiry/BatchTraceReaderContractTest.php` | `7b870f253` |
| Push 3 | `apps/api/tests/Feature/Console/ApplyLotActionPermissionDeltaCommandTest.php` | `ed1ef81fe` |
| Push 3 | `apps/api/tests/Feature/Console/ExportFrontendPermissionsMapCommandTest.php` | `892b30110` |
| Push 3 | `apps/api/tests/Feature/Console/LotActionReseedMarkerTest.php` | `7c50e28bf` |
| Push 3 | `apps/api/tests/Feature/Identity/GeneralManagerAssignmentTest.php` | `ed1ef81fe` |
| Push 3 | `apps/api/tests/Feature/Identity/GeneralManagerAssignmentWriterCensusTest.php` | `ed1ef81fe` |
| Push 3 | `apps/api/tests/Feature/Identity/GeneralManagerRoleProtectionTest.php` | `ed1ef81fe` |
| Push 3 | `apps/api/tests/Feature/Identity/LotActionPermissionDeltaTest.php` | `f2d79c9a8` |
| Push 3 | `apps/api/tests/Feature/Identity/LotActionSeededRoleMatrixTest.php` | `ed1ef81fe` |
| Push 3 | `apps/api/tests/Feature/Identity/RoleIndexResponseContractTest.php` | `ed1ef81fe` |
| Push 3 | `apps/api/tests/Feature/Identity/RolesAndPermissionsSeederMarkedTenantTest.php` | `892b30110` |
| Push 2 | `apps/api/tests/Feature/Migrations/RoleProvisioningSourceSchemaTest.php` | `66c7068ef` |
| Push 3 | `apps/api/tests/feature-lane-manifest.json` | `f2d79c9a8` |
| Push 5 | `apps/web/e2e/batch-permissions.spec.ts` | `08b5c21cf` |
| Push 5 | `apps/web/playwright.config.ts` | `08b5c21cf` |
| Push 5 | `apps/web/src/components/organisms/Sidebar/Sidebar.tsx` | `2a6ba01ea` |
| Push 5 | `apps/web/src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx` | `2a6ba01ea` |
| Push 5 | `apps/web/src/features/batches/hooks/__tests__/tenantScope.test.tsx` | `61fd45cf3` |
| Push 5 | `apps/web/src/features/batches/pages/BatchDetailPage.tsx` | `2a6ba01ea` |
| Push 5 | `apps/web/src/features/batches/pages/BatchListPage.tsx` | `2a6ba01ea` |
| Push 5 | `apps/web/src/features/batches/pages/ExpiryWriteOffPage.test.tsx` | `61fd45cf3` |
| Push 5 | `apps/web/src/features/batches/pages/ExpiryWriteOffPage.tsx` | `61fd45cf3` |
| Push 5 | `apps/web/src/features/batches/pages/__tests__/BatchPermissions.test.tsx` | `61fd45cf3` |
| Push 5 | `apps/web/src/features/batches/pages/__tests__/BatchSeededPermissionMap.test.ts` | `2a6ba01ea` |
| Push 5 | `apps/web/src/features/batches/types.ts` | `61fd45cf3` |
| Push 5 | `apps/web/src/features/settings/RolesPage.test.tsx` | `a50230cb2` |
| Push 5 | `apps/web/src/features/settings/RolesPage.tsx` | `a50230cb2` |
| Push 5 | `apps/web/src/hooks/__tests__/usePermissions.moduleAccess.test.tsx` | `2a6ba01ea` |
| Push 5 | `apps/web/src/hooks/permissionsMap.generated.ts` | `2a6ba01ea` |
| Push 5 | `apps/web/src/hooks/usePermissions.ts` | `2a6ba01ea` |
| Push 5 | `apps/web/src/locales/ar/common.json` | `a50230cb2` |
| Push 5 | `apps/web/src/locales/en/common.json` | `a50230cb2` |
| Push 5 | `apps/web/src/locales/fr/common.json` | `a50230cb2` |
| Push 5 | `apps/web/src/routes/__tests__/BatchRoutePermissions.test.tsx` | `2a6ba01ea` |
| Push 5 | `apps/web/src/routes/index.tsx` | `7c50e28bf` |
| Push 5 | `packages/shared/types/generated.d.ts` | `61fd45cf3` |
| Push 5 | `scripts/factory/manifests/routes-web.yaml` | `850f7a295` |

Generated artifacts:

- `packages/shared/types/generated.d.ts` SHA-256 `f6010d61cdec02da5ed0bfed1940abc074d7e4800c56d18e3f902766d7000dce`.
- `apps/web/src/hooks/permissionsMap.generated.ts` SHA-256 `2017732065366f5b75491dcde572f56cd31be8ad4fb32c4e1c990214e1286dff`.
