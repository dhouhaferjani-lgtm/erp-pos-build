# Step 2 Review: Quantity Unit Precision

Branch: `codex/qty-unit-precision`
Base: `origin/dev`
Date: 2026-06-05

## Scope

- Expose `quantity_decimals` on product DTOs from the product unit of measure.
- Preserve `quantity_decimals` through `ProductPicker`.
- Drive stock-transfer quantity input precision from the selected product.
- Keep ProductPicker tenant/company cache behavior reactive.
- Prevent compact SKU badges from wrapping or overflowing mobile rows.

## Adversarial Review

Reviewer: `gpt-5.5` high effort, Opus-style adversarial review.

Findings and resolution:

1. Product update responses could return fallback `quantity_decimals: 4` because `unitOfMeasure` was not loaded after `fresh()`.
   - Added a failing update-response test.
   - Fixed store/update response loads to include `unitOfMeasure`.

2. `ProductPicker` used `tenantScopedKey()` without subscribing to auth/company stores, so an open picker might keep stale company-scoped results.
   - Added a failing company-switch refetch test.
   - Subscribed ProductPicker to tenant and company stores and gated the query until both are present.

3. `whitespace-nowrap` SKU badges could overflow narrow mobile rows for long SKUs.
   - Added max-width + truncation to selected and option SKU badges.
   - Verified with a browser run using a deliberately long SKU on mobile.

Coverage gaps noted by the reviewer:

- Backend detail serialization is indirectly covered by the same eager-loading pattern as list/update; no separate detail test was added in this branch.
- Full API result -> picker -> page flow is covered by browser route interception rather than a committed e2e spec.

## Verification

- `php artisan test --filter=ListProductsTest`
- `pnpm --filter @autoerp/web test -- src/components/molecules/pickers/ProductPicker.test.tsx src/features/stock-transfers/__tests__/CreateStockTransferPage.quantityScale.test.tsx`
- `pnpm --filter @autoerp/web typecheck`
- `pnpm --filter @autoerp/web test:arch`
- `pnpm --filter @autoerp/web lint` exited 0 with the existing warning backlog.
- `./vendor/bin/pint --test app/Modules/Product/Application/DTOs/ProductData.php app/Modules/Product/Domain/Product.php app/Modules/Product/Presentation/Controllers/ProductController.php tests/Feature/Product/ListProductsTest.php`
- `./vendor/bin/phpstan analyse app/Modules/Product/Application/DTOs/ProductData.php app/Modules/Product/Domain/Product.php app/Modules/Product/Presentation/Controllers/ProductController.php tests/Feature/Product/ListProductsTest.php --no-progress`
- Browser verification against `http://localhost:5173/inventory/stock-transfers/new` with mocked API responses:
  - desktop initial quantity step `0.0001`, selected product step `1`
  - mobile initial quantity step `0.0001`, selected product step `1`
  - long mobile SKU badge constrained under 100px

Known residual noise:

- PHPUnit emits existing doc-comment metadata deprecation warnings and fixture file warnings.
- Vitest emits the existing `--localstorage-file` warning.
- Full web lint exits 0 with the existing app-wide warning backlog.
