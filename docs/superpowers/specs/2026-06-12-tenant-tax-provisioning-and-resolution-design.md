# Tenant Tax Provisioning & Default Resolution — Design (rev 2)

**Date:** 2026-06-12
**Status:** Approved design, revised after Codex adversarial review (pending user review of rev 2)
**Scope:** Item 1 of the parapharmacy/POS stabilization pass
**Branch base:** `dev`
**Review:** `docs/superpowers/specs/reviews/2026-06-12-tax-pos-specs-codex-review.md` (verdict NEEDS-REWORK → addressed below)

---

## 0. What changed in rev 2 (from the Codex review)

- **B1:** the provisioning fix is now a shared `CompanyTaxProvisioningService` called from an **enumerated inventory of every company writer**, not just `ParapharmacySeeder`.
- **B2 / M1:** "resolve once at import" is replaced by a hard **invariant** — *no sellable product/composite persists with NULL `tax_rate`* — enforced by a shared resolver helper wired into **every** product/composite writer (API, import, composite API/import, seeders), not a single non-existent chokepoint.
- **M2 / m2:** category (and product) `default_tax_configuration_id` validation uses the **`TaxConfigurationCountryCoherent`** rule (composite-item precedent), since Product today only uses `exists:`.
- **M3:** the shared step **fails loud** when `countries` is missing for the company country; `CoffeeShopSeeder`'s tenant-connection ordering is normalized as a prerequisite.
- **M4:** explicit **non-VAT levies decision** (FODEC, eco-tax) — out of scope, with rationale + tracking.
- **m1:** corrected `DraftPersistenceService` path + batch-insert line.

---

## 1. Problem

A freshly **demo-provisioned** Tunisia (and France) tenant has `tax_configurations = 0`. Because the tax
engine resolves tax from these rows, every POS sale and invoice computes **0 tax**, onboarding permanently
shows "taxes not configured," and products are created with `tax_rate = NULL` (which the POS line layer turns
into 0).

### 1.1 Evidence reconciliation (the original report was partly stale/misattributed)

- **Registration already seeds TN tax.** `TenantInitializationService::initializeForNewRegistration()` seeds
  country tax configs (commit `63632fe53`, ordering fix `019aec647`, 2026-05-28). Real signup → TN configs →
  tax resolves.
- **The broken case is the demo/seeder path.** `ParapharmacySeeder` and siblings build a tenant directly and
  **never call** `TenantInitializationService`; they have **zero** tax references → `tax_configurations = 0`.
- **France has no tax-config seeder.** Only `TunisiaTaxConfigurationSeeder` exists; the parapharmacy demo is
  `country_code = FR`, so even the registration path's `match (TN → seeder, default → null)` seeds nothing.
- **`default_tax_configuration_id` is runtime-cosmetic.** `TaxCalculationService` selects configs by
  `company.country_code` and matches each line's `tax_rate` against `percentage_rate` via `bccomp`
  (`TaxCalculationService.php:71-105`). It never reads the product/company FK. But
  `OnboardingChecklistService::checkTaxConfig()` reads `company.default_tax_configuration_id`, which nothing sets.

### 1.2 What actually drives tax at runtime — and the invariant it implies

```
product.tax_rate ─copied at line creation─▶ line.tax_rate ─bccomp match─▶ tax_configurations.percentage_rate (country-scoped)
```

- Document lines: `tax_rate = $lineData['tax_rate'] ?? 0` — single path
  `app/Modules/Document/Domain/Services/DraftPersistenceService.php:256-267`, batch path `:599-612`.
- POS lines: `ReceiptCreationService.php:207,217` fall back to `0.00` when a product/composite `tax_rate` is null,
  then persist it at `:336`.

**Therefore the load-bearing invariant for this whole workstream is:**

> **No sellable product or composite item may persist with a NULL `tax_rate`.** It must carry either an
> explicit rate or a resolved default (which may legitimately be a 0% / exempt config). If this holds, every
> downstream line resolves correctly and the fiscal calc/line-creation path needs no change.

---

## 2. Industry-standard research

Odoo, WooCommerce, Shopify, QuickBooks, Xero, Stripe Tax, ERPNext converge on:
1. **A company-level default tax** so new products are taxable by default.
2. **Tax classes/categories** — classify the product, don't type a percentage.
3. **Most-specific-wins cascade:** line → product → **product category** → company default. Shopify/WooCommerce
   recommend category-level defaults to reduce per-product overrides.
