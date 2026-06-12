# Tenant Tax Provisioning & Default Resolution — Design

**Date:** 2026-06-12
**Status:** Approved design (pending user review of this doc)
**Scope:** Item 1 of the parapharmacy/POS stabilization pass
**Branch base:** `dev`

---

## 1. Problem

A freshly **demo-provisioned** Tunisia (and France) tenant has `tax_configurations = 0`. Because the
tax engine resolves tax purely from these rows, every POS sale and invoice computes **0 tax**, and the
onboarding checklist permanently shows "taxes not configured." Products are created with `tax_rate = NULL`
and never inherit a sensible default.

### 1.1 Evidence reconciliation (the original report was partly stale/misattributed)

The prior-session evidence ("`TunisiaTaxConfigurationSeeder` is never called", "standard provisioning
doesn't seed tax") does **not** match current code. The truth, after reading the code + git history:

- **Registration path already seeds TN tax.** `TenantInitializationService::initializeForNewRegistration()`
  seeds country tax configurations (commit `63632fe53`, ordering fix `019aec647`, 2026-05-28). A tenant created
  through real signup gets TN configs and **tax resolves correctly**.
- **The broken case is the demo/seeder path.** `ParapharmacySeeder` (and siblings) build a tenant directly
  and **never call** `TenantInitializationService`. They have **zero** tax references → `tax_configurations = 0`.
  This is the tenant the evidence was gathered from.
- **France has no tax-config seeder at all.** Only `TunisiaTaxConfigurationSeeder` exists. The parapharmacy
  demo tenant is `country_code = FR`, so even the registration path would seed nothing for it
  (`seedTaxConfigurations()` `match` returns `null` for non-TN).
- **`default_tax_configuration_id` is runtime-cosmetic.** `TaxCalculationService` selects configs by
  `company.country_code` + matches each line's `tax_rate` against `percentage_rate`. It never reads the
  product/company `default_tax_configuration_id` FK (confirmed by `TaxConfigurationCountryCoherent` doc:
  "silently ignored at runtime"). But `OnboardingChecklistService::checkTaxConfig()` **does** read
  `company.default_tax_configuration_id` — and nothing ever sets it.

### 1.2 What actually drives tax at runtime

```
product.tax_rate ──copied at line creation──▶ line.tax_rate ──matched by bccomp──▶ tax_configurations.percentage_rate (country-scoped)
```

If `tax_configurations` is empty, or `product.tax_rate` is NULL/0, the line resolves **no tax**. So the
functional fix has two halves: (a) seed the country configs on every path, and (b) ensure products carry a
sensible default rate.

---

## 2. Industry-standard research (how mature systems model this)

Reviewed Odoo, WooCommerce, Shopify, QuickBooks, Xero, Stripe Tax, ERPNext. They converge on one model:

1. **A company-level default tax** so new products are taxable out of the box (Odoo "Default Taxes",
   Xero "default tax rate").
2. **Tax classes/categories** (Standard / Reduced / Zero / Exempt) — you classify the product; you don't
   type a raw percentage.
3. **A most-specific-wins cascade:** line override → product → **product category** → company default.
   Shopify and WooCommerce explicitly recommend *category-level defaults to reduce per-product overrides*.
4. **Customer/location remapping** (Odoo "fiscal positions") is a separate, more advanced layer — **out of
   scope** here (our `WithholdingTaxRule` + partner tax status is the nascent equivalent).

**Our codebase already matches this model — but the group layer is dead code:**

- `tax_configurations` per country ≈ the "tax classes".
- `categories` **already have** `default_tax_rate` + `default_tax_configuration_id` columns
  (migration `2025_12_30_104000`) — but the `Category` **model doesn't expose them** (absent from
  `$fillable`/casts/DTO) and nothing sets/reads them.
- `TaxResolutionService::resolveProductTax()` **already implements** the line→product→category→company
  cascade — but it is **never called anywhere**. Document lines just take `tax_rate ?? 0`
  (`DraftPersistenceService:266`).

**Answer to "do we have a group tax engine?":** Yes — a category-level one exists in skeleton, but it is
incomplete (model) and unwired (resolver). No tag-level or product-type-level tax (and that's fine — category
is the industry norm).

### 2.1 Researched default rates (the data we will seed)

