# Vertical Setup & Provisioning Audit

> Part of the 2026-06-15 "module gating + vertical setup" audit.
> Domain: how a tenant/company gets its VERTICAL, and how that vertical flows through provisioning and config.
> Auditor scope: vertical assignment + provisioning. Product-form internals, module-route gating, and docs are owned by sibling agents.

> **BRANCH CAVEAT (read first):** This audit was run on `docs/media-subsystem-architecture`, which branches from a point BEFORE the `c37fd6471` registration hotfix on `origin/dev`. On THIS branch `PosStockPolicy::defaultForVertical()` still calls the deleted `Vertical::defaultModules()` (Finding HIGH-1), which would fatal every registration. Memory indicates this was already fixed on `dev`. Verify against `dev` before treating HIGH-1 as live. All other findings are independent of that hotfix.

## Summary

A tenant's vertical is set **once, at registration**, from the `vertical` field the signup form submits (validated against the `Vertical` enum) and persisted on the **`tenants`** row — NOT on `companies`. The Company model has no `vertical` column; every consumer reads `company->tenant->vertical`. The frontend's effective config (`config.vertical`) is derived purely from `tenant->vertical` via `CompanyConfigService` → `CompanyConfigController`. The DB column default is `'retail'` (safe — never parapharmacy), and all seeders set verticals explicitly and correctly (the restaurant demo seeds `'restaurant'`). **Vertical assignment is NOT a plausible root cause of a restaurant seeing parapharmacy fields**: the frontend gates parapharmacy fields on the exact string `config?.vertical === 'parapharmacy'`, and nothing in the assignment path makes a restaurant tenant report `parapharmacy`. The biggest *real* leak this domain surfaces is adjacent: `config/verticals.php` sets `product_defaults.requires_batch_tracking => true` for **restaurant** and **coffee_shop**, so new physical products in a restaurant get batch/expiry tracking turned on — pharmacy-style behavior bleeding into F&B verticals (Finding HIGH-2). DB `vertical_configs` overrides only touch module lists, not `product_defaults`, so they cannot drive the batch-tracking leak.

## How vertical gets assigned & flows

1. **Signup form → validation.** `RegisterRequest::rules()` requires `vertical` and validates it against the enum: `'vertical' => ['required', 'string', Rule::enum(Vertical::class)]` (`app/Modules/Identity/Presentation/Requests/RegisterRequest.php:74`). No default here — a missing/invalid vertical is a 422, so registration cannot silently pick parapharmacy.

2. **Persisted on the TENANT.** Both registration paths write `'vertical' => $validated['vertical']` onto the `tenants` row:
   - db-per-tenant path: `TenantProvisioningService::provisionForRegistration()` `app/Modules/Tenant/Application/Services/TenantProvisioningService.php:85`.
   - shared-DB compat path: `AuthController::register()` `app/Modules/Identity/Presentation/Controllers/AuthController.php:369`.
   The `Company` created alongside (`...TenantProvisioningService.php:134` / `AuthController.php:401`) carries **no** vertical column — confirmed against `Company::$fillable` and the model docblock (`app/Modules/Company/Domain/Company.php:191-267`); there is no `vertical` attribute anywhere on Company.

3. **Cast + DB default.** `Tenant` casts `'vertical' => Vertical::class` (`app/Modules/Tenant/Domain/Tenant.php:128`). The migration default is `->default('retail')` (`database/migrations/2025_12_30_115627_add_vertical_to_tenants_table.php:16`) — a **safe, non-batch, izipos retail** default.

4. **Vertical → effective config (what the frontend sees).** `CompanyConfigService::getConfigForTenant(Tenant)` reads `$tenant->vertical` and asks `VerticalConfigService` for default modules / compatible extras (`app/Services/CompanyConfigService.php:53-74`). `CompanyConfigController::show()` returns `config.vertical = $config->vertical->value` plus module lists and company currency/locale (`app/Http/Controllers/Api/CompanyConfigController.php:73`). The frontend `ProductForm` then does `const isParapharmacy = config?.vertical === 'parapharmacy'` (`apps/web/src/features/inventory/ProductForm.tsx:94`).

5. **Vertical → module list resolution.** `VerticalConfigService::getVerticalConfig()` reads `config/verticals.php` and overlays the central `vertical_configs` DB row **only for `default_modules` and `compatible_extras`** (`app/Services/VerticalConfigService.php:43-62`). `product_defaults` is NOT overridable — it always comes from the config file.

6. **Vertical → product batch-tracking default.** `Product::booted()` sets `requires_batch_tracking` from `config("verticals.{$vertical->value}.product_defaults.requires_batch_tracking", false)` using the tenant's vertical (`app/Modules/Product/Domain/Product.php:154-179`).

## Findings

### [HIGH] HIGH-1 — `PosStockPolicy::defaultForVertical()` calls the deleted `Vertical::defaultModules()` (registration fatal on this branch)

