# Ticket: `/api/v1/admin/marketplace/sellers` is a fleet-wide admin surface running on the tenant stack — needs a real redesign, not a guard swap

Raised 2026-08-05 from the pre-launch security lane. The live exposure is closed
for now by the `config('marketplace.enabled')` kill-switch (default **false**),
which de-registers the whole Marketplace HTTP surface. This ticket exists because
the flag is a **containment**, not a fix: the module cannot be turned on again
until the admin surface is redesigned.

## Finding — P1 (privilege escalation across the fleet; latent while the flag is off)

`app/Modules/Marketplace/Presentation/routes.php:47-65` mounts a group whose
controller declares super-admin, fleet-wide semantics:

- `MarketplaceSellerController::index()` — `#[CrossTenantRoute(reason: 'Super-admin
  marketplace management: lists all marketplace sellers across all tenants …')]`
  (`:21`), and the query is a bare `MarketplaceSeller::orderByDesc('created_at')`
  with no tenant predicate.
- `store()` (`:38`) validates and accepts **nullable `tenant_id` / `company_id`
  in the request body** — the caller picks which tenant the seller belongs to.
- `update()` (`:63`) — "edits any marketplace seller record across the fleet".
- `suspend()` (`:83`) — "suspends any marketplace seller across the fleet".

But the group runs on the ordinary **tenant** middleware stack —
`['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]`
— behind `can:marketplace.admin`. And `marketplace.admin` is granted to every
tenant admin: `database/seeders/RolesAndPermissionsSeeder.php` creates the
`marketplace.*` permissions and its `admin` role syncs the full permission set
(`permissionNames()`), so the ability is present in every tenant database.

Net effect, with the module enabled: **any tenant admin can create, edit and
suspend marketplace seller records, and can name another tenant's `tenant_id` /
`company_id` when creating one.** The `#[CrossTenantRoute]` attribute documents
the fleet-wide intent; nothing enforces that only the fleet operator invokes it.

### How far that reaches is TENANCY-MODE DEPENDENT — read this before quoting the finding

The controller has no tenant predicate, but the *connection* underneath it does,
so the blast radius differs per mode (corrected 2026-08-05 after adversarial
review; the earlier unqualified "reads/writes every tenant's sellers" phrasing
was wrong for the shipped topology):

- **database-per-tenant (`TENANCY_DB_PER_TENANT=true` — the live mode):**
  `marketplace_sellers|listings|orders` are per-tenant tables
  (`database/migrations/tenant/2026_03_10_400000..400002`) and
  `MarketplaceSeller` pins no `$connection` and carries no global scope
  (`Domain/Models/MarketplaceSeller.php:50-77`), so every query runs inside the
  caller's OWN tenant database. No cross-tenant read or write actually occurs —
  the same fact that makes the guard swap impossible (next section).
  **The real residual here is attribution spoofing:** `store()`
  (`Presentation/Controllers/MarketplaceSellerController.php:42-50`) accepts an
  arbitrary `tenant_id` / `company_id` in the body, so a tenant admin can plant
  seller rows attributed to *another* tenant inside their own DB. Those rows are
  inert today — and become fleet-visible the moment option 1(a) below (promote
  the registry to a central table) is executed, or any sync/aggregation job
  hoists per-tenant rows into a shared view.
- **legacy row-level mode (`config/tenancy_resolver.php:30`,
  `TENANCY_DB_PER_TENANT` defaults **false**):** all tenants share one database,
  so the missing predicate IS a live fleet-wide read/write of every tenant's
  seller rows, exactly as the bullets above describe.

Related, same route file, lower severity: `marketplace.browse` (listings index /
show / price-comparison, `:16-26`) is also seeded to **all** roles, so every
authenticated user of every tenant could read the listing catalog reachable from
their connection. Cross-tenant browsing is the module's *design intent*
(anonymised listings), so it is a product question rather than a defect — but it
is part of what the flag currently closes.

## Why the obvious fix does not work

The tempting one-line fix is to move the group onto the super-admin guard
(`super_admin` middleware / the `sanctum-admin` central guard). **That yields a
500, not a fix.**

Under database-per-tenant (Stancl `PostgreSQLDatabaseManager`), the super-admin
surface runs in **CENTRAL** context: the request never initializes a tenant, so
the default connection stays pointed at `synerivia_central`. But
`marketplace_sellers`, `marketplace_listings` and the marketplace order tables
are **per-tenant** tables — they live in `database/migrations/tenant/`
(`2026_03_10_400000_create_marketplace_sellers_table.php`,
`…400001_create_marketplace_listings_table.php`,
`…400002_create_marketplace_orders_tables.php`) and are therefore created only
inside each `tenant_<uuid>` database. A guard swap makes every query in
`MarketplaceSellerController` resolve against the central DB →
`relation "marketplace_sellers" does not exist`.

So "fleet-wide seller management" is not a middleware change. It needs a design
decision about where marketplace seller identity actually lives.

## What the redesign has to answer (T7 productization)

