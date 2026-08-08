# Country Defaults in the Super Admin Panel — Design Spec

- **Date:** 2026-08-08
- **Status:** Rev 2 — post Codex adversarial round 1 (REJECT, 19 findings — `reviews/2026-08-08-country-defaults-spec-review.md`). All findings addressed below; pending Codex round 2 + owner sign-off.
- **Owner decisions (locked):**
  - Scope = all country defaults via a generic framework, phased with chart of accounts (COA) first
  - Model = template library + country assignment
  - Accountant access = scoped central-admin role
  - Propagation = **company creation only** (ruling on F-07, 2026-08-08): any *newly created company* — whether via new-tenant registration or an existing tenant adding a company — receives the currently assigned published template. Accounts of existing companies are never touched.
  - Treasury literal codes (F-01 ruling): **locked protected codes in v1**; purpose-based resolver migration is a follow-up lane
  - Certification (F-18 ruling): **certification metadata on published versions in v1**; four-eyes approval is a later phase

## 1. Problem

Country-specific accounting defaults (chart of accounts, tax rates, payment settings, withholding rules, pricing regulations) are seeded from hardcoded PHP arrays:

- `apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php` (PCN)
- `apps/api/database/seeders/FranceChartOfAccountsSeeder.php` (PCG)
- `apps/api/database/seeders/GenericChartOfAccountsSeeder.php` (fallback)
- plus `CountryTaxRatesSeeder`, `CountryPaymentSettingsSeeder`, `TunisiaTaxConfigurationSeeder`, `FranceTaxConfigurationSeeder`, `TunisiaWithholdingRulesSeeder`, `CountryPricingRegulationSeeder`, `ExpenseCategorySeeder`.

Country selection is duplicated `match` statements on `companies.country_code`:

- `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:199-210`
- `apps/api/app/Modules/Accounting/Application/Services/ChartOfAccountsService.php:134-148`

Consequences:

1. A chartered accountant cannot review or correct the defaults without reading PHP.
2. Adding a country means a new seeder class plus edits to both `match` sites.
3. Certification per country needs the account plan to be data: reviewable, versioned, auditable.

**Runtime resolution is *mostly* purpose-based, with two known literal-code surfaces.** Business code generally resolves accounts through the `SystemAccountPurpose` enum (41 cases) via `Account::findByPurpose()` (`apps/api/app/Modules/Accounting/Domain/Account.php:238-242`; the scope predicate is at `:221-236`), with `UNIQUE(company_id, system_purpose)`. The exceptions (verified, Codex F-01):

- `InstrumentAccountResolver` resolves nine treasury instrument accounts **by literal code**, country-conditional (`5312`/`5112`, `4035`, `413`, `403`, `5313`/`5113`, `5314`/`5114`, `6275`/`627`, `43666`/`44566`, `416`) — `apps/api/app/Modules/Treasury/Application/Services/InstrumentAccountResolver.php:41-55`. Live consumers: payment instruments, outbound instruments, lifecycle transitions, remittance, projections, reconciliation.
- Withholding certificates emit literal base codes `42236`/`42237` (`apps/api/app/Modules/Taxation/Domain/Enums/WithholdingDirection.php:26-31`).

These codes are treated as **protected codes** in v1 (§4.2) and their purpose-based migration is an explicit follow-up lane (§13).

## 2. Goals / Non-Goals

**Goals (this phase):**

- G1. Central, DB-backed template library for country defaults, starting with domain `chart_of_accounts`.
- G2. Super-admin UI: list, clone, edit (draft), validate, publish, assign per (country, domain) with wildcard fallback.
- G3. Scoped central-admin role (`defaults_editor`) coexisting with the existing `super_admin` / `support_approver` roles and their four-eyes semantics.
- G4. Company provisioning (new tenants and additional companies) reads the assigned published template; byte-equivalent output for TN/FR/Generic proven by a golden-parity test (§5.4) before switchover.
- G5. Audit rows written **in the same transaction** as every template/assignment mutation.
- G6. Published versions carry certification metadata: content hash, reviewer identity, referenced standard (e.g. "PCN 2018", "PCG (ANC 2014-03)"), decision timestamp, supersession link.

**Non-Goals (explicit):**

