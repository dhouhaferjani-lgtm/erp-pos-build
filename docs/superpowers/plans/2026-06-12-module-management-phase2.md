# Module Management — Phase 2 (production hardening, pre-launch)

Owner decisions (2026-06-12):
- E-commerce is a key module for pharmacies and most retailers but must be **opt-in** (super-admin activated), never a default module. ✅ Phase 1 made it a compatible extra for pharmacy/parapharmacy (retail/fashion/parts_retailer already had it).
- Seeded demo tenants get **every compatible extra** so testers see all gated surfaces. ✅ Phase 1 fixed the seeders (extras were nested in `settings` JSON, which nothing reads — demo tenants ran with zero extras).
- First customer expected ~early July 2026.

## Shipped in Phase 1 (branch `feat/sidebar-regroup-module-gating`)

- Frontend: sidebar regroup + fail-closed module gating (typed `BackendModule` vocabulary).
- `config/verticals.php`: Ecommerce extra for pharmacy + parapharmacy; `Parapharmacy` module restored to its own vertical's default_modules (drift had dropped it → its nav group + RequireModule routes were dead).
- Regression pin: super-admin `update-extras` → tenant config cache invalidation (already worked via `TenantObserver::updated`; now tested end-to-end in `UpdateTenantExtrasTest`).
- Existing super-admin surface confirmed working: `sanctum-admin` guard, `POST /v1/admin/tenants/{id}/update-extras` (validates against vertical's compatible extras, audit-logged), React `TenantDetailModal → ManageModulesSection` toggles (reads `compatible_extras` from the API, so new extras appear automatically).

## Phase 2 backlog (in priority order)

1. **Assign modules by vertical (super-admin)** — vertical defaults live in `config/verticals.php` (file, deploy-time). To make them super-admin-editable: central-DB tables (`vertical_module_defaults`, `vertical_compatible_extras`) seeded from the config file, `VerticalConfigService` reads DB-first with config fallback, admin CRUD UI + audit logging. Decide: is deploy-time config actually enough for launch? (One customer in July — possibly yes; the per-tenant extras toggle already covers day-to-day needs.)
2. **Kill the enum/config drift** — `App\Enums\Vertical::defaultModules()/compatibleExtras()` duplicates `config/verticals.php` and they disagree (restaurant/coffee_shop: `Inventory` is an extra in config but a default in the enum; `CompositeItems` config-only). Per cross-app deprecation memory: grep ALL callers (apps/api, seeders, tests, console) before deprecating the enum methods in favor of `VerticalConfigService`. Add a unit test asserting enum == config until the enum methods are gone.
3. **Frontend↔backend vocabulary drift gate** — CI check that `apps/web/src/lib/modules.ts` BACKEND_MODULES ⊇ all names appearing in `config/verticals.php` (and flags removals).
4. **Aggregate E-commerce Orders page** — owner wants orders visible under the E-commerce group; today they exist only per-channel (`/channels/:id/orders`). New `/ecommerce/orders` page aggregating across channels + nav child under `ecommerce`.
5. **Admin UI polish** — ManageModulesSection: show which modules are vertical defaults (read-only) vs toggleable extras; surface the tenant's vertical; expose audit-log history for module changes.
6. **Route-level module gating sweep** — `RequireModule` middleware exists; channels routes (and other extra-gated features) still only check role permissions. Gate `/channels/*` on Ecommerce, parapharmacy routes on Parapharmacy, etc.
