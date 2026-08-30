# Unit scoping — tenant vs company (research, read-only)

**Date:** 2026-08-29
**Trigger:** owner hunch that a unit should be COMPANY-scoped (derivable from the tenant as a default), plus the hard invariant "an import must never run against an empty unit set with no error surfaced".
**Status:** research only. No code changed, no tests run. Every claim below is cited `path:line`.
**Paths are relative to** `/Users/houssamr/Projects/syneriva/apps/erp/`.

---

## 0. Executive answer to the owner's two questions

| Question | Answer |
|---|---|
| Are units company-scoped today? | **No.** They are tenant-scoped *by column* and in practice **tenant-database-global** — every seeded row is written with `tenant_id = NULL` (`apps/api/database/seeders/UomSeeder.php:16-259` never sets `tenant_id`), and the read path unions `tenant_id IS NULL OR tenant_id = <tenant>` (`apps/api/app/Modules/Uom/Presentation/Controllers/UomController.php:75-79`). |
| If a user creates a 2nd company inside a tenant today, does that company have units? | **Yes — trivially.** `CompanyController::store` never seeds units (`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:69-187`), but it does not need to: the tenant DB already holds the 19 shared rows and they are visible to every company of the tenant. The "2nd company has no units" failure mode **does not exist today**. |
| Does the import fail loudly when units are missing? | **The question does not arise — and that is the real defect.** The products import never resolves or writes `products.unit_id` at all. It copies the cell verbatim into the legacy free-text `products.unit` string (`apps/api/app/Modules/Product/Application/Services/ProductService.php:73`), so an empty unit catalogue, a misspelled unit and a correct unit are **indistinguishable outcomes**. See §3.3. |

The spec's §4.13 statement ("units are TENANT-scoped, `unique(['tenant_id','code'])`, 19 seeded units, no company default unit") is **verified correct** on all four counts. This research adds the company-scoping dimension the spec did not analyse, and finds two latent defects the spec did not name (§1.4, §3.5).

---

## 1. Units data model

### 1.1 Tables

**`unit_categories`** — `apps/api/database/migrations/tenant/2026_01_09_095018_create_unit_categories_table.php`
- `id` uuid PK (`:14`), `tenant_id` uuid **nullable** (`:15`), `code` varchar(50) (`:17`), `name` (`:18`), `description` (`:19`), `base_unit_id` uuid nullable (`:21`), `is_system` bool (`:23`), `is_active` bool (`:24`), timestamps (`:26`).
- Original `unique(['tenant_id','code'])` (`:30`) — **replaced** by two PostgreSQL partial unique indexes in `apps/api/database/migrations/tenant/2026_04_28_120000_fix_unit_categories_partial_unique.php:67-77`:
  - `unit_categories_system_code_unique ON (code) WHERE tenant_id IS NULL`
  - `unit_categories_tenant_code_unique ON (tenant_id, code) WHERE tenant_id IS NOT NULL`
  The migration's own docblock states the reason plainly: "PG treats each NULL as distinct in a unique index, meaning system-level rows (`tenant_id IS NULL`) can have duplicate codes" (`:12-15`).
- `base_unit_id` FK added after `units` exists — `2026_01_09_095045_create_units_table.php:43-45` (`nullOnDelete`). This is a **cycle**: `unit_categories.base_unit_id → units.id` and `units.category_id → unit_categories.id`.

**`units`** — `apps/api/database/migrations/tenant/2026_01_09_095045_create_units_table.php`
- `id` uuid PK (`:14`), `tenant_id` uuid **nullable** (`:15`), `category_id` FK → `unit_categories` cascadeOnDelete (`:16`), `code` varchar(20) indexed (`:18`), `name` varchar(100) (`:19`), `symbol` varchar(10) (`:20`), `conversion_factor` decimal(20,10) default 1 (`:25`), `decimal_places` int default 2 (`:28`), `rounding_method` varchar(20) default `half_up` (`:29`), `is_base_unit` (`:31`), `is_system` (`:32`), `is_active` (`:33`), timestamps (`:35`).
- `unique(['tenant_id','code'])` (`:37`), `index(['category_id','is_active'])` (`:38`), `index(['tenant_id','is_active'])` (`:39`).
- **No `company_id` column. No soft deletes** — "delete" is `is_active = false` (`UomController.php:247`).
- Later migrations touching units: only the two above plus the N-9 backfill (`2026_08_26_100000_seed_base_units_for_unit_less_tenants.php`). Full list: `ls apps/api/database/migrations/tenant | grep -i unit` → 8 files, of which `2026_01_09_095108_add_unit_id_to_products_table.php`, `2026_01_09_100552_map_product_units_to_uom_ids.php`, `2026_03_22_130333_add_unit_cost_to_pos_receipt_lines_table.php` and `2026_06_27_100000_add_accrual_unit_cost_to_document_lines.php` are consumer/unrelated.