- N1. No changes to accounts of existing companies. Pushing a new required account to existing tenants remains an idempotent tenant backfill migration + `tenants:migrate-rolling`.
- N2. Other domains are follow-up phases; the framework is built domain-generic now, only the COA content table and editor ship.
- N3. No tenant-facing changes. Tenants keep the existing account API (list/show/create/update — there is no account-delete route; purpose mappings do have delete/unassign: `apps/api/app/Modules/Accounting/Presentation/routes.php:25-75`).
- N4. No i18n of template content in v1.
- N5. No four-eyes publish approval in v1 (certification metadata only; see §13).
- N6. Purpose-based rewrite of `InstrumentAccountResolver` and withholding GL codes (follow-up lane, treasury-reviewer gated).

## 3. Data Model (central DB — logical `central` connection)

> Naming note (F-19): tables live in the **central** database, referenced only via the logical `central` connection. The physical DB name is deployment-specific; ERP resources are never named after Synerivia (`apps/api/config/database.php:124-135`).

**All three models use Stancl's `CentralConnection` concern** — an explicit invariant (F-13), same as `VerticalConfig` (`apps/api/app/Models/VerticalConfig.php:20-24`). During provisioning the default connection points at the tenant DB; unpinned models would query the wrong database. A tenancy-initialized test proves central resolution works while the default connection is swapped (pattern: `apps/api/tests/Feature/Services/TenantConfigCacheTenancyTest.php:177-203`).

### 3.1 `admin_templates`

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| domain | string enum `TemplateDomain` | `chart_of_accounts` only in v1 |
| name | string | e.g. "PCN Tunisie v1" |
| description | text nullable | |
| status | string enum `TemplateStatus` | `draft` \| `published` \| `archived` |
| cloned_from_id | uuid nullable FK admin_templates | provenance / supersession chain |
| content_hash | string nullable | SHA-256 over the canonical row projection (§5.4); set at publish |
| standard_ref | string nullable | referenced accounting standard + version |
| certified_by | FK super_admins nullable | who published/certified |
| published_at | timestamp nullable | certification decision timestamp |
| created_by | FK super_admins nullable | |
| timestamps | | |

### 3.2 `admin_template_accounts` (COA domain content)

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| template_id | uuid FK admin_templates cascade | |
| code | string | UNIQUE(template_id, code) |
| name | string | |
| type | string enum (tenant `accounts.type` enum) | |
| parent_code | string nullable | must reference a code in the same template |
| system_purpose | string nullable | `SystemAccountPurpose` values; UNIQUE(template_id, system_purpose) where not null |
| is_system | boolean default false | |
| sort_order | int | preserves seeder ordering |

### 3.3 `country_template_assignments`

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| country_code | string(2) or `*` | `*` = wildcard fallback |
| domain | string enum | **must equal the assigned template's domain** (F-14) — enforced in the assignment transaction and by a composite-FK-style guard (template `(id, domain)` unique index + assignment FK over both columns) |
| template_id | uuid FK admin_templates | must be `published` |
| UNIQUE(country_code, domain) | | |

**Country catalog (F-09):** the tenant-DB `countries` table is NOT usable centrally (`apps/api/database/migrations/tenant/2025_12_01_192409_create_countries_table.php`; `TenantProvisioningService.php:74-80` documents this). The assignments UI is backed by a **static central country provider**: a versioned PHP/JSON ISO-3166 list shipped with the API (superset of `CountriesSeeder`). Registration accepts any 2-letter string (`RegisterRequest.php:69-74`), so: exact assignments may be created for any ISO code in the static list; unknown codes resolve to the wildcard; the wildcard row is pinned, non-removable, and always visible in the UI. Test: an accepted 2-letter code absent from `CountriesSeeder` provisions via wildcard.

## 4. Lifecycle: draft → publish → assign; published is immutable

### 4.1 States and transitions

- Drafts freely editable. Published templates immutable (API rejects row mutations). Change = clone → draft → edit → publish → re-point assignment. `archived` hides from pickers; a template referenced by any assignment can be neither archived nor deleted.
- **Atomicity (F-14):** publish, archive, delete, and assignment re-point each run in a central-DB transaction with row locks (`SELECT ... FOR UPDATE` on the template row; on re-point, lock old and new template rows in deterministic id order plus the assignment row). Status checks happen after acquiring locks. Wildcard protection is a mutation rule (delete/repoint of `*` to a non-published or wrong-domain template is rejected in-transaction), not merely a health check.

### 4.2 Publish gate (server-side, in the publish transaction)

