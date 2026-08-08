# Country Defaults in the Super Admin Panel — Design Spec

- **Date:** 2026-08-08
- **Status:** Rev 4 — post Codex round 3 (REJECT: 9 CLOSED / 11 PARTIAL / 0 OPEN; 6 new findings R3-01..R3-06, all addressed: no artisan certify path, scope algebra + `CertificationScope` VO, TN expense codes protected, bootstrap key-assertion import, `CanonicalCoaSerializer` byte spec, three-role FE matrix; purpose manifest §4.2.1 now ENUMERATED from a code inventory). Pending Codex round 4 + owner sign-off. Register: `reviews/2026-08-08-country-defaults-spec-review.md`.
- **Owner decisions (locked):**
  - Scope = all country defaults via a generic framework, phased with chart of accounts (COA) first
  - Model = template library + country assignment
  - Accountant access = scoped central-admin role
  - Propagation = **company creation only** (F-07 ruling): any *newly created company* — new-tenant registration or an existing tenant adding a company — receives the currently assigned published template. Accounts of existing companies are never touched.
  - Treasury literal codes (F-01 ruling): **locked protected codes in v1**; purpose-based resolver migration is a follow-up lane
  - Certification (F-18 ruling): **certification metadata on published versions in v1**; four-eyes deferred

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

**Runtime resolution is *mostly* purpose-based, with known literal-code surfaces.** Business code generally resolves accounts through the `SystemAccountPurpose` enum (41 cases) via `Account::findByPurpose()` (`apps/api/app/Modules/Accounting/Domain/Account.php:238-242`), with `UNIQUE(company_id, system_purpose)`. The exceptions (verified, Codex F-01/R2-01/R2-09):

- `InstrumentAccountResolver` resolves nine treasury instrument accounts **by literal code against `accounts`**, country-conditional (`5312`/`5112`, `4035`, `413`, `403`, `5313`/`5113`, `5314`/`5114`, `6275`/`627`, `43666`/`44566`, `416`) — `apps/api/app/Modules/Treasury/Application/Services/InstrumentAccountResolver.php:41-70`. Live consumers: payment instruments, outbound instruments, lifecycle transitions, remittance, projections, reconciliation. **These are the v1 protected codes** (§4.2.4).
- Withholding certificates emit literal base strings `42236`/`42237` (`WithholdingDirection.php:26-31`) formatted into display values like `42236.10` (`WithholdingCertificate.php:214-224`). **These are document-format literals, not `accounts` lookups — they are NOT COA rows, are NOT in the protected set, and are handled in the withholding follow-up domain lane** (R2-01).
- `ExpenseCategorySeeder` maps categories to literal TN codes (`613`,`615`,`616`,`624`,`626`) with a silent `GeneralExpense` fallback (`ExpenseCategorySeeder.php:37-69`). In v1 these five codes join the **TN-scope protected set** (existence + type at publish; demo-consumer protection, lifted when the expense-category domain phase introduces a stable semantic mapping — R3-03) and the seeder's silent fallback becomes a loud failure (§5.2).

## 2. Goals / Non-Goals

**Goals (this phase):**

- G1. Central, DB-backed template library for country defaults, starting with domain `chart_of_accounts`.
- G2. Super-admin UI: list, clone, edit (draft), validate, publish, assign per (country, domain) with wildcard fallback.
- G3. Scoped central-admin role (`defaults_editor`) coexisting with the existing `super_admin` / `support_approver` roles and their four-eyes semantics.
- G4. Company provisioning (new tenants and additional companies) reads the assigned published template; parity with legacy output proven against frozen goldens (§5.4) before switchover.
- G5. Audit rows written **in the same transaction** as every template/assignment mutation.
- G6. Published versions carry certification metadata: content hash, reviewer identity, referenced standard, decision timestamp, supersession link, and an **immutable certified jurisdiction scope** (R2-08).

**Non-Goals (explicit):**

- N1. No changes to accounts of existing companies. New required accounts for existing tenants remain idempotent tenant backfill migrations + `tenants:migrate-rolling`.
- N2. Other domains are follow-up phases; the framework is domain-generic now, only the COA content table and editor ship.
- N3. No tenant-facing changes. Tenants keep the existing account API (list/show/create/update — no account-delete route; purpose mappings have delete/unassign: `Accounting/Presentation/routes.php:25-75`).
- N4. No i18n of template content in v1.
- N5. No four-eyes publish approval in v1.
- N6. Purpose-based rewrite of `InstrumentAccountResolver` and the withholding document-literal lane (follow-ups, §13).