### 1.2 Models

- `apps/api/app/Modules/Uom/Domain/Entities/Unit.php` — `HasUuids`, fillable includes `tenant_id` (`:43-55`), casts `conversion_factor` to **string** (`:63`) per the precision contract, `rounding_method` to the `RoundingMethod` enum (`:65`). Scopes: `active` (`:88`), `forCategory` (`:98`), `system` (`:108`). No global tenant/company scope.
- `apps/api/app/Modules/Uom/Domain/Entities/UnitCategory.php` — `baseUnit()` (`:68-71`), `units()` (`:74-77`), `activeUnits()` (`:80-83`).
- DTO `apps/api/app/Modules/Uom/Application/DTOs/UnitData.php:14-27` — emits `id, categoryId, code, name, symbol, conversionFactor, decimalPlaces, roundingMethod, isBaseUnit, isSystem, isActive`. **`tenant_id` is deliberately not emitted**, so the client cannot today tell a shared unit from a tenant-authored one.

### 1.3 API surface

`apps/api/app/Modules/Uom/Presentation/routes.php:10-23` — middleware `['api','auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]` (compliant with rule 12; no `module:` gate). Endpoints: `GET uom/categories`, `GET uom/units`, `GET uom/units/{id}`, `POST uom/units`, `PUT uom/units/{id}`, `DELETE uom/units/{id}`, `POST uom/convert`.

Scoping is done **per action, by hand**:
- `indexCategories` / `indexUnits` filter `whereNull('tenant_id')->orWhere('tenant_id', $tenantId)` where `$tenantId = $this->companyContext->requireCompany()->tenant_id` (`UomController.php:40-46`, `:72-79`). **The CompanyContext is consulted only to reach the tenant id — the company id is discarded.**
- `showUnit` (`:107`), `updateUnit` (`:163`), `destroyUnit` (`:217`), `convert` (`:264-266`) use a **bare `findOrFail($id)`** with no tenant filter at all. Safe only because of database-per-tenant.
- `storeUnit` writes `tenant_id = <tenant>` (`:131`), `is_system = false` (`:140`) — so operator-authored units ARE tenant-stamped, unlike the seeded ones.
- Permission team is the **tenant**, not the company: `setPermissionsTeamId($user->tenant_id)` (`apps/api/app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php:28`). So `uom.view/create/edit/delete` are tenant-level abilities.

**FormRequest gap:** `CreateUnitRequest.php:23-31` has **no uniqueness rule on `code`** (only `max:20`), and `UpdateUnitRequest.php:24-30` likewise. A duplicate code is caught (if at all) by the DB constraint as a 500, not as a 422.

### 1.4 Latent defect — `units` never got the partial-unique repair that `unit_categories` got

`2026_04_28_120000_fix_unit_categories_partial_unique.php` fixed **only** `unit_categories`. `grep -rn "units" apps/api/database/migrations/tenant/*.php | grep -iE "unique|index"` returns **no** unique-index migration for `units`. So `units.unique(['tenant_id','code'])` is still the NULL-distinct shape, and every seeded row has `tenant_id = NULL` → **two `units` rows with `code = 'kg'` and `tenant_id = NULL` are legal in PostgreSQL today.** In practice nothing writes them (the seeder is guarded, §2.2), but any future path that seeds twice will corrupt resolution silently rather than erroring. Any rescope work should fix this in the same migration.

### 1.5 Tables referencing a unit — **6 FK columns across 6 tables**

| Table | Column | FK behaviour | Company reachable via | Citation |
|---|---|---|---|---|
| `products` | `unit_id` | `constrained('units')` (no cascade clause) | **direct** `products.company_id` | `2026_01_09_095108_add_unit_id_to_products_table.php:15`; company_id added `2025_11_30_130000_add_company_id_to_existing_tables.php:49-54`, made NOT NULL `2025_11_30_134000_make_company_id_required.php:30` |
| `composite_items` | `stock_unit_id` | `nullOnDelete` | **direct** `company_id` | `2026_02_19_100001_create_composite_items_table.php:16,26` |
| `recipes` | `yield_unit_id` | `nullOnDelete` | **1 hop** → `composite_items.company_id` | `2026_02_19_100002_create_recipes_table.php:16,21` (no company_id on the table) |
| `recipe_lines` | `unit_id` | `nullOnDelete` | **2 hops** → `recipes` → `composite_items` | `2026_02_19_100003_create_recipe_lines_table.php:15,19` |
| `modifiers` | `component_unit_id` | `nullOnDelete` | **1 hop** → `modifier_groups.company_id` | `2026_02_19_100006_create_modifiers_table.php:15,22`; parent `2026_02_19_100005_create_modifier_groups_table.php:16` |
| `workshop_service_bundle_components` | `unit_id` | **`restrictOnDelete`, NOT NULL** | **1 hop** → `workshop_service_bundles.company_id` | `2026_04_19_110002_create_workshop_service_bundle_components_table.php:26`; parent `2026_04_19_110001_create_workshop_service_bundles_table.php:17` |