4. **Customer/location remapping** (Odoo "fiscal positions") — separate, advanced, **out of scope**.

**Our codebase already matches this — but the group layer is dead code:** `tax_configurations` ≈ tax classes;
`categories` already have `default_tax_rate` + `default_tax_configuration_id` columns
(`migrations/tenant/2025_12_30_104000`) absent from the `Category` model `$fillable`/casts/DTO;
`TaxResolutionService::resolveProductTax()` (`:25-59`) already encodes line→product→category→company but has
**no callers** (`TaxationServiceProvider.php:41-44` only registers it).

### 2.1 Researched default rates (seeded, user-editable)

| Product nature | Tunisia (default **19%**) | France (default **20%**) |
|---|---|---|
| Standard (cosmetics, hygiene, supplements, general parapharmacy) | 19% | 20% |
| Medicines / pharma / medical devices | 7% | non-reimbursed **10%**, reimbursed **2.1%** |
| Reduced essentials | 13% | 5.5% |
| Exempt / export | 0% | 0% |
| Per-invoice fiscal stamp | Timbre fiscal 1.000 DT (modelled) | none |

France rates verified against service-public.gouv.fr F23567 (Codex confirmed). Tunisia per jurisite/efacturetn.

### 2.2 Non-VAT levies decision (M4) — explicit

- **FODEC** (Tunisia, ~1% on certain manufactured/imported goods): **OUT of scope.** No model/seeder/rule named
  FODEC exists today; only generic `eco_tax_*` placeholders. If FODEC is legally relevant to the target
  catalog it is a **separate workstream** (it is a line/aggregate levy, not a VAT class). Tracked, not silently
  implied as covered by TVA+timbre.
- **Eco-tax:** placeholder columns exist (`pos_receipt_lines` / `document_lines`, migrations `2026_05_01_000004/5`),
  marked Phase-1-always-null in `ReceiptLine.php:92-118` / `DocumentLine.php:96-126`. **OUT of scope** here; not
  populated by this work.

---

## 3. Design

Four parts. The fiscal line-creation/calculation path is **not** modified; correctness rests on the §1.2 invariant.

### 3.1 Shared company tax provisioning + per-country registry (B1)

Introduce **`CompanyTaxProvisioningService::provisionForCompany(Company $company)`** (Application layer):
1. Resolve the country seeder via a `CountryTaxConfigurationRegistry`
   (`TN → TunisiaTaxConfigurationSeeder`, `FR → FranceTaxConfigurationSeeder`).
2. **Fail loud** (in non-production/seed/dev) if `countries` has no row for the company country, instead of the
   current silent no-op; in production, log + skip (registration already seeds countries first).
3. Run the country config seeder (idempotent `updateOrCreate`).
4. Set `company.default_tax_configuration_id` = the country's `is_default` config and `company.default_tax_rate`
   = its `percentage_rate`.

**Call it from every company writer (enumerated inventory — must all be wired):**

| Path | File:line | Action |
|---|---|---|
| DB-per-tenant registration | `AuthController.php:352-358` → `TenantProvisioningService.php:180-182` → `TenantInitializationService` | refactor `seedTaxConfigurations()` to delegate to the service (replaces hardcoded `match`), add company FK+rate |
| Shared-DB registration | `AuthController.php:478-482` → `TenantInitializationService` | same delegate |
| Tenant reset | `ResetTenantCommand.php:97-110` | already reuses `initializeForNewRegistration()` → covered by the delegate |
| Add-company endpoint | `CompanyController.php:66-104` / `:145-152` (currently CoA only) | call `provisionForCompany()` after company create |
| `DatabaseSeeder` | `:75-118` | call after each company create (replace ad-hoc TN-only seeding) |
| `DemoTenantSeeder` | company creates at `:184-196,1480-2026` | call per company |
| `ParapharmacySeeder` | `:367-426` | call in financial-foundation setup |
| `ParapharmacyMultiBranchSeeder` | `:197-215` | call per company |
| `CoffeeShopSeeder` | `:236-287` | call (after fixing tenant-connection ordering — see §6) |
| `TunisianParapharmacySeeder` | `:99-101,157-178` | replace direct TN seeder call with `provisionForCompany()` |
| `ProductionSeeder` | `:41-44` | stays lookup-only; **add FR** alongside TN if it preloads country-global reference configs |

