Verdict: APPROVE
Commit reviewed: 900b0131

Review date: 2026-05-09
Reviewer: Codex adversarial round-3 re-review
Cluster: api.catalog

## F3 Closure

### test_recipe_store_rejects_cross_tenant_composite_item_id

Present: yes. The test posts as tenant A to tenant B's composite item recipe route at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1599-1606`, asserts 404 at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1606`, then asserts `Recipe::query()->where('composite_item_id', $this->compositeItemB->id)->count()` is exactly `0` at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1608-1614`.

Semantically correct: yes. `Recipe` is the model whose fillable fields include `composite_item_id` at `apps/api/app/Modules/Catalog/Domain/Entities/Recipe.php:47-58`, and `composite_item_id` is the ownership FK documented on the model at `apps/api/app/Modules/Catalog/Domain/Entities/Recipe.php:16-24`. The assertion checks the right field for a recipe created under tenant B's composite item.

Regression-sensitive: yes. `RecipeController::store()` currently requires tenant and company on the parent composite item lookup before `findOrFail()` at `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeController.php:73-85`; if that parent scope regressed, the controller would call `Recipe::create()` with `$item->id` as `composite_item_id` at `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeController.php:87-100`. Because the test starts from a tenant B composite item created in setup at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:214-221` and does not create a tenant B recipe before the request, the count assertion at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1610-1614` would fail after such a mutation.

### test_recipe_calculate_cost_rejects_cross_tenant_via_composite_item

Present: yes. The test creates a tenant B recipe for tenant B's composite item at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1657-1665`, posts as tenant A to `/api/v1/recipes/{id}/calculate-cost` at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1667-1669`, asserts 404 at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1669`, then reloads the recipe and asserts `calculated_cost` is null at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1671-1676`.

Semantically correct: yes. `Recipe` exposes nullable `calculated_cost` and includes it in `$fillable` at `apps/api/app/Modules/Catalog/Domain/Entities/Recipe.php:16-24` and `apps/api/app/Modules/Catalog/Domain/Entities/Recipe.php:47-58`. The no-mutation assertion checks the exact persisted field that cost calculation writes.

Regression-sensitive: yes. `RecipeController::calculateCost()` currently scopes the recipe through `whereHas('compositeItem', ...)` with tenant and company predicates before `findOrFail()` at `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeController.php:157-171`; if that predicate regressed, the controller would pass the foreign recipe to the cost service at `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeController.php:173-175`. `RecipeCostCalculationService::calculate()` persists `calculated_cost` at `apps/api/app/Modules/Catalog/Application/Services/RecipeCostCalculationService.php:23-76`, so the assertion at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1674-1676` would fail after the mutation.

### test_variant_store_rejects_cross_tenant_composite_item_id

Present: yes. The test posts as tenant A to tenant B's composite item variants route with code `VAR-HIJACK` at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1755-1765`, asserts 404 at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1765`, then asserts the `composite_item_variants` table is missing a row with that code at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1767-1771`.

Semantically correct: yes. `CompositeItemVariant` stores `composite_item_id` and `code`, and both fields are fillable at `apps/api/app/Modules/Catalog/Domain/Entities/CompositeItemVariant.php:14-24` and `apps/api/app/Modules/Catalog/Domain/Entities/CompositeItemVariant.php:45-55`. The controller creates variants from request data plus the parent `composite_item_id`, so checking the submitted unique sentinel code on the correct table is a direct no-create assertion.

