# Gate r2 — parapharmacy remediation spec v2, tenancy/authz/module-gating lens

Date: 2026-09-05. Reviewer: Opus 5 tenancy-authz-reviewer (adversarial, read-only).
Working HEAD `f75aa5023` (source identical to `b9a5565aa`; only docs differ). Spec under review: `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md` v2 (uncommitted).
Scope of this gate: W0 guards (G1 route action-permission ratchet, G2 location-scope census, G7 `_pins_limitation_`), W1 permission design, R2 module gating on every layer, second-of-everything. **W3/W4/W7 reviewed only for false claims about current code.** No code edited, no tests run, no commits.

Every finding below cites a file:line I opened. `CONFIRMED` = read in source at this HEAD. `UNVERIFIED` = stated as such.

---

## BLOCKERS

### BL-1 — R2 (owner ruling) is a no-op for the launch vertical, and the invariant it asserts is violated today on a reachable path. CONFIRMED. Blocks spec.

Spec lines 41, 197, 199, and acceptance row 263.

The spec states the R2 invariant as "`requires_batch_tracking` and vertical alone do not enable the module" (line 197) and "a product's tracking flag or parapharmacy vertical alone grants no entitlement" (line 41). Both halves are wrong against the entitlement model:

- **Vertical alone DOES grant it.** `apps/api/config/verticals.php:344-355` — parapharmacy's `default_modules` contains `'BatchExpiry'` (with the comment "batch/expiry management is a default capability, not an upgrade"), and `product_defaults.requires_batch_tracking = true` at `:342`.
- **There is no disable path.** `apps/api/app/Services/CompanyConfigService.php:71` — `allEnabledModules = array_unique(array_merge($defaultModules, $enabledExtras))`. Extras only ADD. A tenant on parapharmacy can never have BatchExpiry inactive, so the R2 gate is unreachable-by-construction for the actual customer, and acceptance row 263 ("Module disabled, missing config…") is unconstructible in-vertical. The existing test already had to cross verticals to build the fixture: `apps/api/tests/Feature/Security/BatchExpiryModuleAccessControlTest.php:23-29` ("a vertical without it (e.g. retail)").
- **The flag alone DOES enable lot writes today.** `PosCoreReceiptProjection::requiresModule()` returns `null` (`apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:221-224`, docblock `:73` "**Always runs.**"), and the lot arm's only gate is the product flag: `:2026` → `FEFOInventoryService::productRequiresBatchTracking()` (`apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:930-934`, a bare `products.requires_batch_tracking` read).
- **And the flag is settable without the module.** `apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php:226` and `UpdateProductRequest.php:207` accept `requires_batch_tracking` as a plain boolean with no module check; the Product routes are not `module:BatchExpiry` gated (they cannot be). The web form shows the section on `hasModule('BatchExpiry') || hasModule('Inventory')` (`apps/web/src/features/inventory/ProductForm.tsx:173`), and retail has `Inventory` in `default_modules` while BatchExpiry is in neither its `default_modules` nor its `compatible_extras` (`config/verticals.php:137,141-150`).

Failure scenario (live today, not hypothetical): a **retail** tenant — which can never license BatchExpiry — sets `requires_batch_tracking = true` on a product through the normal product form, sells it on the POS, and `PosCoreReceiptProjection` writes `inventory_batch_stock` consumption plus `pos_receipt_line_batch_allocations` rows for an unentitled module. Conversely the parapharmacy customer gets an R2 lane whose central acceptance row can never be exercised on its own vertical, so the lane can ship "R2 done" with zero evidence.

Codex's own r2 flag (fix-round notes line 30) calls `PosCoreReceiptProjection.php:2026` "not evidence of complete entitlement enforcement". That understates it: it is **positive counter-evidence** that the invariant does not hold.

Minimum correction (spec):
1. Replace the entitlement sentence with the real model, cited: `default_modules` are unconditional per vertical, `enabled_extras` are additive-only (`CompanyConfigService.php:71`), resolution is **tenant-level** (see M-3), and BatchExpiry is a parapharmacy default (`verticals.php:355`).
2. Add the product **write path** to the R2 invariant: setting/keeping `requires_batch_tracking` requires the module, or the lot arm must gate on the module independently of the flag.
3. Require the module-off fixture on a vertical that can legitimately lack BatchExpiry (retail), as `BatchExpiryModuleAccessControlTest` already does, and say so in acceptance row 263 — otherwise the row is untestable.
4. Record the existing unentitled-lot-leg cohort (retail/fashion tenants with the flag set) in the W8 census (spec line 302), since spec line 197 promises "existing module-off history is retained".