## 3. Data Model (central DB — logical `central` connection)

> Naming note: tables live in the **central** database, referenced only via the logical `central` connection; the physical DB name is deployment-specific (`apps/api/config/database.php:124-135`).

**All three models use Stancl's `CentralConnection` concern** — explicit invariant (F-13), same as `VerticalConfig` (`apps/api/app/Models/VerticalConfig.php:20-24`). A tenancy-initialized test proves central resolution works while the default connection points at a tenant DB (pattern: `tests/Feature/Services/TenantConfigCacheTenancyTest.php:177-203`).

### 3.1 `admin_templates`

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| domain | string enum `TemplateDomain` | `chart_of_accounts` only in v1 |
| name | string | e.g. "PCN Tunisie v1" |
| description | text nullable | |
| status | string enum `TemplateStatus` | `draft` \| `published` \| `archived` |
| bootstrap_key | string nullable, UNIQUE, **immutable** | set only by the bootstrap data migration (e.g. `coa.tn.legacy-v1`); guards idempotent import (R2-04) |
| cloned_from_id | uuid nullable FK admin_templates | provenance / supersession chain |
| content_hash | string nullable | SHA-256 over the canonical serialization (§5.4); set at publish |
| standard_ref | string nullable | referenced accounting standard + version; required at publish |
| certified_country_codes | jsonb nullable | **immutable once published** (R2-08). Scope algebra (R3-02): EITHER a non-empty set of exact ISO codes OR the single element `*` — **never mixed**. Exact set ⇒ assignable only to exactly those countries; protected-code validation runs per listed country with that country's variant. `*` ⇒ certified as the generic international fallback: assignable ONLY to the `*` assignment row, validated against the non-TN variant set; it is explicitly NOT jurisdiction-certified for any exact country (matches today's Generic seeder role). A country with no exact assignment provisioning via wildcard therefore receives a generic, not-country-certified chart — by design. |
| certified_by | FK super_admins nullable | who published/certified |
| published_at | timestamp nullable | certification decision timestamp |
| created_by | FK super_admins nullable | |
| timestamps | | |

Also: UNIQUE index on `(id, domain)` — parent key for the assignment composite FK (§3.3).

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

`is_active` is **not stored**: it is a derived constant `true` at seed time (all legacy seeders write `true`) and is excluded from the canonical serialization (R2-11).

### 3.3 `country_template_assignments`

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| country_code | string(2) uppercase or `*` | normalized at every write boundary (§5.1, R2-05) |
| domain | string enum | must equal the template's domain — composite FK `(template_id, domain)` → `admin_templates(id, domain)` |
| template_id | uuid FK | must be `published` AND `country_code` within the template's `certified_country_codes` (checked in-transaction) |
| UNIQUE(country_code, domain) | | |

**Country catalog (F-09):** tenant-DB `countries` is not usable centrally. The assignments UI uses a **versioned static central ISO-3166 provider** (superset of `CountriesSeeder`). Registration accepts any 2-letter string, so unknown codes resolve to the wildcard; the wildcard row is pinned, non-removable, always visible. Test: an accepted 2-letter code absent from `CountriesSeeder` provisions via wildcard.

## 4. Lifecycle: draft → publish → assign; published is immutable

### 4.1 States and transitions

- Drafts freely editable. Published templates immutable (API rejects row mutations and `certified_country_codes` changes). Change = clone → draft → edit → publish → re-point. `archived` hides from pickers; a template referenced by any assignment can be neither archived nor deleted.
- **Atomicity (F-14):** publish, archive, delete, and re-point each run in a central-DB transaction with row locks (`SELECT ... FOR UPDATE`; on re-point, lock old and new template rows in deterministic id order plus the assignment row). Status and scope checks happen after acquiring locks. Wildcard protection is a mutation rule (delete/repoint of `*` to a non-published, wrong-domain, or out-of-scope template is rejected in-transaction).

### 4.2 Publish gate (server-side, in the publish transaction)

