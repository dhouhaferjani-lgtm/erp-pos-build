# 04 — Frontend (web admin) vertical/module gating audit

**Scope:** `apps/erp/apps/web` only. Backend request/DTO gating and the Tauri POS desktop app are other agents' domains; hand-offs flagged inline.
**Date:** 2026-06-15
**Reporter:** Frontend gating auditor (read-only)

---

## Summary

**The `ProductForm.tsx` parapharmacy block is NOT the source of the reported restaurant leak.** Its gate, `config?.vertical === 'parapharmacy'` (`ProductForm.tsx:94`, used at `:645`), is fail-closed and correct: a restaurant tenant's `config.vertical` is the string `'restaurant'` (backend `config/verticals.php` `restaurant.name = 'restaurant'`), never `'parapharmacy'`, and `restaurant.default_modules` does not contain `Parapharmacy`. `ProductForm` also renders **no** batch/expiry fields, so `requires_batch_tracking=true` (true for restaurant) cannot surface pharmacy-ish fields there.

The most likely thing the tester actually saw is **one of two data-driven (not vertical-gated) leaks elsewhere in the web app**:

1. **`ProductInfoModal` (web POS product info)** renders an entire **"Parapharmacy" tab gated purely on data presence** — `hasParapharmacyData = !!product?.parapharmacy_metadata` (`ProductInfoModal.tsx:163`, `:211`), with **zero vertical/module check**. Any product carrying `parapharmacy_metadata` (e.g. seeded demo data, a product imported/migrated with that JSONB populated, or a shared-catalog product) shows pharmacy fields to a restaurant user.
2. **Parapharmacy feature routes are reachable by URL for any user with `settings.manage`** — `/parapharmacy/ingredients`, `/parapharmacy/certifications`, etc. are wrapped in `RequirePermission permission="settings.manage"` only, with **no `ModuleGuard module="Parapharmacy"`** (`routes/index.tsx:2154`+). The sidebar hides the nav group (correct, module-gated), but the routes are not module-gated, so a restaurant admin can deep-link straight to parapharmacy authoring screens.

Ranked root-cause verdict below. The structural problem is that **vertical/module gating in the web app is inconsistent**: three different gate strategies are mixed (config-vertical string, `hasModule`, build-time `isOtospex` env, and bare data-presence), and the routes layer does not module-guard vertical features.

---

## Root-cause analysis

### Data flow for `config.vertical`

- `ProductForm.tsx:93` `const { config } = useCompanyConfig()`.
- `useCompanyConfig` → `CompanyConfigContext.tsx`. The provider fetches `GET /company/config` via React Query (`CompanyConfigContext.tsx:56-62`), `enabled` only when authenticated + tenant + company present. Shape (`CompanyConfig`, `:11-22`): `{ vertical: string, default_modules[], enabled_extras[], all_enabled_modules[], ... }`. On no data, `config` is `null` (`:72`).
- Backend source: `apps/api/config/verticals.php` (read via `VerticalConfigService` → `CompanyConfigService`, per `lib/modules.ts` header). For `restaurant`: `name => 'restaurant'`, `default_modules` = Identity/Tenant/Catalog/Menu/Partner/Sales/Treasury/Accounting/Tables/CompositeItems — **no `Parapharmacy`**, `product => 'izipos'`, `product_defaults.requires_batch_tracking => true`.
- Therefore for a restaurant: `config.vertical === 'restaurant'`, `all_enabled_modules` excludes `Parapharmacy`.

### Hypotheses (ranked)

1. **(MOST LIKELY) Data-driven leak in `ProductInfoModal` — the tester viewed a product whose `parapharmacy_metadata` JSONB was populated.** The modal shows the Parapharmacy tab on `!!product?.parapharmacy_metadata` with no vertical gate (`ProductInfoModal.tsx:163,211,403`). This matches the symptom exactly ("saw product fields relevant only to parapharmacy") and is vertical-blind by construction. Likely trigger: demo/seed data, a product migrated from another tenant, or a catalog product carrying the field. **Confidence: high** given it is the only web surface that renders parapharmacy fields without a vertical/module gate.

2. **(LIKELY, if the tester navigated) Un-module-guarded parapharmacy routes.** A restaurant admin with `settings.manage` can open `/parapharmacy/*` by URL even though the sidebar hides it; those pages render parapharmacy authoring UI (`routes/index.tsx:2154`+, gated by `RequirePermission permission="settings.manage"` only). **Confidence: high that the gap exists; medium that it is what the tester hit** (requires deliberate/auto navigation rather than the product editor).

