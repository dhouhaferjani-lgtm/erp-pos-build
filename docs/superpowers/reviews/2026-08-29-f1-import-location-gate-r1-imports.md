# Lane F1 — adversarial gate r1 (imports-reviewer)

**VERDICT: CHANGES — 3 × P1, 3 × P2, 10 × P3. DO NOT MERGE.**

- Branch `fix/f-bug-1-import-location` @ `afd2aaf71`, worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/f-bug-1`, base `dev` = `a4ceeb0f5`.
- Diff reviewed: `git diff dev...HEAD` — 17 files, +541/−16.
- Every claim below was read in the tree at the cited line. Where I could not verify, I say so.

---

## Commands run (all green — the greens are not the problem)

| Command | Result |
|---|---|
| `phpunit tests/Feature/Company/Migrations/BackfillDefaultLocationCodeF1MigrationTest.php` | OK 2 tests / 13 assertions |
| `phpunit tests/Feature/Import/ProductsImportPipelineTest.php` | OK 25 tests / 243 assertions |
| `phpunit tests/Feature/Company/CreateCompanyTest.php` | OK 15 tests / 80 assertions |
| `php tools/feature-lane-manifest-check.php` | OK (1457 classes / 74 groups) |
| `phpstan analyse` (3 touched backend files) | No errors |
| `pint --test` (4 touched backend files) | pass |
| `vitest run src/features/import` | 10 files / 55 tests passed |
| `tsc --noEmit` | clean |
| `eslint src/features/import` | 0 errors, 71 warnings (1 NEW — see P3-9) |
| `node tools/audit-tanstack-keys.mjs` | Gate C: 0 |
| `node tools/audit-design-system.mjs` | 807 acknowledged, 0 new |
| `node tools/audit-i18n-completeness.mjs` | cannot verify locally — fails closed, `I18N_BASELINE_PROTECTED_BLOB` unset (owner repo var) |

Stray vitest workers killed.

---

## P1 findings

### P1-1 — A `NULL` location code crashes the new options step (white screen), for exactly the tenants this lane targets

`apps/web/src/features/import/pages/ImportWizardPage.tsx:282-289` and `:155`:

```ts
const codedLocations = locations.filter((location) => location.code.trim() !== '')
...
codedLocations.find((location) => location.isDefault)?.code.trim()
```
```ts
const code = location.code.trim()   // :155, inside StockLocationOptions
```

`location.code` is **not** guaranteed to be a string at runtime:

- `apps/api/database/migrations/tenant/2025_11_30_105000_create_locations_table.php:30` — `$table->string('code', 20)->nullable();`
- `apps/api/app/Modules/Company/Presentation/Requests/CreateLocationRequest.php:30` — `'code' => ['nullable', 'string', 'max:20']` → a user can create a location with **no code** through the normal Locations UI.
- `apps/api/app/Modules/Company/Presentation/Resources/LocationResource.php:27` — `'code' => $this->code,` (raw `null`, no coalesce).
- `apps/web/src/features/locations/api/locations.ts` `mapLocation` — `code: raw.code` (straight copy), and `apps/web/src/features/locations/types.ts:12` declares `code: string`. **The TS type is a lie**; that is why `tsc` is green.
- The lane's own migration deliberately leaves this population alive: `apps/api/database/migrations/tenant/2026_08_29_100000_backfill_default_location_code_f1.php:22` filters on `is_default = true`, and the new test asserts it — `tests/Feature/Company/Migrations/BackfillDefaultLocationCodeF1MigrationTest.php:41` `self::assertNull($nonDefaultLocation->refresh()->code);`
- Corroborating signal: ESLint reports `@typescript-eslint/no-unnecessary-condition` at `ImportWizardPage.tsx:288` — the compiler thinks the `?? ''` guard is dead because `code` "cannot" be null. It can.

**Failure scenario.** Tenant `019ee4d7` (the tenant in the triage) has a second, non-default location created from the Locations page with the Code field left empty → `code = null`. The operator opens the products wizard, maps `quantity`, clicks Next → `optionVisibility.stock` is true → `StockLocationOptions` renders → `TypeError: Cannot read properties of null (reading 'trim')` → the wizard unmounts. The import is now **completely unusable**, a strictly worse outcome than F-BUG-1 itself.

The three new Vitest cases only ever feed `code: 'MAIN'`, `code: 'BRANCH'`, `code: ''` (`ImportWizardPage.options.test.tsx:204-207, 231-234`) — never `null` — so the suite masks it.

**Fix:** widen `RawLocation.code` / `Location.code` to `string | null` (both `apps/web/src/features/locations/types.ts:12,44` and the raw interface in `api/locations.ts`), then `(location.code ?? '').trim()` at both sites; add a Vitest case with `code: null`.

---

### P1-2 — On async imports (≥ 100 rows) the new warning panel omits every warning the lane exists to surface

The opening-stock warnings are written in the **finalize** phase, after the last progress broadcast and immediately before the completion broadcast:

- `apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php:186` — `$importService->finalizeImport($job, $this->companyId);`
- `apps/api/app/Modules/Import/Services/ImportService.php:466-486` — `finalizeImport` runs `ProductOpeningStockPhase` and only then calls `addRowWarning` for `location_unresolved` / `qty_without_cost` / `opening_failed` / `opening_exists`.
- `ProcessImportJob.php:204` — `broadcastCompleted(...)` fires right after.

The wizard never refetches after that:

- `ImportWizardPage.tsx:327` — `refetchInterval: isImporting ? 2000 : false`.
- `ImportWizardPage.tsx:449-456` — on `realtimeProgress?.status === 'completed'` it does `setIsImporting(false)` (polling dies) + `setCurrentStep('complete')`. **No query invalidation.** Confirmed by grep: no `invalidateQueries` / `queryClient` in `apps/web/src/features/import/hooks/useImportProgress.ts` or `apps/web/src/stores/importProgressStore.ts`.
- `ImportWizardPage.tsx:336-345` — the merge overwrites `status`/`processed_rows`/`successful_rows`/`failed_rows` from the WebSocket but leaves `warning_rows` / `warning_summary` at whatever the last poll returned — a poll taken **mid row-loop, before finalize ran**.
- The WS is mounted globally (`apps/web/src/components/templates/DashboardLayout/DashboardLayout.tsx:15` → `useImportProgress()`), so this path is live in production.

**Failure scenario = the exact reported job.** Staging job `01a04cd3` is 859 rows → `ImportController.php:522` sends it down the async branch (`ASYNC_THRESHOLD = 100`, `:40`). The panel renders with only the row-loop warnings (`price_conflict` 543, `category_created`) and shows **none of the 422 `location_unresolved` lines** — so the operator still does not learn that zero stock was created, which is the entire point of Task 3+4. Worse: a clean file with no price conflicts and no new categories yields `warning_rows = 0` at the last poll, so `ImportWizardPage.tsx:1142` is false and the panel does not render at all.

The sync (<100 rows) path is fine — `useExecuteImport` (`apps/web/src/features/import/api/queries.ts:143-154`) awaits `invalidateQueries(importKeys.detail(id))` before the component's `onSuccess` navigates, and prefix matching covers the `tenantScopedKey` suffix. The new Vitest case (`ImportWizardPage.options.test.tsx:225-274`) stubs `useImportJob` with a completed job, so it exercises neither path.

**Fix:** invalidate/refetch `importKeys.detail(jobId)` when entering the `complete` step (or in the WS `import.completed` handler). Add a Vitest case where the WS completes first and assert the panel reflects a *refetched* payload.

---

### P1-3 — `warningStats` turns `GET /api/v1/imports` from O(1) counts into an unbounded row scan

`apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:684-707`:

```php
foreach ($job->rows()->whereNotNull('warnings')->get(['warnings']) as $row) {
```

`formatJob` (`:662`) calls this for **every job on the list page** — `index()` at `:60-65` paginates 20 jobs and maps `formatJob` over all of them. The code it replaced was a single `count()` per job (pre-diff `countWarningRows`: `whereRaw('jsonb_array_length(warnings) > 0')->count()`), i.e. constant memory.

Quantified:
- Upload cap is 10 MB (`ImportController.php:88`) and I found **no row cap** (no `MAX_ROWS` in `SpreadsheetParserService` or `ImportController`), so ~100k-row jobs are reachable.
- Staging warning density is ~1.1 warnings/row (859 rows → 968 warnings, triage §1b), so "most rows carry a warning" is the norm for real customer files, not the exception.
- Measured floor on this machine (`php -r`), decoded warning arrays **alone**, no Eloquent: 100k rows = **75.3 MB / 0.03 s**. `get()` returns a Collection of hydrated `ImportRow` models (~2-5 KB each), so the real figure is several hundred MB for one such job — and the page builds 20 of them.

**Failure scenario.** A tenant that has run a handful of large catalogue imports opens the Imports dashboard; `GET /api/v1/imports` exhausts `memory_limit` (fatal 500) or times out. The imports landing page becomes unusable — a regression on the exact prod path this lane is repairing.

Compounding waste: **neither field is consumed by the list UI.** Grep for `warning_rows|warning_summary` in `apps/web/src` (excluding tests) returns only `types.ts:58-59` and `ImportWizardPage.tsx:1142,1148,1151` — the single-job wizard view.

**Bounded alternative:** give `formatJob` a `bool $withSummary = false` parameter; `index()` keeps the cheap per-job COUNT for `warning_rows` and omits `warning_summary`; the single-job responses (`:187,206,215,239,402,538,569,813`) compute it. If you want it everywhere, use one grouped query per job — PG `SELECT w->>'code' AS code, count(*) FROM import_rows, jsonb_array_elements(warnings) w WHERE import_job_id = ? GROUP BY 1` with the existing PHP path as the SQLite fallback (the driver split already existed in the code you deleted).

---

## P2 findings

### P2-1 — The lane's green path has no backend test; only the red path is pinned

The one new import test asserts the **skip**, not the fix: `tests/Feature/Import/ProductsImportPipelineTest.php:212-235` uploads a file with no location at all and asserts `warning_summary.location_unresolved = 2`.

Every existing opening-stock test supplies a **per-row `location_code` column** (`ProductsImportPipelineTest.php:160, 255, 306, 335, 363, 471, 518, 544, 575, 603`). `ImportJobOptionsTest.php:117-128` only asserts that PATCH merges options into the JSON blob. **Nothing anywhere asserts that a job-level `options.location_code` results in a posted `StockMovement`/stock level** — which is the whole fix.

**Failure scenario.** A future refactor of `ProductOpeningStockPhase::resolveLocationId` (`apps/api/app/Modules/Import/Services/ProductOpeningStockPhase.php:243-255`) that drops the `$options['location_code']` fallback at `:248` keeps all 25 pipeline tests, the FE suite and PHPStan green, and silently restores F-BUG-1 for every customer.

**Fix:** one Feature test — upload `name,sku,type,quantity,purchase_price` (no location column), `PATCH /imports/{id}/options` with `{"options":{"location_code":"MAIN"}}`, execute, assert a `MovementType::Opening` `StockMovement` on the MAIN location and `warning_summary` free of `location_unresolved`.

### P2-2 — Per-row `location_code` still wins, so the reported staging file still imports zero stock

`ProductOpeningStockPhase.php:246-248` prefers `$data['location_code']` over `$options['location_code']` (correct per the brief). But the team's file has a **mapped `location_code` column full of shelf codes** (`ZF1161`, `ZF625` — triage §1c). Those 422 rows still resolve to nothing after this lane ships.

There is no pre-flight check either: Products validation is `'location_code' => ['nullable', 'string', 'max:100']` (`apps/api/app/Modules/Import/Domain/Enums/ImportType.php:199`) — no existence check — and `prepareProductPlacements` only validates `location_code` for rows that also carry a `placement_path` (`ProductPlacementImportService.php:182-193`). So the only mitigation shipped is the completion panel, which P1-2 disables for exactly this job size.

**Fix (scope call for the orchestrator):** at validation/preview time, count `location_code` values that `findIdByCode` cannot resolve and surface them on the validation step, with an explicit "use the selected stock location instead of the mapped column" escape. At minimum, make the options-step hint state that a mapped `location_code` column *overrides* the picker for every row that has a value.

### P2-3 — `warning_summary` counts warning occurrences; every locale string calls them "rows"

`ImportController.php:697-703` increments `$summary[$code]` **per warning entry**, not per row. `ProductPriceResolver.php:66-72` can emit `price_conflict` **twice for a single row** (it loops over every non-chosen candidate, and there can be two).

Meanwhile the strings say rows:
- `apps/web/src/locales/en/import.json` `warnings.price_conflict` = `"{{count}} rows contained conflicting prices; …"` (fr/ar identical in meaning).
- The headline uses `warning_rows` (genuinely rows): `ImportWizardPage.tsx:1148`.

**Failure scenario.** A file where 300 rows each carry TTC+HT+margin conflicts renders "700 rows completed with warnings" above "900 rows contained conflicting prices" — self-contradictory numbers in the operator's only feedback channel.

**Fix:** count distinct row ids per code (`$seen[$code][$row->id] = true`) so the summary is row-based like the headline, or reword the eight strings to "occurrences".

---

## P3 findings

- **P3-1** `tests/.../BackfillDefaultLocationCodeF1MigrationTest.php:44-62` — the idempotency assertion is vacuous. The migration updates through `DB::table(...)->update(['code' => 'MAIN'])` (`2026_08_29_100000_...php:53-60`), which never touches `updated_at`; so "timestamps unchanged after a second run" cannot fail even if the update re-ran. Assert on `code` + a `DB::getQueryLog()` update count instead.
- **P3-2** The new class lands in `feature-lane-tenancy/Company`, which the manifest itself documents as **PARKED behind `vars.SELF_HOSTED_RUNNER_READY`** (`apps/api/tests/feature-lane-manifest.json`, Company note; `feature-lane-manifest-check.php` prints "70 group(s) / 1198 class(es) are laned but not yet running"). The backfill therefore has **no executing CI guard**. Acknowledged in the note, but it should be named in the `backend-pgsql --filter` allowlist like its siblings (`FiscalPeriodCloseEndpointTest`, `FiscalPeriodReopenEndpointTest`) if it is meant to actually run.
- **P3-3** `KNOWN_WARNING_CODES` (`ImportWizardPage.tsx:31-39`) lists `category_created`, but the emitted code is dynamic — `'category_'.$category->outcome->value` (`ImportService.php:557-562`) — so `category_restored` falls to the generic. It also omits codes that ARE emitted: `margin_without_cost` (`ProductPriceResolver.php:44`), `opening_exists` (`ProductOpeningStockPhase.php:132`), `expiry_in_past` / `expiry_ignored_not_batch_tracked` / `expiry_ignored_no_default_lot` (`OpeningLotExpiryOutcome`), `balance_not_posted` (`PartiesBalancesPhase.php:210,255`). The `warnings.other` fallback keeps them visible, but `opening_exists` (the re-run case) and `margin_without_cost` deserve real strings.
- **P3-4** The picker lists **inactive** locations with no marker: `LocationController::index` has no `is_active` filter (`apps/api/app/Modules/Company/Presentation/Controllers/LocationController.php:87-91`), and `LocationService::findIdByCode` (`apps/api/app/Modules/Company/Application/Services/LocationService.php:22-29`) does not check it either — opening stock can be posted into a deactivated location.
- **P3-5** `findIdByCode` is an exact, case-sensitive `where('code', $code)`; the per-row path passes the raw cell **untrimmed** (`ProductOpeningStockPhase.php:246-248`, `(string) $data['location_code']`) while the new wizard deliberately trims (`ImportWizardPage.tsx:155,282`). A CSV cell `main` (or with a stray space that survives to the DB via a seeder) silently skips. Pre-existing, but `MAIN` is now the seeded convention that operators will hand-type.
- **P3-6** `'options.location_code' => ['sometimes','string','max:100']` (`ImportController.php:93,381`) vs `locations.code` = `varchar(20)` (`create_locations_table.php:30`) — a 21-100 char value is accepted and can never resolve. Pre-existing lines, not introduced here.
- **P3-7** The migration `echo`s to stdout inside `up()` (`2026_08_29_100000_...php:47`). `Log::warning` on the line above is already the durable record; the `echo` pollutes `tenants:migrate` output (and the test depends on it via `ob_start()`).
- **P3-8** `apps/web/src/locales/ar/import.json` has only 5 top-level keys (`wizard`, `options`, `preview`, `mapping`, `warnings`), so the new Arabic warning panel will render inside an otherwise English completion screen (`wizard.complete.imported`/`failed` have no `ar` entry). Pre-existing partial-file condition — worth a ticket, not a blocker for this lane.
- **P3-9** New ESLint warning at `ImportWizardPage.tsx:288` (`@typescript-eslint/no-unnecessary-condition`). Non-blocking (`pnpm lint:eslint` = `eslint .`, no `--max-warnings`), but it is the type system telling you P1-1 is real — do not silence it, fix the type.
- **P3-10** No `ColumnMapper` test for the new `mapping.targetLabels.*` suffix — `ColumnMapper.test.tsx` is unchanged (2 tests), so the 1c label clarification (`ColumnMapper.tsx:159-162, 183-186`) is unpinned.

---

## Verified correct (stated explicitly so the next round does not re-litigate)

- **Task 1 parity is right.** `CompanyController.php:126` `'code' => 'MAIN'` now matches `TenantProvisioningService.php:165` and `AuthController.php:432`. Pinned by `CreateCompanyTest.php:158`.
- **Migration is idempotent and collision-safe.** After the first run the rows no longer match the `code IS NULL OR code = ''` predicate (`:22-26`), so a re-run is a no-op. The collision guard (`:37-41`) correctly reads `idx_locations_company_code` semantics — `CREATE UNIQUE INDEX ... (company_id, code) WHERE code IS NOT NULL` (`create_locations_table.php:68`) means `''` **participates** in uniqueness while `NULL` does not; a company holding one `NULL` and one `''` default is handled (first → `MAIN`, second → census, no 23505). Self-guards on missing table/columns (`:14-21`), `down()` is a documented no-op (`:63-65`).
- **Cursor-while-update is safe here.** `DB::table(...)->cursor()` on `pgsql`/`sqlite` buffers the result set client-side (Laravel requests no server-side/unbuffered cursor), and the `update()` re-asserts the full predicate (`:53-59`), so a stale snapshot cannot cause a wrong write.
- **Portability improved.** `warningStats` is now pure PHP, so the deleted `sqlite` / `jsonb_array_length` driver split is gone and the SQLite test suite exercises the *same* code path as Postgres. This change carries no SQLite-masks-PG risk — its cost is P1-3.
- **Gating untouched.** No new route; `ImportServiceProvider.php:67` still `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class,'can:imports.manage']`.
- **Precision contract clean.** No `(float)`, `parseFloat`, or `Number(...)` on money/quantity anywhere in the diff. Opening stock still goes through `CurrencyScale::bcformatStrict((string) $data['quantity'], 4)` / `..., $scale` (`ProductOpeningStockPhase.php:105-107`) with `$scale` from the explicit company currency (`:66`, `getScale($company->currency)`) — queue-safe, no bare no-arg `getScale()`.
- **`location_code` can never leak to non-products types.** `optionVisibility` returns `stock: false` for any non-products type (`ImportWizardPage.tsx:351-353`) and the PATCH payload is gated on `optionVisibility.stock` (`:572-574`).
- **Locale parity holds.** Every new key (`wizard.complete.warnings`, `options.stockLocation.{title,label,hint,select,noCode}`, `mapping.targetLabels.{location_code,placement_path}`, `warnings.*`) exists in `en`, `fr` and `ar` with identical interpolation variables (`{{count}}`, plus `{{code}}` for `warnings.other`); no duplicate JSON keys in any of the three files.
- **The location list is membership-scoped**, not view-scope-scoped (`LocationScopeResolver.php:31-46` → `LocationContext::getAllowedLocationIds`), so a location-restricted operator can only open stock into locations they are assigned to. Correct behaviour.

---

## What to fix before merge

Null-guard `location.code` (P1-1), refetch the job when the WebSocket completes it so async imports actually show `location_unresolved` (P1-2), and stop computing `warning_summary` on the 20-job list endpoint (P1-3) — then add the missing `options.location_code → StockMovement` Feature test (P2-1).
