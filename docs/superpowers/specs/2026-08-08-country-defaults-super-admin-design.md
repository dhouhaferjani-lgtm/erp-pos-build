# Country Defaults in the Super Admin Panel — Design Spec

- **Date:** 2026-08-08
- **Status:** Draft — pending Codex adversarial review + owner sign-off
- **Owner decisions already made:** scope = all country defaults via a generic framework, phased with chart of accounts (COA) first; model = template library + country assignment; accountant access = scoped admin role; propagation = **new tenants only** (existing tenants keep and edit their own copy)

## 1. Problem

Country-specific accounting defaults (chart of accounts, tax rates, payment settings, withholding rules, pricing regulations) are seeded from hardcoded PHP arrays:

- `apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php` (PCN)
- `apps/api/database/seeders/FranceChartOfAccountsSeeder.php` (PCG)
- `apps/api/database/seeders/GenericChartOfAccountsSeeder.php` (fallback)
- plus `CountryTaxRatesSeeder`, `CountryPaymentSettingsSeeder`, `TunisiaTaxConfigurationSeeder`, `FranceTaxConfigurationSeeder`, `TunisiaWithholdingRulesSeeder`, `CountryPricingRegulationSeeder`, `ExpenseCategorySeeder`.

Country selection is duplicated `match` statements on `companies.country_code`:

- `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:200-210`
- `apps/api/app/Modules/Accounting/Application/Services/ChartOfAccountsService.php:139-148`

Consequences:

1. A chartered accountant cannot review or correct the defaults without reading PHP.
2. Adding a country (e.g. Algeria, Italy, UK) means writing a new seeder class and editing the two `match` statements (`CountryTaxConfigurationRegistry.php` documents this: *"Add new countries here only."*).
3. Certification per country requires evidence that account plans are data, reviewable, and auditable — not code literals.

The runtime is already safe to make this data-driven: **no business code resolves accounts by literal code**. Everything goes through the `SystemAccountPurpose` enum (41 purposes) via `Account::findByPurpose()` (`apps/api/app/Modules/Accounting/Domain/Account.php:229`), with `UNIQUE(company_id, system_purpose)` and `ChartOfAccountsService::validateCompanyAccounts()` checking `requiredPurposes()` coverage.

## 2. Goals / Non-Goals

**Goals (this phase):**

- G1. Central, DB-backed template library for country defaults, starting with domain `chart_of_accounts`.
- G2. Super-admin UI: list, clone, edit (draft), validate, publish templates; assign a template per (country, domain) with a wildcard fallback.
- G3. Scoped admin role so an external chartered accountant can access ONLY the Country Defaults section.
- G4. Tenant provisioning reads the assigned published template instead of the hardcoded seeder classes, with byte-identical output for TN/FR/Generic proven by a golden-parity test before switchover.
- G5. Full audit trail of template edits via the existing `AdminAuditService`.

**Non-Goals (explicit):**

- N1. No propagation to existing tenants. Templates apply at tenant creation only. Pushing a new required account to existing tenants remains an idempotent tenant backfill migration (pattern: `apps/api/database/migrations/tenant/2026_08_07_100000_backfill_purchase_stamp_duty_account.php`) + `tenants:migrate-rolling`.
- N2. Other domains (tax rates, tax configurations, payment settings, withholding rules, pricing regulations, expense categories) are follow-up phases. The framework (tables 3.1/3.3, lifecycle, role, UI shell) is built domain-generic now; only the COA content table and editor ship in this phase.
- N3. No tenant-facing changes. Tenants keep the existing account CRUD + purpose-mapping API (`apps/api/app/Modules/Accounting/Presentation/routes.php:27-73`).
- N4. No i18n of template content in v1 (account names are stored as entered, matching current seeder behavior — French names for TN/FR, English for Generic).

## 3. Data Model (central DB `synerivia_central`)

Mirrors the `vertical_configs` precedent (central table + `GlobalCache` + observer cache-bust + super-admin CRUD with `#[CrossTenantRoute]` + audit + admin page).

### 3.1 `admin_templates`

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| domain | string enum (PHP enum `TemplateDomain`) | `chart_of_accounts` only in v1 |
| name | string | e.g. "PCN Tunisie v1" |
| description | text nullable | |
| status | string enum `TemplateStatus` | `draft` \| `published` \| `archived` |
| cloned_from_id | uuid nullable FK admin_templates | provenance chain |
| published_at | timestamp nullable | |
| created_by | FK super_admins nullable | |
| timestamps | | |