3. **(RULED OUT for `ProductForm`) `config.vertical === 'parapharmacy'` for a restaurant.** Impossible: backend `restaurant.name='restaurant'`. The gate is fail-closed on `undefined`/`null` too (`config?.vertical === 'parapharmacy'` → `false`). `ProductForm`'s parapharmacy block cannot render for a restaurant.

4. **(RULED OUT) `requires_batch_tracking` surfacing pharmacy fields in `ProductForm`.** `ProductForm` has no batch/expiry/lot fields at all (grep: 0 hits). The only conditional blocks are Automotive (`isOtospex`, `:552`) and Parapharmacy (`isParapharmacy`, `:645`). Note `restaurant.product_defaults.requires_batch_tracking=true` is a *backend* default; it is not consumed by `ProductForm` to render any field. (Whether the **backend** then attaches batch fields/requirements is a backend-agent hand-off.)

5. **(RULED OUT) `ProductDetailPage` (inventory read view).** Zero parapharmacy references (grep: 0). It renders Automotive info only, gated `isOtospex && (oem_numbers?.length || cross_references?.length)` (`ProductDetailPage.tsx:303`).

---

## FE gating inventory

| UI element | Gate expression | file:line | Correct? |
|---|---|---|---|
| ProductForm → Parapharmacy fields | `config?.vertical === 'parapharmacy'` | `features/inventory/ProductForm.tsx:94,645` | Correct but **fragile** (raw string, not `hasModule('Parapharmacy')`) |
| ProductForm → Automotive (OEM/cross-ref) | `isOtospex` (build-time env `VITE_APP_PRODUCT`) | `ProductForm.tsx:95,552` | Works per-build, but **not tenant-vertical aware** — an IziPOS-build automotive tenant would not see it; an Otospex build shows it to *all* Otospex verticals incl. non-automotive |
| ProductDetailPage → Automotive info | `isOtospex && (oem_numbers?.length \|\| cross_references?.length)` | `features/inventory/ProductDetailPage.tsx:303` | Build-env + data presence; no `Vehicle`/`Workshop` module check |
| **ProductInfoModal → Parapharmacy tab** | `!!product?.parapharmacy_metadata` (**data presence only**) | `features/pos/organisms/ProductInfoModal/ProductInfoModal.tsx:163,211,403` | **WRONG — no vertical/module gate** |
| Sidebar → Parapharmacy group | `hasModule('Parapharmacy')` | `components/organisms/Sidebar/Sidebar.tsx:302,366-380` | Correct (fail-closed) |
| Sidebar → Automotive group | `hasModule(['Vehicle','Workshop','PlatformIntegration'])` + `isAutomotiveVertical` for service placement | `Sidebar.tsx:284,331,382` | Correct |
| Sidebar → batches child | `hasModule(['BatchExpiry','Parapharmacy'])` | `Sidebar.tsx:204` | Correct, but **inconsistent** with hub card (below) |
| Sidebar → Menu/Tables/CompositeItems/Loyalty/Ecommerce | `hasModule(...)` | `Sidebar.tsx:187-189,215-216,243-244,230` | Correct |
| InventoryHub → Batches card | `hasModule('Parapharmacy')` | `features/inventory/pages/InventoryHubPage.tsx:89,132` | **Inconsistent** — sidebar gates the same page on `['BatchExpiry','Parapharmacy']`; a `BatchExpiry`-only (e.g. pharmacy) tenant sees the nav item but the hub card is hidden |
| ImportDashboard → inventory import types | `hasModule('Inventory')` | `features/import/pages/ImportDashboardPage.tsx:58` | Correct |
| CompositeItemForm | `hasModule('Inventory')` + `companyVerticalToCatalog(config.vertical)` | `features/catalog/pages/CompositeItemFormPage.tsx:42-43` | Correct |
| AnalyticsDashboard → F&B view | `FNB_VERTICALS.includes(config?.vertical ?? '')` | `features/pos/pages/AnalyticsDashboardPage/AnalyticsDashboardPage.tsx:41` | Raw-string vertical list (fragile but fail-closed) |
| PartsCatalog page | `(config?.vertical ?? 'mechanic')` **default to mechanic** | `features/parts-catalog/pages/PartsCatalogPage.tsx:28` | Defaulting an unknown vertical to `'mechanic'` is a code smell; page itself is module-guarded upstream |
| **Route: `/parapharmacy/*`** | `RequirePermission permission="settings.manage"` **only** | `routes/index.tsx:2154`+ | **WRONG — no `ModuleGuard module="Parapharmacy"`**; reachable by URL for any restaurant admin |
| ModuleGuard component | `hasModule(module)`, redirect on `!config`/error | `components/guards/ModuleGuard.tsx:49-68` | Correct & fail-closed — but **under-used** (parapharmacy routes don't use it) |

---

## Findings

### [HIGH] `ParapharmacyMetadataFields` gated on a raw vertical string, not `hasModule('Parapharmacy')`
**Evidence:** `ProductForm.tsx:94` `const isParapharmacy = config?.vertical === 'parapharmacy'`; used at `:645`.
**Impact:** Not the reported leak, but fragile. It diverges from the established `hasModule` pattern used by the sidebar/hub/ModuleGuard. A future vertical that enables the `Parapharmacy` module without `vertical==='parapharmacy'` (the config supports module enablement independent of vertical via `enabled_extras`/DB overrides) would *not* get the editor fields, while the sidebar nav and routes *would* show — a silent authoring gap. Conversely it tightly couples one capability to one vertical string.
**Recommendation:** Gate on `hasModule('Parapharmacy')` for consistency with all other module-conditional UI. (Backend-agent hand-off: confirm `Parapharmacy` is the canonical module name for these fields.)

### [HIGH] `ProductInfoModal` renders Parapharmacy tab on data presence with no vertical/module gate — most likely the leak the tester saw
**Evidence:** `features/pos/organisms/ProductInfoModal/ProductInfoModal.tsx:163` `const hasParapharmacyData = !!product?.parapharmacy_metadata`; tab trigger `:211`; tab content `:403`. No `useCompanyConfig`/`hasModule`/vertical reference anywhere in the file (grep: 0).
**Impact:** Any product whose `parapharmacy_metadata` JSONB is non-null displays a full parapharmacy tab (active ingredients, key components, health claims, certifications) to a restaurant user. Matches the bug report symptom precisely. Vertical-blind by construction.
**Recommendation:** Add a vertical/module gate: `hasParapharmacyData && hasModule('Parapharmacy')`. Same hand-off question on canonical module name. (Note: this modal lives under `apps/web/src/features/pos` — it is the **web** product-info modal, in scope; the Tauri desktop POS is a separate agent's surface and may have its own copy — flag for that agent.)

### [HIGH] Parapharmacy routes are not module-guarded — reachable by URL for any `settings.manage` user
**Evidence:** `routes/index.tsx:2155` `<Route path="parapharmacy">` with every child wrapped only in `<RequirePermission permission="settings.manage">` (e.g. `:2159`, `:2169`, `:2189`). No `<ModuleGuard module="Parapharmacy">` wrapper. Contrast: `ModuleGuard` exists and is imported (`routes/index.tsx:6`) and used elsewhere.
**Impact:** A restaurant admin (admins typically hold `settings.manage`) can deep-link to `/parapharmacy/ingredients`, `/parapharmacy/certifications`, `/parapharmacy/health-claims`, `/parapharmacy/key-components` and use the authoring UI, despite the sidebar correctly hiding the group. Defense-in-depth gap; also a candidate for what the tester saw if navigation auto-restored a stored route.
**Recommendation:** Wrap the `parapharmacy` route subtree in `<ModuleGuard module="Parapharmacy">`. (Backend-agent hand-off: confirm the API endpoints behind these pages also reject non-`Parapharmacy` tenants — FE guard alone is not authorization.)

### [MEDIUM] Automotive UI gated on build-time `isOtospex` env, not tenant vertical/module
**Evidence:** `ProductForm.tsx:95,552` and `ProductDetailPage.tsx:303` gate OEM/cross-reference UI on `useProductConfig().isOtospex`, which derives from `VITE_APP_PRODUCT` at build time (`contexts/ProductConfigContext.tsx:58-75,98`).
**Impact:** Gating is per-build, not per-tenant. In an Otospex build, **every** Otospex vertical (incl. non-automotive ones if any) sees automotive fields; in an IziPOS build, an automotive-capable tenant never would. Inconsistent with the sidebar's automotive gate, which correctly uses `hasModule(['Vehicle','Workshop','PlatformIntegration'])` (`Sidebar.tsx:284`). Lower severity because Otospex/IziPOS builds are vertical-segregated today, but it is the same class of fragility as the parapharmacy string gate.
**Recommendation:** Prefer `hasModule('Vehicle')` (or the relevant module) for field-level gating so the editor matches the nav gate.

### [MEDIUM] Inconsistent module gate for the Batches page (sidebar vs inventory hub)
**Evidence:** Sidebar batches child: `module: ['BatchExpiry', 'Parapharmacy']` (`Sidebar.tsx:204`). InventoryHub batches card: `requiredModule: 'Parapharmacy'` (`InventoryHubPage.tsx:89`, filtered `:132`).
**Impact:** A `BatchExpiry`-only tenant (e.g. `pharmacy`, whose `default_modules` includes `BatchExpiry` but not `Parapharmacy`) sees the Batches nav item but the hub card for the same page is hidden — confusing, and a discoverability inconsistency. Not a leak, but a correctness divergence in gate expressions for one destination.
**Recommendation:** Align both to the same module set (`['BatchExpiry','Parapharmacy']`).

### [LOW] `PartsCatalogPage` defaults unknown vertical to `'mechanic'`
**Evidence:** `features/parts-catalog/pages/PartsCatalogPage.tsx:28` `const vertical = (config?.vertical ?? 'mechanic') as TenantVertical`.
**Impact:** Fail-open default value; benign because the page is reached only via a `PlatformIntegration`-gated nav/route, but defaulting to an automotive vertical on missing config is a smell.
**Recommendation:** Default to a neutral/`generic` value or render nothing while config loads.

### [INFO] No central capability helper; gating is scattered across 4 strategies
**Evidence:** Gates use (a) `config?.vertical === '<string>'` (`ProductForm.tsx:94`, `AnalyticsDashboardPage.tsx:41`), (b) `hasModule(...)` (Sidebar, ModuleGuard, hubs), (c) build-env `isOtospex` (`ProductConfigContext`), (d) bare data presence (`ProductInfoModal.tsx:163`). `lib/modules.ts` provides only the `BackendModule` *type* (compile-time name safety) — not a runtime capability API.
**Impact:** No single source of truth for "can this tenant see capability X"; each surface re-decides, producing the inconsistencies above.
**Recommendation:** Centralize a small capability hook (e.g. `useCapabilities()` exposing `hasModule`, `isVertical`, `isAutomotiveVertical`) and route all vertical/module UI gating through it. `lib/modules.ts`'s role should expand from type-only to the canonical runtime helper, or a sibling `lib/capabilities.ts` should own it.

---

## Cross-cutting / hand-off

- **Backend agent:** (1) Confirm `restaurant` tenants never receive `Parapharmacy` in `all_enabled_modules` and `config.vertical` is exactly `'restaurant'` (FE relies on this for fail-closed gates). (2) Confirm the `/parapharmacy/*` API endpoints reject non-`Parapharmacy` tenants regardless of the FE route guard. (3) Confirm whether the **product read API** returns `parapharmacy_metadata` for products belonging to non-parapharmacy tenants — if the API leaks the JSONB, the `ProductInfoModal` data-presence gate is the visible symptom of a backend serialization leak. (4) Clarify whether `requires_batch_tracking=true` for `restaurant`/`coffee_shop` drives any backend-attached batch UI requirement the FE should reflect.
- **Tauri POS agent:** `ProductInfoModal` (web copy at `apps/web/src/features/pos/...`) has a parapharmacy tab gated only on data presence; check whether the desktop POS has an equivalent component with the same vertical-blind gate.
- **Most actionable single fix to stop the reported leak:** add a vertical/module gate to `ProductInfoModal.tsx:163` and module-guard the `/parapharmacy/*` routes.

---

## Files reviewed

- `apps/web/src/features/inventory/ProductForm.tsx`
- `apps/web/src/features/products/components/ParapharmacyMetadataFields.tsx`
- `apps/web/src/contexts/CompanyConfigContext.tsx`
- `apps/web/src/contexts/ProductConfigContext.tsx`
- `apps/web/src/lib/modules.ts`
- `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`
- `apps/web/src/components/guards/ModuleGuard.tsx`
- `apps/web/src/features/auth/config/verticals.ts`
- `apps/web/src/features/inventory/ProductDetailPage.tsx`
- `apps/web/src/features/pos/organisms/ProductInfoModal/ProductInfoModal.tsx`
- `apps/web/src/features/inventory/pages/InventoryHubPage.tsx`
- `apps/web/src/features/parts-catalog/pages/PartsCatalogPage.tsx`
- `apps/web/src/routes/index.tsx` (product + parapharmacy route blocks)
- `apps/api/config/verticals.php` (read-only, to confirm vertical names/modules — backend file, referenced not owned)
- Grep sweeps across `apps/web/src` for `vertical`, `hasModule`, `parapharmacy_metadata`, `ModuleGuard`, `=== 'parapharmacy'`
