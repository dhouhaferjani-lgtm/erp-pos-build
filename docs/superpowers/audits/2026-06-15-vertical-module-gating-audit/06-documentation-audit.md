# 06 — Documentation & Context Accuracy Audit: Vertical Setup & Module Gating

**Date:** 2026-06-15
**Auditor:** read-only documentation auditor
**Scope:** docs/context accuracy for the vertical → module-gating model (root `claude/` + ERP `apps/erp/`).
**Trigger:** a tester testing "as a restaurant" saw parapharmacy-only product fields — partly a docs/expectations failure.

---

## Summary

**Posture: the gating MODEL is documented, but the docs are stale on the module SHAPE, silent on the one mechanism that caused the bug, and undiscoverable from the entry-point CLAUDE.md files.**

Three independent problems compound:

1. **The root `claude/` docs are about a different codebase.** `claude/architecture.md`, `claude/vertical-boundaries.md`, and `claude/glossary.md` describe the **Synerivia platform/data-platform** (DBs `platform` / `car_parts` / `parapharmacy`; modules `Automotive/*`, `VinDecoding/*`, `Parapharmacy/*`; a "vertical = its own DB + Laravel modules" model). The **ERP's** vertical/module-gating system (the 12-case `App\Enums\Vertical`, `config/verticals.php`, `RequireModule`, `enabled_extras`, `CompanyConfigService`) is a **completely different concept** and is **not mentioned at all** in the root docs. A developer who reads `claude/glossary.md`'s definition of "vertical" ("a domain-specific slice … own DB … own Laravel modules") will form a wrong mental model of how the ERP gates modules.

2. **The ERP docs that DO describe the gating model are stale on module names and the config-as-SoT migration.** `docs/architecture/module-loading.md` and `docs/architecture/security.md` reference modules that do **not** exist in `App\Enums\ModuleName` (`Communication`, `Media`, `Recipe`), publish per-vertical default-module lists that **contradict** `config/verticals.php`, and (in `module-loading.md`) still teach `app(VerticalConfigService::class)` and the pre-override config shape with no mention that DB overrides (`vertical_configs`) are now authoritative. None of the three stale docs reference the now-deleted `Vertical::defaultModules()` / `compatibleExtras()` **methods**, so there is no "ghost method" reference — but they teach the same drift the method deletion was meant to kill.

3. **No doc says vertical-specific PRODUCT METADATA must be gated, and the actual gate is undocumented.** The parapharmacy product fields are gated in the frontend on `config?.vertical === 'parapharmacy'` (`apps/web/src/features/inventory/ProductForm.tsx:94,645`), i.e. **by vertical string, not by module**. Backend parapharmacy master-data routes ARE gated on `module:Parapharmacy` (`apps/api/app/Modules/Product/routes.php:71`), but the **`Parapharmacy` module is not documented anywhere** — it is absent from `verticals.md`, `module-loading.md`, `security.md`, and `company-config.md`. A developer reading any doc would not learn that (a) a `Parapharmacy` module exists, (b) parapharmacy fields should be gated, or (c) whether to gate on vertical or module. This is the heart of the bug: there was no documented contract, and the two layers gate on different things.

**How docs contributed to the misunderstanding:** A tester setting up "a restaurant" had no doc telling them how to choose/set a tenant's `vertical` (no onboarding/test-setup doc covers vertical selection at signup or seed). If their tenant landed on `vertical='parapharmacy'` (the `DemoTenantSeeder` seeds a parapharmacy demo tenant at line 1990; the tenants migration default is `'retail'`, not restaurant), the frontend shows parapharmacy fields purely from the `vertical` string. No doc warns that product-form fields are vertical-driven, nor how to verify the active vertical. The expectation gap is a direct product of (3) + the missing test-setup guidance.

---

## Doc-vs-code mismatches

