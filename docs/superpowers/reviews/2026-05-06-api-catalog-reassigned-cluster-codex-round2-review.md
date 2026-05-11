# Codex round-2 second-layer review — api.catalog reassigned-callsite remediation

Review date: 2026-05-06
Branch tip reviewed: 0d15e4be (latest pushed; round-2 fix at 48ff4bb9, stubs at 3d685611)
Reviewer: codex (round-2 second-layer review)

Verdict: REQUEST-CHANGES
Commit reviewed: 48ff4bb9 (production fix), 3d685611 (manual stubs)

> Note: codex could not save this verdict file directly because its
> sandbox rejected writes outside `apps/api/`; the verdict was emitted
> in the codex stdout log
> (`/tmp/codex-catalog-round2-stdout.log`) and persisted here verbatim
> by Claude with no edits to the verdict text.

## Round-1 finding closure

- **Finding 1 (BLOCKING — ProductController + EnrichmentReviewController bare-where reads): CLOSED.**
  Production now uses `Model::query()->where('tenant_id')->where('company_id')->where('id')` for the four ProductController route reads (`show`, `update`, `destroy`, `stockLevels`) and the three EnrichmentReviewController reads (`show`, `accept`, `reject`). `stockLevels` also re-anchors the `StockLevel` aggregate read on tenant_id + company_id. The chained MethodCall pattern is detectable by the AST visitor and the cross-tenant invariant is enforced. Manual stubs `api.catalog.027–.033` pin `fix_commit = 48ff4bb9` and each carries the regression-test selector. History events are present in the same mutate cycle (no orphan).

- **Finding 2 (NON-BLOCKING — over-broad `.015` parent-verify matcher): CLOSED.**
  The matcher now counts post-fix-shape SELECTs (`from "categories"` AND `"company_id" = ?` AND `"categories"."id" = ?` AND `select * from` AND `limit 1` AND not `count(*)`). Update flow emits exactly two such queries (primary load + parent-verify). A regression that drops `company_id` from the parent-verify lookup would cause its SQL to no longer match the post-fix shape and the count would drop to 1, failing the assertion loudly. The unscoped `parent` BelongsTo lazy-load (Domain::booted updated → updatePath → $this->parent) emits a different shape and is filtered out (separate domain-tier concern outside this cluster).

## New findings (round 2, second-layer)

1. **NEW — ProductImageController authenticated route binding is unscoped (REQUEST-CHANGES).**
   Routes at `apps/api/app/Modules/Product/routes.php:113–135` use implicit `Product $product` / `ProductImage $image` route binding, and the controller methods at `apps/api/app/Modules/Product/Presentation/Controllers/ProductImageController.php:25, 36, 55, 76, 86, 98` rely entirely on that binding. There is no scoped route binding (`Route::bind` / `Route::model` / `resolveRouteBinding` override) and no controller-tier `CompanyContext` reload before mutating. Additionally, `update`, `destroy`, and `download` do not verify that the bound `ProductImage` belongs to the bound `Product`. A request like `GET/PATCH/DELETE /api/v1/products/{tenantAProductId}/images/{tenantBImageId}` can act on the foreign bound image after an unscoped route-param read.

   **Suggested remediation:**
   - Override `Product::resolveRouteBinding` and `ProductImage::resolveRouteBinding` to scope by `CompanyContext` tenant + company (or add explicit `Route::bind` callbacks at registration time).
   - In `ProductImageController::update`/`destroy`/`download`, reload via `Product::query()->where('tenant_id', ...)->where('company_id', ...)->where('id', $product->id)->firstOrFail()` and assert `$image->product_id === $product->id` before mutating.
   - Add manual stubs to `tenant-isolation-sweep-manual-callsites.yml` for each affected controller method (likely 6 callsites: `index`, `store`, `show`, `update`, `destroy`, `download`).
   - Add cross-tenant denial tests + SQL-log invariant tests in `CatalogTenantIsolationTest.php` covering the foreign-image and product/image mismatch cases.

## Audit exhaustiveness

Read the round-1 verdict end-to-end; ran `git show` for `48ff4bb9` and `3d685611`; inspected the post-fix `ProductController`, `EnrichmentReviewController`, `CategoryController` (parent-verify), and `CatalogTenantIsolationTest`. Verified each manual stub at `api.catalog.027–.033` has `fix_commit` pinned and the matching `regression_test` selector.

Hostile grep across `apps/api/app/Modules/Product/` (excluding `/tests/`) surfaced ProductImageController as the next StaticCall blind-spot pattern. CategoryController, ModifierController, ModifierGroupController, RecipeController, and CompositeItem* controllers were checked again and remain in the state described in the round-1 verdict (clean for this cluster's invariant).

## Gates

- `vendor/bin/phpunit tests/Feature/Catalog/CatalogTenantIsolationTest.php` — PASS, 36 tests / 98 assertions.
- `vendor/bin/phpunit tests/Feature/Catalog` — PASS, 73 tests / 204 assertions (32 PHPUnit deprecations, pre-existing).
- `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Product tests/Feature/Catalog/CatalogTenantIsolationTest.php` — clean.
- `./vendor/bin/pint --test app/Modules/Product tests/Feature/Catalog/CatalogTenantIsolationTest.php` — pass.
- `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` — verified 1489 events / 314 callsites / 0 problems.

## Confidence

HIGH on the round-1 closure. HIGH on the new ProductImageController finding (verified by reading the routes file and controller methods directly; bound `Product`/`ProductImage` instances are returned to the controller by Laravel without any scope check, and the controller does not re-scope before mutating).