Regression-sensitive: yes. `CompositeItemVariantController::store()` currently scopes the parent `CompositeItem` by tenant and company before `findOrFail()` at `apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemVariantController.php:46-58`; if that scope regressed, the controller would create the variant with `composite_item_id => $item->id` at `apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemVariantController.php:60-71`. The `assertDatabaseMissing()` at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1769-1771` would fail because the regressed path would persist the submitted `VAR-HIJACK` code.

## Round-2 Re-confirm

F1 remains closed. The variant update regression test uses the registered `PATCH /api/v1/variants/{id}` route at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1319-1336`, and production registers that route at `apps/api/app/Modules/Catalog/Presentation/routes.php:43-46`. It also asserts the foreign variant name remains `Variant B` at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1338-1342`, while the controller still scopes update through the parent composite item before mutating at `apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemVariantController.php:74-111`.

The eight non-blocking F2 closures still hold at HEAD:

- `test_duplicate_composite_item_rejects_cross_tenant_id()` uses registered `POST /api/v1/composite-items/{id}/duplicate` at `apps/api/app/Modules/Catalog/Presentation/routes.php:20-24`, sends tenant B's id as tenant A at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1571-1576`, and asserts tenant B's item still exists/name unchanged at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1578-1583`. Production still scopes `duplicate()` before duplicating at `apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemController.php:135-180`.
- `test_recipe_index_rejects_cross_tenant_composite_item_id()` uses registered `GET /api/v1/composite-items/{compositeItemId}/recipes` at `apps/api/app/Modules/Catalog/Presentation/routes.php:30`, sends tenant B's composite item as tenant A at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1591-1596`, and targets a non-mutating endpoint whose parent lookup remains scoped at `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeController.php:26-46`.
- `test_recipe_update_rejects_cross_tenant_via_composite_item()` creates a tenant B recipe, sends tenant A's patch to registered `PATCH /api/v1/recipes/{id}` at `apps/api/app/Modules/Catalog/Presentation/routes.php:33`, asserts 404, and verifies `yield_quantity` remains `1.0` at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1617-1636`. Production still scopes update through `whereHas('compositeItem', ...)` before updating at `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeController.php:106-125`.
- `test_recipe_activate_rejects_cross_tenant_via_composite_item()` creates an inactive tenant B recipe, posts as tenant A to registered `POST /api/v1/recipes/{id}/activate` at `apps/api/app/Modules/Catalog/Presentation/routes.php:34`, asserts 404, and verifies the recipe remains inactive at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1639-1654`. Production still scopes activate through `whereHas('compositeItem', ...)` before activation updates at `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeController.php:128-154`.
- `test_update_recipe_line_rejects_cross_tenant_recipe_id()` creates a tenant B recipe line, sends tenant A's patch to registered `PATCH /api/v1/recipes/{recipeId}/lines/{lineId}` at `apps/api/app/Modules/Catalog/Presentation/routes.php:39`, asserts 404, and verifies quantity remains `2.0` at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1683-1712`. Production still scopes the parent recipe before line lookup/update at `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeLineController.php:72-120`.
- `test_destroy_recipe_line_rejects_cross_tenant_recipe_id()` creates a tenant B recipe line, sends tenant A's delete to registered `DELETE /api/v1/recipes/{recipeId}/lines/{lineId}` at `apps/api/app/Modules/Catalog/Presentation/routes.php:40`, asserts 404, and verifies the line still exists at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1715-1740`. Production still scopes the parent recipe before line lookup/delete at `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeLineController.php:123-142`.
- `test_variant_index_rejects_cross_tenant_composite_item_id()` uses registered `GET /api/v1/composite-items/{compositeItemId}/variants` at `apps/api/app/Modules/Catalog/Presentation/routes.php:43`, sends tenant B's composite item as tenant A at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1747-1752`, and targets a non-mutating endpoint whose parent lookup remains scoped at `apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemVariantController.php:24-43`.
- `test_destroy_variant_rejects_cross_tenant_via_composite_item()` creates a tenant B variant, sends tenant A's delete to registered `DELETE /api/v1/variants/{id}` at `apps/api/app/Modules/Catalog/Presentation/routes.php:46`, asserts 404, and verifies the variant still exists at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1774-1791`. Production still scopes destroy through `whereHas('compositeItem', ...)` before delete at `apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemVariantController.php:116-134`.

