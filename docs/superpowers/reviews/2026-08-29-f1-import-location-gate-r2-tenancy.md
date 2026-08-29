# Gate r2 — Lane F1 (F-BUG-1) import location / default-location code — tenancy+authz lens

**VERDICT: CHANGES — 1× P1 (blocking, CI-red), 1× P2, 4× P3. All four r1 blocking/important findings are CLOSED; the P1 below is a NEW regression introduced by the fix round.**

Reviewer: tenancy-authz-reviewer (adversarial, code-grounded). Branch `fix/f-bug-1-import-location` @ `6f36f777f` (r1 was `afd2aaf71`), base `dev` @ `a4ceeb0f5`, worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/f-bug-1`.
Scope reviewed: full lane diff `git diff dev...HEAD` (24 files). Every claim below was read in the file cited or produced by a command run in this worktree.

Fix before merge (one line): the `ImportRow` `@warnings` docblock weakening (`ImportRow.php:19`) puts **2 new PHPStan level-8 errors** into `ResultWorkbookService.php:119-120` — restore the strict shape and guard inside `warningSummary()` instead.

---

## r1 findings — disposition

| r1 finding | Status | Evidence |
|---|---|---|
| **[P1]** nullable `locations.code` crashes the wizard | **CLOSED** | Normalised at every mapper boundary: `apps/web/src/features/locations/api/locations.ts:11` (`code: string \| null`), `:41`, `:52` (`code: raw.code ?? ''`), `:88`; `apps/web/src/features/locations/api/scopedLocations.ts:12` + `:27`; `apps/web/src/features/locations/hooks/useManagementLocations.ts:13` + `:31`. Every `.code.trim()/.toLowerCase()` consumer I could find now reads a mapped value: `ImportWizardPage.tsx:159,286,290,296`, `LocationSelectorMulti.tsx:91` (via `useLocations`→`mapLocation`), `LocationField.tsx:65` (via `getLocations`). `LocationField.tsx:128` reads an UNmapped single-location payload but guards with `selectedLocation.code &&`. Pinned by real mapper tests with `code: null` (`locations.test.ts:79-121`) and by a wizard test that feeds `code: null` through the real mapper (`ImportWizardPage.options.test.tsx:262-283`). |
| **[P2]** wizard location list on the `module:Inventory`-gated `GET /locations` | **CLOSED** | `ImportWizardPage.tsx:31,263` now uses `useScopedLocations` → `GET /company/locations` (`scopedLocations.ts:23`). That route is `apps/api/app/Modules/Company/routes.php:95-96` inside the group at `:24` — `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]`, no `module:` gate, no `can:`. Rule-12 compliant, and a strict SUBSET of the import routes' middleware (`ImportServiceProvider.php:67` adds `can:imports.manage`). Persona trace below. |
| **[P2]** `warningStats()` hydrated warning rows on the paginated list | **CLOSED for the list** | `formatJob(ImportJob $job, bool $withWarningSummary)` (`ImportController.php:660`); `index()` passes `false` (`:65`), so `:672` emits `warning_summary: null` and `warningSummary()` (`:700`) is never entered. `warning_rows` stays the pre-existing driver-aware COUNT (`countWarningRows`, `:685-695`) — verified `countWarningRows` is context (unchanged) in the diff, i.e. it was never replaced. Pinned by `ProductsImportPipelineTest::test_import_list_omits_warning_summary_while_show_counts_rows_per_code` (asserts `data.0.warning_summary` is `null`, and rows-per-code de-dup: two `price_conflict` entries on one row ⇒ `1`). **Residual cost remains on `show` — see P2 below.** |
| **[P2]** backfill did not touch `updated_at` | **CLOSED** | `2026_08_29_100000_backfill_default_location_code_f1.php:64-67` — `->update(['code' => 'MAIN', 'updated_at' => now()])`. `locations` has had `timestamps()` since creation (`2025_11_30_105000_create_locations_table.php:57`), so no column-existence risk. |
| **[P3]** no migration header docblock | **CLOSED** | `…f1.php:13-17` — states the collision-safety intent and that the census line is for operator action. Shorter than the sibling census migrations but it says the load-bearing thing. |
| **[P3]** wizard offers inactive locations | **CLOSED** | `ImportWizardPage.tsx:264-267` filters `location.isActive`, fed by the new `is_active` on the picker payload (`LocationController.php:123`). Pinned: `ImportWizardPage.options.test.tsx:277` asserts `Inactive Branch (OLD)` is not an option. |
| **[P3]** import jobs tenant-scoped, never company-scoped | **NOT CLOSED (deliberate)** | Unchanged; explicitly deferred to Lane F2 by the fix brief. Still true: `import_jobs` has no `company_id`, lookups are `where('tenant_id', …)` (`ImportController.php:60-62`). Carry to F2. |
| **[P3]** per-element warning shape unguarded | **PARTIALLY CLOSED** | Outer guard added (`ImportController.php:705`) and the key access is now `?? ''` (`:711`). The per-ELEMENT `is_array($warning)` check is still absent — and the docblock change made this worse, see P1. |

---

## Persona trace for the new locations source (the parent's explicit ask)

Route: `Route::get('company/locations', [LocationController::class, 'scopedIndex'])` — `apps/api/app/Modules/Company/routes.php:95-96`, group middleware `apps/api/app/Modules/Company/routes.php:24`.
Handler: `LocationController::scopedIndex` (`:36-42`) → `LocationScopeResolver::resolve($user)` → `pickerPayload()` (`:105-126`, `requireCompanyId()` + `where('company_id', …)`).
`CompanyContextMiddleware` runs for BOTH route groups (appended to the `api` group, `apps/api/bootstrap/app.php:143-152`) and binds the company from `X-Company-Id` or the user's default, refusing anything else with 403 (`CompanyContextMiddleware.php:125-142`) — and it requires an **ACTIVE** membership (`CompanyContext.php:154-164`).

- **Tenant owner / admin — PASSES.** Owner gets a `UserCompanyMembership` with `allowed_location_ids` unset at every company-creation path (`CompanyController.php:143-150`, `TenantProvisioningService.php:186`, `AuthController.php:452`); `LocationContext::getAllowedLocationIds` returns `null` ⇒ `LocationScopeResolver::effectiveAllowedIds` returns every company location. Every location-creation path in the codebase writes a code (`CompanyController.php:126` `MAIN` — the lane's own fix; `TenantProvisioningService.php:164`; `AuthController.php:431`; `LocationController.php:190-193` auto `LOC-%03d`; all five seeders; `LocationFactory` `:23`), and the migration backfills legacy code-less defaults. Non-empty, coded, `MAIN` preselected. Pinned by `ImportWizardPage.options.test.tsx:238-260` (asserts the select defaults to `MAIN` and the PATCH sends `{location_code:'MAIN'}`).
- **`imports.manage` + `products.*` only — PASSES.** `company/locations` carries no `can:` and no `module:`; its middleware is a strict subset of the import routes'. Any token that reaches `/api/v1/imports/*` reaches this endpoint with the same tenant + company binding. (Contrast the r1 state: `GET /locations` is `module:Inventory` + `can:inventory.view` — `apps/api/app/Modules/Inventory/Presentation/routes.php:31,33-35` — which 403s on `restaurant`/`coffee_shop`, `config/verticals.php:75,107`.)
- **Location-restricted user without `users.manage_location_access` — PASSES.** `scopedIndex` is ungated; only `company/locations/all` requires that permission (`routes.php:102-104`). The restricted user sees exactly their granted subset, and those locations always carry codes (same audit as above). Pinned by `LocationListEndpointsTest::test_scoped_company_locations_excludes_unassigned_for_restricted_user` (`:69-79`) and `…_returns_all_for_null_membership` (`:81-93`).
- **Fail-closed empty-list cases are unreachable in production.** `getAllowedLocationIds` returns `[]` for an absent/suspended membership (`LocationContext.php:200-212`), but both `LocationListEndpointsTest` pins for that (`:177-198`) must `withoutMiddleware(CompanyContextMiddleware::class)` to reach the controller — in the real stack such a user is 403'd at `NO_COMPANY_ACCESS`/`COMPANY_ACCESS_DENIED` long before the wizard loads.

**Conclusion: no persona that can reach the import wizard gets an empty or all-disabled stock-location select.** The parent's P1 condition is NOT met.

FE `enabled` gating: `useScopedLocations.ts:15` — `enabled: Boolean(tenantId && companyId)`, `tenantScopedKey(['company-locations','scoped'])` at `:13` (rule-14 compliant). `tenantId`/`companyId` are the same two values the whole authenticated shell depends on (`ViewScopePicker` uses this exact hook); if either is null the wizard itself cannot have loaded a job.

## `is_active` on the picker payload — no other consumer changes behaviour

`LocationController.php:123` adds `is_active` to `pickerPayload()`, which serves BOTH `scopedIndex` and `managementIndex` (`:41`, `:57`). Audited every consumer:
- `ViewScopePicker.tsx:30,73-83` — reads `id`/`name` only.
- `useViewScope.ts:9-24` — reads `id` only.
- `RebalancingView.tsx:17` and `StockByLocationPage.tsx:25` — read `id`/`name` only.
- `useManagementLocations.ts:31-34` (`/company/locations/all`) — mapper extended in the same lane; its consumers (`LocationAccessField.tsx:23` and the two settings tests) do not filter on it.
- Only `ImportWizardPage.tsx:265` filters on `isActive`.
`pnpm typecheck` is clean, so no `ScopedLocation` object literal elsewhere broke on the added required field. `LocationListEndpointsTest::pickerRow` (`:256-266`) was updated so the existing exact-JSON assertions cover the new key. No new data is exposed that `/locations` and `/company/locations/transaction-destinations` did not already emit (`LocationResource`, `transactionPickerPayload` `:140`).

## Migration safety under `tenants:migrate` (re-verified after the change)

Unchanged from r1 except the `updated_at` write: still guarded by `Schema::hasTable` + per-column `hasColumn` (`…f1.php:20-28`), still collision-checked per `company_id` against the real partial unique index (`2025_11_30_105000:68`), still a no-op `down()` (`:71-74`), still confined to `database/migrations/tenant` (`config/tenancy.php:195-199`) so central can never see it. `echo` census remains established precedent.
**Deploy note (unchanged from r1):** pushing to `origin/dev` auto-runs `tenants:migrate` on staging. Operators must grep the per-tenant output for `default-location-code-collision` lines (`…f1.php:50-52`) — a tenant on that branch keeps a NULL-coded default and its wizard will simply offer the other, already-coded `MAIN` location instead.

## Gates run in this worktree

- `phpunit tests/Feature/Company/Migrations/BackfillDefaultLocationCodeF1MigrationTest.php tests/Feature/Company/LocationListEndpointsTest.php` → **OK (13 tests, 54 assertions)**
- `phpunit tests/Feature/Import/ProductsImportPipelineTest.php` → **OK (28 tests, 263 assertions)**
- `phpstan analyse` on the 5 touched PHP files → **No errors**
- `phpstan analyse app/Modules/Import/Services/ResultWorkbookService.php` → **2 ERRORS (new — see P1)**
- `pint --test` on the touched PHP files → **pass**
- `php tools/feature-lane-manifest-check.php` → **OK** (ceiling raised 1197→1198, Company group 33→34, note written)
- `pnpm vitest run src/features/import src/features/locations src/components/organisms/ViewScopePicker …` → **17 files / 75 tests pass**
- `pnpm typecheck` → clean. `pnpm lint` NOT run (parent instruction).
- i18n: all 16 new keys (`options.stockLocation.*`, `mapping.targetLabels.*`, `wizard.complete.warnings`, `warnings.*`) present in `en`, `fr` AND `ar`.

---

## Findings

### [P1] `apps/api/app/Modules/Import/Domain/ImportRow.php:19` — the loosened `@warnings` shape puts 2 NEW PHPStan level-8 errors into `ResultWorkbookService.php:119-120`; preflight and CI go red

The fix round changed the property docblock from the `dev` shape

```php
// dev: apps/api/app/Modules/Import/Domain/ImportRow.php:19
* @property list<array{code: string, detail: string}>|null $warnings
// branch:
* @property list<array{code?: string, detail?: string}>|null $warnings
```

`ResultWorkbookService::formatWarnings()` reads both keys unconditionally:

```php
// apps/api/app/Modules/Import/Services/ResultWorkbookService.php:117-121
static fn (array $warning): string => sprintf('%s: %s', $warning['code'], $warning['detail']),
```

Run in this worktree:

```
apps/api $ ./vendor/bin/phpstan analyse app/Modules/Import/Services/ResultWorkbookService.php
  119   Offset 'code' might not exist on array{code?: string, detail?: string}.   offsetAccess.notFound
  120   Offset 'detail' might not exist on array{code?: string, detail?: string}. offsetAccess.notFound
 [ERROR] Found 2 errors
```

Both are NEW: with the `dev` docblock the offsets are required and neither error exists; there is no baseline entry for this file (`grep ResultWorkbookService phpstan*.neon*` → empty). This is exactly the trap the lane's own verification list walked into — `phpstan analyse <touched php files>` is green because the error surfaces only in an UNtouched transitive consumer.

**Failure scenario:** `./scripts/preflight.sh` and the CI static-analysis job fail on this branch (rule 10, PHPStan level 8 zero-errors law), so the lane cannot land as-is; the fix also silently weakened the contract that `ResultWorkbookService` — the failed-rows/result workbook that operators download after exactly this kind of import — depends on.

**Fix:** revert `ImportRow.php:19` to `list<array{code: string, detail: string}>|null` and put the defensiveness where it belongs, inside the new loop:

```php
// ImportController.php:710-717
foreach ($row->warnings as $warning) {
    if (! is_array($warning) || ! isset($warning['code']) || ! is_string($warning['code']) || $warning['code'] === '') {
        continue;
    }
    $rowCodes[$warning['code']] = true;
}
```

That also closes the still-open half of r1's P3 (a scalar element currently fatals at `$warning['code']`, `ImportController.php:711`, and at `ResultWorkbookService.php:117`'s `array $warning` type). Re-run `phpstan analyse app/Modules/Import` before re-gating, not just the touched files.

### [P2] `apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:704` + `:239` — the row hydration moved off the list endpoint but landed on the 2-second in-flight poll, where the result is never displayed

`show()` passes `true` (`:239`), so every `GET /api/v1/imports/{id}` runs

```php
foreach ($job->rows()->whereNotNull('warnings')->get(['warnings']) as $row) {
```

The wizard polls that endpoint every 2 seconds for the whole duration of an async import: `refetchInterval: isImporting ? 2000 : false` (`apps/web/src/features/import/pages/ImportWizardPage.tsx:339`, hook at `:337`). A 50k-row product import with warnings on most rows therefore hydrates ~50k `ImportRow` models with their jsonb payloads roughly 30 times a minute, on the tenant-#1 onboarding path.

And the result is discarded until the very end: the warning panel is rendered ONLY inside `case 'complete'` (`ImportWizardPage.tsx:1149`, panel at `:1182`). Every poll before the terminal one pays the full cost for a value nothing reads.

**Fix (cheap, no contract change):** compute the summary only when the job is terminal —
`'warning_summary' => $withWarningSummary && $job->status->isTerminal() ? $this->warningSummary($job) : null` — or aggregate in SQL on pgsql (`jsonb_array_elements(warnings) ->> 'code'`, `GROUP BY`) keeping the PHP loop for sqlite. The existing `test_import_list_omits_warning_summary_while_show_counts_rows_per_code` pin would need its `show` job driven to a terminal status.

### [P3] `apps/web/src/features/import/pages/ImportWizardPage.tsx:263` — the query's `isError`/`isLoading` are still discarded; a failed locations fetch renders a silently empty select and the wizard lets the operator continue

```ts
const { data: scopedLocations = [] } = useScopedLocations()
```

r1's suggested fix had two halves; the fix-round brief carried only the endpoint swap, not "surface `isError` in the stock section". On a 500/network failure (or a `requireCompanyId()` throw, `LocationController.php:107`) the list is `[]`, `stockLocationCode` resolves to `''` (`:286-289`), the PATCH correctly omits `location_code` (`:613-615`) and the import posts opening stock with no location. Not reachable by any persona (see the trace above), and now partially mitigated — the operator does at least see `location_unresolved` counts on the complete step (`:1182-1200`). Still worth a `isError`/empty-state branch in `StockLocationOptions` before the team's manual staging pass.

### [P3] `apps/web/src/features/import/__tests__/ImportWizardPage.options.test.tsx` — no test covers "stock section visible, zero locations returned"

`beforeEach` defaults `mockApiGet.mockResolvedValue([])` (`:172`) but no test combines that default with a `quantity` mapping, so the empty-list rendering path (the P3 above) is unpinned. Add a case asserting the select renders, offers nothing selectable, and that `updateOptions` is called WITHOUT `location_code`.

### [P3] `apps/api/tests/feature-lane-manifest.json:739-741` — the new migration test is laned into a PARKED lane, so it runs nowhere in CI

The raise to `classes: 34` is correctly justified in the note, but `feature-lane-tenancy/Company` is gated behind `vars.SELF_HOSTED_RUNNER_READY`; the manifest checker confirms `70 group(s) / 1198 class(es) are laned but not yet running`. `BackfillDefaultLocationCodeF1MigrationTest` — the only pin on the collision-safe backfill that is about to auto-run fleet-wide — is verified by path only (it passes here). Either name it in the `backend-pgsql --filter` allowlist as the two `FiscalPeriod*EndpointTest` siblings were, or record that it is path-run evidence only.

### [P3] `apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:710-711` — r1's per-element guard is still missing

See the P1 fix snippet; folding the two together is one edit.

---

## Test-quality notes

- `ProductsImportPipelineTest::test_products_import_uses_job_location_option_when_rows_have_no_location_code` and `…test_per_row_location_code_overrides_the_job_location_option` are the real green-path proof r1 was missing: real upload → real execute → asserts the `StockMovement.location_id` and the `StockLevel.quantity` string `'3.0000'` (no float, rule 19), and asserts the non-target location has **zero** stock levels. Not mocks.
- `test_import_list_omits_warning_summary_while_show_counts_rows_per_code` pins the rows-per-code de-dup AND feeds a legacy warning element with no `code` key — a genuine adversarial input, and precisely the shape whose docblock change caused the P1.
- `ImportWizardPage.options.test.tsx:262-283` mocks at `@/lib/api`'s `apiGet`, so the REAL `getScopedLocations` mapper runs — the `code: null` normalisation is exercised end-to-end, not stubbed. It also asserts the endpoint string `'/company/locations'`, which is what makes the r1-P2 fix regression-proof.
- `…:287-360` (WebSocket completion) holds the refetch promise open and asserts the complete step is not entered until it resolves — a real ordering pin, not a happy-path smoke test.
- No new `can:`/`module:` guard is introduced by this lane, so no seeder sync and no authz deny-path test are required. `LocationListEndpointsTest` already carries the deny paths for the sibling routes (`:111-118`, `:133-139`).
