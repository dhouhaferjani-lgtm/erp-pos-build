# Vertical & Module Gating — Canonical Reference

**Status:** Canonical. This document supersedes the per-vertical / module
descriptions in [`verticals.md`](verticals.md), [`module-loading.md`](module-loading.md),
and [`security.md`](security.md) wherever they disagree.

**Single source of truth for module lists:** `apps/api/config/verticals.php`.
Everything in this document is reproduced from that config plus the enums and
services listed under [Source of truth & overrides](#source-of-truth--overrides).
If the config and this document ever diverge, the config wins — fix the document.

---

## Why this document exists

A teammate did a fresh **Restaurant** signup and was confused to see
pharmacy/parapharmacy-flavoured product fields. The root cause was a
documentation gap: the repo-root `claude/` docs describe a *different* codebase
(the Synerivia platform), and the ERP's own vertical/module model was never
written down in one place. This document fills that gap.

The short version: **a tenant only ever sees the modules its vertical activates.**
Vertical-specific product metadata (parapharmacy health data, automotive fitment
data) is gated by the tenant's vertical on both the backend and the frontend, so
a Restaurant tenant cannot see or persist parapharmacy fields. See
[Vertical-specific product metadata](#vertical-specific-product-metadata).

---

## Two applications, each with its own verticals

The ERP codebase serves **two distinct products** from one shared
infrastructure (same Laravel API, same database-per-tenant topology, same
deploy). They are not the same product with a theme switch — they have
**disjoint vertical sets** and different default modules.

| Product | Brand | Domain | Verticals (from `Vertical::product()`) |
|---|---|---|---|
| **Otospex** | Automotive | Auto service & parts businesses | `mechanic`, `body_shop`, `parts_retailer`, `car_glass`, `tire_shop`, `service_station` |
| **IziPOS** | Generic retail | Retail, hospitality, health retail | `pharmacy`, `restaurant`, `coffee_shop`, `retail`, `fashion`, `parapharmacy` |

The mapping lives in `App\Enums\Vertical::product()` (returns `'otospex'` or
`'izipos'`). `Vertical::isAutomotive()` is `product() === 'otospex'`. There are
exactly **12 verticals**, six per product.

A tenant belongs to **one** vertical, stored in `tenants.vertical` (cast to the
`Vertical` enum). The vertical is chosen at signup and determines which product
experience and which modules the tenant gets.

---

## Verticals activate DEFAULT modules

On provisioning, the tenant's chosen vertical determines its **`default_modules`**
— the modules turned on automatically with no admin action. These come straight
from `config/verticals.php`. For example a `mechanic` gets `Vehicle` + `Workshop`;
a `restaurant` gets `Menu` + `Tables` + `CompositeItems`; a `parapharmacy` gets
the `Parapharmacy` master-data module.

`default_modules` always include the core platform modules every business needs
(`Identity`, `Tenant`, `Catalog`, `Partner`, `Sales`, `Treasury`, `Accounting`)
plus the vertical-specific ones.

---

## Verticals can UPGRADE into more modules later

Each vertical also declares **`compatible_extras`** — optional modules the
vertical is *allowed* to turn on later. Think of these as the **upgrade path**;
some are paid or optional add-ons (e.g. `Ecommerce`, `Loyalty`, `Reservation`).

- An extra is enabled per tenant by a super-admin via
  `App\Http\Controllers\Api\Admin\SuperAdminController::updateExtras`
  (`PATCH` on the tenant), which writes the array to `tenants.enabled_extras`.
- `updateExtras` **validates against the vertical's `compatible_extras`** and
  rejects anything outside that list with HTTP **422** (`array_diff` of the
  requested extras against `getCompatibleExtras($vertical)`; the response
  includes `valid_extras`). A coffee shop cannot, for instance, enable
  `Prescription` — it is not in coffee shop's `compatible_extras`.
- A tenant's **effective** module set is therefore
  `default_modules ∪ enabled_extras` (see
  `App\Services\CompanyConfigService::getConfigForTenant`, which `array_unique`s
  the merge into `all_enabled_modules` and caches it per tenant for 24h via
  `GlobalCache`).

Note the overlap convention: a few verticals list a default module *also* in
`compatible_extras` (e.g. pharmacy has `BatchExpiry` as a default **and** as an
extra). The default already enables it; its presence in `compatible_extras` is
harmless because the union dedupes.

---

## Source of truth & overrides

| Concern | Where |
|---|---|
| Vertical identity, product mapping, labels | `App\Enums\Vertical` |
| Canonical module names (22 cases) | `App\Enums\ModuleName` |
| **Default modules + compatible extras + product_defaults** | **`config/verticals.php`** (SoT) |
| Reading vertical config (DB-first w/ overrides) | `App\Services\VerticalConfigService` |
| Central per-vertical override rows | `App\Models\VerticalConfig` (table `vertical_configs`) |
| Per-tenant effective module set (merge + cache) | `App\Services\CompanyConfigService` |
| Enabling/validating extras for a tenant | `SuperAdminController::updateExtras` |

`VerticalConfigService` reads `config/verticals.php` **DB-first**: a row in the
central `vertical_configs` table overrides `default_modules` and/or
`compatible_extras` **per field** (a `null` jsonb column falls back to the config
file value). The DB lookup is cached for 24h in Stancl's `GlobalCache` (not the
`Cache` facade — see the docblocks in `VerticalConfigService`/`CompanyConfigService`
for the cache-topology reasoning). Override changes are invalidated via
`CompanyConfigService::invalidateForVertical`.

> **Deleted method — do not re-add.** `Vertical::defaultModules()` and
> `Vertical::compatibleExtras()` used to live on the enum. They drifted from the
> config and were **deleted**. Module lists belong in `config/verticals.php`,
> read via `VerticalConfigService` (constructor-injected). The enum docblock
> records this. Do not reintroduce module lists onto the enum.

---

## Enforcement (both layers)

Module gating is enforced on **both** the backend and the frontend. Neither layer
alone is sufficient — the backend is the security boundary; the frontend hides UI
the tenant can't use.

### Backend — `RequireModule` middleware

`App\Http\Middleware\RequireModule` (alias `module`) resolves the tenant's
`CompanyConfig` and aborts **403** if the requested module is not in
`all_enabled_modules`:

```php
Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, 'module:Vehicle'])
    ->group(function (): void {
        // ... vertical-exclusive routes
    });
```

The module name is **case-sensitive** and must match a `ModuleName` case exactly
(`module:Vehicle`, not `module:vehicle`). Module middleware sits *after*
`auth:sanctum` + `SetPermissionsTeam` and is layered on top of the usual
`can:<permission>` gates — module gating and permission gating are independent.

### Frontend — `hasModule()` / `RequirePermission moduleKey`

`useCompanyConfig()` (`apps/web/src/contexts/CompanyConfigContext.tsx`) fetches
`GET /api/v1/company/config` and exposes
`hasModule(name) => all_enabled_modules.includes(name)`. Route-level gating uses
`<RequirePermission moduleKey="...">`
(`apps/web/src/features/auth/components/RequirePermission.tsx`), which calls
`canAccessModule(moduleKey)` and redirects to `/dashboard` (or renders a fallback)
when access is denied. Inline UI gates fields/sections directly with
`hasModule(...)`.

### How to gate a new vertical-exclusive feature

1. **Backend route:** add `module:<Name>` to the feature's `routes.php` middleware
   array (keep the `['api', 'auth:sanctum', SetPermissionsTeam::class, …]` prefix
   from CLAUDE.md rule 12). `<Name>` must be a `ModuleName` case.
2. **Frontend route:** wrap the route with
   `<RequirePermission moduleKey="...">` (or a `ModuleGuard`).
3. **Vertical-specific fields/sections:** gate them inline with
   `hasModule('<Name>')` so they never render for the wrong vertical.
4. **Test:** add a module-access-control test asserting the wrong vertical gets
   **403** from the backend route (see `tests/Feature/Security/*` and
   `tests/Feature/Product/*` for the pattern).

---

## Vertical-specific product metadata

This is the rule that prevents the reported "Restaurant sees parapharmacy data"
bug. Product records can carry two kinds of **vertical-specific metadata**:
`parapharmacy_metadata` (health-product data) and `automotive_metadata`
(fitment/cross-reference data). Both are gated by the tenant's vertical on both
layers:

- **Backend read** (`ProductController`): vertical-specific relations are only
  eager-loaded when the tenant matches —
  `parapharmacyMetadata.*` only when `tenant->vertical === Vertical::Parapharmacy`,
  `automotiveMetadata.*` only when `tenant->vertical->isAutomotive()`. A
  Restaurant (or any non-matching vertical) never receives this metadata in the
  product payload.
- **Backend write — validation guard** (`CreateProductRequest` /
  `UpdateProductRequest`): a `withValidator` hook **rejects** any presence of
  `parapharmacy_metadata` with HTTP **422** unless `vertical ===
  Vertical::Parapharmacy`, and `automotive_metadata` unless
  `vertical->isAutomotive()`. It checks `exists()` (key present), so `null` and
  `[]` are rejected too — a bare `prohibited` rule would let those through. A
  mismatched tenant gets a clear validation error rather than a
  silently-discarded payload. The controller (`ProductController`
  `store`/`update`) additionally only persists the metadata when the vertical
  matches, as defence-in-depth. Regression coverage:
  `tests/Feature/Product/ProductMetadataVerticalGuardTest.php` (422 rejection
  incl. null/`[]`, plus allow paths) and `ParapharmacyProductTest` /
  `AutomotiveProductTest`.
- **Why vertical, not module, for this metadata:** automotive metadata spans six
  verticals via `isAutomotive()` and has **no single "Automotive" module**, so
  product metadata is authorised by **vertical** on every layer (read, write,
  frontend). Do not gate these specific fields by `hasModule(...)` — that would
  diverge from the backend authority. (Module gating is the right tool for whole
  features/routes such as the master data below, not for this metadata.)
- **Parapharmacy master data** (ingredients, certifications, health-claims,
  key-components endpoints) is a distinct feature gated behind `module:Parapharmacy`
  in `app/Modules/Product/routes.php`. Under the current static config only the
  parapharmacy vertical has that module by default, so only it can reach those
  routes; everyone else gets **403**. The web routes mirror this with
  `<ModuleGuard module="Parapharmacy">`.
- **Frontend:** the parapharmacy product-metadata fields (the `ProductForm`
  section and the POS `ProductInfoModal` tab) are gated on
  `config.vertical === 'parapharmacy'`, matching the backend write authority, so
  the UI never invites input the API would 422-reject.

> If you find vertical-specific product fields rendering for the wrong vertical,
> the bug is a missing gate on one of these layers — not "bad seed data."

---

## The full vertical → module matrix

Reproduced exactly from `apps/api/config/verticals.php`. **`+` marks a default
module that is also listed in `compatible_extras`** (already on by default; the
extra entry is a harmless dedupe).

| Vertical | Product | `default_modules` | `compatible_extras` |
|---|---|---|---|
| `mechanic` | otospex | Identity, Tenant, Catalog, Vehicle, Partner, Workshop, Sales, Inventory, Treasury, Accounting, PlatformIntegration | Appointments, Fleet |
| `body_shop` | otospex | Identity, Tenant, Catalog, Vehicle, Partner, Workshop, Sales, Inventory, Treasury, Accounting, PlatformIntegration | Appointments, Fleet |
| `car_glass` | otospex | Identity, Tenant, Catalog, Vehicle, Partner, Workshop, Sales, Inventory, Treasury, Accounting, PlatformIntegration | Appointments, Fleet |
| `parts_retailer` | otospex | Identity, Tenant, Catalog, Vehicle, Partner, Sales, Inventory, Treasury, Accounting, PlatformIntegration | Ecommerce |
| `tire_shop` | otospex | Identity, Tenant, Catalog, Vehicle, Partner, Sales, Inventory, Treasury, Accounting, PlatformIntegration | Appointments |
| `service_station` | otospex | Identity, Tenant, Catalog, Partner, Sales, Inventory, Treasury, Accounting, PlatformIntegration | _(none)_ |
| `pharmacy` | izipos | Identity, Tenant, Catalog, Partner, Sales, Inventory, Treasury, Accounting, BatchExpiry | BatchExpiry +, Prescription, Ecommerce |
| `parapharmacy` | izipos | Identity, Tenant, Catalog, Partner, Sales, Inventory, Treasury, Accounting, Parapharmacy | BatchExpiry, Loyalty, Ecommerce |
| `restaurant` | izipos | Identity, Tenant, Catalog, Menu, Partner, Sales, Treasury, Accounting, Tables, CompositeItems | Tables +, Reservation, Inventory |
| `coffee_shop` | izipos | Identity, Tenant, Catalog, Menu, Partner, Sales, Treasury, Accounting, CompositeItems | Tables, Loyalty, Inventory |
| `retail` | izipos | Identity, Tenant, Catalog, Partner, Sales, Inventory, Treasury, Accounting | Loyalty, Ecommerce |
| `fashion` | izipos | Identity, Tenant, Catalog, Partner, Sales, Inventory, Treasury, Accounting | Loyalty, Ecommerce |

Notes worth calling out:

- `restaurant` and `coffee_shop` do **not** have `Inventory` as a default — it is
  a `compatible_extra` they can upgrade into. (Their `Sales`/`CompositeItems`
  flow doesn't require stock by default.)
- `pharmacy` lists `BatchExpiry` and `restaurant` lists `Tables` in *both*
  `default_modules` and `compatible_extras` (the `+` rows above).

---

## Module catalog

Every `ModuleName` case (22 total). "Class" is how the module appears across
verticals: **core** = a default for every vertical; **vertical-default** = a
default for some verticals only; **upgrade-extra** = only ever appears in
`compatible_extras`.

| Module | Class | Purpose |
|---|---|---|
| `Identity` | core | Users, roles, permissions |
| `Tenant` | core | Tenant / company / subscription context |
| `Catalog` | core | Product catalogue & categories |
| `Partner` | core | Customers & suppliers |
| `Sales` | core | Sales / POS / documents |
| `Treasury` | core | Payments, cash drawer, instruments |
| `Accounting` | core | Journal entries, GL, chart of accounts |
| `Inventory` | core* | Stock levels & locations. Default for most verticals; an *extra* for `restaurant`/`coffee_shop`. Product CRUD routes are gated `module:Inventory`. |
| `Vehicle` | vertical-default | Vehicle records — automotive (Otospex) verticals. |
| `Workshop` | vertical-default | Work orders — `mechanic`, `body_shop`, `car_glass`. |
| `PlatformIntegration` | vertical-default | Synerivia platform catalogue link — all Otospex verticals. |
| `BatchExpiry` | vertical-specific | Batch / expiry tracking — default for `pharmacy`; extra for `pharmacy`/`parapharmacy`. |
| `Menu` | vertical-specific | Menu items — `restaurant`, `coffee_shop`. |
| `Tables` | vertical-specific | Table / floor management — default for `restaurant`; extra for `restaurant`/`coffee_shop`. |
| `CompositeItems` | vertical-default | Composite / recipe products — `restaurant`, `coffee_shop`. |
| `Parapharmacy` | vertical-specific | Parapharmacy master data (ingredients, certifications, health claims) — `parapharmacy` only. |
| `Appointments` | upgrade-extra | Booking / scheduling — Otospex verticals (extra). |
| `Fleet` | upgrade-extra | Fleet management — `mechanic`, `body_shop`, `car_glass` (extra). |
| `Prescription` | upgrade-extra | Prescription tracking — `pharmacy` (extra). |
| `Reservation` | upgrade-extra | Table reservations — `restaurant` (extra). |
| `Loyalty` | upgrade-extra | Loyalty / rewards — `coffee_shop`, `retail`, `fashion`, `parapharmacy` (extra). |
| `Ecommerce` | upgrade-extra | Online sales channel — `pharmacy`, `parts_retailer`, `retail`, `fashion`, `parapharmacy` (extra). |

\* `Inventory` is configured as a default for 10 of 12 verticals and an extra for
the remaining two, so it is effectively core but **not** universally a default —
do not assume it is always present.

The vertical-specific modules to be mindful of when gating UI/data are
`Parapharmacy`, `Menu`, `Tables`, `BatchExpiry`, `Prescription`, `Loyalty`,
`Vehicle`, `Workshop`.

---

## QA: choosing a vertical for testing

**Set the vertical** by doing a fresh signup and selecting the business type at
the **Business** step of the registration wizard — that choice writes
`tenants.vertical` and provisions the matching `default_modules`. (Existing
tenants' verticals can be inspected/changed by a super-admin.)

**Verify it** with the config endpoint the frontend itself uses:

```
GET /api/v1/company/config        (auth:sanctum)
```

The response `data` includes `vertical`, `default_modules`, `enabled_extras`,
`compatible_extras`, and **`all_enabled_modules`** (the effective set). Confirm
`vertical` is what you selected and that `all_enabled_modules` matches the matrix
row above. If a module you expect is missing, check whether it's an *extra* that
needs enabling via `updateExtras`.

### Gotcha: a Restaurant legitimately has batch/expiry concepts

`config/verticals.php` sets `product_defaults.requires_batch_tracking = true`
for **perishable** verticals — `restaurant`, `coffee_shop`, `pharmacy`,
`parapharmacy` — because their ingredients/stock expire. So seeing
batch/expiry-flavoured behaviour in a **Restaurant** test tenant is **expected**
and is *not* parapharmacy data leaking in. Parapharmacy *product metadata*
(health claims, dosage, certifications) is a different thing entirely and is
gated as described in [Vertical-specific product metadata](#vertical-specific-product-metadata).

> **Known refinement (flagged, not yet implemented):** `requires_batch_tracking`
> is currently a config-level `product_defaults` flag per vertical, read via
> `VerticalConfigService::getProductDefaults()`. It is *not* yet a per-product
> opt-in or a paid module — every product in a perishable vertical inherits the
> flag. Making batch tracking a per-product toggle / billable module is a future
> refinement.

---

## Known gaps / follow-ups

These are tracked hardening items from the 2026-06-15 vertical/module gating
audit. They do **not** change the model above; they close defence-in-depth
holes.

- **Ungated vertical-exclusive routes (HIGH) — DONE (2026-06-17).** Menu,
  BatchExpiry, Loyalty, and composite-items routes are now gated with
  `module:Menu` / `module:BatchExpiry` / `module:Loyalty` / `module:CompositeItems`,
  each with a `*ModuleAccessControlTest` (`tests/Feature/Security/`). Existing
  feature tests were updated to provision the enabling module/vertical.
- **CompositeItems vs Inventory split — DONE, industry-researched, then refined
  to the decoupled model (2026-06-18).** A deep-research pass (Toast, Lightspeed,
  MarketMan, Odoo, NetSuite, Cin7, Katana) found two valid patterns; the owner
  chose the **progressive-growth (decoupled)** one: composite-item /recipe
  **definition + theoretical costing are independent of inventory**, and inventory
  is required only for **stock-aware behaviours**. So in `Catalog/Presentation/routes.php`,
  everything under `module:CompositeItems` — composite-item CRUD + availability,
  **menu modifiers**, and **recipes / recipe-lines / `calculate-cost` / composite-item
  variants**. `calculate-cost` rolls up each component's `cost_price` field (set
  manually before any stock ledger, kept accurate by WAC once Inventory is on).
  The **Inventory** module's role is the stock-aware layer in the SELL path:
  accurate WAC costing, ingredient depletion, and recipe-driven availability /
  **86-ing** (enforced via `PosStockPolicy` + `CompositeItemAvailabilityService`
  when inventory tracking is active — the enforcement wiring is a pending build).
  See `docs/superpowers/research/2026-06-16-composite-items-recipe-inventory-industry-standards.md`.
- **Cross-vertical CompositeItems / BOM — DONE (2026-06-18).** `CompositeItems`
  is now a `compatible_extra` for **retail** (kits/bundles), **fashion** (garment
  BOM — a shirt = fabric + buttons + thread) and **parapharmacy** (gift sets);
  those verticals already have `Inventory` as a default module, so recipes/costing
  work once the extra is enabled. The frontend `useVerticalLabels` hook maps
  `fashion → 'sewing'` and relabels "recipe" as "Bill of Materials" (i18n
  `vertical.sewing.*`). The composite/recipe/BOM domain + services + the label
  hook already existed — this was config + i18n only.
- **Per-product batch tracking — DONE (2026-06-18).** `requires_batch_tracking`
  now **defaults `false`** for restaurant/coffee_shop (opt-in), and is exposed as
  a per-product **"Track batches / expiry"** checkbox in `ProductForm` (shown only
  when the tenant has `BatchExpiry` or `Inventory`). The per-product column +
  `default_shelf_life_days` already existed. Pharmacy/parapharmacy keep the
  default on. Model = module gates availability (the upgrade); per-product flag
  controls granularity — matching the industry per-item toggle.
- **Frontend ModuleGuard parity — DONE (2026-06-18).** Web routes for menus
  (Menu), batches (BatchExpiry), composite-items + modifier-groups
  (CompositeItems) and loyalty (Loyalty) are wrapped in `<ModuleGuard>`; the
  Sidebar Batches nav now gates on `BatchExpiry` (matching the backend).
- **Parapharmacy gets `BatchExpiry` as a default module (2026-06-18).** Its
  products are expiry-tracked, so batch management is a default capability (was
  only a compatible_extra, which the `module:BatchExpiry` gate would have locked
  out).

### Restaurant / coffee_shop inventory is an opt-in upgrade (by design)

This is **not** a gap — it is the intended model, confirmed in code:

- `Inventory` is a **`compatible_extra`** for `restaurant` (`['Tables',
  'Reservation', 'Inventory']`) and `coffee_shop` (`['Tables', 'Loyalty',
  'Inventory']`), **not** a default module. A restaurant/coffee shop can operate
  **without** inventory tracking and later **upgrade into** proper inventory
  tracking via `enabled_extras` (see "Upgrading into more modules" above).
- Once on inventory, sellable dishes/drinks are modelled as **composite items
  with recipes**: `CompositeItem` → `Recipe` (`yield_quantity` / `yield_unit`) →
  `RecipeLine` (each line is a component `product`/`variant` with a `quantity`
  and `unit`). Ingredients are ordinary catalog **products** (including
  primary ingredients that live in other verticals' product space).
- `CompositeItemAvailabilityService::checkAvailability($item, $locationId)`
  computes the **maximum producible quantity** of a dish from on-hand ingredient
  stock — `min(available_stock / recipe_quantity)` across all required *leaf*
  components, recursing through nested composite items down to primary
  ingredients, per location. (`app/Modules/Catalog/Application/Services/`.)

## Related code (quick links)

- `apps/api/config/verticals.php` — **SoT** for module lists
- `apps/api/app/Enums/Vertical.php` — verticals + product mapping
- `apps/api/app/Enums/ModuleName.php` — canonical module names
- `apps/api/app/Services/VerticalConfigService.php`
- `apps/api/app/Services/CompanyConfigService.php`
- `apps/api/app/Models/VerticalConfig.php`
- `apps/api/app/Http/Middleware/RequireModule.php`
- `apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php` (`updateExtras`)
- `apps/api/app/Http/Controllers/Api/CompanyConfigController.php` (`GET /company/config`)
- `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php` (metadata gating)
- `apps/api/app/Modules/Product/routes.php` (`module:Inventory`, `module:Parapharmacy`)
- `apps/web/src/contexts/CompanyConfigContext.tsx` (`useCompanyConfig`, `hasModule`)
- `apps/web/src/features/auth/components/RequirePermission.tsx` (`moduleKey`)
