# Codex second-layer review — api.catalog reassigned-callsite remediation

Review date: 2026-05-05
Branch tip reviewed: 32e25195
Reviewer: codex

Verdict: REQUEST-CHANGES
Commit reviewed: f88ad4a5

## Opus claim verification

Verified the four submitted callsites independently.

- api.unmapped.011 / .012: `CreateProductRequest` and `UpdateProductRequest` only add inline annotations for `exists:tax_configurations,id`. The table is country-scoped global reference data: `2025_12_30_100000_create_tax_configurations_table.php` has `id`, `country_code`, tax fields, and no `tenant_id` / `company_id`. This is a structural false positive, not a code fix.
- api.unmapped.018 / .019: `CategoryController::store` and `update` changed `Category::where(...)->find(...)` to `Category::query()->where(...)->find(...)`. SQL semantics are unchanged; this is scanner-readability only. `categories` has `company_id` and no `tenant_id`, so the single company predicate is the correct table invariant.
- api.catalog.014 / .015 do behaviorally cover cross-tenant `parent_id`: `test_create_category_rejects_cross_tenant_parent_id` and `test_update_category_rejects_cross_tenant_parent_id` submit tenant B's category as tenant A and assert 422 validation errors on `parent_id`.
- POS surface diff for `dev..32e25195` is empty on POS app/source paths. There are unrelated `apps/web/tools` audit-tool diffs, matching Opus.

One metric did not reproduce: at `32e25195`, `verify-history` reports `1316 event(s) across 296 callsite(s); 0 problem(s)`, not Opus's `1352`. Current branch head reports `1409 / 299 / 0`. Chain integrity is clean; the count in Opus appears stale or from a different tree.

## Test-honesty re-test

Confirmed Opus's suspicious signal. In a temporary worktree at `f88ad4a5`, I checked the three production files back to pre-fix `30069a66` while keeping the five new tests, then ran:

`vendor/bin/phpunit tests/Feature/Catalog/CatalogTenantIsolationTest.php --filter 'test_(create_product_accepts_real_tax_configuration_id_country_scoped|create_product_rejects_nonexistent_tax_configuration_id|update_product_accepts_real_tax_configuration_id_country_scoped|create_category_parent_lookup_query_includes_company_predicate|update_category_parent_lookup_query_includes_company_predicate)'`

Result: `OK (5 tests, 10 assertions)`.

Interpretation: 011/012 tests are same-tenant / nonexistent controls for a global-reference table. 018/019 are not behavioral fixes. The create structural SQL test would catch removing `company_id` from the store parent lookup. The update structural SQL test is weaker than advertised: because the initial category load already emits a `company_id + categories.id` query, a future regression that removes `company_id` only from the explicit parent-verify lookup could still pass.

## edit_applied repair audit

The api.unmapped.011 / .012 repair events are present at `32e25195`.

- Both rows have `stale_mark` events moving `in_progress -> needs_recheck`.
- Both have `edit_applied` events at `2026-05-05T09:46:19Z`, actor `codex`, command `manual-one-shot-recheck-clear`, `from_status: needs_recheck`, `to_status: in_progress`, and `commit: f88ad4a5`.
- Both then have normal `submit` events to `under_review` with the pinned regression tests.
- `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` at `32e25195`: `verified 1316 event(s) across 296 callsite(s); 0 problem(s)`.

No orphan mutation found.

## Cross-cluster blind-spot deferral verification

This is the blocking miss.

Opus identified Product-module bare-where chains:

- `ProductController::show/update/destroy/stockLevels`: `Product::where('company_id', ...)->where('id', $product)->first()` at lines 225, 385, 521, 557. `products` has both `tenant_id` and `company_id`.
- `EnrichmentReviewController::show/accept/reject`: `EnrichmentResult::where('company_id', ...)->where('id', $id)->first()` at lines 120, 186, 256. `enrichment_results` has both `tenant_id` and `company_id`.

Opus deferred these as "tracked by d7184178". I verified `d7184178`: it documents the generic bare-where scanner gap, but its status section says other clusters still need sweeping and only names Document, Partner, and POS. It does not mention Product, ProductController, EnrichmentReviewController, or api.catalog. The submitted inventory at `32e25195` has manual api.catalog stubs for CompositeItem / Recipe / units / related catalog surfaces, but no ProductController or EnrichmentReviewController row.

Per the review instructions, this makes the deferral unverified. These Product-module reads are in the api.catalog/Product surface and violate the post-Treasury invariant as defense-in-depth gaps.

## New findings

1. BLOCKING: ProductController and EnrichmentReviewController bare-where route reads are not fixed and not actually tracked by `d7184178` or the `32e25195` inventory. Add `tenant_id` predicates or add explicit inventory rows with a concrete deferral before approval.

2. NON-BLOCKING: `test_update_category_parent_lookup_query_includes_company_predicate` overclaims precision. It can pass by matching the initial scoped category load even if the explicit parent-verify lookup regresses. If retained as a structural invariant, narrow it to the relevant query instance or assert no single-row category lookup on the update path lacks `company_id`, with known relationship lazy-loads filtered explicitly.

## Audit exhaustiveness

Read Opus's verdict end-to-end; read `git show f88ad4a5` and `git show 32e25195`; inspected the four YAML rows, the repair history, api.catalog.014/.015 tests, category/product/tax migrations, ProductController, EnrichmentReviewController, and `d7184178`.

Gates at `32e25195`:

- `vendor/bin/phpunit tests/Feature/Catalog/CatalogTenantIsolationTest.php`: `OK (27 tests, 71 assertions)`.
- `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Product tests/Feature/Catalog/CatalogTenantIsolationTest.php`: no errors.
- `./vendor/bin/pint --test app/Modules/Product tests/Feature/Catalog/CatalogTenantIsolationTest.php`: pass.
- `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml`: `1316 / 296 / 0`.

## Confidence

HIGH. The four reassigned callsites themselves are honestly disposed, and Opus's test-honesty interpretation is mostly correct. The approval cannot stand because the explicit ProductController / EnrichmentReviewController deferral claim fails independent verification.
