# Country Defaults in the Super Admin Panel — Design Spec

- **Date:** 2026-08-08
- **Status:** Rev 10 — post Codex round 9 (REJECT: R9-01 + R9-02). R9-01 closed: capability-version drift now blocks **provisioning** (resolver step 2b, typed `TemplateRecertificationRequiredException`; verify fails non-zero; registry bumps are runbook-gated clone→recertify→re-point). R9-02 (live tenant tax-surface defects, pre-existing): **owner ruling 2026-08-09 — handed to the main fixes lane** via `docs/handoff/HANDOVER-live-accounting-gaps-country-defaults-review-2026-08-09.md` (items G+H), recorded in N3 as a named precondition for the certification claim; N3 otherwise intact. Pending Codex round 10 + owner sign-off. Register: `reviews/2026-08-08-country-defaults-spec-review.md`.
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
- `ExpenseCategorySeeder` maps categories to literal codes (`613`,`615`,`616`,`624`,`626`) with a silent `GeneralExpense` fallback (`ExpenseCategorySeeder.php:37-69`), and runs for BOTH TN and FR pharmacy paths. In v1 these five codes join the **TN and FR protected sets** (existence + type at publish; demo-consumer protection, lifted when the expense-category domain phase introduces a stable semantic mapping — R3-03/R4-02/R5-03) and the seeder's silent fallback becomes a loud failure (§5.2).

## 2. Goals / Non-Goals

**Goals (this phase):**

- G1. Central, DB-backed template library for country defaults, starting with domain `chart_of_accounts`.
- G2. Super-admin UI: list, clone, edit (draft), validate, publish, assign per (country, domain) with wildcard fallback for non-timbre countries (timbre-capable countries require exact assignments — §5.1 algorithm).
- G3. Scoped central-admin role (`defaults_editor`) coexisting with the existing `super_admin` / `support_approver` roles and their four-eyes semantics.
- G4. Company provisioning (new tenants and additional companies) reads the assigned published template; parity with legacy output proven against frozen goldens (§5.4) before switchover.
- G5. Audit rows written **in the same transaction** as every template/assignment mutation.
- G6. Published versions carry certification metadata: content hash, reviewer identity, referenced standard, decision timestamp, supersession link, and an **immutable certified jurisdiction scope** (R2-08).

**Non-Goals (explicit):**

- N1. No changes to accounts of existing companies. New required accounts for existing tenants remain idempotent tenant backfill migrations + `tenants:migrate-rolling`.
- N2. Other domains are follow-up phases; the framework is domain-generic now, only the COA content table and editor ship.
- N3. No tenant-facing changes. Tenants keep the existing account API (list/show/create/update — no account-delete route; purpose mappings have delete/unassign: `Accounting/Presentation/routes.php:25-75`). **R9-02 disposition (owner ruling 2026-08-09):** the tenant tax-surface defects found in round 9 (tax API permits `is_stamp_duty` for non-timbre countries; `TaxCalculationService` aggregates ALL `DOCUMENT_TOTAL` taxes into `stamp_duty_amount` unfiltered) are live defects independent of this feature, handed to the main fixes lane — `docs/handoff/HANDOVER-live-accounting-gaps-country-defaults-review-2026-08-09.md` items G+H. They remain a **named precondition for the per-country certification CLAIM**: until G+H ship, the timbre invariant is enforced at the template layer only, and tenant-authored tax data can still route amounts into stamp accounting. The template invariants in this spec ship regardless.
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
| certified_country_codes | jsonb nullable | **immutable once published** (R2-08). Scope algebra (R3-02/R7-02): EITHER a non-empty set of exact ISO codes OR the single element `*` — **never mixed**, and an exact set may not mix timbre with non-timbre countries per `CountryAccountingCapabilities` (§4.2.1). Exact set ⇒ assignable only to exactly those countries; protected-code validation runs per listed country with that country's variant. `*` ⇒ certified as the generic **non-timbre** fallback: assignable ONLY to the `*` assignment row, must not contain a stamp-duty-purposed account, and is explicitly NOT jurisdiction-certified for any exact country (matches today's Generic seeder role). A non-timbre country with no exact assignment provisions via wildcard (generic, not-country-certified chart — by design); a **timbre-capable country never falls back to wildcard** — provisioning fails loudly without an exact assignment. |
| capability_registry_version | string nullable | **set at publish, immutable** (R8-03/R9-01): the `CountryAccountingCapabilities` version the certification was decided under. **Version drift blocks PROVISIONING, not just new assignments:** the resolver (§5.1 step 2b) refuses any assignment whose template's stored version differs from the current registry version, throwing a typed `TemplateRecertificationRequiredException`; `country-defaults:verify` FAILS (non-zero) on drift. Bumping the registry version is a runbook operation: clone → recertify → re-point every affected assignment BEFORE the new version becomes active. Existing companies are untouched (owner ruling) |
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