Publish takes two inputs: `standard_ref` and `certified_country_codes` (the jurisdiction scope the certifier is vouching for — this fixes R2-08's "context-free publish": country-conditional checks run against the declared scope, not against assignments that don't exist yet). The scope obeys the algebra in §3.1 (exact set XOR `*`), implemented as a shared `CertificationScope` value object used by publish, assignment, `country-defaults:verify`, and the UI, with a truth-table test covering scopes `["TN"]`, `["FR"]`, `["TN","FR"]`, `["*"]` (and rejected mixed input `["FR","*"]`) against assignments `TN`, `FR`, an unlisted ISO code, and `*` (R3-02).

1. **Operational purpose set (F-02/R2-02):** the versioned manifest `ProvisioningRequiredPurposes::V1` is **enumerated here** (from the 2026-08-08 code inventory of every throwing purpose resolution: `Account::findByPurposeOrFail`, the private wrapper `GeneralLedgerService::getAccountByPurpose()` (`GeneralLedgerService.php:4582-4584`) and its dynamic call sites, and `AccountingService::findAccountByPurpose` on the live invoice-posting path via `InvoicePostedListener.php:30-32`). **16 REQUIRED-UNCONDITIONAL cases** — reachable throwing resolution in ordinary operations with no optional module gate:
   `Bank`, `Cash` (paid-expense repository match, `GLS:3950-3951`), `CustomerReceivable` (`AS:409`), `Inventory` (`GLS:1829,1986,1721`), `SupplierPayable` (`GLS:1989`), `VatCollected` (`AS:427`), `VatDeductible` (`GLS:1984`), `ProductRevenue` (`AS:414`), `ServiceRevenue` (resolved eagerly on every invoice — `AS:422,574`), `CostOfGoodsSold` (ungated `PostCOGSOnInvoice.php:73` → `GLS:1720`), `GeneralExpense` (throwing fallback for category-less expenses — `GLS:3924,3938`), `OpeningBalanceEquity` (`AccountingOpeningService.php:314`), `PurchasePriceVarianceExpense`+`Income` (eager in GR-IR clearing — `GLS:1987-1988`), `GoodsReceivedNotInvoiced` (`GLS:1830,1983`), `PurchaseStampDuty` (eager on every GR-IR supplier-invoice clearing — `GLS:1985`).
   The remaining 25 cases are CONDITIONAL (15 — module/flow-gated: advances, tolerance/rounding, refund compensation, vouchers, stamped credit notes, uninvoiced-delivery accrual, `SalesDiscount`) or SOFT (10 — nullable-tolerated or unused). CONDITIONAL purposes are validated as warnings in the editor panel (publishable, but surfaced); the manifest class documents each gate. Drift guard = a **PHPStan rule / AST ratchet** that flags any new throwing purpose-resolution call site not listed in the consumer manifest — not a grep test. NOT the 11-value `requiredPurposes()` helper.
2. **Purpose/type compatibility (F-03):** `row.type === purpose.expectedAccountType()` for every purpose-bearing row.
3. **System flag (F-03):** `system_purpose !== null ⇒ is_system === true`.
4. **Protected codes (F-01/R2-01/R2-07/R3-03):** for each country in the certified scope (per the §3.1 algebra; `*` = the non-TN variant set), the **nine instrument codes** from the shared protected-code registry (single PHP class consumed by the gate and, as a test dependency, by `InstrumentAccountResolver`) must exist with the resolver's queried account types, **and each protected row must have `is_system=true`** (tenant immutability is solely the `is_system` check — `AccountController.php:164-180`). When the scope includes `TN`, the registry additionally requires the five expense-category codes (`613`,`615`,`616`,`624`,`626`) to exist with type `expense` (existence + type only, no `is_system` requirement; tagged in the registry as demo-consumer protection, lifted by the expense-category domain phase — R3-03). Withholding `42236`/`42237` are excluded (document-format literals, §1). Assignment re-runs the same per-country validation for its `country_code`.
5. Structural checks: unique codes, unique purposes, parent references resolve in-template, valid type enum values.
6. **Certification metadata:** sets `content_hash` (§5.4), `certified_by` = acting admin, `published_at`; `standard_ref` and non-empty `certified_country_codes` required.

Editor shows protected rows locked with an explanation. Negative tests for every rule.

## 5. Provisioning Rewire

### 5.1 Resolver

`CountryTemplateResolver::resolve(TemplateDomain $domain, string $countryCode): TemplateData` — reads on the `central` connection. **Normalization invariant (R2-05):** the resolver `trim()`s and uppercases its input, and assignment writes normalize `country_code` route/body parameters. Note the additional-company path accepts any 2-char string and persists verbatim (`CreateCompanyRequest.php:19-27`), while only the legacy COA service uppercased before matching — lowercase `tn` must resolve to TN, covered by tests for both registration and additional-company creation. Resolution: exact country → `*`.

