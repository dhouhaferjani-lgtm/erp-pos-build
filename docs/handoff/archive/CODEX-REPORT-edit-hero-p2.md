# CODEX Report: Edit Hero Phase 2

Date: 2026-07-02
Branch: `feat/product-editor-hero-p2`

## Summary

Implemented the Phase 2 edit-mode product hero:

- Added `ProductEditHero` with a 176px image slot, md signed-media variant, upload/replace overlay, barcode lookup wiring, enrichment chips, and a ready-to-sell strip slot.
- Moved opening quantity/cost controls into the hero strip and removed the old center-column `section-opening` card.
- Added bidirectional `Marge %` / `Prix de vente HT` / `Prix de vente TTC` behavior using only `bc*` decimal helpers. Stored `sale_price` remains authoritative TTC.
- Added `EDITOR_SECTIONS` and `HERO_BLOCKS` descriptor arrays; nav/scroll-spy now derives from `EDITOR_SECTIONS`, and ready-to-sell cells render from `HERO_BLOCKS` through a renderer registry.
- Wired real enrichment state from product payload and invalidates product/list queries after manual refresh.
- Extended backend `ProductData` for `enrichment_status`, `latest_enrichment_result`, `stock_quantity`, `brand_source`, and `category`; product show eager-loads/aggregates those fields.
- Added catalog i18n keys for `en/fr/ar`.

## TDD Red Output

Frontend red:

```text
FAIL src/features/inventory/__tests__/ProductForm.opening.test.tsx
× moves opening controls into the ready-to-sell strip and removes the opening card
× keeps stored TTC authoritative while recalculating HT and margin with cost markup math
× renders edit hero primary image with md variant and accepted enrichment state

TestingLibraryElementError: Unable to find an element by: [data-testid="ready-to-sell-strip"]
TestingLibraryElementError: Unable to find an accessible element with the role "img" and name "Opening Test Product"

Tests: 3 failed | 4 passed (7)
```

Backend red:

```text
FAIL Tests\Feature\Modules\Product\ProductEditorContractTest
⨯ show exposes editor hero enrichment and stock contract

ErrorException: Undefined array key "enrichment_status"
at tests/Feature/Modules/Product/ProductEditorContractTest.php:569

Tests: 1 failed (1 assertions)
```

## Green Output

Frontend:

```text
PASS src/features/inventory/__tests__/ProductForm.opening.test.tsx

Test Files  1 passed (1)
Tests       7 passed (7)
Duration    2.26s
```

Note: this existing test file still emits React `act(...)` warnings from async provider/query updates. The assertions pass.

Backend:

```text
PASS Tests\Feature\Modules\Product\ProductEditorContractTest
✓ show exposes editor hero enrichment and stock contract

Tests: 1 passed (4 assertions)
Duration: 2.43s
```

Typecheck:

```text
pnpm --filter @autoerp/web typecheck
tsc --noEmit
exit 0
```

## Files Changed

- `apps/web/src/features/products/editor/components/ProductEditHero.tsx`
- `apps/web/src/features/inventory/ProductForm.tsx`
- `apps/web/src/features/inventory/__tests__/ProductForm.opening.test.tsx`
- `apps/web/src/locales/en/catalog.json`
- `apps/web/src/locales/fr/catalog.json`
- `apps/web/src/locales/ar/catalog.json`
- `apps/api/app/Modules/Product/Application/DTOs/ProductData.php`
- `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php`
- `apps/api/tests/Feature/Modules/Product/ProductEditorContractTest.php`

## Verification Commands

```bash
pnpm --filter @autoerp/web test src/features/inventory/__tests__/ProductForm.opening.test.tsx
pnpm --filter @autoerp/web typecheck
php artisan test tests/Feature/Modules/Product/ProductEditorContractTest.php --filter=test_show_exposes_editor_hero_enrichment_and_stock_contract
```