**Country catalog (F-09):** tenant-DB `countries` is not usable centrally. The assignments UI uses a **versioned static central ISO-3166 provider** (superset of `CountriesSeeder`). Registration accepts any 2-letter string, so unknown codes resolve to the wildcard **unless timbre-capable — the §5.1 algorithm governs, and timbre countries get a typed provisioning error instead of wildcard fallback (R8-02)**; the wildcard row is pinned, non-removable, always visible. Test: an accepted 2-letter non-timbre code absent from `CountriesSeeder` provisions via wildcard.

## 4. Lifecycle: draft → publish → assign; published is immutable

### 4.1 States and transitions

- Drafts freely editable. Published templates immutable (API rejects row mutations and `certified_country_codes` changes). Change = clone → draft → edit → publish → re-point. `archived` hides from pickers; a template referenced by any assignment can be neither archived nor deleted.
- **Atomicity (F-14):** publish, archive, delete, and re-point each run in a central-DB transaction with row locks (`SELECT ... FOR UPDATE`; on re-point, lock old and new template rows in deterministic id order plus the assignment row). Status and scope checks happen after acquiring locks. Wildcard protection is a mutation rule (delete/repoint of `*` to a non-published, wrong-domain, or out-of-scope template is rejected in-transaction).

### 4.2 Publish gate (server-side, in the publish transaction)

Publish takes two inputs: `standard_ref` and `certified_country_codes` (the jurisdiction scope the certifier is vouching for — this fixes R2-08's "context-free publish": country-conditional checks run against the declared scope, not against assignments that don't exist yet). The scope obeys the algebra in §3.1 (exact set XOR `*`), implemented as a shared `CertificationScope` value object used by publish, assignment, `country-defaults:verify`, and the UI, with a truth-table test covering scopes `["TN"]`, `["FR"]`, `["*"]`, plus rejected inputs `["FR","*"]` (wildcard mixing) and `["TN","FR"]` (timbre mixing — R7-02), against assignments `TN`, `FR`, an unlisted ISO code, and `*` (R3-02).

