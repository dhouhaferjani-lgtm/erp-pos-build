/**
 * Backend module vocabulary — the single source of truth for module gating
 * in the frontend.
 *
 * These names MUST match the PascalCase module names the backend emits in
 * `all_enabled_modules`. The single source on the backend is
 * `config/verticals.php` (read via `VerticalConfigService` →
 * `CompanyConfigService`, DB-first with central `vertical_configs`
 * overrides); the old drifted `App\Enums\Vertical::defaultModules()`
 * duplicate has been deleted.
 * Navigation and feature gating reference modules through the
 * `BackendModule` type so an unknown name is a compile error instead of a
 * silently-always-visible nav item.
 */
export const BACKEND_MODULES = [
  // Default modules (per-vertical baseline)
  'Identity',
  'Tenant',
  'Catalog',
  'Vehicle',
  'Partner',
  'Workshop',
  'Sales',
  'Inventory',
  'Treasury',
  'Accounting',
  'PlatformIntegration',
  'BatchExpiry',
  'Menu',
  'Tables',
  'CompositeItems',
  'Parapharmacy',
  // Optional extras (tenants.enabled_extras)
  'Appointments',
  'Fleet',
  'Prescription',
  'Reservation',
  'Loyalty',
  'Ecommerce',
] as const

export type BackendModule = (typeof BACKEND_MODULES)[number]
