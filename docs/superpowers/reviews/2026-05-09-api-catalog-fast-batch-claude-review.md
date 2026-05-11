# api.catalog fast-batch — Claude adversarial review of codex-owned callsites

Verdict: APPROVE

Commit reviewed: f88ad4a5

Branch: feat/tenant-isolation-sweep-execution
Cluster: api.catalog (codex-owned, 4 callsites originally under api.unmapped namespace)
Reviewer: Claude (cross-agent reviewer per agent-recalibration §16.1; codex-owned → claude-reviewed)
Date: 2026-05-09

## Scope

Four callsites reassigned from api.unmapped to api.catalog in commit `9630e58b`,
all with fix_commit `f88ad4a5`:

| ID | File | Symbol | Disposition |
|---|---|---|---|
| api.unmapped.011 | `apps/api/app/Modules/Product/Presentation/Requests/UpdateProductRequest.php` | `UpdateProductRequest::rules` | scanner false-positive — annotated |
| api.unmapped.012 | `apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php` | `CreateProductRequest::rules` | scanner false-positive — annotated |
| api.unmapped.018 | `apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php` | `CategoryController::store` | scanner blind-spot fix — `Category::query()->where()->find()` |
| api.unmapped.019 | `apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php` | `CategoryController::update` | scanner blind-spot fix — same pattern as `::store` |

## Verification

### Code state at HEAD

- `UpdateProductRequest.php:55-63` — annotation block matches `2026-05-04-scanner-tax-configurations-false-positive.md` audit; rule unchanged (`exists:tax_configurations,id` retained intentionally; the table is country-scoped global reference, no tenant_id / company_id columns).
- `CreateProductRequest.php:53-61` — identical annotation block; mirrors api.catalog.002 / 005 disposition closed in `516c6f61`.
- `CategoryController::store` (lines 126-141) — `Category::query()->where('company_id', $companyId)->find($validated['parent_id'])`. The `::query()` prefix was the targeted fix: the `PhpAstFindScanner` chain visitor only walks `MethodCall` chains, so the prior `Category::where(...)->find()` static-call form bypassed `chainIsScoped()`. Annotation block on lines 126-132 explains the structural rationale clearly. `categories` has no `tenant_id` column (company-scoped global reference), so the `where('company_id', …)` predicate alone IS the cluster invariant — no defense-in-depth tenant_id needed.
- `CategoryController::update` (lines 183-190) — same fix, same annotation, same rationale.

### Regression tests

All five tests added by `f88ad4a5` exist at the registered selectors and pass on
current HEAD (`feat/tenant-isolation-sweep-execution` tip `14fa2bef`):

```
test_create_product_accepts_real_tax_configuration_id_country_scoped
test_create_product_rejects_nonexistent_tax_configuration_id
test_update_product_accepts_real_tax_configuration_id_country_scoped
test_create_category_parent_lookup_query_includes_company_predicate
test_update_category_parent_lookup_query_includes_company_predicate
```

`vendor/bin/phpunit --filter <combined regex> tests/Feature/Catalog/CatalogTenantIsolationTest.php` —
12 / 12 (fast-batch full set: 5 codex-owned + 7 claude-owned 027-033) — 0 failures, 0 risky, 29 assertions.

### Cross-tenant exfiltration analysis

- **011 / 012:** `tax_configurations` schema (`2025_12_30_100000_create_tax_configurations_table.php`) confirms no `tenant_id` / `company_id` columns; rows are partitioned by `country_code` only and constitute public reference data shared across every tenant in a country. Cross-tenant exfiltration via `default_tax_configuration_id` is structurally impossible. The cross-country business-rule concern (e.g., a French company assigning a Tunisian tax configuration) is explicitly out-of-scope for the tenant-isolation sweep per the audit.
- **018 / 019:** `categories` schema is company-scoped (no tenant_id). The targeted forward fix (`::query()` chain prefix) makes the existing company-scoping predicate visible to the AST scanner — no SQL semantics change, no security delta. Regression tests pin the `where "company_id" AND "categories"."id"` shape via SQL-log invariants, so a future regression that drops the predicate fails loudly.

### Bar-raising checks

- **Defense-in-depth probe:** `categories` having no `tenant_id` column means the company predicate is sufficient; layering a tenant_id where the column doesn't exist would error at SQL parse time. Confirmed via the migration history grep: no `categories.tenant_id` introduction commit.
- **Annotation accuracy probe:** the `mirrors api.catalog.002 / 005 precedent` claim was re-verified by reading 011/012's annotation against the existing 002/005 annotation in `Update/Store CompositeItemRequest`. Wording is consistent.
- **Surface diff probe:** `git show --stat f88ad4a5` shows changes confined to four files (the two requests + CategoryController + CatalogTenantIsolationTest). No POS/Voucher/Document/Inventory module collateral.

## Verdict

APPROVE for all four callsites. Code state at HEAD matches `f88ad4a5`, annotation
blocks accurately describe the disposition, regression tests cover both same-tenant
controls and the structural-SQL-log invariants, and the cross-tenant exfiltration
analysis confirms no remaining gap.