1. **Operational purpose set (F-02/R2-02):** the versioned manifest `ProvisioningRequiredPurposes::V1` is **enumerated here as the complete 41-case partition** (R6-03; from the 2026-08-08 code inventory of every throwing purpose resolution: `Account::findByPurposeOrFail`, the private wrapper `GeneralLedgerService::getAccountByPurpose()` (`GeneralLedgerService.php:4582-4584`) and its dynamic call sites, and `AccountingService::findAccountByPurpose` on the live invoice-posting path via `InvoicePostedListener.php:30-32`). The manifest class is the source of truth once committed; a conformance test asserts the spec table and the class agree (R6-04). **REQUIRED cases (27)** — reachable throwing resolution in ordinary operations with no allowed gate:
   `Bank`, `Cash` (paid-expense repository match, `GLS:3950-3951`), `CustomerReceivable` (`AS:409`), `Inventory` (`GLS:1829,1986,1721`), `SupplierPayable` (`GLS:1989`), `VatCollected` (`AS:427`), `VatDeductible` (`GLS:1984`), `ProductRevenue` (`AS:414`), `ServiceRevenue` (resolved eagerly on every invoice — `AS:422,574`), `CostOfGoodsSold` (`PostCOGSOnInvoice.php:43-80` → `GLS:1720`; the listener catches and logs, so absence silently SKIPS COGS entries rather than failing the invoice — still required: silent missing COGS is a books-integrity defect), `GeneralExpense` (throwing fallback for category-less expenses — `GLS:3924,3938`), `OpeningBalanceEquity` (`AccountingOpeningService.php:314`), `PurchasePriceVarianceExpense`+`Income` (eager in GR-IR clearing — `GLS:1987-1988`), `GoodsReceivedNotInvoiced` (`GLS:1830,1983`), `PurchaseStampDuty` (eager on every GR-IR supplier-invoice clearing — `GLS:1985`), **case 17: `SalesDiscount`** (R4-01 — unprechecked throwing lookup on the core POS account-charge path whenever a transaction discount is positive, `GLS:3807-3827`; the projection expects the line, `TreasuryAccountChargeBridge.php:239-249`), **case 18: `CustomerAdvance`** (R5-01 — ordinary payment allocation posts an advance for an order allocation or excess payment, `PaymentAllocationService.php:307-365` → unprechecked throwing lookup `GLS:396-417`; plain Treasury permission route, no module gate), **case 19: `SupplierAdvance`** (R5-01 — ordinary prepayment-refund route → `VendorRefundService.php:153-175` → unprechecked throwing lookup `GLS:496-517`), **cases 20–24: the five voucher purposes `SalesReturnsClearing`, `VoucherLiability`, `MarketingGoodwillExpense`, `PosTenderClearing`, `RoundingLossExpense`** (R6-01 — voucher routes carry auth+permissions but NO module gate, `Voucher/Presentation/routes.php:20-42`, `ModuleName` has no Voucher case; issuance/redemption/rounding all resolve through the throwing wrapper, `GLS:2563-2566,2619-2648`, via live `VoucherIssuanceService.php:295-356` and `VoucherRedemptionService.php:184-204,226-253`), **cases 25–26: `PaymentToleranceExpense`, `PaymentToleranceIncome`** (R6-02 — the tolerance projection's alert-and-skip is NOT durably fail-safe: `recordTolerancePurposeMissingAlertSafely()` swallows persistence failures, `TreasuryReceiptBridge.php:567-583`, leaving a reachable state with neither GL entry nor durable record — so both are REQUIRED rather than blessed by a fragile gate), **case 27: `PurchaseExpenses`** (R6-03 — the bonus-return GL path `GLS:2311-2370` is gated only by the application-level `PurchaseBonusGate` FormRequest check (`CreateSupplierInvoiceRequest.php:68-72,140-148`), which fits no allowed gate kind; classified REQUIRED — costless since all three charts define it).
   **SCOPE-REQUIRED (1):** `SalesStampDutyPayable` — required only when the certified scope includes a timbre country: the throwing site fires only for credit notes actually carrying stamp duty (`GLS:291` inside `if ($hasStampDuty)`). **Timbre capability comes from ONE versioned central registry, `CountryAccountingCapabilities`** (v1: timbre = `{TN}`), consumed by `CertificationScope`, publish, assignment, `country-defaults:verify`, and provisioning (R7-02 — no more prose-only "TN today").
   **Timbre scope rules (R7-02/R8-01, P1):** because runtime absorber selection picks the stamp account by mere existence before the rounding absorbers (`AS:212-225`), a template containing the stamp account would silently mispost non-timbre countries' rounding residuals to a stamp liability. Therefore: **a certified scope may not mix timbre and non-timbre countries** (publish rejects e.g. `["TN","FR"]`); **EVERY non-timbre scope — exact or wildcard — must NOT contain any row with the `SalesStampDutyPayable` purpose** (publish-gate negative rule, re-run at assignment and by `country-defaults:verify` — R8-01: an FR-only template with a correctly-typed stamp row would recreate the misposting); and **a timbre-capable country requires an exact assignment — the resolver refuses wildcard fallback for it** (§5.1 algorithm is authoritative). Tests: `["TN","FR"]` rejected at publish; `["FR"]` (and a second exact non-timbre country) containing a stamp-purposed row rejected at publish; TN positive control; non-timbre-with-rounding positive control; `["*"]` resolving an unknown non-timbre country OK; a registry-declared timbre country without an exact assignment → typed provisioning error on BOTH registration and additional-company paths.
   **CONDITIONAL (4):** `SalesReturn`, `RefundWriteOff` — gate kind `DOMAIN_PRECHECK_4XX`: refund compensation prechecks both and raises `RefundCompensationRefusedException` rendered 422 (`RefundCompensationService.php:184-193`; `bootstrap/app.php:487-496`; Codex-verified). **`SalesRoundingDifferenceIncome`, `SalesRoundingDifferenceExpense`** (R7-01 — NOT soft: for a positive residual the preflight selects stamp-duty else the rounding absorber, and absence refuses the plan → `UnpostableDocumentGlException` rendered 422 BEFORE sealing, `AS:212-233,82-96`, `DocumentPostingService.php:95-109`; the gate definition explicitly admits a nullable lookup whose absence itself produces the dominating 4xx). Conformance tests remove each absorber and assert the preflight refusal.
   **SOFT (9):** `OfficeExpense`, `TravelExpense`, `MealsExpense`, `UtilitiesExpense`, `RetainedEarnings`, `RealizedFxGain`, `RealizedFxLoss`, `VoucherBreakageIncome` (no Expired arm wired), and `UninvoicedRevenue` — **`UninvoicedDeliveryNoteService` has zero PRODUCTION callers (verified 2026-08-08; feature tests do exercise it directly — R7-03): dead/unwired production code**; the caller-scan conformance assertion runs over production roots (`app/`, `routes/`, `config/`, `database/`, `bootstrap/`), tests excluded.
   27 + 1 + 4 + 9 = 41.
   **Classification contract (R4-01/R5-02/R6-01):** each manifest entry is `(purpose, call site, classification, gate kind, evidence citation)`. A purpose is REQUIRED when a throwing resolution is reachable from a core business operation and no allowed gate dominates the lookup — data-gating does NOT downgrade it, and permission middleware is authorization, not a gate. Exactly two **allowed gate kinds** remain (R6-02 removed `ALERT_AND_SKIP`): `MODULE_GATE` (route/module middleware — currently zero members) and `DOMAIN_PRECHECK_4XX` (a dominating precheck raising a 4xx-mapped domain exception).
   **Enforcement, stated honestly (R5-02):** the **PHPStan/AST rule** enforces *registration* — any new throwing purpose-resolution call site absent from the manifest fails CI. *Classification correctness* is enforced by a **manifest-conformance PHPUnit suite**: every CONDITIONAL entry's gate evidence is asserted mechanically (precheck method + 4xx mapping exist), every SOFT entry is asserted to have no registered throwing site, and the suite includes a deliberately misclassified fixture entry that MUST fail. Residual semantic judgment (whether a gate "dominates") is review-owned and documented per entry. NOT the 11-value `requiredPurposes()` helper.
2. **Purpose/type compatibility (F-03):** `row.type === purpose.expectedAccountType()` for every purpose-bearing row.
3. **System flag (F-03):** `system_purpose !== null ⇒ is_system === true`.
4. **Protected codes (F-01/R2-01/R2-07/R3-03):** for each country in the certified scope (per the §3.1 algebra; `*` = the non-TN variant set), the **nine instrument codes** from the shared protected-code registry (single PHP class consumed by the gate and, as a test dependency, by `InstrumentAccountResolver`) must exist with the resolver's queried account types, **and each protected row must have `is_system=true`** (tenant immutability is solely the `is_system` check — `AccountController.php:164-180`). When the scope includes `TN` **or `FR`** (R4-02 — the parapharmacy seeder family defaults to France and runs `ExpenseCategorySeeder` unconditionally), the registry additionally requires the five expense-category codes (`613`,`615`,`616`,`624`,`626`) to exist with type `expense` (existence + type only, no `is_system` requirement; tagged in the registry as demo-consumer protection, lifted by the expense-category domain phase — R3-03). The FR legacy chart lacks `624`; the certified FR fixture adds it as a documented delta (PCG 624 "Transports de biens et transports collectifs du personnel" is a real PCG account). Withholding `42236`/`42237` are excluded (document-format literals, §1). Assignment re-runs the same per-country validation for its `country_code`.
5. Structural checks: unique codes, unique purposes, parent references resolve in-template, valid type enum values.
6. **Certification metadata:** sets `content_hash` (§5.4), `certified_by` = acting admin, `published_at`; `standard_ref` and non-empty `certified_country_codes` required.

Editor shows protected rows locked with an explanation. Negative tests for every rule.

## 5. Provisioning Rewire

### 5.1 Resolver

`CountryTemplateResolver::resolve(TemplateDomain $domain, string $countryCode): TemplateData` — reads on the `central` connection. **Normalization invariant (R2-05):** the resolver `trim()`s and uppercases its input, and assignment writes normalize `country_code` route/body parameters. Note the additional-company path accepts any 2-char string and persists verbatim (`CreateCompanyRequest.php:19-27`), while only the legacy COA service uppercased before matching — lowercase `tn` must resolve to TN, covered by tests for both registration and additional-company creation.
**Authoritative resolution algorithm (R8-02/R9-01 — this section governs; G2/§3.3 defer to it):**
1. normalize (trim + uppercase);
2. exact assignment for `(countryCode, domain)` → **2b. version check:** the assigned template's `capability_registry_version` must equal the current `CountryAccountingCapabilities` version, else throw `TemplateRecertificationRequiredException` (a stale certification must never provision a new company — covers both non-timbre→timbre and timbre→non-timbre transitions); then use it;
3. no exact assignment → consult `CountryAccountingCapabilities`: if the country is **timbre-capable, throw `TimbreCountryRequiresExactAssignmentException`** (never seed a non-timbre generic chart);
4. otherwise resolve the pinned `*` assignment (same 2b version check applies).
Tests: capability transitions in both directions through BOTH registration and additional-company paths (provisioning refused until re-pointed).

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
| `ExpenseCategorySeeder.php:37-69,86-93` (literal codes `613`/`615`/`616`/`624`/`626`, silent `GeneralExpense` fallback; run for BOTH TN and FR pharmacy paths — `ParapharmacySeeder.php:134-162,630-650`) | consumer-migrated (R2-09/R3-03/R4-02): the five codes are protected for **both TN and FR scopes** (§4.2.4; FR gets `624` as a certified fixture delta), so they are guaranteed present in any TN/FR-certified template. The seeder resolves its mapping **per company country** (country-aware map from the protected-code registry, not a global TN table) and the silent `GeneralExpense` fallback becomes a **loud failure**. Renumbering them is impossible until the expense-category domain phase ships a stable semantic mapping (editor shows them locked with that explanation). Tests: publish a TN template missing `613` → gate rejects; publish an FR template missing `624` → gate rejects; run the seeder against template-provisioned TN AND FR companies (incl. FR transport category) → all five categories resolve, none fall back. |
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
- **Known baseline defects (R2-02/R4-01/R4-02/R5-01 + 2026-08-08 inventory, Codex-verified):** against the manifest (§4.2.1) — the R6-01/R6-02/R6-03 additions (voucher five, tolerance two, `PurchaseExpenses`) change NO deltas because all three frozen charts already define them, and scope-required `SalesStampDutyPayable` is present in TN — so: **Tunisia is missing ONE required purpose (`SalesDiscount`), Generic is missing NONE, France is missing FIVE required purposes plus one protected code**:
  - FR `CostOfGoodsSold` — `PostCOGSOnInvoice` catches and logs, so FR product invoices post **without COGS entries silently** (books-integrity defect, not a crash); also silently disables POS scrap write-offs (`hasInventoryWriteOffAccounts`, `GLS:4349`).
  - FR `GeneralExpense` — throws on any expense whose category has no `account_id` (`GLS:3938`).
  - TN + FR `SalesDiscount` — unprechecked throwing lookup on POS account-charge with a positive transaction discount (`GLS:3807-3827`) → 500 today. **Publish blocker per the manifest (R4-01).**
  - FR `CustomerAdvance` — ordinary payment allocation with an order allocation or excess payment → unprechecked throwing lookup (`PaymentAllocationService.php:307-365`, `GLS:396-417`) → 500 today (R5-01).
  - FR `SupplierAdvance` — ordinary prepayment refund → unprechecked throwing lookup (`VendorRefundService.php:153-175`, `GLS:496-517`) → 500 today (R5-01).
  - FR protected code `624` absent (R4-02).
  The TN and FR legacy goldens therefore CANNOT pass the v1 publish gate as-is; the **certified-fixture delta table (R4-03/R5-01, derived from the manifest — never restated ad hoc)** is: TN + `SalesDiscount`; FR + `CostOfGoodsSold`, `GeneralExpense`, `SalesDiscount`, `CustomerAdvance`, `SupplierAdvance`, `624`. The acceptance suite is data-driven from the manifest: assert each country's missing set BEFORE certification, assert exactly these deltas and ZERO missing required purposes AFTER; any future manifest addition fails fixture verification until every country delta is explicitly resolved. **All six gaps are also live in today's seeding — tracked as separate existing-tenant backfill tickets, outside this spec (N1).**

### 5.5 Template seeder semantics (F-15)

`TemplateChartOfAccountsSeeder` reproduces the ACTUAL current semantics: first pass inserts missing rows, preserves existing name/type/purpose, promotes `is_system`; second pass **re-issues `parent_id` links for every definition including pre-existing rows** (`TunisiaChartOfAccountsSeeder.php:94-100`; `SeedChartsCommand.php:47-50,110-123`). A rerun-after-manual-reparent test pins this.

## 6. Bootstrap + Deploy (F-05/R2-03/R2-04)

Reality: the entrypoint runs central migrations (failures logged, boot continues — `entrypoint.sh:118-127`), seeds only when `AUTO_SEED=true` AND zero tenants (`entrypoint.sh:169-190`), and never invokes custom verify commands. The rollout is therefore explicitly **externally gated**, not entrypoint-self-guarding:

- **Release 1 (schema + draft import):** central migrations create the tables; a **central data migration** imports the three legacy templates **as DRAFTS** (not published — R2-03), each keyed by an immutable `bootstrap_key` (`coa.tn.legacy-v1`, `coa.fr.legacy-v1`, `coa.generic.legacy-v1`). Each keyed import is **one atomic transaction** (template header + all rows). The key is an **assertion, not a presence flag** (R3-04): on key collision the importer recomputes the canonical legacy serialization and requires exact domain, expected bootstrap status, and matching hash/row count — any mismatch **aborts the migration with a diagnostic** (a partially committed or manually altered keyed row must never be silently blessed). `bootstrap_key` immutability and bootstrap-only assignment are enforced in the model (guarded attribute + service check). Tests: keyed wrong-domain row, missing child rows, altered content, a published keyed row, concurrent import attempts, retry after failed import.
- **Certification step (human, before Release 2):** for each draft — apply certification edits (e.g. the FR `CostOfGoodsSold` correction, §5.4), then publish **through the authenticated admin UI/HTTP publish endpoint only**. There is NO artisan certify path (R3-01): a CLI flag naming an admin cannot prove that person made the decision, so `certified_by` may only ever be set from the authenticated Sanctum actor of the publish request. No migration ever creates a published row; nothing bypasses the gate.
- **Assignments:** created after publish (`TN`, `FR`, `*`) via the authenticated UI, within certified scopes.
- **Release 2 (reader switchover):** consumers switch to the resolver ONLY after `php artisan country-defaults:verify` passes on staging and production. `verify` asserts: three bootstrap-keyed templates exist; `TN`/`FR`/`*` assignments reference **published** templates whose certification fields (incl. `capability_registry_version`) are all present and whose scope covers the assignment; every published template passes the full current publish-gate invariants (re-executed, not just hash-compared — incl. the non-timbre stamp-purpose negative rule); capability-version drift is reported (recertification required before new assignments, R8-03); drafts-only state fails. **Capability-drift CI test (R8-03):** every country in `CountryTaxConfigurationRegistry` whose tenant tax seeder writes an active `is_stamp_duty=true` configuration MUST be flagged timbre in `CountryAccountingCapabilities`, and vice versa — adding a timbre tax seeder without the capability flag fails CI. The release-2 gate is a deploy-checklist item (added to the launch-program consolidated deploy list), enforced by runbook + the verify command — explicitly NOT by the entrypoint.
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

- Publish-gate rules (each red/green; F-02/F-03 negatives; protected-code per-scope checks incl. `is_system=false` negative — R2-07); protected-registry drift test vs `InstrumentAccountResolver`; purpose-manifest AST/PHPStan ratchet; resolver exact/wildcard/missing/lowercase (`tn` → TN — R2-05); immutability incl. scope immutability; scope-algebra truth table (R3-02); transactional races (publish-vs-assign, archive-vs-assign); role/route-inventory incl. `/editors` verbs (R2-06); MFA-flag login block; parity vs legacy goldens; **data-driven certified-fixture delta suite derived from the manifest (R4-03/R5-01 — per-country missing sets before, exact deltas per §5.4 and zero missing required after)**; manifest-conformance suite incl. deliberately-misclassified failing fixture (R5-02); canonical-serializer golden vector incl. non-ASCII (R2-11/R3-05); bootstrap key-assertion suite (wrong-domain/altered/published keyed row, concurrency — R3-04); `country-defaults:verify` red/green incl. drafts-only and missing-certification failure (R2-03); company-creation rollback in both paths; rerun-reparenting pin; tenancy-initialized central-read; `ExpenseCategorySeeder` loud-failure (R2-09).
- Frontend: Vitest for grid validation, locked rows, role-filtered nav + landing + direct-URL guards (R2-12), assignment confirm.
- Preflight by path; no full PHPUnit suite without permission.

## 11. Phasing & Effort

- **Phase A (this spec):** framework + COA end-to-end, **~9–12 dev-days** (Rev 3 adds: certification step tooling, scope model, FE role shell, consumer additions).
- **Phase B+:** tax rates → tax configurations → payment settings → withholding rules (incl. the `42236`/`42237` document-literal lane) → pricing regulations → expense categories (semantic mapping); each = content table/payload + editor + parity + seeder retirement.

## 12. Risks & Mitigations

| risk | mitigation |
|---|---|
| Accountant renumbers a treasury protected account | per-scope protected-code gate + `is_system=true` requirement + locked editor rows (§4.2.4) |
| Template passes gate but breaks later GL flows | fully partitioned purpose manifest + registration ratchet + conformance suite (§4.2.1); all six baseline defects (§5.4) caught and corrected via certified deltas |
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
3. **Existing-tenant backfill tickets for live purpose gaps found in review (§5.4):** FR `CostOfGoodsSold` (silent missing COGS), FR `GeneralExpense`, TN+FR `SalesDiscount` (POS account-charge with transaction discount 500s), FR `CustomerAdvance` + `SupplierAdvance` (ordinary treasury advance/prepayment-refund flows 500), FR `624`.
4. **Treasury hardening ticket (R6-02):** `TreasuryReceiptBridge::recordTolerancePurposeMissingAlertSafely()` swallows alert-persistence failures (`:567-583`) — a skipped tolerance entry can leave neither GL entry nor durable record; needs fail-closed/outbox semantics independent of this lane.
5. **Dead-code cleanup note:** `Compliance/Services/UninvoicedDeliveryNoteService` has zero production callers (verified 2026-08-08; feature tests exercise it) — wire it or remove it.
6. **Four-eyes certification** for external editors.
7. **Central-admin MFA** (consumed here only via the activation flag).
8. Remaining domains (Phase B+), incl. semantic expense-category mapping.
