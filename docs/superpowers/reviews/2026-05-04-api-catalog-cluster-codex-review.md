# api.catalog Cluster - Codex Second-Layer Adversarial Review

Verdict: REQUEST-CHANGES
Commit reviewed: 516c6f61

Reviewer: Codex headless second-layer adversarial reviewer
Branch: feat/tenant-isolation-sweep-execution
Submit-state YAML commit: 77185828
Date: 2026-05-05

## Summary

I read `git show 516c6f61` end to end and verified the implemented fixes on the 17 inventoried api.catalog callsites. The submitted changes correctly scope the inventoried `categories`, `products`, and `modifier_groups` validators/lookups, and the `modifiers` route lookups are scoped through `modifier_groups`.

However, I am not approving the cluster because the required raw-FK persistence sweep found a Catalog recipe-line path Opus missed: `StoreRecipeLineRequest` and `RecipeLineController::update` build a dynamic bare `exists:{$existsTable},id` rule for `products` or `composite_items`, then persist the validated `component_id` directly to `recipe_lines`. Both target tables carry tenant/company scope, so this is a reachable cross-tenant association write in the same Catalog presentation surface.

## Opus Claim Verification

1. MEDIUM manual-stub gap: CONFIRMED. `grep -nE "(api\.catalog|RecipeController|CompositeItemVariantController|CompositeItemController|RecipeLineController)" docs/superpowers/plans/tenant-isolation-sweep-manual-callsites.yml` produced no output. The commit message says sibling-controller blind spots are tracked in the manual stub, but no api.catalog entries exist there.

2. MEDIUM forbidden helper in `StoreModifierGroupRequest`: CONFIRMED. `apps/api/app/Modules/Catalog/Presentation/Requests/StoreModifierGroupRequest.php:25` still has `$companyId = app(CompanyContext::class)->getCompanyId();`.

3. LOW bare `exists:units,id`: PARTIALLY CONFIRMED. The issue is real and `units` has nullable `tenant_id`, but I could only reproduce 8 direct Catalog-scope hits, not 9, with the requested grep. Hits were in `StoreCompositeItemRequest`, `UpdateCompositeItemRequest`, `StoreModifierRequest`, `ModifierController`, `StoreRecipeRequest`, `UpdateRecipeRequest`, `StoreRecipeLineRequest`, and `RecipeLineController`.

4. NICE-TO-HAVE tax annotation overstates the guard: CONFIRMED. `tax_configurations` has only `country_code`, and the Catalog validators still accept any existing tax configuration id. Tax calculation generally re-derives applicable configs by company country, and `TaxConfigurationController` scopes direct tax-config APIs by country, but I did not find a guard that rejects a foreign-country `default_tax_configuration_id` when it is stored on a composite item.

5. NICE-TO-HAVE same-tenant cross-company fixture missing: CONFIRMED. `CatalogTenantIsolationTest` creates only `tenantA/companyA` and `tenantB/companyB`; it does not create two companies under one tenant. The structural SQL tests still pin some tenant predicates, but behavior tests are cross-tenant and cross-company at the same time.

## New Findings

1. HIGH - Dynamic recipe-line component validators allow cross-tenant FK persistence

`StoreRecipeLineRequest::rules()` chooses `$existsTable = $componentType === 'composite_item' ? 'composite_items' : 'products'` and validates `component_id` with `"exists:{$existsTable},id"` at `apps/api/app/Modules/Catalog/Presentation/Requests/StoreRecipeLineRequest.php:23-28`. `RecipeLineController::store()` then writes `...$request->validated()` directly into `RecipeLine::create()` at `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeLineController.php:51-53`.

The update path repeats the same dynamic bare exists rule at `RecipeLineController.php:77-86`, then writes `$validated` directly via `$line->update($validated)` at `RecipeLineController.php:95`.

Both routes are reachable:

```
apps/api/app/Modules/Catalog/Presentation/routes.php:37 POST recipes/{recipeId}/lines
apps/api/app/Modules/Catalog/Presentation/routes.php:38 PATCH recipes/{recipeId}/lines/{lineId}
```

Schema evidence: `products` has `tenant_id` in `2025_11_30_052910_create_products_table.php:18` and `company_id` added/made required in `2025_11_30_130000_add_company_id_to_existing_tables.php:49-54` and `2025_11_30_134000_make_company_id_required.php:29-31`; `composite_items` has both `tenant_id` and `company_id` in `2026_02_19_100001_create_composite_items_table.php:15-16`.