### 3.2 `admin_template_accounts` (COA domain content)

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| template_id | uuid FK admin_templates cascade | |
| code | string | UNIQUE(template_id, code) |
| name | string | |
| type | string enum (same account-type enum as tenant `accounts.type`) | |
| parent_code | string nullable | must reference a code within the same template |
| system_purpose | string nullable | values of `SystemAccountPurpose`; UNIQUE(template_id, system_purpose) where not null |
| is_system | boolean default false | |
| sort_order | int | preserves seeder ordering |

Normalized rows (not a jsonb blob) so the editor works row-by-row and validation errors are precise per-row.

### 3.3 `country_template_assignments`

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| country_code | string(2) or `*` | `*` = wildcard fallback |
| domain | string enum | |
| template_id | uuid FK admin_templates | must be `published` |
| UNIQUE(country_code, domain) | | |

Resolution order at provisioning: exact `country_code` match → `*` row. A missing `*` row for a domain is a deploy-time invariant violation (bootstrap seeds it; a health check asserts it).

## 4. Lifecycle: draft → publish → assign; published is immutable

- Drafts are freely editable (rows added/edited/deleted).
- **Publish gate** (server-side, atomic): all `SystemAccountPurpose::requiredPurposes()` present; codes unique; purposes unique; every `parent_code` resolves within the template; type values valid. Publish sets `status=published`, `published_at`.
- **Published templates are immutable** — API rejects row mutations. To change: **clone → new draft → edit → publish → re-point the assignment**. Yields version history for free and guarantees a tenant provisioned mid-edit can never receive a half-finished chart.
- `archived` hides a template from assignment pickers; a template referenced by an assignment cannot be archived or deleted.
- Assignment changes are instant for the next tenant creation (cache bust via observer).

## 5. Provisioning Rewire

New service `CountryTemplateResolver` (module: Accounting Application or a new `Shared` central-config service — placement decided at plan time against `.claude/context/architecture.md`):

- `resolve(TemplateDomain $domain, string $countryCode): TemplateData` — reads assignment + template + rows from the **central** connection, cached via **`GlobalCache`** (NOT the `Cache` facade — written from central context, read during tenant provisioning, same reason as `VerticalConfigService`, `apps/api/app/Services/VerticalConfigService.php:17-30`). Cache-bust observers on all three tables (pattern: `apps/api/app/Observers/VerticalConfigObserver.php`).
- A single `TemplateChartOfAccountsSeeder` replaces the three hardcoded classes: takes resolved rows, writes tenant `accounts` with today's exact idempotent semantics (never rewrite existing rows; only promote `is_system` — `TunisiaChartOfAccountsSeeder.php:44-58`).
- Both `match` sites (`TenantInitializationService.php:200-210`, `ChartOfAccountsService.php:139-148`) route through the resolver. `ChartOfAccountsService::getSupportedCountries()` becomes "countries with an exact assignment".
- **Golden-parity test** (pre-switchover gate): for each of TN / FR / other, provision via old seeder and via template path into fresh schemas; assert identical `accounts` rows (all columns except ids/timestamps). The bootstrap import (§6) is only accepted when parity is green.
- Failure mode: if the resolver finds no template (broken assignment, central DB drift), provisioning **fails loudly** inside the existing compensation flow (`TenantProvisioningService` step 6 drops the DB + central rows). No silent fallback to hardcoded seeders after switchover.

## 6. Bootstrap + Deploy

- Central migration creates the three tables.
- A **self-guarding** central seeder (idempotent — skips if templates exist; safe under the `origin/dev` auto-deploy that runs migrations, per house rule `feedback_push_dev_autodeploys_migrations`) imports the three PHP seeders' arrays into three **published** templates + assignments `TN`, `FR`, `*`.
- The PHP seeder classes are retired from the provisioning path after parity passes; classes may remain temporarily as the bootstrap data source, then deleted in a cleanup commit.
- Deploy order: migrate central → run bootstrap seeder → deploy code that reads templates. Single release is fine because the bootstrap runs in the same migration batch before any new tenant can be provisioned.

## 7. Access Control: scoped admin role

