# Module Management Phase 2 — Implementation Plan (subagent-driven)

Branch: `feat/sidebar-regroup-module-gating` (off dev). Execution: one implementer subagent per task + spec review + quality review. Playwright visual pass at the end.

Shared conventions for every task (paste into every implementer prompt):
- **NEVER run the full PHPUnit suite** (`php artisan test` with no path/filter, or `scripts/preflight.sh`) — scope every run to a file or `--filter`.
- Backend: PHP 8.2 strict_types, constructor injection only (`private readonly`, no `app()` in app code), enums for status/type columns, hexagonal module layout, PHPStan L8 + pint on changed files, TDD (red first).
- Frontend: TS strict (no `any`), atomic design, `t()` for all user-facing strings, TanStack Query 5, `apiGet` unwraps `data.data` (paginated endpoints: use `api.get` directly to preserve `meta`), design tokens for Tailwind colors in new code, modals have fixed dimensions, `pnpm vitest run <path>` + `pnpm exec tsc --noEmit`.
- Central DB = `synerivia_central` (tenants/super_admins/plans); tenant data in per-tenant DBs. Central migrations live in `apps/api/database/migrations/` root; tenant migrations in `migrations/tenant/`.
- Runtime vertical config source: `apps/api/config/verticals.php` via `VerticalConfigService` (`app/Services/VerticalConfigService.php`) → `CompanyConfigService` (24h cache `tenant_config:{tenant_id}`, invalidated by `TenantObserver::updated`).

## Tasks

### T1 — Backend: ModuleName enum + vertical_configs central table + DB-first VerticalConfigService
- [x] `App\Enums\ModuleName`: string-backed enum with every module name appearing in config/verticals.php (Identity, Tenant, Catalog, Vehicle, Partner, Workshop, Sales, Inventory, Treasury, Accounting, PlatformIntegration, BatchExpiry, Menu, Tables, CompositeItems, Parapharmacy, Appointments, Fleet, Prescription, Reservation, Loyalty, Ecommerce) + `values(): array<int,string>`. Unit test asserting the enum covers exactly the set of names found in config('verticals') (both lists, all verticals) — backend drift guard.
- [x] Central migration `create_vertical_configs_table`: `vertical` string PK, `default_modules` jsonb, `compatible_extras` jsonb, timestamps.
- [x] Model `App\Models\VerticalConfig` (central connection — mirror SuperAdmin/Plan), array casts, `$incrementing=false`, string key.
- [x] `VerticalConfigService`: DB-row-first read for default_modules/compatible_extras with config fallback per key; cache `vertical_config_override:{vertical}` TTL 24h; `invalidateVertical(Vertical $v): void`. Existing public API unchanged for callers.
- [x] Tests (scoped): service returns config values with no row; DB values when row exists; fallback per-field if row field null; invalidation works.

### T2 — Backend: super-admin vertical-config endpoints
- [x] `App\Http\Controllers\Api\Admin\VerticalConfigController` (constructor: VerticalConfigService, AdminAuditService).
- [x] `GET /v1/admin/verticals`: every Vertical case → `{vertical, label, product, default_modules, compatible_extras, is_overridden}` + top-level `available_modules` (ModuleName::values()).
- [x] `PUT /v1/admin/verticals/{vertical}`: validate vertical enum; `default_modules`/`compatible_extras` present arrays of valid ModuleName values; upsert VerticalConfig; audit-log old/new (AdminAuditService — add a vertical-scoped/log-generic method if only tenant-scoped exists; tenant_id nullable in admin_audit_logs); invalidate vertical cache AND `tenant_config:{id}` for every central tenant with that vertical; return updated effective config.
- [x] Routes in the existing `/v1/admin` group (mirror update-extras middleware exactly).
- [x] Feature tests mirroring UpdateTenantExtrasTest: auth required, validation (bad vertical, bad module name), override read-through on GET, tenant config cache invalidated after PUT.