**Rejected:** copying seeding into each seeder (drift); making seeders run the full `TenantInitializationService`
(too broad — they do bespoke setup).

### 3.2 `FranceTaxConfigurationSeeder` (new)

Mirrors the TN seeder. Country `FR`, `tax_type = PERCENTAGE`, `applies_to = LINE_ITEMS`, `is_recoverable = true`,
same `applicable_document_types` as TN, **no** stamp:

| name | code | rate | is_default |
|---|---|---|---|
| TVA 20% (taux normal) | `TVA_FR_20` | 20.00 | **true** |
| TVA 10% (intermédiaire) | `TVA_FR_10` | 10.00 | false |
| TVA 5,5% (réduit) | `TVA_FR_5_5` | 5.50 | false |
| TVA 2,1% (particulier) | `TVA_FR_2_1` | 2.10 | false |
| Exonéré TVA | `TVA_FR_EXEMPT` | 0.00 | false |

### 3.3 Repair the dormant group engine (M2)

- Add `default_tax_rate`, `default_tax_configuration_id` to the `Category` model `$fillable`, casts, docblock.
  (Safe: `Category` uses an explicit allow-list, not `guarded = []`.)
- Add them to `CategoryData` DTO and to **`CategoryController` create/update validation** (`:116-123`, `:168-174`).
- **Validation uses `TaxConfigurationCountryCoherent`** (the composite-item precedent at
  `StoreCompositeItemRequest.php:51-62`), plus `nullable|uuid|exists:tax_configurations,id` and rate
  `numeric|min:0|max:100` + 2-dp regex. **Note:** Product's own requests currently use only `exists:`
  (`CreateProductRequest.php:53-62`, `UpdateProductRequest.php:55-63`) — bring Product up to coherence too
  (small, in scope) so product/category/composite are consistent.
- Make `TaxResolutionService` the **one canonical resolver**; confirm/repair its company fallback to read
  `company.default_tax_rate`.

This pass seeds **no** category→rate rows and adds **no** category tax UI (owner decision) — categories merely
become capable of carrying an editable default the resolver honours.

### 3.4 Enforce the NULL-tax invariant at every sellable-item writer (B2 / M1)

Replace "resolve once at import" with: a shared resolver helper (thin wrapper over `TaxResolutionService`) that,
when a writer receives **no explicit** `tax_rate`, resolves (category default → company default) and **persists**
the resolved `tax_rate` (+ matching `default_tax_configuration_id`). Explicit values always win. Wire into:

| Writer | File:line | Note |
|---|---|---|
| Product API create | `ProductController.php:304-308` | creates directly, bypasses ProductService — wire here |
| Product import/upsert | `ProductService.php:43-83` (`:55`) ← `ImportService.php:360-365` | wire in upsert |
| Composite API create | `CompositeItemController.php:83-91` | wire here |
| Composite import/upsert | `CompositeItemImportService.php:19-69` | only sets rate if provided — wire |
| Composite duplicate | `CompositeItemController::duplicate()` | **decision:** preserve source tax fields (do not recompute) |
| Demo seeders product creates | `ParapharmacySeeder.php:536-546`, `CoffeeShopSeeder.php:334-343`, `ParapharmacyMultiBranchSeeder.php:438-448` | seed explicit rates, or call helper |

Defensive backstop (not a substitute): keep the POS/document `?? 0` fallback, but the invariant means it should
never be exercised for a normally-created item. Optionally assert/log when it is, to catch new writers.

A one-off **backfill** of pre-existing NULL-rate products/tenants is **out of scope** (tracked separately).

### 3.5 Onboarding

`company.default_tax_configuration_id` is now set at provisioning → `checkTaxConfig()` returns `true`
automatically (optionally relabel to soft "Double-check taxes"). Prominent-onboarding + sales-gating is a
**separate spec, next pass** (owner decision).

---

## 4. Scope boundaries

**In:** TN+FR config seeding on **all** enumerated company writers via `CompanyTaxProvisioningService`; the
registry; company default FK+rate everywhere; `FranceTaxConfigurationSeeder`; Category model/DTO/controller
repair with coherence validation (+ Product coherence parity); canonical `TaxResolutionService`; the
NULL-tax invariant enforced at every product/composite writer; auto-satisfy onboarding tax step;
`CoffeeShopSeeder` tenant-connection ordering fix.