- `super_admins` gains `role` (enum `SuperAdminRole`): `owner` (everything, today's behavior — existing rows backfilled to `owner`) and `defaults_editor` (Country Defaults only).
- Enforcement server-side: new route middleware/capability check (e.g. `admin_capability:manage_defaults` vs `admin_capability:full`) layered on the existing `['auth:sanctum-admin', 'super_admin', 'throttle:admin-sensitive']` stack (`apps/api/routes/api.php:57-120`). All existing admin routes get `full`; the new template routes accept either role.
- Frontend: `AdminLayout` nav (`apps/web/src/features/admin/components/AdminLayout.tsx:8-17`) filters by role from the `me` payload; `defaults_editor` sees only Country Defaults. Route guard denies direct URL access to other admin pages.
- MFA: super-admin MFA is already an owner-confirmed production requirement; `defaults_editor` accounts are subject to the same policy.
- All template/assignment mutations logged via `AdminAuditService` with `#[CrossTenantRoute(reason: ...)]` attributes (pattern: `apps/api/app/Http/Controllers/Api/Admin/VerticalConfigController.php:38,63`).

## 8. API Surface (prefix `admin/country-defaults`)

| verb | route | notes |
|---|---|---|
| GET | `/templates?domain=` | list |
| POST | `/templates` | create empty draft |
| POST | `/templates/{id}/clone` | clone any template → draft |
| GET | `/templates/{id}` | header + rows |
| PUT | `/templates/{id}` | rename/describe (draft only) |
| DELETE | `/templates/{id}` | draft only, unassigned only |
| PUT | `/templates/{id}/rows` | bulk upsert/delete rows (draft only) |
| GET | `/templates/{id}/validation` | live validation report (missing purposes, dup codes, orphan parents) |
| POST | `/templates/{id}/publish` | runs publish gate |
| POST | `/templates/{id}/archive` | unassigned only |
| GET | `/assignments?domain=` | matrix country → template |
| PUT | `/assignments/{countryCode}` | point at a published template |

FormRequests with strict typing; no `mixed`; enums for domain/status/type/purpose. Standard response envelope (no double-unwrap on FE).

## 9. Admin UI (new nav section "Country Defaults")

1. **Templates list** — filter by domain; status chips; Clone / Archive actions.
2. **Template editor** — spreadsheet-style grid (code, name, type, parent, purpose dropdown, system flag); draft-only editing; a persistent **validation panel** ("missing purposes: PurchaseStampDuty, …"; duplicate codes; orphan parents) driven by the validation endpoint; **Publish** button disabled until clean.
3. **Assignments view** — table of countries (from `CountriesSeeder` list) × assigned template with wildcard row; changing an assignment shows a confirm dialog stating "affects newly created tenants only".
- All text via `t()` (new i18n namespace `adminCountryDefaults`); design tokens; TanStack keys — admin data is central, not tenant-scoped, so plain keys (NOT `tenantScopedKey`), consistent with existing admin pages.

## 10. Testing (TDD)

- **Backend:** publish-gate unit tests (each validation rule red/green); resolver tests (exact match, wildcard, missing-assignment failure); immutability tests (mutating published → 422); role tests (`defaults_editor` 403 on tenants/billing routes, 200 on template routes; `owner` unchanged); golden-parity test (§5); bootstrap idempotency test (run twice, no dupes).
- **Frontend:** Vitest for editor grid validation display, role-filtered nav, assignment confirm flow.
- Full preflight (`./scripts/preflight.sh`) — but no full PHPUnit suite runs without permission; tests run by path.

## 11. Phasing & Effort

- **Phase A (this spec):** framework + COA domain end-to-end. ~5–7 dev-days.
- **Phase B+ (follow-ups, one small increment each):** tax rates → tax configurations → payment settings → withholding rules → pricing regulations → expense categories. Each adds: a content table (or jsonb payload where row-editing isn't needed), a domain case in the enum, an editor panel, a parity test, and retirement of its PHP seeder.

## 12. Risks & Mitigations

| risk | mitigation |
|---|---|
| Template drift breaks provisioning for new tenants | publish gate + immutable published + provisioning fails loudly into existing compensation flow |
| External accountant in the admin panel | scoped role, server-side enforced; MFA policy; full audit trail |
| Bootstrap runs on auto-deploy | self-guarding idempotent seeder; parity test green before the switchover commit merges |
| Central/tenant cache context confusion | `GlobalCache` + observers, copied from the proven `VerticalConfigService` pattern |
| Purpose coverage regression when accountant edits | publish gate re-validates `requiredPurposes()` on every publish; UI validation panel surfaces it live |