**No caching for provisioning resolution (F-11).** Company creation is low-frequency; the documented forget-race (`CompanyConfigService.php:95-103`) would let a stale template serve for a full TTL after a re-point. Admin-UI list endpoints may cache; provisioning never does.

### 5.2 Consumers — complete inventory (F-06/R2-09/R2-10)

| consumer | change |
|---|---|
| `TenantInitializationService.php:199-210` (new-tenant registration) | route through resolver |
| `ChartOfAccountsService.php:134-148` (`CompanyController.php:153-163` — additional company) | route through resolver; new company gets the currently assigned published template (owner ruling) |
| `DatabaseSeeder.php:83-89, 117-123` | adapt to template path (resolver against bootstrapped templates) |
| `DemoTenantSeeder.php:215-220`, `CoffeeShopSeeder.php:365-373` | adapt to template path |
| `TunisianParapharmacySeeder.php:53-75` (direct `TunisiaChartOfAccountsSeeder` instantiation) + its feature tests (`SeededProductsHaveTaxRateTest.php:70-81`, `DemoSeedersTaxTest.php:89-99`) | adapt to template path; re-scope tests; tests that deliberately exercise the frozen classes are explicitly labeled historical-compat (R2-10) |
| `ParapharmacySeeder.php:627-638` + `DemoPharmacySeeder.php:97-113` (via `ChartOfAccountsSeederContract`) | contract gains a template-backed implementation; class-name override becomes a country/template parameter |
| `ExpenseCategorySeeder.php:37-69,86-93` (literal TN codes `613`/`615`/`616`/`624`/`626`, silent `GeneralExpense` fallback) | consumer-migrated (R2-09/R3-03): the five codes are TN-scope protected (§4.2.4), so they are guaranteed present in any TN-certified template — the seeder keeps code-based lookup but the silent `GeneralExpense` fallback becomes a **loud failure**. Renumbering them is impossible until the expense-category domain phase ships a stable semantic mapping (editor shows them locked with that explanation). Test: publish a TN template missing `613` → gate rejects; run the seeder against a template-provisioned company → all five categories resolve, none fall back. |
| historical tenant migration `2026_03_23_300000_fix_existing_tunisian_companies_tax_setup.php:5-8,44-53` | **the three seeder classes are retained permanently as frozen compatibility artifacts** for migration replay; `@deprecated`, excluded from provisioning, never edited again |

### 5.3 Failure semantics (F-08)

- New-tenant registration: resolver/seed failure throws → existing compensation flow (`TenantProvisioningService.php:187-249`). No silent fallback.
- Additional-company creation: COA resolution/seed failure **rolls back company creation** (the path already wraps a transaction — `CompanyController.php:70-80`; the current catch-log-continue at `:153-165` is removed). Tests cover resolver failure in both paths.

### 5.4 Fixtures, parity, and content hash (F-10/R2-02/R2-11)

- **Canonical projection:** `(code, name, type, parent_code, system_purpose, is_system, sort_order)` with `parent_id` resolved to parent code; `is_active` excluded (derived `true`; R2-11). `sort_order` is the persisted column (§3.2), unique per template (enforced by a `UNIQUE(template_id, sort_order)` index — no tie behavior to define; R3-05).
- **Canonical serialization** (single shared backend class — `CanonicalCoaSerializer` — used by the exporter, publish `content_hash`, `country-defaults:verify`, and tests; R3-05): rows sorted ascending by `sort_order`; each row `json_encode`d with flags `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`, exactly the projection's field order, `null` as JSON null; strings must be valid UTF-8 normalized to NFC before encoding; rows joined by `\n` with **no trailing newline**; SHA-256 over the UTF-8 bytes. A committed golden vector with non-ASCII content (French accented account names) pins the byte output.
- **Two fixture sets (R2-02):**
  1. **Legacy goldens** — immutable, captured from the frozen pre-refactor seeders via a one-time exporter (runs the frozen seeders into a scratch schema, serializes the projection; no reflection into private arrays). The **bootstrap drafts** (§6) must match these byte-for-byte — that is the parity gate.
  2. **Certified v1 fixtures** — the published templates after certification edits, each with a documented delta vs its legacy golden.