| Product nature | Tunisia (default **19%**) | France (default **20%**) |
|---|---|---|
| Standard (cosmetics, hygiene, supplements, general parapharmacy) | 19% | 20% |
| Medicines / pharma / medical devices | 7% | non-reimbursed **10%**, reimbursed **2.1%** |
| Reduced essentials | 13% | 5.5% |
| Exempt / export | 0% | 0% |
| Per-invoice fiscal stamp | Timbre fiscal 1.000 DT (already modelled) | none |

Sources: Tunisia VAT 19/13/7/0 + 1 DT timbre (jurisite/efacturetn); France TVA 20/10/5.5/2.1 with
non-reimbursed medicines 10%, reimbursed 2.1%, cosmetics/hygiene 20% (service-public.gouv.fr F23567).
**These are seeded defaults the tenant can edit** — not hard rules.

---

## 3. Design

Four parts, smallest-blast-radius first. The fiscal line-creation/calculation path is **not** modified.

### 3.1 Shared, idempotent provisioning step + per-country registry

Introduce a single application step that **every** provisioning path calls:

- `CountryTaxConfigurationRegistry` — maps `country_code → tax-config seeder`
  (`TN → TunisiaTaxConfigurationSeeder`, `FR → FranceTaxConfigurationSeeder`).
- `seedCountryTaxConfigurations(Company $company)` (lives in a small service / reused by the registry):
  1. Runs the country's config seeder (idempotent `updateOrCreate`).
  2. Sets `company.default_tax_configuration_id` to the country's `is_default` config.
  3. Sets `company.default_tax_rate` from that config's `percentage_rate`.

Callers:
- `TenantInitializationService::seedTaxConfigurations()` is refactored to delegate to the registry
  (replaces its hardcoded `match (TN → seeder, default → null)`), and now **also sets the company FK + rate**
  (today it only sets `default_tax_rate` via `setDefaultTaxRate()`, never the FK).
- Demo seeders (`ParapharmacySeeder` + the Tunisia parapharmacy variant, any other vertical demo seeder that
  provisions a company) call `seedCountryTaxConfigurations()` during their financial-foundation setup
  (next to where they seed CoA + payment methods).

**Rejected alternatives:** (B) copy seeding into each demo seeder — drifts, multiple sources of truth;
(C) make demo seeders run the full `TenantInitializationService` — too broad, demo seeders do bespoke setup.

### 3.2 `FranceTaxConfigurationSeeder` (new)

Mirrors `TunisiaTaxConfigurationSeeder`'s structure. Seeds country `FR` configs:

| name | code | rate | is_default |
|---|---|---|---|
| TVA 20% (taux normal) | `TVA_FR_20` | 20.00 | **true** |
| TVA 10% (taux intermédiaire) | `TVA_FR_10` | 10.00 | false |
| TVA 5,5% (taux réduit) | `TVA_FR_5_5` | 5.50 | false |
| TVA 2,1% (taux particulier) | `TVA_FR_2_1` | 2.10 | false |
| Exonéré TVA | `TVA_FR_EXEMPT` | 0.00 | false |

All `tax_type = PERCENTAGE`, `applies_to = LINE_ITEMS`, `is_recoverable = true`, same
`applicable_document_types` list as the TN seeder. **No** fiscal stamp (France has none).

### 3.3 Repair the dormant group engine

So the category layer the owner asked about actually works (without new UI this pass):

- Add the two existing tax columns to the `Category` model: `default_tax_rate`, `default_tax_configuration_id`
  in `$fillable`, casts, and the property docblock.
- Add them to `CategoryData` DTO (and to category create/update requests with the same validation rules
  Product uses: `nullable|uuid|exists:tax_configurations,id`, rate `numeric|min:0|max:100` + 2-dp regex,
  plus `TaxConfigurationCountryCoherent`).
- Make `TaxResolutionService` the **one canonical resolver** for "what is this product's effective rate?"
  (it already encodes line → product → category → company). Confirm/repair its company fallback to read
  `company.default_tax_rate`.

This pass **seeds no category→rate rows** and adds **no category tax UI** (owner decision). Categories simply
become capable of carrying a default that the resolver honours.

### 3.4 Resolve-once-at-import (the functional "products get a rate" lever)

Per the owner's "this happens only once at import" steer — bake the rate onto the product, don't re-wire the
live fiscal path:

- In `ProductService` create/import and the composite-item equivalent: when `tax_rate` is **absent/empty**,
  call `TaxResolutionService` to resolve (category default → company default) and **persist** the resolved
  `tax_rate` (and the matching `default_tax_configuration_id`) onto the product.
