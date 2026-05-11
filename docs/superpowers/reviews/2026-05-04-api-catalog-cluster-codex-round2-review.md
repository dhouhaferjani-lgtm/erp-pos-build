# api.catalog - Codex round-2 adversarial review

Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED
Commit reviewed: 516c6f61
Commit reviewed: 91c6a9e9

(The 17 inventoried callsites pin fix_commit=516c6f61 from round-1; the
round-2 commit 91c6a9e9 added remediation for Codex round-1 Findings 1-2
on RecipeLine + StoreModifierGroupRequest surfaces tracked separately
in the manual-stub. The first `Commit reviewed:` line above pins this
round-2 review to the canonical fix_commit the inventory parser reads
when flipping callsites to fixed.)

Branch: feat/tenant-isolation-sweep-execution
Cluster: api.catalog (codex-owned)
Reviewer: Codex headless second-layer adversarial reviewer
Date: 2026-05-05

## Scope verification

`git show --stat --name-only --format=fuller 91c6a9e9` was read end-to-end.
The commit touches exactly four files:

```text
apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeLineController.php
apps/api/app/Modules/Catalog/Presentation/Requests/StoreModifierGroupRequest.php
apps/api/app/Modules/Catalog/Presentation/Requests/StoreRecipeLineRequest.php
apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php
```

No Document, Service, Inventory, Accounting, POS, POS app, or Voucher module
files are part of commit `91c6a9e9`.

## Opus round-2 claim verification

1. MEDIUM carry-over: manual-stub still has zero api.catalog rows.

   Verified. The required command returns zero:

   ```text
   $ grep -c "cluster_id: api.catalog" docs/superpowers/plans/tenant-isolation-sweep-manual-callsites.yml
   0
   ```

   This is a hard prerequisite before the cluster transitions to
   `status: fixed`. I do not consider it a blocker for approving the
   code-level remediation commit because the dynamic recipe-line bug is
   correctly fixed, but the orchestrator must populate the manual stub before
   closing api.catalog.

2. LOW: `ModifierController::update` validates `component_type` as `string`.

   Verified. `apps/api/app/Modules/Catalog/Presentation/Controllers/ModifierController.php:65`
   has:

   ```php
   'component_type' => ['nullable', 'string'],
   ```

   This remains asymmetric with `StoreModifierRequest`, which uses
   `new Enum(ComponentType::class)`. The associated `component_id` is
   tenant+company scoped to products, so I did not find a cross-tenant
   persistence leak here. It is still a validation/integrity bug.

3. LOW: `CompositeItemController::checkAvailability` forwards request
   `location_id` into stock lookup without scoped location validation.

   Verified. `CompositeItemController.php:175-184` accepts the request
   `location_id` after UUID format validation only, then calls
   `CompositeItemAvailabilityService::checkAvailability($item, $locationId)`.
   The service queries stock with:

   ```php
   StockLevel::where('product_id', $productId)
       ->where('location_id', $locationId)
       ->first();
   ```

   The item and product side are already derived from the caller's scoped
   composite item. I did not find a direct cross-tenant data leak in normal
   data, but the route breaks the invariant that user-supplied foreign keys
   are scoped before use.

## Codex round-1 HIGH closure verification

Closed.

`StoreRecipeLineRequest` no longer builds `exists:{$existsTable},id`. It now
branches by `component_type` and uses:

```php
$componentExistsRule = $componentType === ComponentType::CompositeItem->value
    ? ScopedExists::tenantAndCompany('composite_items', $company->tenant_id, $company->id)
    : ScopedExists::tenantAndCompany('products', $company->tenant_id, $company->id);
```

`RecipeLineController::update` independently applies the same two branches:

```php
$componentExistsRule = $componentType === ComponentType::CompositeItem->value
    ? ScopedExists::tenantAndCompany('composite_items', $company->tenant_id, $company->id)
    : ScopedExists::tenantAndCompany('products', $company->tenant_id, $company->id);
```

`ScopedExists::tenantAndCompany()` expands to a `Rule::exists()` predicate with
both `tenant_id` and `company_id`. Both `product` and `composite_item` branches
are therefore tenant+company scoped in both store and update paths.

## New findings