- **Known baseline defects (R2-02 + 2026-08-08 inventory):** verified against the 16-case manifest — **Tunisia and Generic are missing NO required purposes**; **France is missing TWO: `CostOfGoodsSold`** (breaks ungated `PostCOGSOnInvoice` on every product invoice; also silently disables POS scrap write-offs via `hasInventoryWriteOffAccounts`, `GLS:4349`) **and `GeneralExpense`** (throws on any expense whose category has no `account_id`, `GLS:3938`). The FR legacy golden therefore CANNOT pass the v1 publish gate as-is; both corrections are applied as certification edits with documented deltas. **Both are also live gaps in today's FR seeding — tracked as separate existing-tenant backfill tickets, outside this spec (N1).** Additional inventory finding, same ticket family: `SalesDiscount` is CONDITIONAL but its GL site has **no precheck** (`GLS:3826`) and the purpose is absent from BOTH TN and FR charts — a POS account-charge carrying a transaction discount 500s today; recommended as a certification edit for TN and FR plus an existing-tenant backfill ticket.

### 5.5 Template seeder semantics (F-15)

`TemplateChartOfAccountsSeeder` reproduces the ACTUAL current semantics: first pass inserts missing rows, preserves existing name/type/purpose, promotes `is_system`; second pass **re-issues `parent_id` links for every definition including pre-existing rows** (`TunisiaChartOfAccountsSeeder.php:94-100`; `SeedChartsCommand.php:47-50,110-123`). A rerun-after-manual-reparent test pins this.

## 6. Bootstrap + Deploy (F-05/R2-03/R2-04)

Reality: the entrypoint runs central migrations (failures logged, boot continues — `entrypoint.sh:118-127`), seeds only when `AUTO_SEED=true` AND zero tenants (`entrypoint.sh:169-190`), and never invokes custom verify commands. The rollout is therefore explicitly **externally gated**, not entrypoint-self-guarding:

- **Release 1 (schema + draft import):** central migrations create the tables; a **central data migration** imports the three legacy templates **as DRAFTS** (not published — R2-03), each keyed by an immutable `bootstrap_key` (`coa.tn.legacy-v1`, `coa.fr.legacy-v1`, `coa.generic.legacy-v1`). Each keyed import is **one atomic transaction** (template header + all rows). The key is an **assertion, not a presence flag** (R3-04): on key collision the importer recomputes the canonical legacy serialization and requires exact domain, expected bootstrap status, and matching hash/row count — any mismatch **aborts the migration with a diagnostic** (a partially committed or manually altered keyed row must never be silently blessed). `bootstrap_key` immutability and bootstrap-only assignment are enforced in the model (guarded attribute + service check). Tests: keyed wrong-domain row, missing child rows, altered content, a published keyed row, concurrent import attempts, retry after failed import.
- **Certification step (human, before Release 2):** for each draft — apply certification edits (e.g. the FR `CostOfGoodsSold` correction, §5.4), then publish **through the authenticated admin UI/HTTP publish endpoint only**. There is NO artisan certify path (R3-01): a CLI flag naming an admin cannot prove that person made the decision, so `certified_by` may only ever be set from the authenticated Sanctum actor of the publish request. No migration ever creates a published row; nothing bypasses the gate.
- **Assignments:** created after publish (`TN`, `FR`, `*`) via the authenticated UI, within certified scopes.
- **Release 2 (reader switchover):** consumers switch to the resolver ONLY after `php artisan country-defaults:verify` passes on staging and production. `verify` asserts: three bootstrap-keyed templates exist; `TN`/`FR`/`*` assignments reference **published** templates whose certification fields are all present and whose scope covers the assignment; every published template passes the full current publish-gate invariants (re-executed, not just hash-compared); drafts-only state fails. The release-2 gate is a deploy-checklist item (added to the launch-program consolidated deploy list), enforced by runbook + the verify command — explicitly NOT by the entrypoint.
- House rule: everything pushed is safe under `origin/dev` auto-deploy (Release 1 is schema + idempotent draft import; readers stay on legacy seeders until Release 2 code ships).

## 7. Access Control (F-04/F-12/F-17/R2-06)

Current reality: `super_admins.role` exists with enum `SuperAdminRole { SuperAdmin, SupportApprover }`; `EnsureSuperAdmin` rejects every role except `super_admin`; support-access routes use `RequireCentralAdminRole` (`central_admin_role:` middleware, exact-string matrix — `SupportAccess/Presentation/routes.php:17-46`).