Three of the six (`recipes`, `recipe_lines`, `modifiers`) carry **no company column of their own** — a per-company re-point must join upward. `workshop_service_bundle_components.unit_id` is **NOT NULL + restrictOnDelete**, so it is the one column that cannot be nulled out as an escape hatch.

Eloquent mirrors: `Product.php:367`, `CompositeItem.php:204`, `Recipe.php:89`, `RecipeLine.php:132`, `Modifier.php:88`, `ServiceBundleComponent.php:130`.

---

## 2. Seeding

### 2.1 `UomSeeder` — the 19 units, 5 categories

`apps/api/database/seeders/UomSeeder.php`. Every `create()` omits `tenant_id` → all rows land with `tenant_id = NULL` and `is_system = true`.

| Category (`code`) | Units — `code` / name / symbol / `decimal_places` (base unit **bold**) | Citation |
|---|---|---|
| `weight` | **`g`** Gram `g` 2 · `mg` Milligram `mg` 0 · `kg` Kilogram `kg` 3 · `oz` Ounce `oz` 2 · `lb` Pound `lb` 2 | `:16-75` |
| `volume` | **`ml`** Milliliter `mL` 0 · `cl` Centiliter `cL` 1 · `l` Liter `L` 3 · `floz` Fluid Ounce `fl oz` 2 | `:78-127` |
| `length` | **`mm`** Millimeter `mm` 0 · `cm` Centimeter `cm` 1 · `m` Meter `m` 2 · `in` Inch `in` 2 | `:130-179` |
| `pieces` | **`pc`** Piece `pcs` 0 · `pair` Pair `pair` 0 · `doz` Dozen `doz` 0 | `:182-221` |
| `time` | **`min`** Minute `min` 0 · `hr` Hour `hr` 2 · `day` Day `day` 2 | `:224-263` |

**19 units, 5 categories, 5 base units** (`g`, `ml`, `mm`, `pc`, `min`). Codes are **lowercase**.

`UomSeeder` finishes by calling `(new UnitSeeder)->run()` (`:268`), which is **update-only** and corrects `decimal_places` from the migration default 2 to a canonical per-code value, matching **case-insensitively** because "UomSeeder stores lowercase codes while the canonical map is uppercase" (`apps/api/database/seeders/UnitSeeder.php:11-34`, map at `:48-60`, guard constant `MIGRATION_DEFAULT_DECIMAL_PLACES = 2` at `:41`).

### 2.2 `TenantInitializationService::seedUnitsOfMeasure()` — registration only, once per tenant

`apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php`
- Called from `seedReferenceData()` (`:238`), which is step 2.5 of `initializeForNewRegistration()` (`:83`).
- `initializeForNewRegistration(Tenant, Company, User)` runs **only on the registration path** — "Called inside the registration transaction after: Tenant is created, User is created, Company is created, UserCompanyMembership is created" (`:46-53`).
- The seeder call is **self-guarding**: `if (DB::table('units')->exists() || DB::table('unit_categories')->exists()) return;` (`:261-263`), because "UomSeeder writes with bare `create()` … so a second run would collide" (`:251-257`).

**So: once per tenant, at registration. Never per company.**

### 2.3 N-9 backfill migration

`apps/api/database/migrations/tenant/2026_08_26_100000_seed_base_units_for_unit_less_tenants.php`
- Guards: tables exist (`:68-70`); **a `companies` row exists** — "A database with no `companies` row is … a migration target that has not been provisioned yet" (`:39-51`, code `:72-74`); **both** `units` and `unit_categories` empty (`:76-78`). Then `(new UomSeeder)->run()` (`:80`).
- `down()` is a deliberate no-op (`:83-86`).
- It documents the incident it repairs: "a tenant created through `POST /auth/register` … `units 0`" (`:14-18`).
- Note the guard is **tenant-database-wide**, not per company — consistent with the current scoping, and it would need rewriting under option (a).

### 2.4 The second-company path seeds everything **except** units

`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:69-187` (`POST /companies`), inside one `DB::transaction`:

| Step | What it seeds | Line |
|---|---|---|
| 1 | `Company::create` | `:83-120` |
| 2 | default `Location` "Main Location" — `pos_enabled: true`, **no `code`** | `:123-139` |
| 3 | `UserCompanyMembership` (Owner) | `:142-149` |
| 4 | genesis `CompanyHashChain` per `HashChainType` | `:152-164` |
| 5 | `chartOfAccountsService->seedForCompany($company)` | `:167` |
| 5.25 | `paymentMethodSeeder->run($company)` — "Give additional companies the same country defaults as the tenant's first company" | `:169-172` |
| 5.5 | `expenseCategoryProvisioning->provisionForCompany($company)` — added by gate finding I-2 after the same class of miss | `:174-181` |
| 6 | `companyTaxProvisioning->provisionForCompany($company)` | `:184` |
| — | **units** | **absent** |