### T3 — Backend: kill Vertical enum module-list drift
- [x] Grep ALL callers of `->defaultModules()` / `->compatibleExtras()` across apps/api (app, database, tests, routes, console) AND sibling apps (apps/web, apps/pos, packages) before touching anything.
- [x] Migrate callers to VerticalConfigService (or config() in seeders); DELETE `Vertical::defaultModules()` and `Vertical::compatibleExtras()`; fix VerticalTest.
- [x] config/verticals.php remains the seed/fallback truth; VerticalsConfigTest keeps guarding it.

### T4 — Frontend: super-admin Verticals page (assign modules by vertical)
- [x] features/admin/api: `getVerticals()`, `updateVerticalConfig(vertical, {default_modules, compatible_extras})` following the existing admin api file's conventions.
- [x] Hooks `useVerticals` / `useUpdateVerticalConfig` (query invalidation on success).
- [x] `VerticalsPage` at `/admin/verticals` + nav entry in the admin layout/nav alongside Tenants. Table: label, product badge, default-module count, extras count, "customized" badge when is_overridden.
- [x] `VerticalConfigModal` (organism, FIXED dimensions): two checkbox grids (Default modules / Compatible extras) built from `available_modules`; Save → PUT; loading/error states.
- [x] New `admin` i18n namespace (en/fr/ar + the 3 i18n.ts wiring points) for the new page's strings.
- [x] Component tests: renders rows from mocked hook; modal opens with current values; save sends correct payload.

### T5 — Tenant detail modal polish: defaults vs extras
- [x] Backend: `GET /v1/admin/tenants/{id}` response gains effective `default_modules` for the tenant's vertical.
- [x] Frontend ManageModulesSection: show vertical name + read-only default-module chips above the existing extras toggles. Tests updated.

### T6 — Backend: aggregate channel-orders endpoint
- [x] `GET /channels/orders` (registered BEFORE `{id}/orders` in Channel module routes): all ChannelOrders across the tenant's channels, `with('channel')`, filters `status` + `channel_id`, paginated; response shape consistent with the per-channel index.
- [x] Controller method on ChannelOrderController (follow its existing patterns/resources).
- [x] Feature tests: aggregates across 2 channels; status filter; pagination meta.

### T7 — Frontend: E-commerce Orders page
- [x] `EcommerceOrdersPage` under features/channels/pages reusing the per-channel orders page's components/patterns; channel name column; paginated fetch via `api.get` (preserve meta).
- [x] Route `/ecommerce/orders` (same guards as /channels routes); sidebar child `{key:'channelOrders', href:'/ecommerce/orders'}` under the `ecommerce` group; i18n key `navigation.channelOrders` en/fr/ar; page strings in the existing `channels` namespace (all 3 locales).
- [x] Tests: page renders rows from mocked api; sidebar test asserts the child shows with the Ecommerce module.

### T8 — Backend: RequireModule route sweep (conservative)
- [x] Channel module routes: add `RequireModule::class.':Ecommerce'`; Parapharmacy module routes: `:Parapharmacy`. Keep rule-12 middleware pattern intact.
- [x] First grep ALL consumers of these routes across apps (web, pos, seeders, console, CI) to confirm nothing breaks for tenants without the modules.
- [x] Feature tests: 403 without module, 200 with module enabled.

### T9 — Vocabulary drift CI gate (frontend test)
- [x] `apps/web/src/lib/modules.drift.test.ts`: read `apps/api/config/verticals.php` as text, extract every quoted name inside `default_modules`/`compatible_extras` arrays, assert each is in BACKEND_MODULES. Runs in normal vitest CI.

### T10 — Playwright visual verification (controller does this directly)
- [x] Demo stack: sidebar shows Catalog/Inventory (+ Stock Transfers), E-commerce → Channels + Orders, Batches, Parapharmacy group, Settings-only bottom; settings hub cards; `/admin/verticals` page; picker dropdown overlays correctly. Screenshots.