- **Add `DefaultsEditor = 'defaults_editor'` to the existing enum.** No backfill, no rename; `support_approver` four-eyes semantics untouched. (`RequireCentralAdminRole` grants only exact listed roles, so existing support routes cannot leak to the new role. If implementation ever refactors that middleware, `EnsureCentralAdmin` must be added to the support group in the same change so the active-account check is not lost.)
- **Split authentication from capability:** new `EnsureCentralAdmin` (authenticated, active, any role) + `central_admin_role:` per group. Existing fleet/billing/monitoring/verticals groups keep `EnsureSuperAdmin` byte-for-byte. New `admin/country-defaults` group = `EnsureCentralAdmin` + `central_admin_role:super_admin,defaults_editor`.
- **Editor-account lifecycle routes are `super_admin` ONLY (R2-06):** every `/editors` route carries a nested `central_admin_role:super_admin` middleware (the term "owner role" is retired — no such role exists). A `defaults_editor` must never administer accounts.
- **Route-inventory test:** iterates the route table; a `defaults_editor` token gets 403 on every admin route — **including every `/editors` lifecycle verb** — except auth profile/logout + country-defaults template/assignment routes.
- **MFA (F-12):** central-admin auth is password-only today. Per the standing owner MFA ruling, **external `defaults_editor` accounts cannot authenticate until the MFA lane lands**: feature flag `country_defaults.external_editors_enabled` (default off) checked at login for the role. The owner reviews via their own account meanwhile.
- **Account lifecycle (F-17):** super-admin endpoints to create / disable / re-enable / reset-credentials for `defaults_editor` accounts; disable and role change revoke all Sanctum tokens; all lifecycle actions audited.

## 8. API Surface (prefix `admin/country-defaults`)

| verb | route | notes |
|---|---|---|
| GET | `/templates?domain=` | list |
| POST | `/templates` | create empty draft |
| POST | `/templates/{id}/clone` | clone → draft |
| GET | `/templates/{id}` | header + rows |
| PUT | `/templates/{id}` | rename/describe/standard_ref (draft only) |
| DELETE | `/templates/{id}` | draft only, unassigned only |
| PUT | `/templates/{id}/rows` | bulk upsert/delete rows (draft only) |
| GET | `/templates/{id}/validation` | live validation report (optionally `?scope=TN,FR` to preview per-country protected checks) |
| POST | `/templates/{id}/publish` | inputs: `standard_ref`, `certified_country_codes`; gate §4.2, transactional |
| POST | `/templates/{id}/archive` | unassigned only, transactional |
| GET | `/assignments?domain=` | matrix from static country provider |
| PUT | `/assignments/{countryCode}` | normalized param; transactional; published + in-scope + per-country protected validation |
| GET/POST/PUT | `/editors` | **nested `central_admin_role:super_admin`** (R2-06); defaults_editor lifecycle (F-17) |

FormRequests, strict typing, enums throughout; standard response envelope.

**Audit (F-16):** every mutation writes its `AdminAuditLog` row inside the same central transaction (service-owned; a mutation without an audit row cannot commit). Bulk row updates store before/after canonical serializations (or hash pair for large diffs). Cache invalidation (admin-UI caches only) after commit.

## 9. Admin UI (new nav section "Country Defaults")

1. **Templates list** — domain filter, status chips, certification metadata (incl. scope), Clone/Archive.
2. **Template editor** — spreadsheet grid (code, name, type, parent, purpose dropdown, system flag); protected rows locked with tooltip; persistent validation panel; Publish modal collects `standard_ref` + `certified_country_codes` and shows the content hash on success.
3. **Assignments view** — static country catalog × assigned template; pinned wildcard row; re-point confirm: "affects newly created companies only"; out-of-scope templates not offered.
4. **Role-aware shell — full three-role capability matrix (R2-12/R3-06):** the stored admin role union gains `defaults_editor` (`adminAuthStore.ts:4-9`; `support_approver` is already in it). Login success and the `/admin` index route land each role on its home: `super_admin` → `/admin/dashboard`, `defaults_editor` → `/admin/country-defaults`, **`support_approver` → `/admin/support-access`** (today login unconditionally navigates to the dashboard — `AdminLoginPage.tsx:16-31`, `routes/index.tsx:385-411`). Client route guards per role: full-admin routes = `super_admin` only; country-defaults routes = `super_admin` + `defaults_editor`; support-access routes = `super_admin` + `support_approver` (mirroring the backend matrix — `SupportAccess/Presentation/routes.php:17-43`). Nav renders only each role's permitted sections — `support_approver` keeps its support-access entry and must not be stranded on a forbidden dashboard. Tests: login landing, `/admin` index redirect, direct-URL navigation, and nav filtering **for all three roles**. Backend remains authoritative.
- `t()` throughout (`adminCountryDefaults` namespace); design tokens; plain (non-tenant-scoped) query keys like existing admin pages.