1. **Operational purpose set (F-02):** a new versioned constant `ProvisioningRequiredPurposes::forChartOfAccounts()` — NOT the 11-value `requiredPurposes()` helper (`SystemAccountPurpose.php:155-175`), which is not an operational-completeness contract. The set is derived by inventorying every unconditional `findByPurposeOrFail()` consumer (e.g. goods receipt Inventory/GRIR and supplier-invoice StampDuty/PPV/SupplierPayable in `GeneralLedgerService.php:1828-1829`, `:1982-1988`, plus refund/voucher/tolerance/rounding purposes) and is guarded by a test that greps/reflects unconditional purpose consumers against the set, failing when a new consumer appears.
2. **Purpose/type compatibility (F-03):** every purpose-bearing row must satisfy `row.type === purpose.expectedAccountType()` (`SystemAccountPurpose::expectedAccountType()`, `SystemAccountPurpose.php:177-205`).
3. **System flag (F-03):** `system_purpose !== null ⇒ is_system === true` (tenant immutability is enforced solely via `is_system`; `AccountController.php:147-180`).
4. **Protected codes (F-01, owner ruling):** for COA templates, the nine instrument codes (country-conditional per `InstrumentAccountResolver::accountCode()`) and withholding bases `42236`/`42237` must exist with the account types the resolver queries. The protected-code registry is a single PHP class consumed by BOTH the publish gate and (as a test dependency) `InstrumentAccountResolver`, so drift fails a test. Editor shows these rows locked with an explanation. Because the codes are country-conditional, the gate takes the set of countries the template is (or may be) assigned to: validation runs per-assignment-country at publish for currently assigned countries and re-runs at assignment time for new countries (a TN assignment checks the TN variant set).
5. Structural checks: unique codes, unique purposes, parent references resolve in-template, valid type enum values.
6. **Certification metadata (F-18, owner ruling):** publish requires `standard_ref`; sets `content_hash` (canonical projection §5.4), `certified_by` = acting admin, `published_at`.

Negative tests for every rule.

## 5. Provisioning Rewire

### 5.1 Resolver

`CountryTemplateResolver::resolve(TemplateDomain $domain, string $countryCode): TemplateData` — reads assignment + template + rows on the `central` connection. Resolution: exact country → `*`.

**No caching for provisioning resolution (F-11).** Company creation is low-frequency; the documented forget-race (`CompanyConfigService.php:95-103`) would let a stale certified template serve for a full TTL after a re-point, violating the "next company gets the new assignment" promise. The resolver reads the DB directly. (Admin-UI list endpoints may cache; provisioning never does.)

### 5.2 Consumers — complete inventory (F-06, F-07)

| consumer | change |
|---|---|
| `TenantInitializationService.php:199-210` (new-tenant registration) | route through resolver |
| `ChartOfAccountsService.php:134-148` (`CompanyController.php:153-163` — additional company in existing tenant) | route through resolver; per owner ruling the new company gets the **currently assigned** published template |
| `DatabaseSeeder.php:83-89, 117-123` (dev/demo) | adapt to template path (resolver against bootstrapped templates) |
| `DemoTenantSeeder.php:215-220`, `CoffeeShopSeeder.php:365-373` | adapt to template path |
| `ParapharmacySeeder.php:627-638` + `DemoPharmacySeeder.php:97-113` (via `ChartOfAccountsSeederContract`) | contract gains a template-backed implementation; class-name override becomes a country/template parameter |
| historical tenant migration `2026_03_23_300000_fix_existing_tunisian_companies_tax_setup.php:5-8,44-53` (imports `TunisiaChartOfAccountsSeeder`) | **the three seeder classes are retained permanently as frozen compatibility artifacts** for migration replay; marked `@deprecated`, excluded from the provisioning path, never edited again. No shipped migration is mutated. |

### 5.3 Failure semantics (F-08)

- New-tenant registration: resolver/seed failure throws → existing compensation flow (drop tenant DB, remove central rows; `TenantProvisioningService.php:187-249`). No silent fallback to hardcoded seeders.
- Additional-company creation: `CompanyController` currently catches the seeding `RuntimeException`, logs, and returns the company anyway (`CompanyController.php:153-165`). This changes: COA resolution/seed failure **rolls back company creation** and returns an error. Tests cover resolver failure in both paths.

### 5.4 Golden-parity test (F-10)

