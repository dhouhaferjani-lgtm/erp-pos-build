---
status: review
promotion_ready: false
blocking_decision: none
promotion_blocker: orchestrator review and staged acceptance
---

# W-LOT-A-1a handback — fix round 3, 2026-09-11

Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w-lot-a-1a`. Branch: `lane/w-lot-a-1a`.
Original dispatch: `4373ba2f60ac6c303e90e4ae47236285f05e648c`; round-1 base: `04e60530c`; round-1 verified source HEAD: `850f7a295bbf53b5ff9ca751d3047ee3134380df`. Historical implementation/ruling evidence remains in the handback at `04e60530c`.

Authority: `CODEX-PROMPT-WLOTA-1a-fix-round-2-2026-09-10.md`, plan rev 11 §00 Round-10 addendum, and all three gate-r2 registers read in full from the main checkout. Round-1 history below remains attributed to its own source HEAD. The subsequent `CODEX-PROMPT-WLOTA-1a-fix-round-2b-2026-09-10.md` ruling closes the scope decision: the transfer writer failure is a disclosed pre-existing residual owned by the inventory movement seam after T-2 S1, not a lane blocker. Historical round-2 results below remain attributed to their tested source; the Fix round 2b block supersedes their blocker disposition. Round 3 follows plan rev 12 §000 and the gate-r3 tenancy register; its table below records only the new requested fixes. Reviewer verdicts belong to the orchestrator's registers.

No merge, push, deployment, activation or fleet operation performed. Legacy NULL-team role identities remain unchanged; technician receives no batches.view. Push 4 delta acceptance must precede Push 5 web; never combine them in an automatic deployment. Post-activation browser execution remains orchestrator-owned and was not run against an activated tenant.

## Fix round 1 (historical; source citations below updated where round 2 moved them)

| Finding | Resolution | Current source citation |
| --- | --- | --- |
| Tenancy B-1 | Shared race harness skips non-PG drivers; private DB-name assertion removed. Both callers pass on PG and skip only their race on SQLite. | `apps/api/tests/Feature/Identity/LotActionPermissionDeltaTest.php:32` |
| Tenancy B-2 / M-1; inventory I-1 / I-2 / I-3 | Option B retained. All five mutation/create resource paths load scoped stock; unloaded resource throws. Real flag-off HTTP tests assert full payloads and work without batches.view. | `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:130`; `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:24`; `apps/api/tests/Feature/BatchExpiry/BatchActionPermissionsTest.php:43` |
| Tenancy M-2 | Technician remains ungranted, with the owner ruling named beside grants. | `apps/api/database/seeders/RolesAndPermissionsSeeder.php:103` |
| Tenancy M-3 / frontend B-4 | PHP transformer attribute emits Array<string>; generated file regenerated. The RolesPage unsafe spread warning is gone. | `apps/api/app/Modules/Identity/Application/DTOs/RoleData.php:20`; `packages/shared/types/generated.d.ts:1012` |
| Tenancy M-4 | Malformed partner UUID returns 404; malformed product/date filters return 422 using the existing API validation envelope. | `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:106`; `apps/api/tests/Feature/BatchExpiry/BatchTraceReaderContractTest.php:87` |
| Tenancy M-5 / inventory I-5 | Document number is nullable in both DTOs; backward product ID is nullable; adapter casts removed. Forward DTO has no productId field, so no new wire field was invented. Draft/null HTTP coverage passes. | `apps/api/app/Shared/Contracts/BatchTraceability/BackwardDocumentBatchTraceData.php:16`; `apps/api/app/Shared/Contracts/BatchTraceability/ForwardDocumentBatchTraceData.php:11`; `apps/api/tests/Feature/BatchExpiry/BatchTraceReaderContractTest.php:97` |
| Inventory I-4 | Restricted POS foreign-location denial preserves seven tables; unrestricted POS JSON is identical across activation; backward trace discriminates two companies sharing a partner fixture. | `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php:280`; `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php:292`; `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php:393` |
| Frontend B-1 | Committed env-driven harness, isolated port, CI Node 20; exact requested command passes at committed source line 39:3. | `apps/web/playwright.config.ts:4`; `apps/web/e2e/batch-permissions.spec.ts:39` |
| Frontend B-2 | Post-activation suite skips without the explicit opt-in, before any fixture side effects. | `apps/web/e2e/batch-permissions.spec.ts:82` |
| Frontend B-3 | Browser denial loop uses the real /inventory/batches/new route. | `apps/web/e2e/batch-permissions.spec.ts:51` |
| Frontend B-5 | p-4 / rounded-lg / existing hover treatment retained; only base background token used, no composite card elevation. | `apps/web/src/features/settings/RolesPage.tsx:255` |
| Frontend B-6 | Batch totals and expired stock availability are strings; false float documentation removed. List and expiry write-off render 3.1234 strings; expiry submission preserves 3.1234. | `apps/web/src/features/batches/types.ts:38`; `apps/web/src/features/batches/pages/__tests__/BatchListPermissions.test.tsx:30`; `apps/web/src/features/batches/pages/ExpiryWriteOffPage.test.tsx:160` |
| Frontend B-7 | Deferred staged-middleware alignment ticket, with factual qualification: create/update already enforce permission via FormRequests. No new Task-1 middleware added. | `docs/superpowers/tickets/2026-09-10-batch-create-update-api-enforcement.md:1` |
| Tenancy N-1 | Flag-independent POS UUID-validation behavior documented in release notes. | `docs/handoff/RELEASE-NOTES-WLOTA-1a-2026-09-10.md:10` |
| Tenancy N-2 | Example environment declares activation false. | `apps/api/.env.example:193` |
| Tenancy N-3 / N-4 | Stderr marker limited to marked/activated paths. Missing tenant/team context takes legacy fallback with a logged reason. Real seeder tests cover all paths. | `apps/api/database/seeders/RolesAndPermissionsSeeder.php:48`; `apps/api/database/seeders/RolesAndPermissionsSeeder.php:53`; `apps/api/tests/Feature/Console/LotActionReseedMarkerTest.php:135` |
| Tenancy N-5 / frontend fixture minor | Fixture has an ID and satisfies Batch; no undefined-key warning. | `apps/web/src/features/batches/pages/__tests__/BatchListPermissions.test.tsx:12` |
| Inventory I-6 | Positive-stock-only picker behavior documented and pinned against a depleted but historically visible lot. | `apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:109`; `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php:339` |
| Inventory I-7 | Boundary ratchet scans every BatchExpiry presentation controller. | `apps/api/tests/Architecture/BatchTraceabilityModuleBoundaryTest.php:13` |
| Inventory I-8 / frontend precision minor | Detail parseFloat and expiry-page deprecated formatting remain deferred; list unit precision is fixed in round 2. | `docs/superpowers/tickets/2026-09-10-batch-detail-parsefloat-quantities.md:1` |
| Inventory I-9 | Stock lookup resolves the read locations once and passes the result to both readers; null remains unrestricted. | `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:371`; `:372`; `:377` |
| Frontend provisioned-copy minor | Marker-derived badge uses roles.provisionedReadOnly in en/fr/ar; legacy system badge retained. | `apps/web/src/features/settings/RolesPage.tsx:276`; `apps/web/src/locales/en/common.json:1096`; `apps/web/src/locales/fr/common.json:1113`; `apps/web/src/locales/ar/common.json:1079` |
| Frontend modal-state minor | Title and comment explicitly identify tampering with the selected object, not a simulated refetch. | `apps/web/src/features/settings/RolesPage.test.tsx:117` |
| Frontend fingerprint minor | Comment explains why the route handle retains the literal in the served bundle. | `apps/web/src/routes/index.tsx:1205` |
| Generated route-manifest follow-up | Preflight detected stale batch permission metadata; regenerated the four rows from source, without changing the generator or routes. Ships in Push 5. | `scripts/factory/manifests/routes-web.yaml:297` |
| Manifest / CI raise | Approved ceilings 21/18/39/15 and total 1264, with truthful parked-lane notes and twelve exact PG selections. Checker exits 0. | `apps/api/tests/feature-lane-manifest.json:9`; `.github/workflows/ci.yml:1133` |

## Fix round 2 (historical, before the round-2b ruling)

Round-2 base: `2fa724c1d83098e12d05024e5e51b083d5566ed0`; tested source HEAD: `e66ab582392ace4a5d95089a6249809b1a0ce0ab`.

The three original r2 blockers have implementation fixes. MAJOR 1 remains **partially blocked**: its real transfer success case reaches a pre-existing PostgreSQL failure before response serialization. The failing test is retained without a mock, schema relaxation or skip; this handback does not claim acceptance or full green verification. Scope expansion was requested; no stock-writer repair has been applied.

| Finding | Resolution / remaining issue | Current source citation |
| --- | --- | --- |
| Tenancy B-1 / inventory B-1 / tenancy M-2 | All five mutations capture membership scope once before writing; explicit location targets are authorized before mutation. Response loading accepts only the captured scope and cannot perform authorization. Restricted PATCH/write-off denial and retries preserve nine tables (original seven plus stock levels/movements); unrestricted totals remain company-wide. | `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:111`; `:130`; `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php:156`; `:177` |
| Tenancy B-2 | Inject activation service; require active current-company membership only with enforcement on. Nullable membership is safe while flag off. Real HTTP succeeds off and returns the specified 422 on. | `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:68`; `:378`; `apps/api/tests/Feature/Identity/GeneralManagerAssignmentTest.php:18` |
| Frontend B-1 | Move types, BatchListPage and its Vitest into Push 3 as the web string-tolerance slice; other web gates stay Push 5. Correct the pre-lane consumer census and release-window warning. Each source file occurs exactly once in the ledger. | Source-push ledger below; `docs/handoff/RELEASE-NOTES-WLOTA-1a-2026-09-10.md:16` |
| Tenancy M-1 / inventory M-1 | Real recall/write-off response tests and flag-off expiry-query/create-stock contracts added to the already-allowlisted class. **Transfer remains blocked:** new real HTTP test expects scoped success but returns 500 because the legacy writer omits required movement_id. | `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php:200`; `:213`; `:235`; `:274`; `docs/superpowers/tickets/2026-09-10-batch-transfer-missing-movement-reference.md:1` |
| Frontend M-1 / inventory I-1, I-2 / frontend F-2, F-5 | Product unit eager-loaded for list/detail/mutations; required quantity_decimals declared; list formats with getQuantityDecimals and no numeric fallback. UI test proves unit 4 against currency 3; PHP exact-JSON fixture uses unit 2. | `apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:34`; `:153`; `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:132`; `apps/web/src/features/batches/pages/BatchListPage.tsx:218`; `apps/web/src/features/batches/types.ts:48`; `apps/web/src/features/batches/pages/__tests__/BatchListPermissions.test.tsx:30`; `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php:94` |
| Tenancy M-3 | Declare create's explicit batch_stock: [] replacing the omitted key, flag-independently. Extend the existing parent batch-totals entry; parent edit stays uncommitted for the orchestrator's path-scoped merge commit. | `docs/handoff/RELEASE-NOTES-WLOTA-1a-2026-09-10.md:7`; `/Users/houssamr/Projects/syneriva/docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md:14` |
| Tenancy N-1 | Marker citations now identify both actual emitter call sites rather than missing-context fallback. | `apps/api/database/seeders/RolesAndPermissionsSeeder.php:48`; `:53` |
| Tenancy N-2 | Fresh PG/SQLite counts recorded in round-2 verification below. Race assertion totals vary with fixture/process execution and are not a stable fingerprint. | Local `docs/sessions/wlota1a/r2-pg-*.txt` and `r2-sqlite-*.txt` |
| Tenancy N-3 | Remove redundant whenLoaded guard; direct batchStock map follows the mandatory loaded-relation invariant. | `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:24`; `:60` |
| Tenancy N-4 / frontend F-1 | Batch.variant_id matches the wire; all four Batch.batch_stock members are strings; ExpiredBatchStock location is a UUID string; fixtures corrected. | `apps/web/src/features/batches/types.ts:18`; `:51`; `:276`; `apps/web/src/features/batches/pages/ExpiryWriteOffPage.test.tsx:104`; `:119`; `:163`; `apps/web/src/features/batches/hooks/__tests__/tenantScope.test.tsx:143` |
| Tenancy N-5 | Marker emitter tolerates a seeder invoked without a console command; a local Command|null annotation corrects Laravel’s non-null PHPDoc without suppressing PHPStan. | `apps/api/database/seeders/RolesAndPermissionsSeeder.php:84`; `:86` |
| Inventory I-3 | Cite the actual scope hoist and both consumers, plus the depleted picker assertion. | `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:371`; `:372`; `:377`; `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php:339` |
| Inventory I-4 | Record float accessor/helper debt; entity remains unchanged. | `docs/superpowers/tickets/2026-09-10-batch-stock-dead-float-accessors.md:1` |
| Frontend F-3 | Display ticket retains detail parseFloat, adds all three deprecated expiry-page formatters and the silent unit-default trap; list is removed from deferred scope. | `docs/superpowers/tickets/2026-09-10-batch-detail-parsefloat-quantities.md:6` |
| Frontend F-4 | Fresh Node 20.19.4 pre-activation browser evidence carries source SHA, exact command and node version as its first three lines. | Local `docs/sessions/wlota1a/r2-browser-pre-node20.txt`; exact header and result below |
| Frontend F-6 | Only literal WLOTA1A_POST_ACTIVATION=1 opts into post-activation execution; absent or 0 remains skipped before side effects. | `apps/web/e2e/batch-permissions.spec.ts:82` |

Retry semantics: per-lot write-off has no idempotency key. One successful call creates one stock movement; two deliberately repeated successes are two new writes (17 → 16 → 15 total, with company-wide available totals 16 → 15 → 14). Denied retries create no movements. These fixtures prove real aggregate/lot stock writes; GL tables are included in denial snapshots, but successful journal posting is not claimed for fixtures without configured GL accounts.

Actual red evidence: `r2-red-batch.txt` shows denied PATCH notes persisted and unrestricted write-off returned 9 instead of 16; `r2-red-role.txt` shows flag-off assignment returned 422; `r2-red-unit.txt` shows unit 2 emitted as 4; `r2-red-list.txt` shows 3.123 rendered instead of 3.1234. Added recall/write-off and flag-off regression cases are not claimed as new production-red proofs.

## Verification — round 2 (historical)

49 PostgreSQL tests were run across six files, one file per invocation, sequentially, with both DB_DATABASE and DB_CENTRAL_DATABASE pinned to `autoerp_test_w` on port 5433. **48 pass; the new real transfer test fails.** No full PHPUnit or Vitest suite was run. No additional test class or manifest ceiling was introduced.

| PostgreSQL file | Fresh result | Evidence under local `docs/sessions/wlota1a/` |
| --- | --- | --- |
| `BatchActionPermissionsTest.php` | 6 tests / 34 assertions, pass | `r2-pg-BatchActionPermissionsTest.txt` |
| `BatchReadLocationScopeTest.php` | 15 tests / 106 assertions, **1 failure** (transfer 500, required movement_id missing); other 14 pass | `r2-pg-BatchReadLocationScopeTest.txt` |
| `LotActionPermissionDeltaTest.php` | 12 tests / 132 assertions, pass | `r2-pg-LotActionPermissionDeltaTest.txt` |
| `GeneralManagerAssignmentTest.php` | 8 tests / 118 assertions, pass | `r2-pg-GeneralManagerAssignmentTest.txt` |
| `LotActionReseedMarkerTest.php` | 6 tests / 24 assertions, pass | `r2-pg-LotActionReseedMarkerTest.txt` |
| `RolesAndPermissionsSeederMarkedTenantTest.php` | 2 tests / 4 assertions, pass | `r2-pg-RolesAndPermissionsSeederMarkedTenantTest.txt` |

SQLite `phpunit.xml`: Delta 12 tests / 45 assertions / 1 PG race skipped; GeneralManagerAssignment 8 tests / 26 assertions / 1 PG race skipped. Both exit 0 (18 executable tests pass, two races skip). PG race assertion counts vary between runs and are recorded as observations, not a stable source fingerprint.

Eight separate Vitest invocations: BatchRoutePermissions 7, BatchPermissions 9, BatchSeededPermissionMap 1, RolesPage 3, Sidebar 56, usePermissions.moduleAccess 12, ExpiryWriteOffPage 8, tenantScope 3 = **99 passed**. Main/e2e typechecks pass. Audits: keys 0 new / 0 stale; design system 802 acknowledged / 0 new / 0 stale; quantity 0 total / 0 new / 0 stale. No baseline changed. Route-manifest generator check and feature-lane-manifest checker both exit 0; existing parked-lane warnings remain. The 75-file source ledger has no duplicate, missing or extra row (`r2-ledger-check.json`).

React Doctor: same-tool explicit-file baseline 92/100 (two diagnostics), current list 93/100 (one pre-existing complexity warning). Changed-scope scan also 93/100. No score regression or suppression.

Final scoped `./scripts/preflight.sh`: **exit 0** (`r2-preflight.txt`). Pint --test and PHPStan level 8 pass on the touched PHP paths (Pint includes tests; PHPStan checks runtime/seeder files). Full ESLint reports 0 errors / 6413 warnings repository-wide. Generated DTO and permission-map drift checks, detector/manifest checks and the fixed fiscal-parity checks pass. The first attempt caught list-return typing and Laravel's inaccurate non-null console-command PHPDoc; both were fixed without suppressions or baseline changes. The exact environment and scopes are retained in `r2-preflight.sh`.

Preflight retains its explicit BatchActionPermissionsTest and BatchPermissions Vitest scopes; its green result **does not override** the separately run, failing BatchReadLocationScopeTest, which is selected by the CI allowlist. At that historical checkpoint the round remained blocked on real transfer success; round 2b below supersedes that disposition.

Committed browser evidence, Node 20.19.4: **1 pre-activation test passed (11.9s), source line 39:3**. The first three lines of `r2-browser-pre-node20.txt` are:

```text
e66ab582392ace4a5d95089a6249809b1a0ce0ab
PLAYWRIGHT_PORT=5198 PLAYWRIGHT_HTML_OPEN=never pnpm exec playwright test e2e/batch-permissions.spec.ts --project=chromium --grep 'pre-activation web gating'
v20.19.4
```

The command runs from this worktree's `apps/web` with `/Users/houssamr/.nvm/versions/node/v20.19.4/bin` first on PATH. The committed env-driven config starts an isolated Vite server. The protected-role screenshot was inspected and retained at `docs/sessions/wlota1a/r2-protected-role.png`. A separate `WLOTA1A_POST_ACTIVATION=0` run reports **1 skipped**, source line 83:3 (`r2-browser-post-zero-skipped.txt`); no activated tenant was used. Logs are local and gitignored; no remote CI or reviewer acceptance is claimed.

## Verification — round 1 historical

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

Round-1 verified source HEAD is `850f7a295bbf53b5ff9ca751d3047ee3134380df`. `apps/web/e2e/batch-permissions.spec.ts` and `apps/web/playwright.config.ts` have no working-copy changes. Using CI's Node 20.19.4 and the committed config:

```bash
export PATH="$HOME/.nvm/versions/node/v20.19.4/bin:$PATH"
export PLAYWRIGHT_PORT=5198
export PLAYWRIGHT_HTML_OPEN=never
pnpm exec playwright test e2e/batch-permissions.spec.ts --project=chromium --grep 'pre-activation web gating'
```

Run from this worktree's `apps/web`. Exact result in `docs/sessions/wlota1a/r1-browser-pre-node20.txt`: **`[chromium] › e2e/batch-permissions.spec.ts:39:3`**, **`1 passed`**. Explicit port uses an isolated Vite instance with strictPort and reuseExistingServer=false. No ignored custom config is used.

The earlier source-line discrepancy is reproducible without any source edit: Node 25.2.1 reports compiled line 88, including with a fresh Playwright cache; Node 20.19.4 reports original line 39. The cold-cache source-map sourcesContent is byte-identical to the committed spec, and line 88 is the test declaration in the generated JavaScript. This supports a local runtime/source-map reporting issue; a mismatched reported line alone did not prove an uncommitted spec was executed. The retained round-1 evidence uses the correct original-source location.

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
| BatchListPage | Pre-lane `.toFixed()` crashes on Push-3 string totals. Ship the list, types and its Vitest together in Push 3 as the web string-tolerance slice; permission-gating web files remain Push 5. Product-unit precision is fixed in round 2. |
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

Round-1 commits are grouped by review concern; the ledger below assigns files to the deployment push. The minors commit contains both backend and the web fingerprint comment: assemble by the per-file ledger, never auto-deploy the entire branch. Pushes 1 and 4 remain operations-only. Push 4 acceptance precedes every Push 5 source promotion. The new manifest and CI files are explicitly Push 3. Push 3 also includes the web string-tolerance slice (BatchListPage, batches/types and BatchListPermissions Vitest), which must be live when the API begins emitting strings. Its formatter accepts both prior numbers and new strings; the existing batches.create grants make its create-button gate safe before activation.

- `7b870f253 Phase 1.1.1: Validate backward trace inputs and preserve nullable references`
- `1cdf05257 Phase 1.1.2: Declare string permissions in the role DTO`
- `61fd45cf3 Phase 1.1.3: Align batch and role consumers with string contracts`
- `a491b5415 Phase 1.1.4: Scope batch response stock and pin flag-off payloads`
- `a50230cb2 Phase 1.1.5: Preserve role card layout and label provisioned roles`
- `08b5c21cf Phase 1.1.6: Make batch browser gates reproducible and opt-in`
- `7c50e28bf Phase 1.1.7: Close seeder and module-boundary review minors`
- `f2d79c9a8 Phase 1.1.8: Guard PostgreSQL races and arm the approved CI classes`
- `850f7a295 Phase 1.1.9: Regenerate the batch permission route manifest`

Round-2 source/contract commits:

- `b1ed81388 Phase 1.2.3: Ship batch string tolerance with the API contract`
- `a48274456 Phase 1.2.6: Declare the empty batch stock create response`
- `8028324e0 Phase 1.2.1: Authorize mutation targets before writing batch stock`
- `8a7777816 Phase 1.2.2: Preserve role assignment before permission activation`
- `3ad468c30 Phase 1.2.5: Display batch quantities at product unit precision`
- `1aee614c5 Phase 1.2.7: Align batch wire types and tighten evidence opt-in`
- `e66ab5823 Phase 1.2.4: Pin mutation responses and expose the legacy transfer failure`

| Push | File | Latest source commit |
| --- | --- | --- |
| Push 3 | `.github/workflows/ci.yml` | `f2d79c9a8` |
| Push 3 | `apps/api/.env.example` | `7c50e28bf` |
| Push 3 | `apps/api/app/Console/Commands/ApplyLotActionPermissionDelta.php` | `892b30110` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Application/Services/LotActionPermissionActivation.php` | `892b30110` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/BatchExpiryServiceProvider.php` | `892b30110` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Domain/Repositories/BatchRepositoryInterface.php` | `892b30110` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php` | `892b30110` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php` | `3ad468c30` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php` | `068734a64` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php` | `7b870f253` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Presentation/Middleware/BatchActionAccess.php` | `892b30110` |
| Push 3 | `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php` | `1aee614c5` |
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
| Push 3 | `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php` | `8a7777816` |
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
| Push 3 | `apps/api/database/seeders/RolesAndPermissionsSeeder.php` | `1aee614c5` |
| Push 3 | `apps/api/tests/Architecture/BatchTraceabilityModuleBoundaryTest.php` | `7c50e28bf` |
| Push 3 | `apps/api/tests/Feature/BatchExpiry/BatchActionPermissionsTest.php` | `3ad468c30` |
| Push 3 | `apps/api/tests/Feature/BatchExpiry/BatchExpiringLocationScopeTest.php` | `068734a64` |
| Push 3 | `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php` | `068734a64` |
| Push 3 | `apps/api/tests/Feature/BatchExpiry/BatchTraceReaderContractTest.php` | `7b870f253` |
| Push 3 | `apps/api/tests/Feature/Console/ApplyLotActionPermissionDeltaCommandTest.php` | `ed1ef81fe` |
| Push 3 | `apps/api/tests/Feature/Console/ExportFrontendPermissionsMapCommandTest.php` | `892b30110` |
| Push 3 | `apps/api/tests/Feature/Console/LotActionReseedMarkerTest.php` | `7c50e28bf` |
| Push 3 | `apps/api/tests/Feature/Identity/GeneralManagerAssignmentTest.php` | `8a7777816` |
| Push 3 | `apps/api/tests/Feature/Identity/GeneralManagerAssignmentWriterCensusTest.php` | `ed1ef81fe` |
| Push 3 | `apps/api/tests/Feature/Identity/GeneralManagerRoleProtectionTest.php` | `ed1ef81fe` |
| Push 3 | `apps/api/tests/Feature/Identity/LotActionPermissionDeltaTest.php` | `f2d79c9a8` |
| Push 3 | `apps/api/tests/Feature/Identity/LotActionSeededRoleMatrixTest.php` | `ed1ef81fe` |
| Push 3 | `apps/api/tests/Feature/Identity/RoleIndexResponseContractTest.php` | `ed1ef81fe` |
| Push 3 | `apps/api/tests/Feature/Identity/RolesAndPermissionsSeederMarkedTenantTest.php` | `892b30110` |
| Push 2 | `apps/api/tests/Feature/Migrations/RoleProvisioningSourceSchemaTest.php` | `66c7068ef` |
| Push 3 | `apps/api/tests/feature-lane-manifest.json` | `f2d79c9a8` |
| Push 5 | `apps/web/e2e/batch-permissions.spec.ts` | `1aee614c5` |
| Push 5 | `apps/web/playwright.config.ts` | `08b5c21cf` |
| Push 5 | `apps/web/src/components/organisms/Sidebar/Sidebar.tsx` | `2a6ba01ea` |
| Push 5 | `apps/web/src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx` | `2a6ba01ea` |
| Push 5 | `apps/web/src/features/batches/hooks/__tests__/tenantScope.test.tsx` | `1aee614c5` |
| Push 5 | `apps/web/src/features/batches/pages/BatchDetailPage.tsx` | `2a6ba01ea` |
| Push 3 | `apps/web/src/features/batches/pages/BatchListPage.tsx` | `3ad468c30` |
| Push 5 | `apps/web/src/features/batches/pages/ExpiryWriteOffPage.test.tsx` | `1aee614c5` |
| Push 5 | `apps/web/src/features/batches/pages/ExpiryWriteOffPage.tsx` | `61fd45cf3` |
| Push 3 | `apps/web/src/features/batches/pages/__tests__/BatchListPermissions.test.tsx` | `068734a64` |
| Push 5 | `apps/web/src/features/batches/pages/__tests__/BatchDetailPermissions.test.tsx` | `068734a64` |
| Push 5 | `apps/web/src/features/batches/pages/__tests__/BatchSeededPermissionMap.test.ts` | `2a6ba01ea` |
| Push 3 | `apps/web/src/features/batches/types.ts` | `1aee614c5` |
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


## Fix round 2b

Authority: orchestrator ruling `CODEX-PROMPT-WLOTA-1a-fix-round-2b-2026-09-10.md` (2026-09-10; owner may overturn). Base `5d847b2ba`. Status remains **review**, `blocking_decision: none`; promotion awaits orchestrator review and staged acceptance only. The pre-existing transfer defect is a disclosed ticketed residual, not a lane blocker. No writer, aggregate service, migration, activation, merge or push change is included.

| Item | Resolution / verification |
| --- | --- |
| Scope ruling | Transfer repair belongs to the inventory movement seam after T-2 S1 merges, avoiding its receive/issue transfer-linkage changes. See `docs/superpowers/tickets/2026-09-10-batch-transfer-missing-movement-reference.md`. |
| Known-red success contract | Existing real HTTP success test is unchanged apart from the exact ticketed skip unless `WLOTA1A_RUN_KNOWN_REDS=1`. No mock or schema relaxation. |
| Failure-and-rollback pin | New sibling expects SQLSTATE 23502 for missing `inventory_batch_movements.movement_id`. Full before/after snapshots cover the original seven tables plus `stock_levels`, `stock_movements` and `inventory_batch_movements`; all ten, including `inventory_batch_stock`, remain unchanged. Ticket owner removes this pin and the success test’s skip together when repairing the writer. |
| Default PostgreSQL | `BatchReadLocationScopeTest.php`: **16 tests, 109 assertions, 1 skipped, exit 0**; the failure/rollback pin passes. Evidence: local `docs/sessions/wlota1a/r2b-pg-default.txt`. |
| Explicit known-red PostgreSQL | Same file with `WLOTA1A_RUN_KNOWN_REDS=1`: **16 tests, 110 assertions, exactly 1 failure, exit 1**. Only `test_transfer_controller_response_uses_all_membership_locations` fails (expected 200, received 500; SQLSTATE 23502). Evidence: local `r2b-pg-known-red.txt`. This intentional opt-in result is not claimed green. |
| Static checks | Pint --test passes on the changed test file. Scoped PHPStan level 8 passes on `BatchController.php` and `BatchResource.php` (runtime boundary, not test-fixture analysis). Manifest checker exits 0 with existing parked-lane warnings; no class/ceiling change. Local `r2b-pint.txt`, `r2b-phpstan.txt`, `r2b-manifest.txt`. |
| Caller census / release note | Ticket records the exact web/POS grep and API-module inspection: no first-party per-lot transfer caller; three local picker-helper matches only. Priority consequence is API/mobile exposure only. Release notes disclose the pre-lane PostgreSQL failure. |
| Push ledger | Existing Push-3 ledger row and assignment remain unchanged. The round-2b test follow-up commit listed below supplements that row’s round-2 provenance; no file moves between pushes. |

Both PostgreSQL runs execute one file at a time, sequentially, with DB_DATABASE and DB_CENTRAL_DATABASE set to `autoerp_test_w`, both ports 5433. From `apps/api`, run `./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php` first with the opt-in unset, then with `WLOTA1A_RUN_KNOWN_REDS=1`. Evidence files are local and gitignored. Earlier web/browser evidence remains at its recorded source SHA; no browser rerun or activated-tenant acceptance is claimed for this backend-test/docs-only round.

Round-2b test commit: `52f5ad796 Phase 1.2.9: Gate the known transfer failure and pin rollback`.

Repository-required scoped preflight also completed successfully (`r2b-preflight.txt`: “All preflight checks passed!”). It ran the default PG `BatchReadLocationScopeTest` with the known-red opt-in unset, the existing scoped BatchPermissions Vitest, Pint, runtime/seeder PHPStan, TypeScript, repository-wide ESLint, generated-artifact/manifest checks and fixed fiscal-parity/chokepoint gates. Exact scopes and environment are retained in local `r2b-preflight.sh`; no full application PHPUnit or Vitest suite ran.


## Fix round 3

Authority: plan rev 12 §000 and the full gate-r3 tenancy register, read from the main checkout. Base `a7010fe4d`; current source commit recorded below. This round preserves all verified r2 blocker/major closures. Status remains **review**, `blocking_decision: none`; promotion still requires orchestrator review and staged acceptance. No merge, push or activation performed.

| Finding | Resolution | Current source citation |
| --- | --- | --- |
| M-1(r3) | Split the combined Vitest into four list cases in Push 3 and five detail cases in Push 5. The prompt names four detail gating cases; the existing fifth state-suppression case is also preserved. All nine original cases survive. The detail page stays Push 5. Release note names the new Push-3 test; ledger reconciles to 76 unique non-doc files. | `apps/web/src/features/batches/pages/__tests__/BatchListPermissions.test.tsx:1`; `:30`; `apps/web/src/features/batches/pages/__tests__/BatchDetailPermissions.test.tsx:31`; `:49`; `docs/handoff/RELEASE-NOTES-WLOTA-1a-2026-09-10.md:16` |
| N-2(r3) | Ignore non-UUID location input on the flag-off expiring path using `Str::isUuid()`. The flag-on resolver retains validation. The real HTTP regression reproduced SQLSTATE 22P02 / 500 before the guard and now returns 200 for `location_id=0`. | `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:321`; `:322`; `apps/api/tests/Feature/BatchExpiry/BatchExpiringLocationScopeTest.php:26` |
| N-3(r3) | PostgreSQL-specific transfer failure pin skips other database drivers. The ticketed known-red success skip and writer remain unchanged. | `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php:235`; `:237` |
| N-4(r3) | Corrected the six round-2 table citations to current method declarations, including the write-off method now shifted to line 274 by the driver guard. | `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php:156`; `:177`; `:200`; `:213`; `:235`; `:274` |
| N-1(r3) | Orchestrator already amended plan rev 12 §6.1; no lane change required. | Main-checkout `docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-12.md:5` (§000 ruling) |

Push-3 import proof: `rg -n '^import' apps/web/src/features/batches/pages/__tests__/BatchListPermissions.test.tsx` lists only Testing Library, React Router, Vitest, `../../types` and `../BatchListPage` (lines 1–5). The Push-3 Vitest imports nothing outside `types.ts`, `BatchListPage.tsx` and shared libraries; it has no detail-page dependency. Its mocks use the existing batch hook and shared permission/currency/i18n hooks. No stash or scratch replacement of the detail page was used.


Verification on the round-3 source, one test file/process at a time. PostgreSQL uses `autoerp_test_w` for both DB_DATABASE and DB_CENTRAL_DATABASE on port 5433; the known-red opt-in is unset. SQLite uses `phpunit.xml`, DB_CONNECTION=sqlite and both database names `:memory:`. Evidence stays local under `docs/sessions/wlota1a/`.

| Check | Result | Local evidence |
| --- | --- | --- |
| PG expiring regression before guard | Expected failure: 1 test / 1 assertion, HTTP 500 with SQLSTATE 22P02 for UUID `0` | `r3-red-expiring.txt` |
| PG `BatchExpiringLocationScopeTest` after guard | 3 tests / 7 assertions, exit 0 | `r3-pg-expiring.txt` |
| PG `BatchReadLocationScopeTest`, default | 16 tests / 109 assertions / 1 known-red skip, exit 0 | `r3-pg-read.txt` |
| SQLite `BatchReadLocationScopeTest`, default | 16 tests / 105 assertions / 2 skips (known-red success plus PG-specific failure pin), exit 0 | `r3-sqlite-read.txt` |
| Vitest `BatchListPermissions.test.tsx` | 4 passed | `r3-vitest-BatchListPermissions.txt` |
| Vitest `BatchDetailPermissions.test.tsx` | 5 passed | `r3-vitest-BatchDetailPermissions.txt` |
| Vitest `ExpiryWriteOffPage.test.tsx` | 8 passed | `r3-vitest-ExpiryWriteOffPage.txt` |
| React Doctor, changed scope against round-3 base, including both untracked split files | 2 files, 100/100, no new issues | `r3-react-doctor-scoped.txt` |

The nine original Vitest bodies compare byte-for-byte equal after the split. React Doctor uses `--scope changed --base a7010fe4d --include-untracked --no-parallel`; the reported result compares this round against its base and includes both split files. No React production component changed.


Final scoped `./scripts/preflight.sh`: **exit 0**, all checks passed (`r3-preflight.txt`; exact scopes/environment in `r3-preflight.sh`). This includes Pint --test on the three touched PHP files; PHPStan level 8 on `BatchController.php`; PG `BatchExpiringLocationScopeTest`; `pnpm typecheck`; full ESLint (**0 errors, 6413 existing warnings**); `pnpm audit:keys` (**0 new / 0 stale**); generated DTO/permission-map drift checks; feature-lane manifest checker (**exit 0**, existing parked-lane warnings); and the fixed local harness, fiscal-parity and chokepoint checks. Preflight's selected application Vitest is the Push-3 `BatchListPermissions.test.tsx` only; the detail and expiry-write-off files were verified individually above. No full application PHPUnit/Vitest suite ran.

Separate `node scripts/factory/gen-route-manifest.mjs --check`: **exit 0** (`r3-route-manifest.txt`). Mechanical final reconciliation: **76 non-doc files = 76 unique ledger rows**, no duplicate, missing or extra path (`r3-ledger-check.json`). Node version for web checks: **20.19.4**. No browser rerun is claimed; React production pages are unchanged in this round.

Round-3 source commit: `068734a64 Phase 1.3.1: Split batch permission tests and guard expiring UUIDs`. The table’s source citations refer to this commit (the subsequent handback/release-note commit is docs-only).