1. LOW - `NoCircularCompositeItemReference` still performs an unscoped
   composite-item lookup during update validation.

   `apps/api/app/Modules/Catalog/Presentation/Rules/NoCircularCompositeItemReference.php:52`
   uses:

   ```php
   CompositeItem::with('activeRecipe.lines')->find($compositeItemId);
   ```

   In the store path, this rule runs after the `StoreRecipeLineRequest`
   FormRequest has already accepted a scoped component id. In the update path,
   the rule is in the same `component_id` rule list as the new
   `ScopedExists::tenantAndCompany(...)` rule. Laravel validation can still
   execute later rules after an earlier non-implicit rule fails, so a forged
   foreign `component_id` can still drive an unscoped read in this validation
   helper before the request is rejected.

   I did not find a persistence leak because the new `ScopedExists` rule still
   rejects the id and the response remains a validation failure. This should be
   added to the api.catalog manual-stub follow-up or closed by passing the
   caller's tenant/company scope into the circular-reference rule.

## Audit exhaustiveness

Required hostile greps:

```text
$ grep -rnE "exists:.\{" apps/api/app/Modules/Catalog/ apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php
# no output

$ grep -rnE "exists:[\"']?\{" apps/api/app/Modules/Catalog/ apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php
# no output

$ grep -rnE "exists:(products|composite_items|categories|modifier_groups|composite_item_variants|recipes|recipe_lines)" apps/api/app/Modules/Catalog/ apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php --include='*.php' | grep -v /tests/
# no output

$ grep -rn "app(CompanyContext" apps/api/app/Modules/Catalog apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php
# no output
```

Validated payload persistence surfaces reviewed:

```text
RecipeLineController.php:53            ...$request->validated()
CompositeItemController.php:83         ...$request->validated()
CompositeItemController.php:102        $item->update($request->validated())
ModifierGroupController.php:80         ...$request->validated()
RecipeController.php:78                ...$request->validated()
RecipeController.php:104               $recipe->update($request->validated())
ModifierController.php:37              ...$request->validated()
CompositeItemVariantController.php:58  ...$request->validated()
```

Key remaining direct global/reference exists rules:

```text
UpdateRecipeRequest.php:24             exists:units,id
StoreRecipeLineRequest.php:46          exists:units,id
UpdateCompositeItemRequest.php:58      exists:tax_configurations,id
UpdateCompositeItemRequest.php:60      exists:units,id
StoreModifierRequest.php:40            exists:units,id
StoreRecipeRequest.php:24              exists:units,id
StoreCompositeItemRequest.php:51       exists:tax_configurations,id
StoreCompositeItemRequest.php:53       exists:units,id
RecipeLineController.php:96            exists:units,id
ModifierController.php:72              exists:units,id
```

These are the known units/tax reference-table follow-ups and must be tracked
in the manual stub before cluster close.

Sibling controller review covered:

```text
CompositeItemController.php
CompositeItemVariantController.php
ModifierController.php
ModifierGroupController.php
RecipeController.php
RecipeLineController.php
```

The company-only/post-load patterns in `CompositeItemController`,
`CompositeItemVariantController`, `RecipeController`, and `RecipeLineController`
remain the same class of manual-stub follow-up as Opus's carry-over finding.
No additional dynamic table-name validator was found.

## Test honesty

I temporarily reverted only the update-path scoped component rule in
`RecipeLineController::update` back to the old dynamic `exists:{$existsTable},id`
shape and ran:

```text
$ cd apps/api && vendor/bin/phpunit tests/Feature/Catalog/CatalogTenantIsolationTest.php
```

The suite failed exactly on the relevant test:

```text
1) Tests\Feature\Catalog\CatalogTenantIsolationTest::test_update_recipe_line_rejects_cross_tenant_component_id
Expected response status code [422] but received 200.
Tests: 22, Assertions: 59, Failures: 1.
```

I restored the scoped rule immediately afterward. `git diff -- apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeLineController.php`
then returned no output.

## Gates

All required gates passed on the restored tree:

```text
$ cd apps/api && php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml
verified 1093 event(s) across 268 callsite(s); 0 problem(s).

$ cd apps/api && vendor/bin/phpunit tests/Feature/Catalog/CatalogTenantIsolationTest.php
OK (22 tests, 61 assertions)

$ cd apps/api && ./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Catalog app/Modules/Product/Presentation/Controllers/CategoryController.php tests/Feature/Catalog/CatalogTenantIsolationTest.php
[OK] No errors

$ git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher
# no output
```

## Confidence

High for the reviewed commit: `91c6a9e9` correctly closes the dynamic
recipe-line tenant-isolation bug in both store and update paths and closes the
`StoreModifierGroupRequest` service-locator helper. Medium-high for the wider
cluster: the remaining issues are mostly manual-stub/invariant work rather
than confirmed data leaks, but the manual-stub gap is real and must be handled
before api.catalog is marked fixed.
