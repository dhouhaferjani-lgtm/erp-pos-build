Verdict: REQUEST-CHANGES
Commit reviewed: 3df52927

Review date: 2026-05-09
Reviewer: Codex adversarial round-2 re-review
Cluster: api.catalog

## Findings

### F2-R2-1 - Three mutating round-2 selectors still lack no-mutation assertions

The new tests are route-correct and no longer appear to be router-level 404s, but the round-2 bar requires confirming tenant B data is not mutated where the operation would mutate. Three mutating tests only assert the 404:

- `test_recipe_store_rejects_cross_tenant_composite_item_id()` posts to tenant B's composite item at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1599-1606`, but it does not assert no tenant B recipe was created and does not assert `composite_items.default_recipe_id` stayed unchanged. If the parent scope at `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeController.php:82-85` regressed, the controller would create a recipe at `RecipeController.php:92-100`.
- `test_recipe_calculate_cost_rejects_cross_tenant_via_composite_item()` posts tenant B's recipe to `/api/v1/recipes/{id}/calculate-cost` at `CatalogTenantIsolationTest.php:1649-1661`, but it does not assert the foreign recipe's `calculated_cost` stayed unchanged. If the `whereHas` at `RecipeController.php:166-171` regressed, `RecipeCostCalculationService::calculate()` persists `calculated_cost` at `apps/api/app/Modules/Catalog/Application/Services/RecipeCostCalculationService.php:75-76`.
- `test_variant_store_rejects_cross_tenant_composite_item_id()` posts to tenant B's composite item at `CatalogTenantIsolationTest.php:1740-1750`, but it does not assert no foreign variant row was created. If the parent scope at `apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemVariantController.php:55-58` regressed, the controller would create a variant at `CompositeItemVariantController.php:66-69`.

Under the verdict rule, these are missing assertions for mutating operations, so I cannot approve even though the production scopes remain intact.

## F1 Closure Verification

`test_show_composite_item_variant_rejects_cross_tenant_via_composite_item()` now uses the registered route: `PATCH /api/v1/variants/{id}` at `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php:1330-1334`. Production registers that URI at `apps/api/app/Modules/Catalog/Presentation/routes.php:45`; there is no registered `/api/v1/composite-item-variants/{id}` route in the route file.

The test is no longer vacuous on the route. It creates a tenant B variant at `CatalogTenantIsolationTest.php:1321-1328`, acts as tenant A, asserts 404 at `CatalogTenantIsolationTest.php:1332-1336`, and asserts the foreign variant name remains `Variant B` at `CatalogTenantIsolationTest.php:1338-1342`.

This assertion shape would fail if the `whereHas('compositeItem', ...)` predicate were dropped from `CompositeItemVariantController::update()`: the controller currently scopes through the parent composite item at `apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemVariantController.php:86-91`, then mutates the variant at `CompositeItemVariantController.php:111`. Without that predicate, tenant A could find tenant B's variant, the response would not be the asserted 404, and the final `assertSame('Variant B', ...)` would fail after the name update to `Hijacked`.

## F2 Closure Verification

Route evidence: `php artisan route:list --columns=method,uri` is unsupported in this install, but plain `php artisan route:list` returns the relevant catalog routes, and the source route file gives exact method/URI anchors at `apps/api/app/Modules/Catalog/Presentation/routes.php:23`, `30-35`, `38-40`, and `43-46`. The test setup enables the Inventory module for both tenants at `CatalogTenantIsolationTest.php:115-130`, grants admin roles at `CatalogTenantIsolationTest.php:155-178`, and pins tenant A auth plus company header at `CatalogTenantIsolationTest.php:1813-1819`; the UUIDs used are real tenant B model ids from `CatalogTenantIsolationTest.php:214-221` or method-local creates, so these 404s exercise controller `findOrFail()` scopes rather than malformed IDs.