The missing `code` on the step-2 Location at `:123-139` is the F-BUG-1 shape the triage found; **units are the same class of omission, currently harmless only because the rows are DB-global.** Under option (a) this method becomes the mandatory seed site.

### 2.5 There is **no** generic "on company created → seed defaults" hook

`CompanyCreated` is fired from the model (`apps/api/app/Modules/Company/Domain/Company.php:158-169`) and has exactly three listeners (`apps/api/app/Providers/EventServiceProvider.php:69-73`):
`CreateFiscalYearsForNewCompany`, `RegisterCompanyWithGrowthAdvisor`, `EnsureFraudSettingsOnCompanyCreated`.

All other per-company provisioning is **imperative and duplicated** across the two call sites (`TenantInitializationService::initializeForNewRegistration` `:59-113` and `CompanyController::store` `:78-187`). That duplication is exactly what produced the G-3 expense-category miss and the I-2 second-company miss cited in the code comments. A `CompanyCreated` listener (`SeedUnitsForNewCompany`) is available as the single hook and would remove the duplication for units; the counter-argument is that the three existing listeners run **outside** the caller's transaction semantics for the registration path, so a listener that must be transactional should instead be a service called from both sites — which is the pattern `ChartOfAccountsService::seedForCompany` (`apps/api/app/Modules/Accounting/Application/Services/ChartOfAccountsService.php:49`), `ExpenseCategoryProvisioningService::provisionForCompany` (`:31-34`) and `CompanyTaxProvisioningService::provisionForCompany` (`:28`) already use.

**Recommended hook shape (both options a and c): a `UnitProvisioningService::provisionForCompany(Company)` called from `CompanyController::store` and `TenantInitializationService`, mirroring the three services above.**

---

## 3. Consumers

### 3.1 Backend — classified

| Consumer | Reads units how | Scope today | Citation |
|---|---|---|---|
| `UomController::indexCategories` / `indexUnits` | `whereNull(tenant_id) OR tenant_id = X` | **tenant-wide** (company discarded) | `UomController.php:42-52`, `:75-87` |
| `UomController::showUnit/updateUnit/destroyUnit/convert` | bare `findOrFail` | **DB-wide** | `:107,163,217,264-266` |
| `ProductController::resolveUnitId` | same tenant-union, both directions | **tenant-wide** | `ProductController.php:1181-1231`, callers `:392`, `:758` |
| `ProductData::quantityDecimals` | `$product->unitOfMeasure->decimal_places` when loaded | **per product, company-agnostic (by id)** | `ProductData.php:37,42,88,93,159-160` |
| `SalesReportService` | `COALESCE(units.decimal_places, 4)` join | by id | `SalesReportService.php:152,169-170` |
| `StockAlertReportService` | same | by id | `StockAlertReportService.php:55,67-69` |
| `PosAnalyticsService` | same | by id | `PosAnalyticsService.php:281,299` |
| `BundleExpansionService` / `ServiceBundleComponentData` | `QuantityScale::formatForUnit(...)` with the component's unit precision | by id | `BundleExpansionService.php:117,171,219,232,263`; `ServiceBundleComponentData.php:24,62-63` |
| `DocumentLineData` | `QuantityScale::formatForUnit($x, null)` — **passes null**, i.e. falls back to scale 4 | unit-agnostic | `DocumentLineData.php:48-52` |
| `ReceiptDetailData` | `formatForUnit(..., $quantityDecimals)` | by id | `ReceiptDetailData.php:100,118-125` |
| `StockAdjustmentService` | imports `Unit` | by id | `StockAdjustmentService.php:30` |
| `RevalidateUnitQuantityScaleJob` | dispatched on `decimal_places` change, carries `tenantId` | **tenant** | `UomController.php:192-197` |

`QuantityScale::formatForUnit(string $value, ?int $decimalPlaces, ?string $roundingMethod = null)` — `apps/api/app/Shared/Domain/QuantityScale.php:72`. It takes an **int**, never a Unit model: the whole display half of rule 19 flows through a denormalised integer, not a unit identity. **This is the single most important fact for the options analysis** — see §3.4.

### 3.2 Web

