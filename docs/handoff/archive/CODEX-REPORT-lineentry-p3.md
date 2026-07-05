# Line Entry Phase 3 Rollout Report

Date: 2026-07-02
Branch/worktree: `feat/line-entry-p3` at `/Users/houssamr/Projects/syneriva/apps/erp.lineentry-1b`

## Summary

Phase 3 rollout and cleanup is implemented for the remaining rollout-map surfaces requested in the prompt:

| Surface | Phase 3 change | ProductCell in lines | Entry behavior |
|---|---|---:|---|
| Inventory counting product/product-location scope | Replaced `ProductSelector` with `LineItemEntryBar`; added selected-product rows rendered with `ProductCell` and remove action. | Yes | Adds products to count scope only. No FEFO/source-location gate. Duplicate adds are ignored because count scope stores product IDs. |
| Goods receipt receive dialog | Receivable lines now render `ProductCell` with thumbnail/SKU/barcode via optional `product_code`, `product_barcode`, `primary_image_url` fields. | Yes | Receipt quantity/batch semantics unchanged. |
| Purchase order line display | PO detail lines now render `ProductCell` with thumbnail/SKU/barcode and centralized product route. | Yes | Display-only; receive flow unchanged. |
| Recipe line editor | Replaced legacy `ProductSearchSelect` with shared `ProductLineSelect`. | Yes in picker rows/selected value | Single-select product component lookup. |
| Modifier group inventory link | Replaced legacy `ProductSearchSelect` with shared `ProductLineSelect`. | Yes in picker rows/selected value | Single-select product component lookup. |
| Batch form product field | Replaced legacy `ProductSearchSelect` with shared `ProductLineSelect`. | Yes in picker rows/selected value | Single-select product component lookup; disabled edit behavior preserved. |
| Shared `ProductPicker` primitive | Product rows/selected value now use `ProductCell`. | Yes | Existing picker API unchanged. |

Cleanup:
- Deleted `apps/web/src/components/ui/ProductSearchSelect.tsx`.
- `rg -n "ProductSearchSelect" apps/web/src` returns no matches.
- Added missing Arabic `common.clearSearch` translation used by the replacement selector.

## TDD Evidence

Red run, before implementation:

```text
pnpm --filter @autoerp/web test -- src/components/molecules/line-items/ProductLineSelect.test.tsx src/features/inventory-counting/pages/__tests__/CreateCountingPage.test.tsx src/features/purchases/components/ReceiveGoodsDialog.test.tsx src/features/documents/purchase-orders/__tests__/PurchaseOrderDetailPage.tenantScope.test.tsx

FAIL src/features/purchases/components/ReceiveGoodsDialog.test.tsx
Unable to find an accessible element with the role "img" and name "Serum Retinol"

FAIL src/features/inventory-counting/pages/__tests__/CreateCountingPage.test.tsx
Unable to find an element by: [data-testid="counting-line-entry-bar"]

FAIL src/features/documents/purchase-orders/__tests__/PurchaseOrderDetailPage.tenantScope.test.tsx
Unable to find an accessible element with the role "img" and name "Stock"

Test Files 4 failed (4)
Tests 3 failed | 12 passed (15)
```

Green focused rollout run:

```text
pnpm --filter @autoerp/web test -- src/components/molecules/line-items/ProductLineSelect.test.tsx src/features/inventory-counting/pages/__tests__/CreateCountingPage.test.tsx src/features/purchases/components/ReceiveGoodsDialog.test.tsx src/features/documents/purchase-orders/__tests__/PurchaseOrderDetailPage.tenantScope.test.tsx src/components/__tests__/SharedSelectors.tenantScope.test.tsx src/components/molecules/pickers/ProductPicker.test.tsx src/features/catalog/pages/ModifierGroupFormPage.test.tsx

Test Files 7 passed (7)
Tests 42 passed (42)
```

Selector recheck after final cleanup:

```text
pnpm --filter @autoerp/web test -- src/components/molecules/line-items/ProductLineSelect.test.tsx src/components/__tests__/SharedSelectors.tenantScope.test.tsx

Test Files 2 passed (2)
Tests 6 passed (6)
```

## Gates

```text
pnpm --filter @autoerp/web typecheck
# exit 0
```

```text
pnpm --filter @autoerp/web audit:keys
[sweep-progress] Gate C — useQuery/useQueries/queryClient queryKeys without an approved tenant scope: 0
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
```

```text
pnpm --filter @autoerp/web exec eslint -- <changed ts/tsx files>
# exit 0; warnings only from existing warning-level rules plus one async test helper warning
```

## Notes

- No git commit was made.
- `ProductCatalogPickerDialog` remains absent from the codebase; this pass covered the rollout-map surfaces and legacy selector cleanup named in the prompt.