**Evidence:** `app/Modules/Company/Domain/Enums/PosStockPolicy.php:26`
```php
return in_array('Menu', $vertical->defaultModules(), true) ? self::Off : self::Block;
```
The `Vertical` enum's `defaultModules()`/`compatibleExtras()` were deleted (`app/Enums/Vertical.php:7-16` docblock explicitly says so). This method is invoked on EVERY registration: `TenantProvisioningService.php:153` and `AuthController.php:422` both call `PosStockPolicy::defaultForVertical($tenant->vertical)`, plus `CompanyController.php:109`.

**Impact:** On any branch where the enum method is gone but this call remains, registration throws `Error: Call to undefined method` → 500, and the compensation path fires. Memory says this was fixed on `dev` (`c37fd6471`) by reading the module list from config; this branch predates that fix.

**Why it could cause the restaurant bug:** Not directly — it breaks registration entirely rather than mis-assigning a vertical. Flagged because it sits squarely in the provisioning path I own and any verification run on this branch will hit it first.

**Recommendation:** Confirm the `dev` fix (route through `VerticalConfigService::getDefaultModules()` / `config('verticals…default_modules')`) is present on whatever branch goes to production. Do not re-add `defaultModules()` to the enum.

### [HIGH] HIGH-2 — Restaurant & coffee_shop default new products to `requires_batch_tracking = true` (pharmacy-style behavior in F&B)