- API client: `apps/web/src/features/uom/api/uomApi.ts:65` (`/uom/categories`), `:73` (`/uom/units`), `:80`, `:87`, `:102`, `:116`, `:163`.
- Hooks: `apps/web/src/features/uom/hooks/useUnits.ts` — `uomKeys` (`:20-25`), wrapped at each call site in `tenantScopedKey([...])` (`:75`) per rule 14, and tenant+company invalidation predicates (`:36-68`). **The query key is already company-suffixed**, so a company-scoped list would need no key surgery — only the server filter changes.
- Unit picker consumers: `apps/web/src/features/workshop-bundles/hooks/useUnits.ts:22-33` (`GET /uom/units`), `apps/web/src/features/inventory/ProductForm.tsx:340`, `apps/web/src/features/products/sections/ProductGeneralSection.tsx`, `apps/web/src/features/catalog/components/RecipeLineEditor.tsx`, `apps/web/src/features/settings/components/UnitDecimalSettings.tsx:181`, `apps/web/src/features/uom/pages/UnitsSettingsPage.tsx`.
- Display precision: `apps/web/src/lib/quantityScale.ts:9-16` — `getQuantityDecimals(product)` reads **`product.quantity_decimals`** (an int on the payload), clamped to `[0,4]`, default 4. ~20+ call sites (`grep -rn getQuantityDecimals apps/web/src`).

### 3.3 POS — **carries no units table at all**

- `apps/pos/src/lib/db/migrations.ts:1914-1918` — migration v62 `add_quantity_decimals_to_products`: `ALTER TABLE products ADD COLUMN quantity_decimals INTEGER`. There is **no `units` table in the device SQLite** (`grep -rn "CREATE TABLE.*unit" apps/pos/src/lib/db` → only this).
- Type: `apps/pos/src/types/product.ts:113-119` — "the server `/products` `quantity_decimals` field and persisted in the local [db]"; cart carries it forward `apps/pos/src/types/cart.ts:19`, `apps/pos/src/stores/cartStore.ts:327`.
- Formatting: `apps/pos/src/lib/quantity.ts:6-20` — `clampQuantityDecimals` (fallback 4) + `formatQuantity`; used by `CartLineItem.tsx:91`, `SaleDetailModal.tsx:87`, `TodaySalesPanel.tsx:25`, `RequestRefillSheet.tsx:49,173`, `QuantityInput.tsx`.
- Server emission: `ProductData::quantity_decimals` (`ProductData.php:42,93`), `OrderLineResource.php:40`, `ShiftController.php:326`, `DocumentAdditionalCostController.php:148`.

**Consequence: the POS is entirely indifferent to unit scoping.** Rescoping units to company changes **zero** POS code, as long as the server keeps emitting `quantity_decimals` per product. This removes the largest normal source of risk in an AutoERP data-model change.

### 3.4 The precision contract reads units **by id**, never by scope

Every rule-19 display path resolves precision from a single product's `unit_id` join (or a pre-computed int). None of them enumerate "the tenant's units" or "the company's units". Therefore **a per-company clone of the unit rows does not break any display path**, provided each row keeps the same `decimal_places` and every referencing FK is re-pointed to the clone that belongs to its own company. Re-pointing is the entire risk of option (a) — see §5(a).

### 3.5 Second latent defect — case-collision makes unit resolution ambiguous on demo tenants

`UomSeeder` writes lowercase codes (`kg`, `l`, `hr` — `:52,113,249`). `DemoTenantSeeder::ensureAutomotiveUnits()` writes **tenant-scoped uppercase** codes into different categories: `Unit::updateOrCreate(['tenant_id' => $tenant->id, 'code' => 'L'], …)`, `'EA'`, `'HR'`, `'KG'` (`apps/api/database/seeders/DemoTenantSeeder.php:328-420+`, categories `autospecs_volume|pieces|time|weight` at `:345-380`). Its docblock says so explicitly: "Units are tenant-scoped (not `UomSeeder` system units)" (`:329-331`).

So on a demo/automotive tenant, a **case-insensitive** lookup of `"kg"` matches **two** rows. `ProductController::resolveUnitId` applies `$matches->count() === 1 ? … : null` (`ProductController.php:1226`) → it silently resolves **nothing**. Any import unit resolver built on case-insensitive matching (spec §4.13.3 step 2, the OQ-G-22 default) inherits this ambiguity and must define a tie-break (company row > tenant row > system row is the natural one, and is only expressible once rows are scope-stamped).

---

## 4. Per-company reference-data precedents