| Doc file:section | Claim | Code reality | Severity |
|---|---|---|---|
| `docs/architecture/module-loading.md` §"Default Modules (All IziPOS Verticals)" L88, matrix L146-157 | Every vertical's default_modules include `Communication` and `Media`; IziPOS verticals get `Communication, Media`; mechanic gets `Workshop` as a default even though matrix elsewhere differs | `config/verticals.php` lists **no** `Communication`/`Media` in any vertical; `ModuleName.php` has **no** `Communication`/`Media`/`Recipe` cases. Mechanic default_modules = Identity,Tenant,Catalog,Vehicle,Partner,Workshop,Sales,Inventory,Treasury,Accounting,**PlatformIntegration** | HIGH |
| `docs/architecture/module-loading.md` matrix L148, §Otospex extras L134 | parts_retailer compatible_extras = `Appointments, Fleet, Workshop`; all Otospex verticals share `Appointments, Fleet` | `config/verticals.php`: parts_retailer `compatible_extras=['Ecommerce']`; tire_shop=`['Appointments']`; service_station=`[]`; mechanic/body_shop/car_glass=`['Appointments','Fleet']`. Per-vertical, not uniform. | HIGH |
| `docs/architecture/module-loading.md` L60, L100-104, L133-136, L152-157 | `Recipe` is a compatible extra for pharmacy/parapharmacy | `Recipe` does not exist in `ModuleName.php`. Real extras: pharmacy=`BatchExpiry,Prescription,Ecommerce`; parapharmacy=`BatchExpiry,Loyalty,Ecommerce` | HIGH |
| `docs/architecture/module-loading.md` (whole doc) | `VerticalConfigService` reads straight from `config/verticals.php`; resolved via `app(VerticalConfigService::class)` | Service is DB-first: central `vertical_configs` rows override config per-field, cached 24h via `GlobalCache` (`VerticalConfigService.php:11-31,43-62`). `app()` helper violates CLAUDE.md rule 13 (constructor injection). No mention of override layer. | HIGH |
| `docs/architecture/security.md` L154 | `Recipe` module, `module:Recipe`, route `/api/v1/recipes` | No `Recipe` module/enum case exists. | MED |
| `docs/architecture/security.md` L131-154 (vertical-specific module table) | Lists Vehicle/Workshop/Menu/Tables/BatchExpiry/Recipe | Omits the **`Parapharmacy`** module (real, gating `apps/api/app/Modules/Product/routes.php:71`) and `CompositeItems`, `PlatformIntegration`. | MED |
| `docs/api/company-config.md` L141 | Extra `Recipe` "Recipe management for workshops", compatible verticals "mechanic, workshop" | No `Recipe` module; no `workshop` vertical (Workshop is a module, not a vertical). | MED |
| `docs/api/company-config.md` L110-118 | coffee_shop default extra-modules = `Menu, Tables`; parts_retailer/car_glass/tire_shop/service_station = `Vehicle` | `config/verticals.php`: coffee_shop=`Menu,CompositeItems` (no Tables in defaults); service_station has **no** Vehicle default; restaurant adds `CompositeItems`; parapharmacy default adds `Parapharmacy` not `BatchExpiry`. | MED |
| `docs/architecture/company-config.md` / `company-config.md` L150 | Cache key `tenant_config:{tenant_id}`, invalidated via `TenantObserver` | `VerticalConfigService` cache key is `vertical_config_override:{vertical}` via `GlobalCache`, invalidated by `VerticalConfigObserver`/admin controller (service docblock L24-30). Doc describes only the tenant-config layer, not the vertical-override layer. | LOW |
| `docs/architecture/verticals.md` L99, L122-131, L420-444 | pharmacy `BatchExpiry` listed both as default AND as extra (L98-99); restaurant matrix shows `Tables ✅` default but coffee_shop `Tables 🔵`; parapharmacy "Default Modules" list (L424-432) **omits** the `Parapharmacy` module; parapharmacy extras = `BatchExpiry, Loyalty` | `config/verticals.php`: parapharmacy default_modules INCLUDE `Parapharmacy` and `BatchExpiry` is NOT a parapharmacy default (it's `requires_batch_tracking=true` via product_defaults) — parapharmacy `compatible_extras=['BatchExpiry','Loyalty','Ecommerce']`. The `Parapharmacy` default module is missing from the doc. | HIGH |
| `docs/architecture/verticals.md` matrix L465 | `BatchExpiry` is a parapharmacy **extra** (🔵), pharmacy **default** (✅) | pharmacy default_modules include `BatchExpiry`; parapharmacy does NOT (it has the `Parapharmacy` module instead). Matrix never shows the `Parapharmacy` module row at all. | MED |
| `claude/glossary.md` L8 ; `claude/architecture.md` L21-32 ; `claude/vertical-boundaries.md` (whole) | "Vertical" = a domain-specific slice with its own DB and own Laravel modules (`Automotive/*`, `Parapharmacy/*` in DBs `car_parts`/`parapharmacy`) | This is the **Synerivia platform** model, not the ERP. In the ERP, a vertical is a `tenants.vertical` enum value that selects a module set from `config/verticals.php`; all 12 verticals share one tenant DB. The root glossary term collides with the ERP term. | HIGH (cross-repo term collision) |
| `apps/erp/CLAUDE.md` L7 | "IziPOS … and Otospex … are separate **verticals**" | IziPOS/Otospex are **products** (`App\Enums\Product`); each owns 6 **verticals**. Calling them verticals contradicts `Vertical.php` / `products.md`. | LOW |

---

## Gaps (things undocumented)

1. **The `Parapharmacy` module is undocumented.** It exists (`ModuleName::Parapharmacy`), is a default module for the parapharmacy vertical (`config/verticals.php:336-346`), and gates real backend routes (`Product/routes.php:71`). No architecture/security/company-config doc lists it. (This is the module that should gate parapharmacy product metadata.)

2. **No doc states the rule "vertical-specific product metadata MUST be gated."** Neither `new-feature-checklist.md`, `security.md`, nor `products.md` tells a developer that adding parapharmacy/automotive-specific fields to a shared product form requires a vertical/module gate. A developer following the checklist would ship ungated fields.

3. **The two gating mechanisms are not reconciled in any doc.** Backend gates parapharmacy on `module:Parapharmacy`; frontend gates the product form on `vertical === 'parapharmacy'` (`ProductForm.tsx:94`). No doc says which is canonical or why they differ. This asymmetry is exactly what let "restaurant" testers see parapharmacy fields if their tenant's `vertical` was parapharmacy.

4. **No onboarding/test-setup doc explains how to choose/set a tenant's `vertical`.** QA docs (`docs/qa/2026-05-12-first-tenant-smoke.md`, etc.) mention "vertical" but none walks a tester through "to test a restaurant, set `vertical=restaurant` (seed: `DemoTenantSeeder` line 1771) — do NOT use the parapharmacy demo tenant (line 1990)." The migration default is `'retail'` (`2025_12_30_115627_add_vertical_to_tenants_table.php:16`), and seeders set many different verticals — a tester has no map.

5. **`config/verticals.php` is never stated as the single source of truth in any prose doc, and the DB-override layer (`vertical_configs`) is undocumented.** Only the enum docblock and the service docblock say so. `module-loading.md` (the most detailed doc) predates both the config-as-SoT migration and the override layer.

6. **No discoverable entry point.** Neither root `CLAUDE.md` nor `apps/erp/CLAUDE.md` nor any `docs/architecture` index links to `verticals.md` / `module-loading.md` / `security.md` / `company-config.md`. There is no `docs/architecture/README.md`. The gating model is only findable by guessing filenames.

7. **All four ERP gating docs are dated 2025-12-30 ("Milestone 3") and self-describe as "Opus 4.5 verified"** — they have not been updated through the parapharmacy reorg, the `PlatformIntegration`/`CompositeItems`/`Parapharmacy`/`Menu`/`Tables` module additions, or the config/DB-override migration. They read as authoritative but are ~6 months stale.

---

## Recommended doc changes (seeds a later doc-update task — NOT applied here)

1. **`docs/architecture/module-loading.md` — full rewrite of the module/extra tables.** Regenerate the per-vertical default_modules and compatible_extras tables **from `config/verticals.php`** (the 12 blocks above are the ground truth). Delete every reference to `Communication`, `Media`, `Recipe`. Add `PlatformIntegration`, `CompositeItems`, `Parapharmacy`, `Menu`, `Tables`, `Loyalty`, `Ecommerce`, `Prescription`, `Reservation`, `BatchExpiry` as the real module set. Add a "Source of truth" banner: "`config/verticals.php` is authoritative, read via constructor-injected `VerticalConfigService` (never `app()`); central `vertical_configs` rows override per-field." Replace `app(VerticalConfigService::class)` examples with constructor injection.

2. **`docs/architecture/verticals.md` — fix parapharmacy + the compatibility matrix.** Add the **`Parapharmacy` module** to parapharmacy's Default Modules and to the matrix (a new row). Correct parapharmacy `compatible_extras` to `BatchExpiry, Loyalty, Ecommerce` and move `BatchExpiry` out of parapharmacy defaults. Resolve the pharmacy "BatchExpiry is both default and extra" contradiction (it is a default; do not list it as an extra). Regenerate the whole matrix from config. Bump version + date; remove the stale "Opus 4.5 verified 2025-12-30" stamp or re-date it.

3. **`docs/architecture/security.md` — add `Parapharmacy` module, drop `Recipe`, add the metadata-gating rule.** Add `Parapharmacy` to the vertical-specific module table with `module:Parapharmacy` and its `Product/routes.php` routes. Delete the `Recipe` row. Add a new subsection **"Gating vertical-specific product metadata"** documenting both layers: backend uses `module:Parapharmacy` middleware; frontend `ProductForm` gates on `vertical === 'parapharmacy'` (cite `ProductForm.tsx:94`). State the canonical rule and flag the vertical-vs-module asymmetry as intentional (or a known issue to reconcile).

4. **`.claude/context/new-feature-checklist.md` — add a "Vertical/module gating" step.** New numbered step: "If the feature is vertical-specific (e.g. parapharmacy/automotive product metadata, menu, batch tracking): (a) confirm/add a `ModuleName` case, (b) wrap backend routes in `Route::middleware('module:<Module>')`, (c) gate frontend with `hasModule('<Module>')` from company-config — NOT a raw `vertical ===` string unless intentionally vertical-wide. Never add vertical-specific fields to a shared form ungated." Also fix the route example (L29) to show the `module:` middleware option.

5. **`docs/api/company-config.md` — correct extras/defaults tables; remove `Recipe`.** Regenerate the "Supported Verticals" and "Available Extras" tables from `config/verticals.php`. Delete `Recipe`. Add the `vertical_configs` DB-override note and the correct cache key/topology (`vertical_config_override:{vertical}` via `GlobalCache`, `VerticalConfigObserver`).

6. **`apps/erp/CLAUDE.md` — add a discoverable pointer + fix the "verticals" wording.** Add to the Context-Files table a row: "Vertical & module gating → `docs/architecture/verticals.md` + `module-loading.md` + `security.md` — read before adding vertical-specific features or product fields." Reword L7 so IziPOS/Otospex are called **products** (each with 6 verticals), not verticals.

7. **Root `claude/glossary.md` (and `claude/architecture.md`) — disambiguate "vertical" across the two codebases.** Add a note to the "Vertical" entry: "In `apps/erp/` (the ERP product), 'vertical' means a `tenants.vertical` enum value selecting a module set from `config/verticals.php`; all 12 ERP verticals share one tenant DB. The platform-layer meaning here (own DB + own modules) does NOT apply to the ERP." Prevents the cross-repo mental-model collision.

8. **New `docs/qa/` test-setup note (or add to `first-tenant-smoke.md`): "Choosing a vertical for testing."** Map each test scenario to its seeder/vertical: restaurant → `vertical='restaurant'` (`DemoTenantSeeder:1771`); parapharmacy → `:1990`; mechanic → `DemoTenantSeeder:143`. State explicitly: "Product-form fields and sidebar items are vertical-driven; if you see parapharmacy fields you are on a parapharmacy tenant. Verify via `GET /api/v1/company/config` → `vertical`." This directly closes the tester-expectation gap.

9. **(Optional, cross-cutting) Add `docs/architecture/README.md`** indexing the gating docs so the model is discoverable without guessing filenames.

---

## Files reviewed

**Ground-truth code (verified against docs):**
- `apps/api/app/Enums/Vertical.php` (12 cases, product mapping, no `defaultModules()`/`compatibleExtras()` — deleted per docblock L7-16)
- `apps/api/config/verticals.php` (single source of truth; all 12 blocks read)
- `apps/api/app/Enums/ModuleName.php` (22 cases; no `Communication`/`Media`/`Recipe`)
- `apps/api/app/Http/Middleware/RequireModule.php`
- `apps/api/app/Services/VerticalConfigService.php` (DB-first override + GlobalCache)
- `apps/api/app/Modules/Product/routes.php` (`module:Parapharmacy` gating, L68-99)
- `apps/web/src/features/inventory/ProductForm.tsx` (`vertical === 'parapharmacy'` gate, L94,645)
- `apps/web/src/contexts/ProductConfigContext.tsx`
- Seeders: `DemoTenantSeeder.php` (verticals at L143,1625,1698,1771,1844,1917,1990), `TenantSeeder`, `ParapharmacySeeder`, `CoffeeShopSeeder`, `DatabaseSeeder`
- `apps/api/database/migrations/2025_12_30_115627_add_vertical_to_tenants_table.php` (default `'retail'`)

**Docs reviewed:**
- Root: `/Users/houssamr/Projects/syneriva/CLAUDE.md`; `claude/architecture.md`, `claude/vertical-boundaries.md`, `claude/glossary.md` (also listed: `database-topology.md`, `shared-patterns.md`, `README.md`, `platform-api.md`, `deploy-runbook.md`)
- ERP: `apps/erp/CLAUDE.md`; `apps/erp/.claude/context/architecture.md`, `new-feature-checklist.md`
- ERP architecture: `docs/architecture/verticals.md`, `docs/architecture/module-loading.md`, `docs/architecture/products.md`, `docs/architecture/security.md` (grep), `docs/api/company-config.md`
- Grep sweeps for `vertical`, `RequireModule`, `module:`, `config/verticals`, `default_modules`, `compatible_extras`, `defaultModules()`, `parapharmacy` across `docs/`, `.claude/`, `CLAUDE.md` (worktree copies excluded as duplicates).