- An **explicit** per-product rate always wins (no overwrite).
- Existing products are untouched by code; a one-off backfill is **out of scope** (note below).

### 3.5 Onboarding

- `company.default_tax_configuration_id` is now set at provisioning → `checkTaxConfig()` returns `true`
  automatically. The tax step shows as satisfied (optionally relabelled to a soft "Double-check taxes").
- The larger "make onboarding prominent (login modal) + hard-gate sales until required steps complete" is a
  **separate spec, next pass** (owner decision). Not in this pass.

---

## 4. Scope boundaries

**In scope:** TN + FR config seeding on all provisioning paths; shared registry/step; company default FK +
rate set everywhere; `FranceTaxConfigurationSeeder`; Category model/DTO repair; canonical
`TaxResolutionService`; resolve-at-import for new/imported products; auto-satisfy onboarding tax step.

**Explicitly out:**
- Re-wiring `TaxCalculationService`/`DraftPersistenceService` to consume the FK at line time (rate-match works).
- Category tax **UI**, and pre-seeded category→rate demo rows.
- Backfilling tax onto pre-existing products/tenants (one-off data migration — track separately if needed).
- Prominent-onboarding + sales-gating feature (separate spec).
- "Expressive/actionable error" overhaul (future).
- UK/IT/other-country seeders (add later via the registry).
- Tag-level / product-type-level tax (not an industry norm; not requested).

---

## 5. Testing (TDD, scoped — never the full PHPUnit suite)

Backend, `RefreshDatabase` + real seeders:

1. **Demo TN tenant** provisioned via the demo path now has the 4 TN VAT configs + 3 timbre rows, and
   `company.default_tax_configuration_id` / `default_tax_rate` set to TVA 19%.
2. **Demo FR tenant** has the 5 FR configs, company default = TVA 20%.
3. **Registration path unchanged** still seeds (now also sets the company FK + rate).
4. **Idempotency:** running the shared step twice creates no duplicates and doesn't flip the default.
5. **Resolve-at-import:** a product created with no rate inherits the company default; with a category that
   has a default rate, inherits the category rate; explicit rate is preserved. A subsequent POS/invoice line
   built from that product resolves **non-zero** tax via `TaxCalculationService`.
6. **Onboarding:** `checkTaxConfig()` is `true` for a freshly demo-provisioned tenant.
7. **Coherence rule** still rejects a category/product `default_tax_configuration_id` whose country differs.

Frontend (if Category DTO surfaces to web-admin types): regenerate types via `php artisan typescript:transform`;
no UI work this pass.

Run scoped: `php artisan test --filter=Tax` (and the specific new test classes), per the no-full-suite rule.
Migrations touching PG constraints get a real-PG migrate check before any dev→main gate.

---

## 6. Risks & notes

- **Fiscal path untouched** — the signed SALE_RECEIPT byte layout and `TaxCalculationService` are not modified,
  so no fiscal-chain regression risk.
- **`tax_configurations` is country-global** (no tenant_id) under db-per-tenant each tenant DB holds its own
  copy; seeding is per-tenant-DB and idempotent. Confirm the demo seeders run inside the tenant connection.
- **`Category` model was silently broken** (columns without model exposure). Fixing `$fillable` is safe but
  verify no mass-assignment guard elsewhere depended on the omission.
- **Parallel work:** a separate session seeded a Tunisia demo tenant "not seeded completely correctly" — this
  spec fixes the provisioning-level seeding that demo depends on; coordinate so we don't double-author the
  France seeder.

---

## 7. File touch list (anticipated)

- `app/Modules/Tenant/Application/Services/TenantInitializationService.php` — delegate to registry; set company FK+rate.
- `app/Modules/Taxation/...` — `CountryTaxConfigurationRegistry` (new); confirm `TaxResolutionService` company fallback.
- `database/seeders/FranceTaxConfigurationSeeder.php` — new.
- `database/seeders/ParapharmacySeeder.php` (+ Tunisia parapharmacy variant / other demo seeders) — call shared step.
- `app/Modules/Product/Domain/Category.php`, `CategoryData` DTO, category create/update requests — expose tax fields.
- `app/Modules/Product/Application/Services/ProductService.php` (+ composite equivalent) — resolve-at-import.
- Tests under `tests/` for each item above.