| Reference data | Table scope | Seeded per company by | Citation |
|---|---|---|---|
| Chart of accounts | `accounts.company_id` | `ChartOfAccountsService::seedForCompany(Company)` | `ChartOfAccountsService.php:49-61`; called `TenantInitializationService.php:167`(via `:266-269`) and `CompanyController.php:167` |
| Payment methods | `payment_methods.company_id`, `unique(company_id, code)` | `PaymentMethodSeeder::run(Company)` | `TenantInitializationService.php:288-292`; `CompanyController.php:172` |
| Expense categories | company-scoped | `ExpenseCategoryProvisioningService::provisionForCompany(Company)` | `ExpenseCategoryProvisioningService.php:31-34`; `TenantInitializationService.php:302-305`; `CompanyController.php:181` |
| Tax configurations | company-scoped | `CompanyTaxProvisioningService::provisionForCompany(Company)` | `CompanyTaxProvisioningService.php:28-45`; `CompanyController.php:184` |
| Locations | `locations.company_id`, partial unique `(company_id, code) WHERE code IS NOT NULL` | inline `Location::create` at both sites | `2025_11_30_105000_create_locations_table.php:26,60,68`; `CompanyController.php:123-139` |
| Product categories | `categories.company_id`, `unique(company_id, slug)` | resolved on demand per company | `2025_12_26_194624_create_categories_table.php:15-16,38` |
| Country reference (`countries`, `country_*_settings`) | tenant-DB global | `seedReferenceData()` once per tenant | `TenantInitializationService.php:221-239` |
| **Units** | **tenant-DB global (`tenant_id` NULL)** | **once per tenant, registration only** | `TenantInitializationService.php:259-265` |

**Units are the only user-facing *catalogue* still on the tenant-global side of that line.** Everything the operator edits per company (accounts, payment methods, expense categories, tax configs, locations, product categories) is `company_id`-scoped.

### 4.1 The payment_methods precedent is *weaker* than it looks

`apps/api/database/migrations/tenant/2026_08_28_100000_enforce_company_scoped_payment_method_codes.php` did **not** add a column or re-point any FK. `payment_methods.company_id` already existed and every production resolver already used it; the migration only **normalised the unique index** from `(tenant_id, code)` to `(company_id, code)`, discovering indexes by columns rather than name (`:11-25`), refusing with a collision census before adding (`:63`), dropping the tenant-wide unique (`:64`), and no-opping when already correct (`:66-71`). `down()` is forward-only by design (`:74-80`).

For units the equivalent work is **strictly larger**: add a column, backfill 19×N rows, re-point 6 FK columns (3 of them via multi-hop joins), and handle the `unit_categories ↔ units` base-unit cycle. Treat the payment_methods migration as the **census/refusal style guide**, not as a size estimate.

---

## 5. Options analysis (facts + estimate — not a decision)

### (a) Re-scope units to COMPANY

**Shape**
1. `units.company_id` uuid **nullable** → backfill → NOT NULL (the exact `products.company_id` playbook: added nullable `2025_11_30_130000_add_company_id_to_existing_tables.php:49`, made required `2025_11_30_134000_make_company_id_required.php:30`). Same for `unit_categories.company_id`, because `units.category_id` is NOT NULL and cascade-deletes — a per-company unit cannot hang off a shared category without leaving a cross-company FK.
2. Unique `(company_id, code)` + drop `(tenant_id, code)`, following `2026_08_28_100000`'s discover-by-columns / census-then-add pattern, and **fixing the §1.4 NULL-distinct gap in the same migration**.
3. **Backfill = clone × N companies.** For each company: clone the 5 categories, clone the 19 units, then rewrite `unit_categories.base_unit_id` to point at the *clone's* base unit (the cycle, `2026_01_09_095045:43-45`). Tenant-authored units (`tenant_id NOT NULL`, e.g. the demo `L/EA/HR/KG`, §3.5) must be cloned to **every** company too, or the operator loses them on all but one company.
4. **Re-point every referencing row to its own company's clone** — the hard part:
   - `products` → `UPDATE products p SET unit_id = m.new_id FROM unit_map m WHERE m.old_id = p.unit_id AND m.company_id = p.company_id` (direct `company_id`).
   - `composite_items.stock_unit_id` → direct `company_id`.
   - `recipes.yield_unit_id` → join `composite_items` on `recipes.composite_item_id`.
   - `recipe_lines.unit_id` → join `recipes` → `composite_items` (2 hops).
   - `modifiers.component_unit_id` → join `modifier_groups` on `modifier_group_id`.
   - `workshop_service_bundle_components.unit_id` → join `workshop_service_bundles`; **NOT NULL + restrictOnDelete**, so this one has no null-out fallback and must be re-pointed before the old rows can be dropped or the FK will refuse.
   Then: **keep the old rows** (do not delete) unless a post-census proves zero references — deletion is what makes this migration irreversible in a bad way, and the N-9 precedent already chose a no-op `down()` for exactly this reason (`2026_08_26_100000:60-62,83-86`).
