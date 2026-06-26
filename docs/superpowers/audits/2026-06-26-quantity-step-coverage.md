# Per-unit quantity step — coverage map & deferred skips

> Companion to `docs/superpowers/plans/2026-06-26-per-unit-quantity-step.md`. Records exactly which quantity inputs were converted to per-unit precision, which were deliberately left, and the pattern to follow for new ones.

## The pattern (for any new quantity input)

A quantity-of-a-product input must derive its `decimalPlaces` from the product's unit, never hardcode a literal:

```tsx
import { getQuantityDecimals } from '@/lib/quantityScale'
<QuantityInput decimalPlaces={getQuantityDecimals(product)} … />
```

`getQuantityDecimals({ quantity_decimals })` clamps to `[0,4]`. The product/line/row object must carry `quantity_decimals`, which the backend derives from `Unit.decimal_places` and surfaces via `ProductData`, `DocumentLineData`, `StockLevelData`, and the batch `BatchResource` (each eager-loads `product.unitOfMeasure`; fallback 4 when unloaded). Storage scale stays `decimal(15,4)` — only the UI increment follows the unit.

## Converted (product-unit quantities)

| Site | File | Source of precision |
|---|---|---|
| Document line qty (invoice/quote/SO/PO/credit/delivery) | `DocumentLineEditor.tsx:354` | `line.quantity_decimals` (new+saved paths) |
| Stock transfer — allocation table | `CreateStockTransferPage.tsx:552` | `line.product` (pre-existing) |
| Stock transfer — batch table | `CreateStockTransferPage.tsx:178` | `line.product` |
| Stock adjustment modal | `StockLevelsPage.tsx:558` | `selectedStock.quantity_decimals` |
| Expiry write-off qty | `ExpiryWriteOffPage.tsx:229` | `batch.product.quantity_decimals` |
| Product reorder point / quantity | `ProductForm.tsx:877,894` | selected unit via `useUnits()` |

Backend support added: `DocumentLineData`, `StockLevelData`, `BatchResource` + eager-loads in the shared `HandlesDocuments` trait, `DocumentController`, `CreditNoteController` (post), `StockLevelController`, `FEFOInventoryService`.

## Deliberately NOT changed

- **POS cart** (`apps/web` `CartLineItem`, device `apps/pos`): uses `+1/−1` buttons (already integer steps); no `QuantityInput` → no per-unit-step bug. Selling fractional units at POS is a separate feature, not this fix.
- **Goods receipt** (`GoodsReceiptListPage`): no editable `QuantityInput` stepper.
- **Non-quantity decimal inputs** — intentional literals, left as-is: loyalty points/thresholds `{2}` (`EarningRuleFormModal`, `TierFormModal`), variant `recipe_multiplier` `{2}` (`VariantEditor`), `wastage_percent` `{1}` (`RecipeLineEditor`). These are ratios/points/percents, not product-unit quantities.
- **Document conversion responses** (`DocumentConversionController`): return raw Eloquent (not `DocumentData`), so they cannot carry `quantity_decimals`; the FE re-fetches the new document via its show endpoint after navigating (covered).

## Deferred (composite/recipe module — post-parapharmacy-launch)

`RecipeLineEditor.tsx:261,330` and `ModifierGroupFormPage.tsx:352` (`decimalPlaces={4}`) are genuine ingredient/modifier quantities, but they belong to the **composite/recipe (F&B) module deferred to after the parapharmacy launch** (`project_composite_inventory_86ing_deferred`). Their line payloads do not carry `quantity_decimals`, so a correct fix requires backend work in a deferred module — out of scope per the one-task-at-a-time rule. When that module is picked up: add `quantity_decimals` to the recipe/modifier line payloads (eager-load the component product's unit) and switch these to `getQuantityDecimals(line)`.

## No lint rule added

A blanket ESLint rule flagging `decimalPlaces={<literal>}` was considered and rejected: it would false-positive on every intentional ratio/points input above and on the deferred recipe sites, producing noise that masks real drift. The reviewed convention here + the typed `quantity_decimals` fields are the guard instead.