### BL-2 — W-LOT L1 gates only the backend; the second layer (FE permission) and the role-grant delta are missing, so the new guards produce visible-but-403 surfaces. CONFIRMED. Blocks spec.

Spec lines 207 (L1 disposition: "Enforce recall `batches.recall`, deactivation `batches.delete`, read `batches.view` and traceability `batches.traceability`") and 262 (L1 acceptance row — backend outcomes only).

CONFIRMED current state:
- Backend gap is real: `apps/api/app/Modules/BatchExpiry/Presentation/routes.php:26,29` (DELETE, recall) carry no `can:`; `BatchController::destroy()` at `:176-188` and `::recall()` at `:193-209` contain no permission check.
- **Permissions all exist and are seeded** — `apps/api/database/seeders/RolesAndPermissionsSeeder.php:403-409` (`batches.view/create/update/delete/recall/write-off/traceability`), manager holds all seven at `:631-632`, cashier holds only `batches.view` at `:692`, admin gets everything via `Permission::all()` at `:547`. So no new permission needs adding — good.
- **But roles `viewer` (:705), `technician` (:745), `operator` (:770-801) and `accountant` (:804) hold no `batches.*` permission at all.** Today they can reach every batch read route because the group is module-gated only (`routes.php:12`). The moment `can:batches.view` lands on `GET /batches`, `/batches/expiring`, `/batches/{uuid}/stock`, `/products/{id}/batch-stock`, those roles get 403.
- **The FE will still show the door.** `apps/web/src/components/organisms/Sidebar/Sidebar.tsx:222` gates the Batches entry on `module: 'BatchExpiry'` with **no** `permission:` key — contrast `:223` where `expiryWriteOff` correctly carries `permission: 'batches.write-off'`. In `apps/web/src/features/batches/`, the only permission gate anywhere is `pages/ExpiryWriteOffPage.tsx:61-62` (`hasPermission('batches.write-off')`); `BatchListPage.tsx` / `BatchDetailPage.tsx` render recall and delete actions ungated.

Failure scenario: after L1 ships, an operator clicks a sidebar entry they can see and lands on a 403 page; a cashier sees a Recall button that 403s. This is exactly the silent-403 class CLAUDE rule 12 exists to prevent, and it is a spec omission, not an implementation detail.

Minimum correction (spec): L1 must carry (a) a **role-grant delta table** — which of viewer/technician/operator/accountant keep `batches.view`/`batches.traceability`, with the seeder edit and the **existing-tenant re-seed step** (permissions are per-tenant; staging/prod tenants only pick up grants when `RolesAndPermissionsSeeder` re-runs); (b) the FE half: `permission:` on the Sidebar entry and `hasPermission` gating of the recall/deactivate actions in `features/batches`; (c) acceptance rows on **both** layers — "role lacking `batches.view` sees no Batches entry AND gets 403", not only the backend deny.

---

## MAJOR

### M-1 — G1's detection rule does not match how this repo declares permissions: two false-negative classes at the enumeration step, one unnamed accepted idiom, one non-existent idiom, and no class for deliberately public routes. CONFIRMED. Blocks plan.

Spec line 107.

Measured at this HEAD (script over all module route files, statements joined on `;`): **559 mutating route statements; 205 carry no inline `can:`/`require.any.permission`.** Crude classification of those 205 — the same classification a static ratchet would attempt: ~50 resolve to a FormRequest whose `authorize()` calls `->can()`, ~50 to a controller method containing `->can(`/`Gate::`/`abort(403`, ~28 sit in a file with a group-level `can:`, and **77 have no visible check at all** (including `AuthController@login/register/forgotPassword`, several closures, `CountryDefaults/TemplateController@store|publish|archive`, `Coupon@destroy|revoke`, `Promotion@destroy|activate|pause|archive`, `Channel@store|resync|publish`, `Progression@activate`, `PurchaseHubOrderController@store`, and the two known F2 routes).

