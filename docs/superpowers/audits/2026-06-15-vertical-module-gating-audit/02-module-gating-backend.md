# Module Gating — Backend Enforcement Audit

Date: 2026-06-15
Scope: per-company module activation + API route gating (`RequireModule`). READ-ONLY.
Auditor domain: backend module gating only. Vertical assignment, product form, frontend, docs = other agents.

## Summary

Module gating is **vertical-driven and computed, not a stored allowlist**: a tenant's enabled
modules = its vertical's `default_modules` (from `config/verticals.php`) ∪ `tenants.enabled_extras`
(a JSON column, validated against the vertical's `compatible_extras` at write time). Enforcement is
the `RequireModule` middleware (alias `module:`), applied as a route-group middleware.

The **activation model is sound** — there is no "activate all modules" path, provisioning seeds
`enabled_extras = []` and relies on vertical defaults, and the admin extras-toggle correctly rejects
extras outside `compatible_extras`. The drift guard (`ModuleNameTest`) keeps the enum and config in lock-step.

**The enforcement layer, however, is largely unwired.** Only **5 of 22** modules are actually gated
in routes (`Inventory`, `Vehicle`, `Workshop`, `Ecommerce`, `Parapharmacy`). The vertical-exclusive
modules **`Menu`, `Tables`, `BatchExpiry`, `Prescription`, `Loyalty`, `Appointments`, `CompositeItems`,
`Reservation`, `Fleet`** have **no `RequireModule` gate anywhere**. The headline cornerstone risk is
real: a **restaurant tenant can reach the full Menu CRUD API**, and any non-pharmacy tenant can reach
the **BatchExpiry / batch-recall** API, because those route files are gated only by `auth + permissions`,
never by module. Treat each as HIGH.

## How module gating works

Resolved-modules source and enforcement path (file:line):

1. **Canonical module list** — `app/Enums/ModuleName.php:16-37` (22 cases).
2. **Per-vertical definition** — `config/verticals.php`: each vertical has `default_modules` (always-on)
   and `compatible_extras` (toggleable). E.g. restaurant defaults include `Menu`, `Tables`,
   `CompositeItems` (`config/verticals.php:79-90`); mechanic includes `Vehicle`, `Workshop`
   (`config/verticals.php:21-33`).
3. **Effective config (computed, cached)** — `app/Services/CompanyConfigService.php:48-76`:
   `allEnabledModules = array_unique(array_merge($defaultModules, $enabledExtras))`. `enabledExtras`
   is decoded from `tenants.enabled_extras` JSON (`:63`, `decodeExtras` `:125-138`). Cached in
   `GlobalCache` under `tenant_config:{tenant_id}`, 24h TTL (`:34-52`). **No `companies` row or
   `company_modules` table is consulted — gating is purely tenant-level / vertical-derived.**
4. **DTO membership test** — `app/DTOs/CompanyConfig.php:50-53`: `hasModule()` is a strict
   `in_array($module, $allEnabledModules, true)` (case-sensitive PascalCase contract).
5. **Route gate** — `app/Http/Middleware/RequireModule.php:38-67`: resolves the authed `User` →
   `$user->tenant` → `getConfigForTenant()` → `$config->hasModule($module)`; `abort(403, ...)` if absent
   (`:62-64`). Registered as alias `module:` (used in routes as `'module:Vehicle'`).
6. **Activation sync with config** — there is no separate sync; the merged list is recomputed live from
   `config/verticals.php` + the JSON column on every cache miss, so config edits propagate within 24h.
   Cache invalidation: `invalidateForTenant` / `invalidateForVertical`
   (`CompanyConfigService.php:84-109`).

Fiscal-engine consumer: `DefaultModuleActivationResolver.isActive()`
(`app/Modules/Fiscal/Application/Services/DefaultModuleActivationResolver.php:50-75`) delegates to the
exact same `CompanyConfigService` → `hasModule()` surface (no aliasing / case-folding), fail-closed on
unknown/malformed tenant id. Consistent with `RequireModule`.

## Route gating inventory

`module:` middleware appears in only 7 route files (5 distinct module names). Full enumeration:

| Module gate value | Route file | Gated? | Middleware stack |
|---|---|---|---|
| **Inventory** | `Inventory/Presentation/routes.php:25` | YES | api, sanctum, SetPermissionsTeam, EnforceTokenTenantClaim, `module:Inventory` |
| **Inventory** | `Catalog/Presentation/routes.php:56` (3rd group) | YES (that group only) | …, `module:Inventory` |
| **Inventory** | `Product/routes.php:42` (2nd group) | YES (that group only) | …, `module:Inventory` |
| **Parapharmacy** | `Product/routes.php:71` (nested) | YES | inherits Inventory group + `module:Parapharmacy` |
| **Vehicle** | `Vehicle/Presentation/routes.php:22` | YES | …, `module:Vehicle` |
| **Workshop** | `Workshop/Bundle/.../routes.php:14`, `WorkOrder/…:26`, `Technician/…:29` | YES | …, `module:Workshop` |
| **Workshop** | `Scheduling/Presentation/routes.php:52` | YES (see Finding M-2: name mismatch) | …, `module:Workshop` |
| **Ecommerce** | `Channel/Presentation/routes.php:25` | YES | …, `module:Ecommerce` |

Vertical-specific route files that are **NOT** module-gated (only `api + sanctum + SetPermissionsTeam +
EnforceTokenTenantClaim`, plus `can:` permissions):

| Module (conceptual) | Route file | Gated by `module:`? | Actual gate |
|---|---|---|---|
| **Menu** | `Menu/Presentation/routes.php:12` | **NO** | auth + permissions only |
| **BatchExpiry** | `BatchExpiry/Presentation/routes.php:11` | **NO** | auth + permissions only |
| **Tables** | (no module dir / no route group) | **NO** | n/a — no gate exists |
| **Prescription** | (no dedicated route group found) | **NO** | n/a — no gate exists |
| **Loyalty** | `Loyalty/Presentation/routes.php:25` | **NO** | auth + permissions only |
| **Appointments** | (Scheduling public storefront `:37`) | **NO** (public by design) | throttle + captcha |
| **CompositeItems** | (served via Catalog/Product groups) | **NO** | auth + permissions only |
| **Catalog/Product (base)** | `Catalog/…:18,33`, `Product/…:28` (1st groups) | **NO** (intentionally — Catalog is a default for every vertical) | auth + permissions |

Note: only the *Inventory-flavoured* Catalog/Product groups are gated; the base catalog groups
(categories, base product CRUD) are ungated, which is correct since `Catalog` is a `default_module` for
every vertical.

## Findings

### [HIGH] Menu module API reachable by any vertical (restaurant-only feature ungated)
**Evidence:** `app/Modules/Menu/Presentation/routes.php:12` —
`Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])` —
no `module:Menu`. Exposes `menus` CRUD, `menu-categories`, `active-menu` (`:14-31`). `Menu` is a
`default_module` only for `restaurant` and `coffee_shop` (`config/verticals.php:84,113`) and is a valid
`ModuleName` case (`ModuleName.php:28`).
**Impact:** A pharmacy/mechanic/retail tenant whose user holds the relevant `can:` permission can create
and read restaurant menus — the exact cornerstone violation (a non-restaurant getting restaurant
functionality). Whether it is reachable in practice depends on whether the permission is seeded for
non-restaurant roles; module gating is supposed to be the backstop and it is absent.
**Recommendation:** Add `'module:Menu'` to the group middleware in
`Menu/Presentation/routes.php:12`. Add a `MenuModuleAccessControlTest` mirroring the existing
`WorkshopModuleAccessControlTest`.

### [HIGH] BatchExpiry / batch-recall API reachable by verticals without the module
**Evidence:** `app/Modules/BatchExpiry/Presentation/routes.php:11` — group middleware has no
`module:BatchExpiry`. Exposes batch CRUD, `recall`, `transfer`, `write-off`, traceability, and a
`/pos/products/{id}/batches` POS feed (`:13-38`). `BatchExpiry` is a `default_module` only for
`pharmacy` and a `compatible_extra` for `pharmacy`/`parapharmacy` (`config/verticals.php:47,60,332`);
it is a valid `ModuleName` case (`ModuleName.php:27`).
**Impact:** A restaurant/retail/mechanic tenant can reach lot/expiry/recall endpoints that belong to the
regulated pharma flow. Cross-vertical functionality leak; also a compliance-surface leak.
**Recommendation:** Gate with `'module:BatchExpiry'`. Add an access-control test.

### [HIGH] No gate exists for `Tables`, `Prescription`, `Loyalty`, `CompositeItems` modules
**Evidence:** `grep "module:" app/Modules` returns only `Inventory`, `Vehicle`, `Workshop`, `Ecommerce`,
`Parapharmacy` — `Tables`, `Prescription`, `Loyalty`, `CompositeItems`, `Reservation`, `Fleet`,
`Appointments` never appear as a route gate. `Loyalty/Presentation/routes.php:25` is auth-only;
`Tables`/`Prescription` have no dedicated route group at all yet, but their `ModuleName` cases exist
(`ModuleName.php:29,31,36,30`) and they are vertical-exclusive in config (`Tables` → restaurant/coffee
extras `config/verticals.php:75,104`; `Prescription` → pharmacy extra `:47`; `Loyalty` → coffee/retail/
fashion/parapharmacy extras).
**Impact:** Where the functionality is served today (Loyalty), it is reachable by any tenant regardless of
vertical/extra. Where it is not yet served (Tables/Prescription), there is no gating pattern in place, so
the next route added inherits the ungated default. This is the systemic root cause: gating is opt-in per
route file and nothing fails CI when a vertical-specific route omits it.
**Recommendation:** (1) Gate `Loyalty` routes with `'module:Loyalty'`. (2) Establish a convention/lint
that any module whose `ModuleName` is NOT a `default_module` of *every* vertical must gate its route
group. Consider a registry test asserting each vertical-exclusive module name has at least one route
behind `module:<Name>`.

### [MEDIUM] Scheduling routes gated by `module:Workshop`, not `module:Appointments`
**Evidence:** `app/Modules/Scheduling/Presentation/routes.php:52` gates the scheduling/bays/appointments
group with `'module:Workshop'`. But appointment scheduling maps conceptually to the `Appointments`
extra, which is a `compatible_extra` for `mechanic`/`body_shop`/`car_glass`/`tire_shop`
(`config/verticals.php:17,186,245,275`) — and `Workshop` is a *default_module* for mechanic/body_shop/
car_glass but NOT for `tire_shop` (`:279-290`, no `Workshop`). So a tire_shop tenant that enabled the
`Appointments` extra still gets 403 on scheduling because it lacks `Workshop`; conversely the
`Appointments` extra has no enforcement of its own.
**Impact:** Mis-mapped gate: scheduling availability is keyed off the wrong module, so the intended
`Appointments` toggle does nothing and tire_shop (an Appointments-compatible vertical) is wrongly blocked.
**Recommendation:** Confirm intended module for scheduling. If it is `Appointments`, change the gate;
if scheduling truly requires `Workshop`, remove `Appointments` from those verticals' `compatible_extras`
or document the dependency.

### [LOW] `/pos/sync/menu` POS endpoint is ungated by `module:Menu`
**Evidence:** `app/Modules/POS/routes.php:101` — `Route::get('/pos/sync/menu', [SyncController::class, 'menu'])`
inside the auth-only POS group (`:35`). No `module:Menu`.
**Impact:** A non-restaurant tenant can pull the menu-sync payload (likely empty, but an unnecessary
surface). Lower severity because it is read-only sync, but it is the same class of gap as Finding 1.
**Recommendation:** Either wrap menu sync in `module:Menu` or have the controller return empty when the
module is inactive.

### [INFO] Activation/provisioning path is correct — no over-activation
**Evidence:** `TenantProvisioningService.php:85` seeds `'enabled_extras' => []`;
`AuthController.php:370` likewise on registration; the merged module list is derived from vertical
defaults only (`CompanyConfigService.php:57-66`). The one explicit extra mutation is
`TenantInitializationService::enableInventoryForFnbVerticals` (`:282-298`), narrowly scoped to
CoffeeShop/Restaurant + idempotent (documented as temporary). No code path activates "all modules".
**Impact:** None — positive finding. Provisioning honours vertical defaults.

### [INFO] Extras validated against `compatible_extras`; drift guard present
**Evidence:** Admin extras toggle `SuperAdminController::updateExtras` (`:263-314`) rejects any requested
extra not in `getCompatibleExtras($vertical)` with 422 (`:282-289`) and audit-logs the change. The
`ModuleNameTest` drift guard asserts the `ModuleName` enum value set **exactly equals** the union of
`default_modules` ∪ `compatible_extras` in `config/verticals.php`
(`tests/Unit/Enums/ModuleNameTest.php:55-67`), so no module name can appear in routes/config without an
enum case, and no stale enum case can survive.
**Impact:** None — the *write side* and *config consistency* are well guarded. The gap is purely on the
*route-enforcement* side (Findings 1-4): nothing forces a vertical-specific route to actually use the
gate.
**Caveat:** The drift guard covers enum↔config. It does NOT cover "every vertical-exclusive module has a
route gate" — that asymmetry is exactly why Menu/BatchExpiry slipped through.

## Cross-cutting / hand-off

- **Permissions layer (authorization agent):** The ungated routes (Menu, BatchExpiry, Loyalty) are still
  behind `can:` permission middleware. Whether the cornerstone leak is *exploitable today* depends on
  whether `RolesAndPermissionsSeeder` grants those permissions to roles in non-matching verticals. If
  permissions are seeded per-vertical, the practical blast radius shrinks — but module gating is meant to
  be the backstop and should not depend on permission seeding. Recommend the authz agent verify whether a
  restaurant role can hold `batches.*` / pharmacy permissions.
- **Vertical-assignment agent:** Confirm `tire_shop` should have `Appointments` as a compatible extra
  given scheduling is gated by `Workshop` (Finding M-2 / `config/verticals.php:275,279-290`).
- **Frontend agent:** `CompanyConfigController::show` (`:71-94`) returns `all_enabled_modules` to the SPA
  for nav rendering. Frontend hiding of nav items is cosmetic; backend gaps (Findings 1-4) are the real
  control. Note frontend nav may already hide Menu for non-restaurant — that masks but does not fix the
  API gap.
- **Docs agent:** No doc states the convention "vertical-exclusive module ⇒ must use `module:` gate."
  Worth codifying alongside CLAUDE.md rule 12 (route middleware pattern).

## Files reviewed

- `app/Http/Middleware/RequireModule.php`
- `app/Enums/ModuleName.php`
- `config/verticals.php`
- `app/DTOs/CompanyConfig.php`
- `app/Services/CompanyConfigService.php`
- `app/Http/Controllers/Api/CompanyConfigController.php`
- `app/Http/Controllers/Api/Admin/SuperAdminController.php` (updateExtras)
- `app/Modules/Fiscal/Application/Services/DefaultModuleActivationResolver.php`
- `app/Modules/Tenant/Application/Services/TenantInitializationService.php`
- `app/Modules/Tenant/Application/Services/TenantProvisioningService.php` (provisioning extras seed)
- `app/Modules/Identity/Presentation/Controllers/AuthController.php` (registration extras seed)
- All `app/Modules/*/routes.php` and `app/Modules/*/Presentation/routes.php` (middleware scan)
- `app/Modules/Menu/Presentation/routes.php`, `BatchExpiry/Presentation/routes.php`,
  `Scheduling/Presentation/routes.php`, `POS/routes.php`, `Workshop/*/routes.php`
- `tests/Unit/Enums/ModuleNameTest.php`