## 10. Testing (TDD)

- Publish-gate rules (each red/green; F-02/F-03 negatives; protected-code per-scope checks incl. `is_system=false` negative — R2-07); protected-registry drift test vs `InstrumentAccountResolver`; purpose-manifest AST/PHPStan ratchet; resolver exact/wildcard/missing/lowercase (`tn` → TN — R2-05); immutability incl. scope immutability; scope-algebra truth table (R3-02); transactional races (publish-vs-assign, archive-vs-assign); role/route-inventory incl. `/editors` verbs (R2-06); MFA-flag login block; parity vs legacy goldens; certified-fixture deltas (FR `CostOfGoodsSold`); canonical-serializer golden vector incl. non-ASCII (R2-11/R3-05); bootstrap key-assertion suite (wrong-domain/altered/published keyed row, concurrency — R3-04); `country-defaults:verify` red/green incl. drafts-only and missing-certification failure (R2-03); company-creation rollback in both paths; rerun-reparenting pin; tenancy-initialized central-read; `ExpenseCategorySeeder` loud-failure (R2-09).
- Frontend: Vitest for grid validation, locked rows, role-filtered nav + landing + direct-URL guards (R2-12), assignment confirm.
- Preflight by path; no full PHPUnit suite without permission.

## 11. Phasing & Effort

- **Phase A (this spec):** framework + COA end-to-end, **~9–12 dev-days** (Rev 3 adds: certification step tooling, scope model, FE role shell, consumer additions).
- **Phase B+:** tax rates → tax configurations → payment settings → withholding rules (incl. the `42236`/`42237` document-literal lane) → pricing regulations → expense categories (semantic mapping); each = content table/payload + editor + parity + seeder retirement.

## 12. Risks & Mitigations

| risk | mitigation |
|---|---|
| Accountant renumbers a treasury protected account | per-scope protected-code gate + `is_system=true` requirement + locked editor rows (§4.2.4) |
| Template passes gate but breaks later GL flows | enumerated purpose manifest + AST ratchet (§4.2.1); FR `CostOfGoodsSold` defect already caught and corrected via certification (§5.4) |
| Bootstrap absent/partial on existing envs | bootstrap-keyed idempotent draft import + human certification + `country-defaults:verify` + externally-gated two-release switchover (§6) |
| Company created without COA | rollback semantics in both provisioning paths (§5.3) |
| Wrong template via lowercase country code | normalization invariant + tests (§5.1) |
| Stale assignment after re-point | no caching on provisioning resolution (§5.1) |
| External actor without MFA | defaults_editor login feature-flagged off until MFA lane lands (§7) |
| defaults_editor self-administration | `/editors` nested `super_admin` middleware + route-inventory test (§7, R2-06) |
| Lifecycle races corrupt assignments | transactions + row locks + in-transaction wildcard/domain/scope rules (§4.1) |
| Audit gaps | audit row required in mutation transaction (§8) |
| Historical migration replay breaks | seeder classes frozen permanently (§5.2) |
| defaults_editor sees full-admin UI | role-aware landing + client route guards + backend 403s authoritative (§9.4) |

## 13. Follow-up lanes (out of this spec)

1. **Treasury purpose migration:** purpose-based resolution for the nine `InstrumentAccountPurpose` cases; unlock those template rows. Treasury-reviewer gated.
2. **Withholding document-literal lane:** `42236`/`42237` handling inside the withholding domain phase.
3. **Existing-tenant backfill tickets for live purpose gaps found in review (§5.4):** FR `CostOfGoodsSold`, FR `GeneralExpense`, and TN+FR `SalesDiscount` (unprechecked GL site `GLS:3826` — POS account-charge with transaction discount 500s).
4. **Four-eyes certification** for external editors.
5. **Central-admin MFA** (consumed here only via the activation flag).
6. Remaining domains (Phase B+), incl. semantic expense-category mapping.