- **Canonical projection:** `(code, name, type, parent_code, system_purpose, is_active, is_system, ordinal)` with `parent_id` resolved back to parent code. Raw-row equality is impossible (fresh UUIDs land in `parent_id`).
- **Frozen golden fixtures** are captured from the pre-refactor seeders (one JSON per country, committed) BEFORE any shared data source is extracted — otherwise the test is tautological. Template path output must match fixtures exactly: row count, order, hierarchy, all purpose assignments; plus a non-TN/FR code exercising the wildcard.
- The same canonical projection feeds `content_hash` (§4.2.6).
- Source arrays are private in the seeder classes; the bootstrap extracts definitions via a one-time exporter that runs the frozen seeders into a scratch schema and serializes the projection (no reflection into privates, no edits to the frozen classes).

### 5.5 Template seeder semantics (F-15)

`TemplateChartOfAccountsSeeder` reproduces the ACTUAL current semantics, documented truthfully: first pass inserts missing rows, preserves existing name/type/purpose, promotes `is_system`; second pass **re-issues `parent_id` links for every definition, including pre-existing rows** (reparenting — `TunisiaChartOfAccountsSeeder.php:94-100`, surfaced by `SeedChartsCommand.php:47-50,110-123`). A rerun-after-manual-reparent test pins this behavior.

## 6. Bootstrap + Deploy (F-05)

Reality check: the entrypoint runs central migrations (failures logged, boot continues — `docker/entrypoint.sh:118-127`) and seeds only when `AUTO_SEED=true` AND zero tenants exist (`entrypoint.sh:169-190`). Seeders are not a deploy hook on existing environments. Therefore:

- **Release 1 (schema + data):** central migrations create the three tables AND a **central data migration** imports the three frozen templates + `TN`/`FR`/`*` assignments from the exported fixtures (§5.4). Guard = per-template presence check by a stable bootstrap key (not "any templates exist" — one manual draft must not suppress the rest; F-05). Also ships `php artisan country-defaults:verify` asserting: 3 published templates, exact `TN`/`FR`/`*` assignments, fixture-matching content hashes, wildcard resolvable.
- **Release 2 (reader switchover):** consumers switch to the resolver only after `country-defaults:verify` passes on staging and production. Deploy checklist entry added to the launch-program consolidated list.
- House rule honored: everything pushed is self-guarding under the `origin/dev` auto-deploy (data migration is idempotent by bootstrap key; verify command is read-only).

## 7. Access Control (F-04, F-12, F-17)

Current reality: `super_admins.role` **exists** with enum `SuperAdminRole { SuperAdmin, SupportApprover }` (`app/Models/Enums/SuperAdminRole.php`), and `EnsureSuperAdmin` rejects every role except `super_admin` (`EnsureSuperAdmin.php:27-49`). Support-access routes use a `central_admin_role` matrix (`SupportAccess/Presentation/routes.php:17-45`).