1. **Where does the seller registry live?** Either (a) promote
   `marketplace_sellers` to a central table (fleet-wide by construction; the
   per-tenant rows become a projection/link), or (b) keep it per-tenant and make
   the admin surface a super-admin endpoint that **iterates tenants explicitly**
   (`TenantScopedCommand::forEachTenant`-style) to compose the fleet view. Option
   (b) makes list/paginate across the fleet awkward and is the reason this is a
   design task, not a patch.
   **Carry the spoofed-attribution residual into this decision:** today `store()`
   lets a tenant admin write seller rows bearing another tenant's `tenant_id` /
   `company_id` into their own tenant DB (see the mode-dependence note above).
   Option (a) promotes exactly those rows into the fleet-wide registry, so
   promotion MUST be preceded by (i) removing `tenant_id` / `company_id` from the
   request body for tenant-scoped callers, and (ii) an audit/quarantine pass over
   existing rows whose `tenant_id` differs from the owning database's tenant.
2. **Who is the actor?** If it is the fleet operator, the surface belongs under
   the super-admin routes (`routes/api.php` super-admin group + `super_admin`
   middleware), and `marketplace.admin` should stop being a tenant permission
   granted by `RolesAndPermissionsSeeder`.
3. **Is there a legitimate tenant-scoped subset?** A tenant managing *its own*
   seller profile is a reasonable feature — but it is a different endpoint with a
   `where tenant_id = current` predicate and no `tenant_id` in the request body.
4. **`marketplace.browse` scope** — confirm the anonymised cross-tenant catalog is
   the intended product, and whether it should be a paid/vertical-gated module
   rather than a permission seeded to every role.
5. **Module gating** — `Marketplace` is not a case of the `ModuleName` enum, and
   `ModuleNameTest` pins that enum to the union of `config/verticals.php`, so no
   `module:Marketplace` middleware exists. Deciding which verticals sell the
   module is part of the same product decision. Tracked alongside the other
   enum-invisible modules in
   `docs/superpowers/audits/2026-06-15-vertical-module-gating-audit/README.md`.

## Interim state (shipped 2026-08-05)

- `config('marketplace.enabled')` (env `MARKETPLACE_ENABLED`, default **false**)
  now gates: Marketplace route registration, the `marketplace:delta-sync` /
  `marketplace:reconcile` schedule entries, and the
  `catalog-carts.marketplace-checkout` endpoint in the Cart module.
- Console command **registration** stays unconditional so an operator can still
  run a reconciliation by hand while the module is dark.
- The Cart → Marketplace **application** path is closed too (added 2026-08-05
  after adversarial review, finding I-1): `catalog-carts.items.store` stays
  registered for procurement, but with the flag off its payload rejects
  `source=marketplace` and `marketplace_listing_id` with a 422 at the validation
  layer, so `CartService::addItem()` can no longer reach
  `MarketplaceListing::findOrFail()` / `MarketplaceOrderService::reserveForCart()`
  (which would create a stock reservation and consume the anti-abuse counter
  while the module is dark).
- Pinned by `tests/Feature/Security/MarketplaceFlagGatingTest.php` (closed state),
  `tests/Feature/Security/MarketplaceCartItemGatingTest.php` (closed cart path),
  `tests/Feature/Security/MarketplaceFlagEnabledTest.php` (open state — every
  `can:` gate still attached) and
  `tests/Feature/Security/MarketplaceFlagEnabledAuthorizationTest.php` (open
  state — the `can:marketplace.browse` gate actually denies a role without the
  permission, and the cart marketplace path works again).
- The whole `tests/Feature/Security` directory now runs in CI (`backend-test`
  job, `ci.yml`); before 2026-08-05 it ran in no gate at all.
- No client consumes these endpoints today: `apps/web`, `apps/pos` and
  `apps/mobile` contain zero references to `api/v1/marketplace` or
  `marketplace-checkout`, so the flag has no user-visible effect.

**Do not set `MARKETPLACE_ENABLED=true` in any environment until item 1 and item 2
above are resolved** — turning it on restores the tenant-admin-can-manage-the-fleet
exposure exactly as described.

## Gotcha for whoever implements this

The Marketplace routes file is `include`d **independently of its service
provider**: `config/event-sourcing.php` points
`auto_discover_projectors_and_reactors` at `app()->path()`, and
`Spatie\EventSourcing\Support\DiscoverEventHandlers::addToProjectionist()` maps
every file under `app/` to a PSR-4 class name and calls `is_subclass_of()` on it,
which makes Composer's autoloader `include` `…/Presentation/routes.php`. Verified
2026-08-05 with a backtrace taken from inside the routes file
(`ClassLoader::loadClass` ← `is_subclass_of` ← `DiscoverEventHandlers:67` ←
`EventSourcingServiceProvider::packageBooted`). Consequence: **under a
non-authoritative autoloader a condition around `loadRoutesFrom()` in a service
provider is NOT a route gate** — the guard has to be inside the routes file
itself. The mechanism is generic to every module in `app/Modules/`, not just
Marketplace.

**But it is autoloader-dependent — do NOT model production from it** (corrected
2026-08-05 after adversarial review). The probe can only reach a class-less
routes file through Composer's **PSR-4 fallback**:

- **dev / CI** (`composer install`, non-authoritative): fallback is live, the
  routes file IS included, the in-file guard is load-bearing.
- **production image**: `apps/api/Dockerfile` runs
  `composer dump-autoload --optimize --classmap-authoritative`, and
  `vendor/composer/ClassLoader::findFile()` returns `false` immediately when
  `classMapAuthoritative` is set. A routes file declares no class, so it is not
  in the classmap and the fallback never runs — discovery cannot include it, and
  the **provider condition is the gate**.

Both guards are therefore required, and a CI guard written for this pattern must
assert both (in-file early return *and* provider condition), not just the
in-file one.