5. Seed on company creation via a new `UnitProvisioningService::provisionForCompany()` called from **both** `CompanyController::store` (`:167-184` block) and `TenantInitializationService` (`:238`), matching the ChartOfAccounts/Expense/Tax pattern (§4).
6. Consumer updates: `UomController` ×6 actions (swap the tenant union for `company_id = requireCompanyId()`, and add company filters to the four unscoped `findOrFail` actions), `ProductController::resolveUnitId` (`:1181-1231`, signature takes `?string $tenantId` — becomes `companyId`), `CreateUnitRequest` gains `Rule::unique('units','code')->where('company_id', …)`, `RevalidateUnitQuantityScaleJob` gains a company id, `UomSeeder` + `UnitSeeder` + `DemoTenantSeeder::ensureAutomotiveUnits` + `CoffeeShopSeeder:127-132` + `ParapharmacySeeder:344-347` all become company-aware, and the N-9 backfill migration's "both tables empty" guard becomes per-company.
7. Tests: 6 files under `apps/api/tests/Feature/Uom/` plus **91 test files** mention `Unit::`/`units` (`grep -rl "Unit::\|units\b" apps/api/tests --include='*.php' | wc -l` → 91); `UnitFactory` + `UnitCategoryFactory` need a company. Web: `apps/web/src/features/uom/__tests__/tenantScope.test.tsx` becomes a company-scope test.

**Size: L. Estimate 5–8 lane-days** (1 migration lane, 1 provisioning lane, 1 consumer/FE lane, 1 test-repair lane), plus a mandatory staging census before and after.

**Riskiest step: step 4 — re-pointing `unit_id` across the three no-company_id tables (`recipes`, `recipe_lines`, `modifiers`) and the NOT-NULL `workshop_service_bundle_components.unit_id`.** A row whose upward join is broken (an orphan recipe, a modifier whose group was deleted) has **no derivable company** and cannot be re-pointed; the migration must census those rows and refuse rather than guess, exactly as `2026_08_28_100000::assertNoCompanyCodeCollisions()` refuses (`:63`).

**Second risk, easy to miss:** the `unit_categories.base_unit_id ↔ units.category_id` cycle means the clone must be written in two passes (categories → units → update categories), the same two-pass shape `UomSeeder` uses (`:16-35`).

### (b) Keep TENANT-scoped shared units, add per-company overrides

**Shape**
- Add `units.company_id` **nullable, staying nullable** — `NULL` means "shared across the tenant", non-NULL means "this company's own unit".
- Unique-index shape that makes *the same code in two companies* legal while shared units stay global:
  ```sql
  CREATE UNIQUE INDEX units_shared_code_unique
    ON units (code) WHERE company_id IS NULL;
  CREATE UNIQUE INDEX units_company_code_unique
    ON units (company_id, code) WHERE company_id IS NOT NULL;
  ```
  (Identical idiom to `2026_04_28_120000_fix_unit_categories_partial_unique.php:67-77`, which is already proven in this schema. It also repairs the §1.4 NULL-distinct gap for the shared partition as a side effect.)
  Note this shape deliberately **permits** a company row to shadow a shared code (`company A` may define its own `kg`), so the read path needs an explicit precedence: company row wins over shared row — which also gives §3.5's ambiguity a principled tie-break.
- Optional `company_units` pivot for enable/disable of a shared unit per company (`company_id, unit_id, is_enabled`). Only needed if the owner wants a company to *hide* shared units; visibility alone does not require it if "disable" is acceptable only for company-owned rows.
- Read path: `where(company_id IS NULL OR company_id = :company)` — a one-line change from today's tenant union in `UomController.php:44-46,76-79` and `ProductController.php:1188-1195,1212-1219`.
- **No backfill, no re-pointing, no FK churn** — existing rows keep `company_id = NULL` and every existing `unit_id` stays valid.

**Size: M. Estimate 2–3 lane-days.** Riskiest step: defining and testing precedence when a company code shadows a shared code (and making the pickers show one, not two).

### (c) Status quo tenant-scoped + guaranteed seeding (invariant work only)