- 018 duplicate: `POST /api/v1/composite-items/{id}/duplicate` is registered at `routes.php:23`; the test sends that route at `CatalogTenantIsolationTest.php:1571-1576`. It asserts 404 and asserts tenant B's composite item still exists/name unchanged at `CatalogTenantIsolationTest.php:1578-1583`. Non-vacuous: `CompositeItemController::duplicate()` would otherwise load and duplicate after the scoped lookup at `CompositeItemController.php:144-148`.
- 019 recipe index: `GET /api/v1/composite-items/{compositeItemId}/recipes` is registered at `routes.php:30`; the test sends it at `CatalogTenantIsolationTest.php:1591-1596`. It asserts 404 from the controller parent lookup at `RecipeController.php:35-38`. Non-mutating endpoint, so no tenant B mutation assertion is required.
- 019 recipe store: `POST /api/v1/composite-items/{compositeItemId}/recipes` is registered at `routes.php:31`; the test sends it at `CatalogTenantIsolationTest.php:1599-1606` and asserts 404. It does not assert no recipe/default mutation, which is a blocker for this mutating operation.
- 019 recipe update: `PATCH /api/v1/recipes/{id}` is registered at `routes.php:33`; the test sends it at `CatalogTenantIsolationTest.php:1609-1623`. It asserts 404 and asserts tenant B's `yield_quantity` is still `1.0` at `CatalogTenantIsolationTest.php:1625-1628`.
- 019 recipe activate: `POST /api/v1/recipes/{id}/activate` is registered at `routes.php:34`; the test sends it at `CatalogTenantIsolationTest.php:1631-1643`. It asserts 404 and asserts tenant B's recipe remains inactive at `CatalogTenantIsolationTest.php:1645-1646`.
- 019 recipe calculateCost: `POST /api/v1/recipes/{id}/calculate-cost` is registered at `routes.php:35`; the test sends it at `CatalogTenantIsolationTest.php:1649-1661` and asserts 404. It does not assert tenant B's `calculated_cost` stayed unchanged, which is a blocker because the service persists that field at `RecipeCostCalculationService.php:75-76`.
- 020 recipe line update: `PATCH /api/v1/recipes/{recipeId}/lines/{lineId}` is registered at `routes.php:39`; the test sends it at `CatalogTenantIsolationTest.php:1668-1692`. It asserts 404 and asserts the foreign line quantity remains `2.0` at `CatalogTenantIsolationTest.php:1694-1697`.
- 020 recipe line destroy: `DELETE /api/v1/recipes/{recipeId}/lines/{lineId}` is registered at `routes.php:40`; the test sends it at `CatalogTenantIsolationTest.php:1700-1722`. It asserts 404 and asserts the foreign line still exists at `CatalogTenantIsolationTest.php:1724-1725`.
- 021 variant index: `GET /api/v1/composite-items/{compositeItemId}/variants` is registered at `routes.php:43`; the test sends it at `CatalogTenantIsolationTest.php:1732-1737`. It asserts 404 from the controller parent lookup at `CompositeItemVariantController.php:33-36`. Non-mutating endpoint, so no tenant B mutation assertion is required.
- 021 variant store: `POST /api/v1/composite-items/{compositeItemId}/variants` is registered at `routes.php:44`; the test sends it at `CatalogTenantIsolationTest.php:1740-1750` and asserts 404. It does not assert no foreign variant was created, which is a blocker for this mutating operation.
- 021 variant destroy: `DELETE /api/v1/variants/{id}` is registered at `routes.php:46`; the test sends it at `CatalogTenantIsolationTest.php:1753-1767`. It asserts 404 and asserts the foreign variant still exists at `CatalogTenantIsolationTest.php:1769-1770`.

## Bar-Raising Hostile Grep

I grepped the added-method block for wrong-route and vacuous-pass patterns. `rg -n "composite-item-variants|/api/v1/variants|/api/v1/composite-items/.*/(recipes|variants)|assertStatus\\(200\\)|assertOk\\(|assertStatus\\(404\\)|fresh\\(\\)" apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php` found the old wrong route only inside the explanatory comment at `CatalogTenantIsolationTest.php:1330`; the actual request is `/api/v1/variants/{id}` at `CatalogTenantIsolationTest.php:1333`. No added cross-tenant test in the reviewed block asserts 200/OK instead of 404.