Defects in the rule as written:
1. **`permission:` middleware does not exist in this application.** The alias map is `apps/api/bootstrap/app.php:113-121`: `super_admin`, `central_admin`, `central_admin_role`, `validate.location.access`, `module`, `require.any.permission`, `scheduling.captcha`, `cross_tenant`. There is no `permission` alias (Spatie's is not registered). Naming it as an accepted form is a false claim about current code.
2. **`require.any.permission:` — the one permission-bearing alias that DOES exist — is unnamed.** `app/Http/Middleware/RequireAnyPermission.php:15-32`, used at `app/Modules/Company/routes.php:99`. A ratchet that does not know it will flag a correctly-guarded route.
3. **Enumeration by file glob under-covers by design.** The archaeology's wording ("every mutating route under `Modules/*/Presentation/routes.php`") misses `app/Modules/POS/routes.php` (36 unchecked statements), `Identity/routes.php` (20), `Product/routes.php` (18), `Company/routes.php`, `Taxation/routes.php`, `Expense/routes.php`, and misses provider-declared routes entirely — `app/Modules/Import/Providers/ImportServiceProvider.php:93` declares the whole Import surface with a **group-level** `can:imports.manage`, and `Compliance/Providers/ComplianceServiceProvider.php` also declares routes. Spec line 107 says "mutating module routes", which is better, but does not pin the enumeration source.
4. **"a reviewed explicit equivalent" is not machine-decidable**, and it is the bucket the ~50 controller-inline checks fall into. A conditional `->can()` inside a branch (only when a field is present) reads identical to an unconditional one from outside. Without a written classification list, this clause turns the ratchet into a rubber stamp for a third of the surface.
5. **No class for deliberately public routes.** `AuthController@login|register|forgotPassword|resetPassword` must never carry a permission; with no "public, reviewed" bucket they are permanent false positives that train reviewers to waive.

Minimum correction: (a) enumerate from Laravel's **route collection** (`Route::getRoutes()` after boot), never a file glob, so provider-declared and non-`Presentation` route files are covered; (b) enumerate the accepted enforcement points exactly — route-level `can:`, group-inherited `can:`, `require.any.permission:`, a FormRequest whose `authorize()` contains a permission call — and put everything else (controller-inline, service-level, deliberately public) into an **explicit written classification list with a reason per entry**, in the shape of `EXCLUDED_TABLES` in `tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:55+`; (c) the liveness partner must inject a new unchecked `DELETE` route and assert the failure message, mirroring `tests/Architecture/TenantOnlyUniqueRatchetLivenessTest.php:30-45`.

### M-2 — W1's "reauthorize inside mutation orchestration" collides with the resolver's own HTTP-only contract and with two non-HTTP callers of the adjustment service. CONFIRMED. Blocks plan.

Spec lines 123, 127, 129.

`apps/api/app/Modules/Company/Services/LocationScopeResolver.php:12-16` (class docblock): "**HTTP-only** read-scope resolver for `location_ids[]` query params … Requires a bound CompanyContext — backfills/migrations/**queued jobs must derive location directly from data, never via this resolver** (review A10)." `resolve()` at `:31` calls `$this->companyContext->requireCompanyId()` at `:33`.

`RepositoryAdjustmentServiceInterface` is constructor-injected by a **queued listener**: `apps/api/app/Modules/Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php:199` (`final class … implements ShouldQueue`) and `:267`. It is also referenced by `GeneralLedgerService`, `TreasuryMovementService` and `Presentation/Console/NormaliseRepositoriesCommand.php`.

Failure scenario: the implementer reads spec line 127 ("Reauthorize inside mutation orchestration against current persisted source custody, so metadata changes cannot bypass a stale precheck") and puts the location check inside `RepositoryAdjustmentService`. In the queued listener there is no bound `CompanyContext` and no `$user` — `requireCompanyId()` throws, or a `$user->can()` on a null user fails closed. The RD4 variance path (and any future system adjustment) then dies in the worker, silently, exactly the rule-20 no-CompanyContext class.

Minimum correction: state that the **authorization boundary is the HTTP adapter** (controller + FormRequest/intent at `RepositoryAdjustmentController.php:58`), that the service takes an already-authorized intent, and that non-user callers (queued listener, console) travel an explicit system-authority path. Add an acceptance row: "queued shift-variance adjustment with no CompanyContext and no user still books, and is not location-denied."

### M-3 — Worker-side module resolution: the spec claims per-company granularity that does not exist, and ignores a 24-hour cache that makes the revocation acceptance row unpassable. CONFIRMED. Blocks plan.

Spec lines 181, 197, 238 ("explicit tenant/company module resolution"), acceptance row 263 ("revocation with cached data").

- `app/Shared/Contracts/Fiscal/ModuleActivationResolver.php:38-42`: the `$companyId` parameter is contract-only, "Phase 1 implementation does not branch on this — the surface is tenant-level". `app/Modules/Fiscal/Application/Services/DefaultModuleActivationResolver.php:56` literally does `unset($companyId);`.
- `app/Services/CompanyConfigService.php:34,36,52`: `CACHE_TTL_SECONDS = 86400`, key `tenant_config:{tenant_id}`, `GlobalCache::remember(...)`. The class docblock at `DefaultModuleActivationResolver.php:33-36` states: "a live admin module-toggle takes **up to 24h** to propagate via the cache — bust the key explicitly if a faster turnaround is needed." Invalidation is forget-based with a documented in-flight race (`CompanyConfigService.php:96-101`).
- The same surface backs the HTTP gate: `app/Http/Middleware/RequireModule.php:59-64` calls `getConfigForTenant()` → `hasModule()`.

Failure scenario: acceptance row 263 asks for correct behaviour on "revocation with cached data". On the server, revocation is not effective for up to 24h on **both** the HTTP middleware and the projection gate unless the key is busted. A lane can pass that row with a device-cache test and ship a 24h window in which a revoked tenant keeps writing lot legs.

Minimum correction: say "tenant-level module resolution (per-company is not implemented — `DefaultModuleActivationResolver.php:56`; a per-company model is out of scope here)"; require explicit `invalidateForTenant()` on any entitlement change in scope; and make row 263 name the server cache window and its invalidation, not only the device cache.

### M-4 — W2 elevates a NEW sealed `SALE_RECEIPT` version to "recommended transport" with no owner decision row, while the analogous lot decision (D7) stays OPEN. CONFIRMED. Blocks spec.

Spec line 143: "**Recommended W2 transport is resolved `repository_id` plus the necessary binding revision on a new compatible SALE_RECEIPT version**", and "W2 tender versioning is independent of D7's lot evidence choice; R3 forbids iteration 1 **lot** payload changes, not an independently gated W2 tender version."

§2.3 (lines 51-66) declares itself the "**Single open owner-decisions register**" with "all ten rows". A new sealed fiscal payload version is owner-grade under CLAUDE rule 8 (events immutable forever) and carries device-fleet cutover, mixed-version verification and replay cost — the same class of choice as D7, which the spec correctly keeps OPEN. Fable r1's F-A asked the spec to *evaluate* that path (review line 17, "must evaluate the simpler path"); v2 has promoted an evaluation to a recommendation without a row, and the only gate on it is the softer "the execution plan must confirm canonical version/cutover" (line 143).

Minimum correction: add **D8 (OPEN)** — tender destination transport: new sealed `SALE_RECEIPT` vN+1 carrying `repository_id` (precedent `TreasuryAccountPaymentBridge.php:406-418`) **vs** server-side authored binding with no payload change — with the mixed-version/cutover consequence of each. Do not leave a payload-version change as an unrowed recommendation in a register that claims completeness.

### M-5 — `treasury.manage_all_locations` has no catalogue/grant/rollout requirement, and "owner/admin default" names a role that does not exist. CONFIRMED. Blocks plan.

Spec lines 123 ("Propose `treasury.manage_all_locations` as the endpoint bypass, with owner/admin default subject to D5, through the existing role surface") and 129 (seams mention "permission seeder").

- Seeded roles are exactly `admin` (`RolesAndPermissionsSeeder.php:561`), `manager` (:564), `cashier` (:669), `viewer` (:705), `technician` (:745), `operator` (:770), `accountant` (:804). **There is no `owner` role.** (`'owner'` appears only as a protected-name string in `Identity/Presentation/Controllers/RoleController.php:228,277`, and as the unrelated permission `dashboard.owner`.)
- `admin` receives `Permission::all()` at `:547`, so admin picks the new permission up **only after the seeder re-runs in that tenant**.
- Permissions are per-tenant rows; a permission that is not seeded into a tenant makes its `can:` guard a 403 for everyone there.

Failure scenario: the scoping ships before the per-tenant seeder re-run. Every restricted-membership principal who was supposed to bypass is now scoped out of repository reads, transfers and adjustments, with no permission in existence to grant. This is the standard silent-403 ordering trap.

Minimum correction: fix the role wording (`admin`, plus whichever seeded role D5 selects); require the permission to be added to the seeder catalogue **and** granted; and state the rollout order explicitly — seed + grant on every tenant (staging and prod) **before** the scoping predicate is enabled — with an acceptance row for "tenant that has not re-seeded".

### M-6 — G2/W1's location-scope baseline is materially understated, and the census unit must be the route, not the controller. CONFIRMED. Blocks plan.

Spec line 121 ("`CashPositionController.php:75` filters locations; repository list/detail are company-scoped at `PaymentRepositoryController.php:39,58`") and line 108 ("proving one cash-position page is scoped is insufficient"). Both true as far as they go — `PaymentRepositoryController::index()` at `:32-48` and `::show()` at `:50-66` filter on `tenant_id`+`company_id` only, no location predicate. But the baseline reads as "one scoped endpoint exists", and the archaeology says so outright (`root-cause-archaeology.md:13`, "Wave 3 read surfaces … scoped `CashPositionController` only").

CONFIRMED reality — `LocationScopeResolver` is consumed by 15 files: `Accounting/Presentation/Requests/GetCashMovementsRequest.php`, `Accounting/…/ReportsController.php`, `Expense/…/{ExpenseController,ExpenseAnalyticsController,ExpenseExportController}.php`, `BatchExpiry/…/BatchController.php`, `Replenishment/…/ReplenishmentRequestController.php`, `POS/…/{AnalyticsController,ReportController}.php`, `Inventory/…/{StockMovementController,StockLevelController,StockMatrixController}.php`, `Treasury/…/{CashPositionController,MaturingInstrumentsController,PaymentInstrumentController}.php`, `Company/…/LocationController.php`. Two of those are Treasury controllers the spec does not mention.

Worse for the census design: coverage is **partial inside a single controller**. `BatchController` injects the resolver at `:40` but calls it exactly once, at `:252` inside `expired()` (`:237-259`); `expiring()` (`:215-236`) applies no location predicate. A controller-grained census records "BatchController: scoped" and misses a live leak — and this is a W-LOT route, so it lands in the middle of L1's "Batch read/stock/suggestion endpoints must respect authorized location visibility" (spec line 216), which the spec asserts as a requirement with no current-state citation.

Two enforcement points the census must also recognise, or it will emit false "unscoped": the FormRequest path (`Accounting/Presentation/Requests/GetCashMovementsRequest.php` calls the resolver) and the validation rule `app/Rules/ValidLocationAccess.php:69-79`. Note also that the registered alias `validate.location.access` (`bootstrap/app.php:117`) appears in no route file — a dead alias worth recording so the census does not credit it.

Minimum correction: replace the two-endpoint baseline with the measured inventory; make the census unit the **route/method**, not the controller, with the partial-coverage case named; list the accepted enforcement points (resolver in controller, resolver in FormRequest, `ValidLocationAccess` rule); and cite `BatchController.php:215-236` vs `:252` in W-LOT L1 as the concrete batch-read gap.

---

## MINOR

### m-1 — Convention 09's mechanical half is unaddressed for the tables W-LOT and W7 touch. CONFIRMED. Blocks plan.
Spec line 32 and the W-LOT matrix (258) require two companies/locations/lots/re-run — good, and stronger than v1. But convention 09 also requires the catalogue-table classification to stay in sync (`docs/conventions/09-SECOND-OF-EVERYTHING.md:34-35`). `product_batches` is a code-keyed, operator-edited-per-company table (batch_number, `database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:25`) and is **absent** from `CATALOGUE_TABLES` (`tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:30-53`). W5's proposed lot-observation child table (spec line 232) and W7's proposed `pos_session_reconciliations` (line 279) will need the same classification. Good news, verified: `product_batches` uniques already carry `company_id` (`database/migrations/tenant/2026_06_02_100008_add_variant_id_to_product_batches.php:27-31`), so there is no existing debt to shrink — this is a one-line classification requirement, not a remediation.

### m-2 — The ratchet the spec patterns G1 on is PG-gated; a route ratchet must not inherit that skip. CONFIRMED. Blocks plan.
Spec lines 107 and 332 cite `TenantOnlyUniqueOnCatalogueTablesRatchetTest` without a path; it lives at `apps/api/tests/Architecture/`, and its liveness partner skips off PostgreSQL (`tests/Architecture/TenantOnlyUniqueRatchetLivenessTest.php:22-27`, "PG-only ratchet — gated by the backend-test-pgsql lane"). A route/permission ratchet has no schema dependency and must run in the default lane; copying the pattern wholesale would make it silently never execute. Say so, and cite the path.

### m-3 — W3 inverts a documented deliberate design without naming the operational consequence. CONFIRMED. Blocks spec wording only.
Spec line 161 ("Choose synchronous posting inside the document transaction"). The current comment states the intent being reversed: `apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:723-726` — "after transaction commits **to prevent listener failures from rolling back the fiscal chain**". W0/G5 frames this as re-ratification, which is right; the acceptance (line 173) should also state the new failure mode in business terms: after W3, an account-purpose/period/chart misconfiguration **blocks sealing the invoice** rather than sealing it without GL. That is the intended trade, but it must be written down, since it changes what an operator sees on a bad chart.

### m-4 — `_pins_limitation_` is a brand-new marker with no home. CONFIRMED. Blocks plan.
Zero occurrences of `_pins_limitation_`, `@limitation` or any equivalent exist in `apps/api/tests/` or `apps/api/app/` today. G7 (spec line 113) is sound, but the spec should require registering the marker in `docs/conventions/` when the first lane lands it — otherwise the grep-ability that is the entire point depends on tribal knowledge. (No second-surface conflict: nothing to reconcile.)

---

## Answers to the gate questions

**Is F-E closed?** *Partially.* Closed: the narrowing to recall/destroy/read (spec 119, 207, 216) with the FormRequest counter-evidence preserved and correctly cited — CONFIRMED `CreateBatchRequest.php:22`, `UpdateBatchRequest.php:13`, `TransferBatchStockRequest.php:21` all contain `authorize(): return $this->user()?->can('batches.*')`; and the repository-adjustments route is now in the baseline, seams and acceptance (121, 129, 262) at the right line — CONFIRMED `Treasury/Presentation/routes.php:98`, `can:treasury.adjust`, and manager does hold `treasury.adjust` (`RolesAndPermissionsSeeder.php:591`), so F-E's reachability claim stands. **Not closed:** the second layer and the role-grant delta (BL-2), and the batch-read location gap is asserted without its current-state citation (M-6).

**Is any owner decision silently made?** RD2 is **correctly still OPEN** — spec line 64 keeps it, and line 216 describes rather than decides ("a role carrying `batches.recall` does until RD2 changes it"), matching the seeded reality (`RolesAndPermissionsSeeder.php:407` seeds it, `:632` grants it to manager). RD3/RD4/D1-D7 likewise remain OPEN with recommendations, not rulings. **One decision is made without a row:** the new sealed `SALE_RECEIPT` version for the tender binding (M-4).

**Would the W0 ratchet as written produce false negatives or false positives?** Both, at scale — see M-1. False negatives: whole route files and provider-declared surfaces missed by the archaeology's glob (POS 36, Identity 20, Product 18 unchecked mutating statements; Import declares its routes in `ImportServiceProvider.php:93`); `require.any.permission:` unrecognised. False positives: public auth routes with no "deliberately public" class; ~50 controller-inline checks that only a human can call equivalent, funnelled into the unfalsifiable "reviewed explicit equivalent".

**Does the module gate reach workers/projections in v2, or only HTTP?** v2 does require the worker layer in words (lines 181, 197, 238) — that is a genuine improvement over v1 and over the handover. But the mechanism it names does not exist at the stated granularity (tenant-level only, `DefaultModuleActivationResolver.php:56`), has a 24h staleness window (`CompanyConfigService.php:34`), and the one worker the spec inspects is currently ungated for lot work (`PosCoreReceiptProjection.php:221-224` + `:2026`). See BL-1 and M-3. The device layer is correctly anchored (`apps/pos/src/stores/productStore.ts:116` `hasModule`, test at `apps/pos/src/stores/__tests__/productStore.hasModule.test.ts:5-32`) and the spec is right that the helper test alone proves nothing (line 238).

**Any false claim about current code?** Four: (1) "vertical alone grants no entitlement" (BL-1); (2) `permission:` middleware as an accepted existing idiom (M-1 — no such alias); (3) "owner/admin default … through the existing role surface" (M-5 — no `owner` role); (4) "tenant/company module resolution" (M-3 — company is `unset()`). Everything else I spot-checked is accurate.

---

## Rejected false positives — verified, do not "fix"

- **`Tenant::find()` inside the worker-side module resolver is central-safe.** `DefaultModuleActivationResolver.php:60` calls `Tenant::find()` on a swapped default connection, which under db-per-tenant would normally be a cross-DB defect. It is not: `App\Modules\Tenant\Domain\Tenant` extends `Stancl\Tenancy\Database\Models\Tenant` (`Tenant.php:62`), which uses `Concerns\CentralConnection` (`vendor/stancl/tenancy/src/Database/Models/Tenant.php:24`), whose `getConnectionName()` returns `config('tenancy.database.central_connection')`. Correct as written.
- **BatchExpiry's route group is rule-12 compliant.** `BatchExpiry/Presentation/routes.php:12` — `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'module:BatchExpiry']`. The F2 defect is per-route permissions only, not the group.
- **No new permission needs seeding for W-LOT L1.** All seven `batches.*` permissions already exist (`RolesAndPermissionsSeeder.php:403-409`). The exposure is grants and re-seed, not catalogue (BL-2).
- **`product_batches` unique keys already carry `company_id`** (`2026_06_02_100008_add_variant_id_to_product_batches.php:27-31`). No second-company blocker on the lot table itself.
- **Treasury mutating routes all carry `can:`** (`Treasury/Presentation/routes.php:88-104`). F-E's adjustments finding is about location scope, not a missing permission — v2 states this correctly.
- **The W4/W7 anchors I spot-checked are accurate**: registry fail-closed exclusion (`FiscalEventProjectionRegistry.php:240-272`), Treasury bridge `requiresModule() = 'Treasury'` (`TreasuryReceiptBridge.php:214-221`), retry predicate = dead-lettered OR pending with `attempts >= EXHAUSTED_ATTEMPTS` (`RetryFiscalProjectionsCommand.php:299-307`), schedule at `routes/console.php:57`, shift-variance listener disabled for exactly the stated reason (`PostShiftCashVarianceAdjustment.php:50-56`), `ShiftExpectedCashService` as the single derivation with its own legacy caveat (`:27-40`), resolver noncash fallthrough (`TenderRepositoryResolver.php:152-162`).

## Preserve in the next revision

Sections 3.1/3.2 unchanged; W0's "a guard closes its mechanism only when its own liveness check fails on a deliberately introduced violation" (line 103) — that is the right bar and matches `TenantOnlyUniqueRatchetLivenessTest`; the W-LOT consolidation and its ordered L1-L8 table with per-gap current-code anchors; the honest `system_fefo_estimate` / `operator_captured` / `unknown` vocabulary and its glossary registration; W4's precise restatement of the seeding hole; W7's separation of the fiscal-derived comparison from the RD4-gated repository check; the two-company/two-location/two-lot/re-run requirement on the W-LOT matrix; keeping all ten decisions OPEN.

## What to fix before merge

Rewrite R2 against the real entitlement model (parapharmacy default + additive-only extras + ungated product flag + ungated projection lot arm) and give L1 its second layer and role-grant delta; then correct G1's enforcement-point taxonomy, W1's non-HTTP authorization boundary, the tenant-level/24h module resolution wording, the missing D8, and the `owner`-role/seeding rollout.

VERDICT: CHANGES-REQUIRED