Impact: a user operating on an in-scope recipe can attach another tenant/company's product or composite item as a recipe line component. The literal hostile grep for `exists:products` / `exists:composite_items` misses this because the rule string is interpolated. Fix should use scoped validation for both dynamic branches and avoid persisting the FK without a scoped re-read or scoped `Rule::exists`.

## Schema Honesty

- `modifier_groups`: confirmed `tenant_id` and `company_id` in `2026_02_19_100005_create_modifier_groups_table.php:15-16`.
- `modifiers`: confirmed no direct `tenant_id` or `company_id`; it has `modifier_group_id`, component fields, and unit FK in `2026_02_19_100006_create_modifiers_table.php:15-22`.
- `categories`: confirmed `company_id` only, no `tenant_id`, in `2025_12_26_194624_create_categories_table.php:15-17`.
- `tax_configurations`: confirmed only `country_code` scope in `2025_12_30_100000_create_tax_configurations_table.php:15-16`.
- `products`: confirmed `tenant_id` at create time and `company_id` added then made required.
- `units`: confirmed nullable `tenant_id` in `2026_01_09_095045_create_units_table.php:15`.

## Audit Exhaustiveness

Read the reviewed commit:

```
git show --stat --patch --find-renames 516c6f61
git show --patch --find-renames 516c6f61 -- <each changed file>
```

Hostile greps:

```
grep -rnE -e "->where\(['\"](id|partner_id|composite_item_id|modifier_group_id|category_id|component_id|component_unit_id)['\"]" ...
```

Found only `RecipeController.php:125` and `CompositeItemVariantController.php:92` for `where('id', '!=', ...)`; route lookup blind spots were instead visible in the `findOrFail` grep.

```
grep -rnE "exists:(partners|products|users|categories|modifier_groups|composite_items|tax_configurations|units|locations)" ...
```

Found 2 `tax_configurations` hits and 8 direct `units` hits. No direct literal `products`, `categories`, `modifier_groups`, or `composite_items` hits remained in the requested scope; the new recipe-line finding was found through raw validated-FK tracing and `rg "exists:\{\$existsTable\}|component_id"`.

```
grep -rnE "\$request->(input|header|query)\(.{0,20}(company_id|tenant_id|X-Company-Id)" ...
```

No output.

```
grep -rn "app(CompanyContext" ...
```

One hit: `StoreModifierGroupRequest.php:25`.

```
grep -rnE "findOrFail|::find\(|::first\(" ...
```

Confirmed Opus's sibling-controller blind spots in `RecipeController`, `RecipeLineController`, `CompositeItemController`, and `CompositeItemVariantController`, plus the fixed scoped lookups in `ModifierGroupController`, `ModifierController`, and `CategoryController`.

```
grep -rnE -e "->validated|validated\(\)|\$validated\[" ...
```

This exposed the dynamic recipe-line raw-FK persistence path described above.

## Test Honesty

I used a different honesty check than Opus. I temporarily reversed only the `CategoryController` part of `516c6f61`, ran:

```
cd apps/api
vendor/bin/phpunit tests/Feature/Catalog/CatalogTenantIsolationTest.php --filter 'category|reorder'
```

The subset failed as expected: 6 tests, 10 assertions, 4 failures. The failing tests were `test_create_category_rejects_cross_tenant_parent_id`, `test_update_category_rejects_cross_tenant_parent_id`, `test_reorder_rejects_cross_tenant_category_id`, and `test_reorder_rejects_cross_tenant_parent_id`. I then reapplied the patch and confirmed `git diff -- apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php` was empty.

## Required Gates

```
cd apps/api
php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml
```

Output: `verified 1089 event(s) across 268 callsite(s); 0 problem(s).`

```
vendor/bin/phpunit tests/Feature/Catalog/CatalogTenantIsolationTest.php
```

Output: `OK (19 tests, 52 assertions)`.

```
./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Catalog app/Modules/Product/Presentation/Controllers/CategoryController.php tests/Feature/Catalog/CatalogTenantIsolationTest.php
```

Output: `[OK] No errors`.

```
git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher
```

Output: empty.

## Confidence

Confidence is HIGH that the 17 inventoried callsites fixed by `516c6f61` are correctly scoped and the submitted tests are non-vacuous.

Confidence is HIGH that this cluster should not be approved yet because the dynamic recipe-line `component_id` validator and direct persistence path is reachable, tenant-scoped by schema, and missed by literal grep. This is the same class of raw foreign-FK persistence issue called out in prior clusters.