**Evidence:** `config/verticals.php:76-78` (restaurant) and `:105-107` (coffee_shop) both set:
```php
'product_defaults' => [ 'requires_batch_tracking' => true ],
```
Consumed at product creation: `app/Modules/Product/Domain/Product.php:175-178` (sets the flag from the vertical's `product_defaults`) and retroactively by `database/seeders/BatchTrackingDefaultsSeeder.php:21-44` (bulk-flips existing physical restaurant/coffee_shop products to `true`). The flag then drives FEFO/batch/expiry enforcement across many services (`FEFOInventoryService.php:321`, `GoodsReceiptService.php:149/174`, `StockTransferService.php:436`, `StockReservationService.php:444`, the SalesOrder converters, etc.).

**Impact:** A restaurant tenant's physical products silently require lot/expiry capture at goods-receipt and block oversell on lots — UX and data-entry behavior most operators associate with pharmacy/parapharmacy. This is a genuine cross-vertical bleed of "pharmacy-style" batch/expiry behavior into F&B, distinct from the parapharmacy *form fields* but the same class of bug the audit is chasing.

**Why it could cause the restaurant bug:** If the tester's "parapharmacy-only fields" were actually the **batch/expiry fields** (lot number, expiry date), THIS is the cause — restaurant shares `requires_batch_tracking=true` with pharmacy/parapharmacy. If the fields were the literal parapharmacy block in `ProductForm.tsx` (`config?.vertical === 'parapharmacy'`), this is NOT the cause and the issue lies in the product-form / config-resolution layer (hand-off below).

**Recommendation:** Product-team decision: is batch tracking actually intended for restaurant/coffee_shop ingredients? If the intent is shelf-life on perishables, that is arguably correct but should be presented as F&B "expiry tracking", not pharmacy lot tracking. If unintended, set `requires_batch_tracking => false` for restaurant/coffee_shop in `config/verticals.php`. Confirm with the product-form agent which fields the tester actually saw.

### [LOW] LOW-1 — `product_defaults` is config-only and cannot be inspected/overridden per tenant

**Evidence:** `VerticalConfigService::getVerticalConfig()` overlays DB overrides for `default_modules`/`compatible_extras` only (`app/Services/VerticalConfigService.php:51-59`); `getProductDefaults()` reads straight from the merged array but the DB row has no `product_defaults` column (`app/Models/VerticalConfig.php:37-41` fillable = vertical/default_modules/compatible_extras). `Product.php:175` reads `config(...)` directly, bypassing even the service.

**Impact:** Admins cannot turn off the HIGH-2 batch-tracking behavior for a single restaurant tenant without a code change — it is a global per-vertical constant. Not a correctness bug, but it removes the safety valve that exists for module lists.

**Recommendation:** If HIGH-2 is left as-is, consider making `product_defaults` overridable via `vertical_configs` for parity, or at least route `Product.php` through `VerticalConfigService::getProductDefaults()` so there is a single read path.

### [INFO] INFO-1 — DB `vertical_configs` overrides are global-per-vertical, correctly invalidated, and cannot leak parapharmacy fields

**Evidence:** Override read is keyed by vertical value and merged per-field with config-file fallback (`VerticalConfigService.php:82-107`); writes self-invalidate via `VerticalConfigObserver` (`app/Observers/VerticalConfigObserver.php:30-57`) and `CompanyConfigService::invalidateForVertical()` fans out to all tenants on that vertical (`app/Services/CompanyConfigService.php:100-109`). Caching uses `GlobalCache` (tenancy-neutral) by design.

**Impact / precedence:** A stale or wrong `vertical_configs` row would change the **module list** for ALL tenants on that vertical (it is a per-vertical, not per-tenant, override) — e.g. an override on `restaurant` removing/adding a module affects every restaurant tenant. But it only affects `default_modules`/`compatible_extras`; it cannot inject the `parapharmacy` vertical value or parapharmacy product fields. No precedence bug found: DB row wins per non-null field, config file is the floor, "no row" is cached via a sentinel so missing rows don't re-query.

### [INFO] INFO-2 — Multi-company-per-tenant: all companies share ONE vertical, no per-request ambiguity

**Evidence:** A tenant can have many companies (`Company` belongsTo `Tenant`; chains via `parent_company_id`, `app/Modules/Company/Domain/Company.php:336-349`), but vertical lives on the tenant, so **every company in a tenant has the same vertical**. `CompanyConfigService::getConfigForTenant()` is keyed by tenant, and `CompanyConfigController::show()` resolves config from `user->tenant` (not the selected company) — `app/Http/Controllers/Api/CompanyConfigController.php:49-56`. `CompanyConfigService` comment confirms "all companies within a tenant share the same vertical."

**Impact:** No ambiguity in current data model — you cannot have a restaurant company and a parapharmacy company under one tenant. This also means there is no per-company override that could make one company report a different vertical. Safe.

### [INFO] INFO-3 — Seeders set verticals explicitly and correctly; no parapharmacy default anywhere

**Evidence:** `DemoTenantSeeder.php` seeds restaurant as `'vertical' => 'restaurant'` (`:1771`), coffee_shop (`:1844`), parapharmacy (`:1990`), pharmacy (`:1698`), retail (`:1625`), fashion (`:1917`), mechanic (`:143`). `CoffeeShopSeeder.php:200` → `Vertical::CoffeeShop`; `ParapharmacySeeder.php:289` → `Vertical::Parapharmacy`; `TenantSeeder.php:35` → `mechanic`; `DatabaseSeeder.php:160` → `mechanic`. The restaurant demo is NOT mis-seeded as parapharmacy. The only place a vertical is "defaulted" is the column default `'retail'` (safe).

**Impact:** A tester provisioned via the restaurant demo seeder gets `vertical = restaurant`, so `config.vertical === 'parapharmacy'` is false → the parapharmacy form block should not render. Reinforces that vertical assignment is not the root cause of a *literal* parapharmacy-field leak.

## Cross-cutting / hand-off

- **To the product-form agent (PRIMARY hand-off):** The frontend gate is `const isParapharmacy = config?.vertical === 'parapharmacy'` at `apps/web/src/features/inventory/ProductForm.tsx:94`. From my domain, a restaurant tenant's `config.vertical` is `'restaurant'`, so that boolean is `false`. **If the tester genuinely saw the parapharmacy field block, the leak is NOT in vertical assignment** — investigate (a) whether `config` was stale/missing and the form fell back to a wrong default, (b) whether the fields were actually the **batch/expiry** fields (driven by `requires_batch_tracking`, see HIGH-2) rather than the parapharmacy block, and (c) localStorage/auth bleed between IziPOS apps on shared localhost (CLAUDE.md warns about this). Please confirm exactly which fields appeared.
- **To the module-gating agent:** `config.vertical` and the module lists both come from `tenant->vertical` via `CompanyConfigService`/`VerticalConfigService`. DB `vertical_configs` overrides are **per-vertical, global to all tenants on that vertical** (INFO-1) and only affect `default_modules`/`compatible_extras` — worth checking whether any gating logic assumes per-tenant overrides.
- **To the docs agent:** `config/verticals.php` `product_defaults.requires_batch_tracking = true` for restaurant + coffee_shop (HIGH-2) is the most likely "pharmacy behavior in F&B" surface and should be documented/decided explicitly. Also document that vertical lives on `tenants`, never on `companies`.

## Files reviewed

- `apps/api/app/Enums/Vertical.php`
- `apps/api/config/verticals.php`
- `apps/api/app/Services/VerticalConfigService.php`
- `apps/api/app/Services/CompanyConfigService.php`
- `apps/api/app/DTOs/CompanyConfig.php`
- `apps/api/app/Http/Controllers/Api/CompanyConfigController.php`
- `apps/api/app/Models/VerticalConfig.php`
- `apps/api/app/Observers/VerticalConfigObserver.php`
- `apps/api/app/Modules/Identity/Presentation/Requests/RegisterRequest.php`
- `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php`
- `apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php`
- `apps/api/app/Modules/Company/Domain/Company.php`
- `apps/api/app/Modules/Company/Domain/Enums/PosStockPolicy.php`
- `apps/api/app/Modules/Tenant/Domain/Tenant.php`
- `apps/api/app/Modules/Product/Domain/Product.php`
- `apps/api/database/migrations/2025_12_30_115627_add_vertical_to_tenants_table.php`
- `apps/api/database/migrations/2026_06_12_100000_create_vertical_configs_table.php`
- `apps/api/database/seeders/BatchTrackingDefaultsSeeder.php`
- `apps/api/database/seeders/DemoTenantSeeder.php` (restaurant/parapharmacy/coffee_shop blocks)
- `apps/api/database/seeders/{TenantSeeder,CoffeeShopSeeder,ParapharmacySeeder,DatabaseSeeder}.php` (vertical assignments)
- `apps/web/src/features/inventory/ProductForm.tsx:94` (parapharmacy gate)