**Shape:** no schema change to `units`. Only:
- a `UnitProvisioningService`-equivalent guarantee that the unit set is non-empty for the company about to import (today's guarantee is the tenant-level self-guard at `TenantInitializationService.php:261-263` + the N-9 backfill),
- the import refusal (§5.1),
- a census over existing tenants.

**Size: S. Estimate 0.5–1 lane-day.** Riskiest step: none material; the guard already exists at tenant level and the N-9 migration already backfilled.

### 5.1 The invariant, for **all three** options: "an import never runs with an empty unit set"

Three enforcement points, in order of when they fire:

1. **Company/tenant creation seeds the set.**
   - Today: `TenantInitializationService::seedUnitsOfMeasure()` (`:259-265`), registration only.
   - Under (a): add the call to `CompanyController::store` in the `:167-184` provisioning block, alongside `paymentMethodSeeder->run($company)` (`:172`).
   - Under (b)/(c): unchanged — but pin it with a test, because nothing today asserts that a *second* company sees a non-empty unit set.

2. **The import refuses, with a coded job-level error, when the resolved unit set is empty.**
   The natural refusal points, all of which already refuse with a coded/typed failure:
   - `ImportController::store` — already refuses a bad `type` as a validation error and a missing-header set at `apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:183-188` (`'error_message' => 'Missing required columns: …'`). This is the best place for a **pre-flight** `units_not_seeded` refusal, because it fires before a file is even queued.
   - `ImportService::validateJob(ImportJob)` — `apps/api/app/Modules/Import/Services/ImportService.php:139`, the validation phase that produces row errors.
   - `ProcessImportJob::handle` — `apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php:96-105` already calls `$this->failJob($job, 'Company not found')`; `failJob($job, 'units_not_seeded')` is the same shape and covers the queued path when the set is emptied between upload and run.
   Job-level, not row-level: an empty catalogue is a configuration fault, not a data fault.

3. **A census/backfill for existing companies**, styled on the two existing precedents: the N-9 self-guarding backfill (`2026_08_26_100000`, guards at `:68-78`) and the payment_methods census-then-refuse (`2026_08_28_100000:63`). Under (c) this is just a re-run of the N-9 guard shape per company; under (a) it is step 3 of the migration.

**Important scoping note for the owner:** points 1 and 3 close *the stated invariant*, but they do **not** close the defect actually costing data today. §3.3/§0 show the products import never resolves `unit_id` at all, so a fully-populated 19-unit catalogue and a completely empty one produce **identical** import output (`unit` string written, `unit_id` NULL, no error). The invariant is necessary; the `UnitResolver` in spec §4.13.3 (G-4) is what makes it *observable*.

---

## 6. Recommendation

**Recommend option (b) — nullable `company_id` with the two partial unique indexes — but sequence it strictly after the G-4 `UnitResolver`, and ship option (c)'s invariant work now.** The owner's premise is right in principle (a catalogue an operator edits should not silently bleed across companies) and units are the last operator-editable catalogue still on the tenant-global side of a line every sibling has already crossed (§4). But the *stated* symptom — "an import happens, there are no units to resolve, no error" — is not caused by scoping: it is caused by the import never resolving units at all (`ProductService.php:73` writes only the free-text column; `products.unit_id` has no writer outside `ProductController`), so re-scoping fixes nothing an operator can see until G-4 lands. Option (a) is the clean end-state, but its cost is concentrated in one irreversible step — re-pointing 6 FK columns, 3 of which reach `company_id` only through 1–2 upward joins and one of which is NOT NULL + `restrictOnDelete` (§1.5) — against a data model whose base-unit cycle forces a two-pass clone; that is L-sized, forward-only, and lands on a tenant-#1 launch path where the POS is about to be exercised by hand. Option (b) buys the entire behaviour the owner asked for (a company can create `sachet` without imposing it on its siblings; two companies may hold the same code) for a nullable column and two partial indexes copied verbatim from an idiom already proven in this schema (`2026_04_28_120000:67-77`), with **zero** backfill, **zero** FK re-pointing, and **zero** POS impact (the device carries only `products.quantity_decimals`, §3.3). It also supplies the precedence rule that resolves the live `kg`/`KG` ambiguity on demo tenants (§3.5). If, after G-4, the owner still wants shared units gone entirely, (a) remains reachable from (b) by flipping the column to NOT NULL — which is strictly easier from (b) than from today, because by then the read path, the FormRequest and the pickers are already company-aware.

### What the owner must rule

1. **Sharing default:** should the 19 seeded units stay *shared* across a tenant's companies (option b: `company_id NULL`), or should every company own its own private copy from day one (option a)? — i.e. is "my three companies all use `kg`" a feature or a leak?
2. **Shadowing:** if company A defines its own `kg` while a shared `kg` exists, is that legal (company row wins) or must it be refused as a duplicate? This single ruling determines the unique-index shape and the resolver tie-break.
3. **Sequencing:** confirm units scoping lands **after** the G-4 `UnitResolver` (spec §4.13.3), i.e. Session G ships the invariant + resolver now and the scoping change as a follow-on lane — or state that scoping blocks G-4.
4. **Blank-cell policy on import** (already open as OQ-G-22, restated because it interacts with scoping): does a blank `unit` on a *created* product stay NULL with a `unit_defaulted` warning, or default to the `pieces` base unit `pc`? Under (a)/(b) "default" must name a **company's** unit row, not a global one.

---

## 7. Appendix — verification commands used

```bash
ls apps/api/database/migrations/tenant | grep -i -E "unit|uom"
grep -rn "unit_id" apps/api/database/migrations/ | grep -v base_unit_id
grep -rn "UomSeeder\|seedUnitsOfMeasure\|seedReferenceData" --include='*.php' apps/api --exclude-dir=vendor
grep -rn "CompanyCreated" --include='*.php' apps/api/app
grep -rn "units" apps/api/database/migrations/tenant/*.php | grep -iE "unique|index"   # → no units unique fix
grep -rn "CREATE TABLE.*unit\|quantity_decimals" apps/pos/src/lib/db                    # → no units table on device
grep -rl "Unit::\|units\b" apps/api/tests --include='*.php' | wc -l                     # → 91
```