**Explicitly out:** re-wiring `TaxCalculationService`/`DraftPersistenceService` to consume the FK at line time;
category tax **UI** + pre-seeded category→rate rows; backfilling pre-existing products/tenants; **FODEC** and
**eco-tax**; prominent-onboarding + sales-gating; expressive-error overhaul; UK/IT seeders (registry-ready);
tag/product-type tax.

---

## 5. Testing (TDD, scoped — never the full PHPUnit suite)

Backend, `RefreshDatabase` + real seeders:

1. **Demo TN tenant** (via demo path) has the 4 TN VAT + 3 timbre configs; company default = TVA 19% (FK+rate).
2. **Demo FR tenant** has the 5 FR configs; company default = TVA 20%.
3. **Registration path** unchanged but now also sets company FK+rate.
4. **Every enumerated company writer** ends with non-zero `tax_configurations` + a set company default
   (parametrized over CompanyController::store, ResetTenantCommand, each demo seeder).
5. **Idempotency:** running the shared step twice → no duplicates, default unchanged.
6. **NULL-tax invariant** — create a product with no rate via **(a) Product API, (b) product import,
   (c) composite API, (d) composite import**; assert each persists a non-null resolved rate, and that
   **POS receipt creation + document draft** produce **non-zero** tax for it. Category default overrides company
   default; explicit rate is preserved; duplicate preserves source.
7. **Onboarding** `checkTaxConfig()` true for a freshly demo-provisioned tenant.
8. **Coherence rule** rejects a wrong-country `default_tax_configuration_id` on category, product, composite.
9. **Fail-loud** when `countries` missing for the company country in seed/dev context.

Run scoped (`--filter`); PG-constraint migrations get a real-PG migrate check before any dev→main gate.

---

## 6. Risks & notes

- **Fiscal path untouched** — signed SALE_RECEIPT byte layout + `TaxCalculationService` unchanged → no
  fiscal-chain regression; correctness rests on the §1.2 invariant.
- **`tax_configurations` is country-global** (no `tenant_id`); under db-per-tenant each tenant DB holds its own
  copy. The shared step must run **after** the tenant connection is active and `countries` exists.
- **`CoffeeShopSeeder` ordering (M3)** seeds reference data before creating/initializing the tenant
  (`:95-102`, `:195-228`) — unlike `ParapharmacySeeder`/`DatabaseSeeder`. Normalize its tenant-connection setup
  **before** adding the tax step, else the fail-loud guard trips.
- **`Category` model silently broken** (columns w/o model exposure) — verify nothing relied on the omission.
- **Parallel work:** a separate session seeded a Tunisia demo tenant "not seeded completely correctly" — this
  spec fixes the provisioning-level seeding it depends on; coordinate to avoid double-authoring the FR/TN path.

---

## 7. File touch list (anticipated)

- `app/Modules/Taxation/Application/Services/CompanyTaxProvisioningService.php` (new) + `CountryTaxConfigurationRegistry` (new).
- `app/Modules/Tenant/Application/Services/TenantInitializationService.php` — delegate; set company FK+rate.
- `app/Modules/Tenant/Application/Commands/ResetTenantCommand.php` — covered via delegate (verify).
- `app/Modules/Company/Presentation/Controllers/CompanyController.php` — call `provisionForCompany()`.
- `database/seeders/FranceTaxConfigurationSeeder.php` (new).
- `database/seeders/{ParapharmacySeeder,ParapharmacyMultiBranchSeeder,CoffeeShopSeeder,TunisianParapharmacySeeder,DemoTenantSeeder,DatabaseSeeder,ProductionSeeder}.php` — call shared step (+ CoffeeShop ordering fix; +FR in ProductionSeeder).
- `app/Modules/Product/Domain/Category.php`, `CategoryData` DTO, `CategoryController`, category requests — expose tax fields + coherence rule.
- `app/Modules/Product/Presentation/Requests/{Create,Update}ProductRequest.php` — add coherence parity.
- `app/Modules/Taxation/Domain/Services/TaxResolutionService.php` — confirm company fallback; canonical resolver.
- Resolver wiring: `ProductController`, `ProductService::upsert`, `CompositeItemController`, `CompositeItemImportService`.
- Tests under `tests/` for each item above.
