# Gate r1 — Lane F1 (F-BUG-1) import location / default-location code — tenancy+authz lens

**VERDICT: CHANGES — 1× P1 (blocking), 3× P2, 4× P3.**

Reviewer: tenancy-authz-reviewer (adversarial, code-grounded). Branch `fix/f-bug-1-import-location` @ `afd2aaf71`, base `dev` @ `a4ceeb0f5`, worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/f-bug-1`.
Scope reviewed: `git diff dev...HEAD` (17 files). Every claim below was read in the file cited.

Fix before merge (one line): guard the nullable `locations.code` on the web side (`ImportWizardPage.tsx:282`) — the diff's own migration deliberately produces the null-code rows that crash it — and move the wizard's location list off the `module:Inventory`-gated `GET /locations`.

---

## Verified-good (checked, not assumed)

- **Migration is collision-safe against the real index.** The partial unique index is `CREATE UNIQUE INDEX idx_locations_company_code ON locations (company_id, code) WHERE code IS NOT NULL` (`apps/api/database/migrations/tenant/2025_11_30_105000_create_locations_table.php:68`). The backfill's pre-check (`2026_08_29_100000_backfill_default_location_code_f1.php:36-41`) is scoped `where('company_id', …)->where('id','!=',…)->where('code','MAIN')`, i.e. exactly the index's scope. **Multi-company tenants are safe**: two companies each getting `MAIN` cannot collide because the index is per `company_id`.
- **Two code-less defaults in the SAME company** (no `(company_id, is_default)` unique constraint exists — checked `2025_11_30_105000:23-66`) do not blow up: the collision check re-queries the DB inside the loop, so the second row sees the `MAIN` just written and takes the census branch. `cursor()` on pgsql buffers client-side, so mutating during iteration is safe.
- **Old tenant shapes guarded**: `Schema::hasTable('locations')` + per-column `hasColumn` early-return (`…f1.php:14-21`).
- **`echo` in a tenant migration is established precedent**, with the same rationale comments in `2026_08_26_100100_null_invented_default_lot_expiries.php:228-233`, `2026_08_27_100000_census_cash_tender_invariant_violations.php:219-224`, `2026_08_28_100000_enforce_company_scoped_payment_method_codes.php:178`. `tenants:migrate` streams stdout per tenant; the census lines from those siblings are visible in this branch's own PHPUnit output. No output-handling break.
- **`down()` no-op is correct** (`…f1.php:61-64`) — a data backfill; reverting would erase legitimate codes.
- **The tenant migration cannot touch the central DB**: `config/tenancy.php:195-199` pins `--path => database_path('migrations/tenant')` for `tenants:migrate` only, and `AppServiceProvider::loadTenantMigrationsInTestingEnvironment()` (`:234-240`) registers that path **only** when `environment('testing')`. Central `migrate` never sees it.
- **(b) No tenant/company scoping regression in `CompanyController::store`.** `Location::create` (`CompanyController.php:123-140`) writes `company_id => $company->id` for a company just created with `tenant_id` taken from the authenticated user (`:73, :83`), inside the request's already-swapped tenant connection. A second company created by the same user cannot collide — index is `(company_id, code)`. All four app-level `Location::create` sites now set a code (`TenantProvisioningService.php:165`, `AuthController.php:432`, `LocationController.php:197` auto-generates `LOC-%03d`, `CompanyController.php:126`). No `Company::created` listener creates a location (`EventServiceProvider.php:69-72`), so no double-MAIN insert.
- **(c) `findIdByCode` IS company-scoped**: `LocationService.php:22-27` → `Location::where('company_id', $companyId)->where('code', $code)`. No cross-company resolution within a tenant. The async path passes company + tenant explicitly (`ImportController.php:562` → `ProcessImportJob::dispatch($job->id, $companyId, $tenantId)`), so the queued worker re-establishes tenant context rather than running tenant-blind. No `getScale()`-without-currency introduced.
- **(d) `useLocations` uses `tenantScopedKey`** — `apps/web/src/features/locations/hooks/useLocations.ts:27`.
- **(e) Route middleware untouched.** No `routes.php` / provider file appears in the diff. Import routes remain `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class,'can:imports.manage']` (`ImportServiceProvider.php:67`). No new `can:` guard ⇒ no seeder sync required for this lane.
- **No money/quantity float** introduced anywhere in the diff.
- **Gates run:** `phpunit tests/Feature/Company/Migrations/BackfillDefaultLocationCodeF1MigrationTest.php` → OK (2/13); `tests/Feature/Company/CreateCompanyTest.php` → OK (15/80); `tests/Feature/Import/ProductsImportPipelineTest.php` → OK (25/243); `phpstan` on the 3 touched PHP files → No errors; `pnpm vitest run src/features/import` → 10 files / 55 tests pass; `pnpm typecheck` → clean.

---

## Findings

### [P1] `apps/web/src/features/import/pages/ImportWizardPage.tsx:282` (also `:159, :283, :288, :289`) — `location.code.trim()` dereferences a NULLABLE field; the diff's own migration guarantees such rows exist

`locations.code` is `->nullable()` (`apps/api/database/migrations/tenant/2025_11_30_105000_create_locations_table.php:30`). It is emitted raw — `'code' => $this->code` (`apps/api/app/Modules/Company/Presentation/Resources/LocationResource.php:26`) — and passed straight through the client mapper — `code: raw.code` (`apps/web/src/features/locations/api/locations.ts:52`) — into a type that *declares* it non-null: `code: string` (`apps/web/src/features/locations/types.ts:12`, `RawLocation` at `locations.ts:10`). There is no zod/runtime validation. So `tsc` cannot see the lie and the new code is the first consumer in this feature to call a method on it.

The offending memo is **not** behind the options step or the `products` type:

```ts
// ImportWizardPage.tsx:281-283
const stockLocationCode = useMemo(() => {
  const codedLocations = locations.filter((location) => location.code.trim() !== '')
```

It executes on **every render of the page, for every import type, at every step** (the hook is called unconditionally at `:263`).

**Concrete failure scenario.** `…f1.php:41-49` — the collision branch — deliberately leaves `code` NULL and only prints a census line. A company that already has a non-default location coded `MAIN` therefore keeps a `code = NULL` default *by design of this very lane*. The next operator to open `/imports/products/wizard` in that company gets `TypeError: Cannot read properties of null (reading 'trim')` during render; the nearest boundary is the **application root** (`apps/web/src/App.tsx:26`), so the entire SPA shell is replaced by the error screen — not just the wizard. The same happens fleet-wide in the deploy window between the web bundle reaching browsers and `tenants:migrate` finishing every tenant DB, i.e. precisely while the team is manually testing F-BUG-1 on staging.

The lane's own test *claims* to cover this case but uses the wrong representation: `ImportWizardPage.options.test.tsx` ("lists a code-less location as disabled") mocks `{ code: '' }`, never `null`. The guard the test pins is the one shape that cannot occur from `LocationResource`.

**Fix:** normalise at the boundary — `RawLocation.code: string | null` and `code: raw.code ?? ''` in `mapLocation` (`locations.ts:46-67`), which also de-risks the identical unguarded `loc.code.toLowerCase()` at `LocationSelectorMulti.tsx:91`. Add a test case with `code: null`.

### [P2] `apps/api/app/Modules/Inventory/Presentation/routes.php:31,33-35` vs `ImportServiceProvider.php:67` — the wizard's new location list hangs off a `module:Inventory`-gated route; on two verticals it 403s and the fix silently no-ops

`GET /api/v1/locations` — the endpoint behind `useLocations()` — sits in a group middlewared `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class,'module:Inventory']` and additionally `->middleware('can:inventory.view')`. The import wizard is gated only on `can:imports.manage` and is **not** module-gated.

`config/verticals.php` lists `Inventory` in `compatible_extras` — i.e. NOT in `default_modules` — for `restaurant` (`:75`) and `coffee_shop` (`:107`). On such a tenant `RequireModule::handle()` aborts 403 (`apps/api/app/Http/Middleware/RequireModule.php:62-64`).

The web side swallows it: `const { data: locations = [] } = useLocations()` (`ImportWizardPage.tsx:263`) discards `isError`. Result chain: empty list → `stockLocationCode` resolves to `''` (`:288-290`) → the PATCH omits `location_code` (`:572-574`, correctly refusing to send an empty string) → the import completes with `location_unresolved` warnings and **no opening stock** — the exact F-BUG-1 symptom, now with a UI control that looks functional and offers nothing. Same outcome for any future role holding `imports.manage` without `inventory.view` (today only `admin` holds `imports.manage` — `RolesAndPermissionsSeeder.php:532` is in the catalog list and `createRoles()` at `:544` gives `admin` `Permission::all()`; manager has `inventory.view` at `:577` but not `imports.manage`).

**Fix:** read from `GET /api/v1/company/locations` (`apps/api/app/Modules/Company/routes.php:95-96` — `scopedIndex`, no module gate, no extra permission, same `LocationScopeResolver`; client already exists at `apps/web/src/features/locations/api/scopedLocations.ts:25`), and surface `isError` in the stock section instead of rendering an empty select.

### [P2] `apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:692` — `warningStats()` replaces a COUNT with full hydration of every warning row, on a paginated list endpoint

```php
foreach ($job->rows()->whereNotNull('warnings')->get(['warnings']) as $row) {
```

Previously this was a single aggregate per job (`jsonb_array_length(warnings) > 0` count / sqlite equivalent). It is now N ImportRow models per job. `formatJob()` is called from ten sites, including `index()` which maps it over a **20-job page** (`:65`, `paginate(20)` at `:62`) and from every wizard poll of `show`/`preview`/`execute`. A tenant that ran a few 10k-row product imports with warnings on most rows hydrates hundreds of thousands of jsonb rows in one dashboard request. Memory/latency regression on the tenant-#1 onboarding path.

**Fix:** aggregate in SQL on pgsql (`jsonb_array_elements(warnings) ->> 'code'`, `GROUP BY`) keeping the PHP loop for sqlite; or persist the summary once at completion; at minimum `->toBase()->pluck('warnings')` + `chunkById`.

### [P2] `apps/api/database/migrations/tenant/2026_08_29_100000_backfill_default_location_code_f1.php:50-58` — the backfill does not touch `updated_at`

`DB::table(...)->update(['code' => 'MAIN'])` bypasses Eloquent, so the row's `updated_at` stays at its original value while `code` changes. Any consumer that delta-syncs locations by `updated_at` will never see the new code. I did not audit every consumer, so I flag the fact, not a proven downstream break — but the sibling census migrations that mutate rows are worth diffing for the same omission. Add `'updated_at' => now()` (it does not break the idempotency assertion in the test, which re-runs against rows that no longer match the `code IS NULL OR ''` predicate).

### [P3] `…f1.php:1-12` — no header docblock

Every sibling census/backfill tenant migration in this repo carries a WHAT-IS-BROKEN / WHY-THIS-SHAPE / DEPLOY-NOTE header (`2026_08_26_100000_backfill_payment_repository_location_n12.php:11-80`, `2026_08_27_100000…:219-224`). This one has a single inline comment. Given it auto-runs fleet-wide on push to `origin/dev` and has a silent census branch an operator must act on, it should say so in the file.

### [P3] `apps/api/app/Modules/Company/Presentation/Controllers/LocationController.php:85-90` — the wizard offers inactive locations

`index()` filters by `company_id` and the user's location scope but not `is_active` (contrast `transactionIndex()` at `:65-67`, which does). The new stock-location select therefore lets an operator post opening stock into a deactivated location.

### [P3] Import jobs are tenant-scoped, never company-scoped — the option is captured under one company and re-resolved under another

`import_jobs` has no `company_id` column (`2025_11_30_150000_create_import_tables.php:14-33`) and every lookup is `ImportJob::where('tenant_id', $tenantId)->where('id', $id)` (`ImportController.php:60-62, 365-368, 462-464`). `updateOptions` stores `location_code` under the company bound at PATCH time; `execute` resolves it under the company bound at execute time (`:458` → `ProductOpeningStockPhase::resolveLocationId()` `:243-255`). Pre-existing and internally consistent (the products land in the same company), but note the behavioural change this lane introduces: **before**, a `MAIN` chosen in company A failed loudly (`location_unresolved`) in a company B whose default had no code; **after**, every company has a location literally coded `MAIN`, so it now resolves silently. Relevant to Lane F2 (company switch) — worth a `company_id` on `import_jobs` there.

### [P3] `ImportController.php:697-705` — per-element shape is unguarded

`$warning['code']` is read without checking the element is an array. The only writer is `ImportService::addRowWarning()` (`:93-97`), which always writes `['code' => …, 'detail' => …]`, so this is safe today and PHPStan is green off the `@property list<array{code: string, detail: string}>` docblock (`ImportRow.php:19`). But a legacy/hand-edited row holding a scalar element would fatal (`Cannot access offset … on string`) inside a formatter that runs on the index endpoint. One `is_array($warning) && isset($warning['code']) && is_string(...)` guard makes the reporting path unkillable. The `$code === ''` check at `:701` is currently unreachable.

---

## Test-quality notes

- `BackfillDefaultLocationCodeF1MigrationTest` is a real pin: it asserts the census line reaches **stdout** (`ob_start()` around `up()`) *and* the log listener, asserts the collision row stays NULL, and proves idempotency by comparing `updated_at` across two runs. Good. Caveat: `phpunit.xml:44-45` runs on `sqlite::memory:`, so the "no unique violation" property is only exercised against SQLite's partial index, not PostgreSQL's — acceptable here because the guard is a pre-check in PHP, not a DB-behaviour dependency.
- `ProductsImportPipelineTest::test_products_import_job_summarizes_unresolved_location_warnings` hits the real endpoints with a real file and asserts `warning_summary.location_unresolved` — real behaviour, not a mock.
- The web "code-less location" test asserts against `code: ''`, a shape the API never emits; see P1.
- No authz deny-path test was added, and none is required: the diff introduces no new `can:`/`module:` guard.