The same grep found all 11 added tests use tenant A auth and tenant B target data in the reviewed lines (`CatalogTenantIsolationTest.php:1574-1766`). The explicit bad pattern found is missing no-mutation assertions after mutating requests: recipe store at `CatalogTenantIsolationTest.php:1599-1606`, recipe calculate-cost at `CatalogTenantIsolationTest.php:1649-1661`, and variant store at `CatalogTenantIsolationTest.php:1740-1750` have no adjacent `fresh()`, count, or database assertion.

## Round-1 Implementation Regression Check

The original nine callsite fixes from 63c1bc95 remain intact at HEAD:

- 018: `CompositeItemController` still scopes route id reads by tenant and company for `show()`, `update()`, `destroy()`, `duplicate()`, and `checkAvailability()` at `apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemController.php:74-78`, `107-110`, `126-129`, `144-148`, and `200-204`.
- 019: `RecipeController` still scopes parent composite item reads at `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeController.php:35-38` and `82-85`, and still scopes recipe reads with `whereHas('compositeItem', ...)` at `RecipeController.php:63-68`, `115-120`, `137-142`, and `166-171`.
- 020: `RecipeLineController` still scopes recipe ownership with `whereHas('compositeItem', ...)` before `store()`, `update()`, and `destroy()` operations at `apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeLineController.php:41-46`, `81-86`, and `132-137`.
- 021: `CompositeItemVariantController` still scopes parent composite item reads at `apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemVariantController.php:33-36` and `55-58`, and still scopes variant reads through `whereHas('compositeItem', ...)` at `CompositeItemVariantController.php:86-91` and `125-130`.
- 022: unit validators still use `ScopedExists::tenantOrSystem('units', ...)` in `StoreCompositeItemRequest.php:65`, `UpdateCompositeItemRequest.php:67`, `StoreRecipeRequest.php:35`, `UpdateRecipeRequest.php:35`, `StoreRecipeLineRequest.php:48`, `RecipeLineController.php:110`, `StoreModifierRequest.php:41`, and `ModifierController.php:76`; the helper keeps grouped tenant-or-null semantics at `apps/api/app/Shared/Presentation/Validation/ScopedExists.php:68-72`.
- 023: composite item create/update still attach `TaxConfigurationCountryCoherent` at `apps/api/app/Modules/Catalog/Presentation/Requests/StoreCompositeItemRequest.php:59-62` and `UpdateCompositeItemRequest.php:61-64`.
- 024: `ModifierController::update()` still validates `component_type` with `new Enum(ComponentType::class)` at `apps/api/app/Modules/Catalog/Presentation/Controllers/ModifierController.php:63-68`.
- 025: `checkAvailability()` still validates `location_id` with `ScopedExists::company('locations', $company->id)` and then scopes the composite item by tenant/company at `CompositeItemController.php:194-204`.
- 026: `RecipeLineController` still passes tenant and company into `NoCircularCompositeItemReference` at `RecipeLineController.php:53-56` and `100-103`; the rule still scopes traversal by caller tenant/company at `apps/api/app/Modules/Catalog/Presentation/Rules/NoCircularCompositeItemReference.php:59-67`.

No revert or refactor wiped a reviewed `whereHas` or tenant/company predicate; the implementation side is intact.

## PHPStan Note

PHPStan gate independently verified by main session at 2026-05-09; verdict no longer blocked on sandbox-imposed environment limitation.

I also ran `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Catalog tests/Feature/Catalog/CatalogTenantIsolationTest.php` from `apps/api`; it completed with `[OK] No errors`.

## Final Rationale

Request changes. F1 is closed, the added tests hit registered routes, and the round-1 implementation fixes are still present. However, three new mutating selectors are not assertion-complete because they do not verify the foreign tenant's data remained unchanged.