- **Add `DefaultsEditor = 'defaults_editor'` to the EXISTING enum.** No backfill, no `owner` rename; existing roles and the four-eyes `support_approver` semantics untouched.
- **Split authentication from capability:** new `EnsureCentralAdmin` (authenticated, active, any role) + explicit capability middleware per route group. Existing fleet/billing/monitoring/verticals route groups keep `EnsureSuperAdmin` byte-for-byte (`routes/api.php:56-124`); support-access keeps its matrix. New `admin/country-defaults` group = `EnsureCentralAdmin` + `central_admin_role:super_admin,defaults_editor`. Auth profile/logout endpoints accept any active central admin.
- **Route-inventory test:** iterates the route table and asserts a `defaults_editor` token gets 403 on every admin route except auth profile/logout + country-defaults (F-04).
- **MFA (F-12):** central-admin auth is password-only today (`SuperAdminAuthController.php:18-56`; no MFA columns). Per the standing owner ruling (super-admin MFA required for production), **external `defaults_editor` accounts cannot be activated until the MFA lane lands**: enforced by a feature flag `country_defaults.external_editors_enabled` defaulting off, checked at login for `defaults_editor` role. The owner can still review via their own account meanwhile.
- **Account lifecycle (F-17):** owner-role endpoints to create / disable / re-enable / reset-credentials for `defaults_editor` accounts; disable and role change revoke all Sanctum tokens; all lifecycle actions audited. (Today's only creation path is env-driven `SuperAdminSeeder` — inadequate for an external actor.)

## 8. API Surface (prefix `admin/country-defaults`)

| verb | route | notes |
|---|---|---|
| GET | `/templates?domain=` | list |
| POST | `/templates` | create empty draft |
| POST | `/templates/{id}/clone` | clone any template → draft |
| GET | `/templates/{id}` | header + rows |
| PUT | `/templates/{id}` | rename/describe/standard_ref (draft only) |
| DELETE | `/templates/{id}` | draft only, unassigned only (locked check) |
| PUT | `/templates/{id}/rows` | bulk upsert/delete rows (draft only) |
| GET | `/templates/{id}/validation` | live validation report incl. protected-code and purpose-set checks |
| POST | `/templates/{id}/publish` | publish gate §4.2, transactional |
| POST | `/templates/{id}/archive` | unassigned only, transactional |
| GET | `/assignments?domain=` | matrix from static country provider |
| PUT | `/assignments/{countryCode}` | transactional re-point, per-country protected-code validation |
| GET/POST/PUT | `/editors` (owner role only) | defaults_editor account lifecycle (F-17) |

FormRequests, strict typing, enums throughout; standard response envelope.

**Audit (F-16):** every mutation writes its `AdminAuditLog` row **inside the same central transaction** (service-level, not controller-optional; a mutation without an audit row cannot commit). Bulk row updates store before/after canonical projections (or content-hash pair for large diffs). Cache invalidation (admin-UI caches only) after commit. `#[CrossTenantRoute]` remains an annotation, not the audit mechanism.

## 9. Admin UI (new nav section "Country Defaults")

1. **Templates list** — domain filter, status chips, certification metadata display, Clone/Archive.
2. **Template editor** — spreadsheet grid (code, name, type, parent, purpose dropdown, system flag); protected rows locked with tooltip; persistent validation panel (missing operational purposes, type mismatches, dup codes, orphan parents, protected-code violations); Publish modal collects `standard_ref` and shows the content hash on success.
3. **Assignments view** — static country catalog × assigned template; pinned wildcard row; re-point confirm dialog: "affects newly created companies only".
- `t()` throughout (namespace `adminCountryDefaults`); design tokens; plain (non-tenant-scoped) query keys like existing admin pages.
- Nav + route guards filter by role from the `me` payload.

## 10. Testing (TDD)

- Publish-gate rules (each red/green, incl. every F-02/F-03/F-01 negative case); protected-code registry drift test; resolver exact/wildcard/missing-assignment; immutability (mutate published → 422); transactional races (publish-vs-assign, archive-vs-assign) via locking tests; role/route-inventory test; MFA-flag login block; golden parity (§5.4); bootstrap data-migration idempotency (run twice); `country-defaults:verify` red/green; company-creation rollback on resolver failure (both paths §5.3); rerun-reparenting pin (§5.5); tenancy-initialized central-read test (§3).
- Frontend: Vitest for grid validation display, locked rows, role-filtered nav, assignment confirm.
- Preflight by path; no full PHPUnit suite without permission.

## 11. Phasing & Effort

- **Phase A (this spec):** framework + COA end-to-end. Revised estimate **~8–11 dev-days** (was 5–7; growth = auth split, account lifecycle, transactional lifecycle+audit, two-release bootstrap, parity fixtures).
- **Phase B+:** tax rates → tax configurations → payment settings → withholding rules → pricing regulations → expense categories; each = content table/payload + editor + parity test + seeder retirement.

## 12. Risks & Mitigations

| risk | mitigation |
|---|---|
| Accountant renumbers a treasury/withholding literal-code account | protected-code registry in publish gate + locked editor rows (§4.2.4) until the resolver migration lane lands |
| Template passes gate but breaks later GL flows | operational purpose set derived from unconditional consumers + drift-guard test (§4.2.1) |
| Bootstrap absent on existing envs | central data migration + `country-defaults:verify` + two-release switchover (§6) |
| Company created without COA | rollback semantics in both provisioning paths (§5.3) |
| Stale assignment after re-point | no caching on provisioning resolution (§5.1) |
| External actor without MFA | defaults_editor activation feature-flagged off until MFA lane lands (§7) |
| Lifecycle races corrupt assignments | transactions + row locks + in-transaction wildcard/domain rules (§4.1, §3.3) |
| Audit gaps | audit row required in mutation transaction (§8) |
| Historical migration replay breaks | seeder classes frozen permanently as compatibility artifacts (§5.2) |

## 13. Follow-up lanes (out of this spec)

1. **Treasury purpose migration:** extend purpose-based resolution to the nine `InstrumentAccountPurpose` cases + withholding bases; unlock those template rows. Treasury-reviewer gated.
2. **Four-eyes certification:** separate reviewer approval before publish for external editors.
3. **Central-admin MFA** (already an owner-confirmed production requirement; this spec only consumes it via the activation flag).
4. Remaining domains (Phase B+).