The test setup still gives both tenants the Inventory extra at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:115-130`, creates tenant B's composite item at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:214-221`, and assigns admin roles to both users at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:155-178`, so the reviewed 404s are not explained by missing module access or malformed ids.

## Round-1+2 Implementation Regression Check

The original production fixes from 63c1bc95 remain intact at HEAD:

- api.catalog.018: `CompositeItemController` still scopes route id reads by `tenant_id` and `company_id` for `show()`, `update()`, `destroy()`, `duplicate()`, and `checkAvailability()` at `apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemController.php:64-80`, `apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemController.php:98-114`, `apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemController.php:117-132`, `apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemController.php:135-180`, and `apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemController.php:183-208`.
- api.catalog.019: `RecipeController` still scopes parent composite item reads at `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeController.php:26-46` and `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeController.php:73-103`, and still scopes recipe reads through `whereHas('compositeItem', ...)` at `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeController.php:49-70`, `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeController.php:106-125`, `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeController.php:128-154`, and `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeController.php:157-175`.
- api.catalog.020: `RecipeLineController` still scopes recipe ownership before `store()`, `update()`, and `destroy()` at `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeLineController.php:29-69`, `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeLineController.php:72-120`, and `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeLineController.php:123-142`.
- api.catalog.021: `CompositeItemVariantController` still scopes parent composite item reads at `apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemVariantController.php:24-43` and `apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemVariantController.php:46-71`, and still scopes variant reads through `whereHas('compositeItem', ...)` at `apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemVariantController.php:74-113` and `apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemVariantController.php:116-134`.
- api.catalog.022: unit validators still use `ScopedExists::tenantOrSystem('units', ...)` in `apps/api/app/Modules/Catalog/Presentation/Requests/StoreCompositeItemRequest.php:64-65`, `apps/api/app/Modules/Catalog/Presentation/Requests/UpdateCompositeItemRequest.php:66-67`, `apps/api/app/Modules/Catalog/Presentation/Requests/StoreRecipeRequest.php:34-35`, `apps/api/app/Modules/Catalog/Presentation/Requests/UpdateRecipeRequest.php:34-35`, `apps/api/app/Modules/Catalog/Presentation/Requests/StoreRecipeLineRequest.php:46-48`, `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeLineController.php:109-110`, `apps/api/app/Modules/Catalog/Presentation/Requests/StoreModifierRequest.php:40-41`, and `apps/api/app/Modules/Catalog/Presentation/Controllers/ModifierController.php:75-76`. The helper keeps the grouped tenant-or-system predicate at `apps/api/app/Shared/Presentation/Validation/ScopedExists.php:63-72`.
- api.catalog.023: composite item create/update still attach `TaxConfigurationCountryCoherent` at `apps/api/app/Modules/Catalog/Presentation/Requests/StoreCompositeItemRequest.php:52-62` and `apps/api/app/Modules/Catalog/Presentation/Requests/UpdateCompositeItemRequest.php:54-64`; the rule still compares the selected configuration country with the company country at `apps/api/app/Modules/Catalog/Presentation/Rules/TaxConfigurationCountryCoherent.php:31-49`.
- api.catalog.024: `ModifierController::update()` still validates `component_type` with `new Enum(ComponentType::class)` at `apps/api/app/Modules/Catalog/Presentation/Controllers/ModifierController.php:63-69`.
- api.catalog.025: `CompositeItemController::checkAvailability()` still validates `location_id` with `ScopedExists::company('locations', $company->id)` and then scopes the composite item by tenant/company at `apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemController.php:183-208`.
- api.catalog.026: `RecipeLineController` still passes tenant and company into `NoCircularCompositeItemReference` at `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeLineController.php:50-56` and `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeLineController.php:100-103`, and the rule still scopes traversal by caller tenant/company at `apps/api/app/Modules/Catalog/Presentation/Rules/NoCircularCompositeItemReference.php:53-68`.

No reviewed production predicate appears reverted or weakened.

## PHPStan Status

Ran independently from `apps/api`: `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Catalog tests/Feature/Catalog/CatalogTenantIsolationTest.php`.

Status: PASS. Output ended with `[OK] No errors`.

## Test Run Status

Ran independently from `apps/api`: `vendor/bin/phpunit tests/Feature/Catalog tests/Unit/Shared/Presentation/Validation --no-coverage 2>&1 | tail -5`.

Status: PASS. Output: `Tests: 107, Assertions: 273, PHPUnit Deprecations: 32` and `OK, but there were issues!`.

## New Findings

None.
