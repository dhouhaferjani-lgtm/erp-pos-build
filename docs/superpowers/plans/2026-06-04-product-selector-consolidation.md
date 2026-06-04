# Product-Selector Consolidation — Phase 2 Plan

**Date:** 2026-06-04
**Status:** Plan only (Phase 1 shipped on branch `fix/product-picker-open-on-click`)
**Owner decision:** Phase 1 only this pass; rich-object return; converge on one canonical element.

## Background

A UI/UX audit found **four** different product-selection components with inconsistent
open behavior, return types, and styling. The stock-transfer create page surfaced two
regressions (selector required typing before showing anything; fields misaligned after
selecting a product).

### Phase 1 (DONE)

Made **`ProductPicker`** (`apps/web/src/components/molecules/pickers/ProductPicker.tsx`)
the canonical single-select picker:
- Lists products on open before typing (removed the 2-char gate; fetches whenever open,
  empty query → first page, typing filters via `search=`) — matches `DocumentLineEditor`.
- Renders the field label in **both** empty and selected states so the control keeps a
  constant vertical footprint and stays aligned with sibling fields (the quantity column).
- Returns a rich object `{ id, sku, name, sale_price, currency }`.
- Verified live on `/inventory/stock-transfers/new` (:5175): open-on-click, typing filters,
  empty state correct, chip aligned with quantity input. 7/7 ProductPicker + 28/28
  picker-family/stock-transfer tests; `tsc` clean; eslint 0 errors (no new warnings).

## Current inventory (after Phase 1)

| Component | Path | Behavior | Returns | Consumers |
|---|---|---|---|---|
| **ProductPicker** (CANONICAL) | `components/molecules/pickers/ProductPicker.tsx` | open-on-click + list-all | rich object | `CreateStockTransferPage` |
| ProductSearchSelect | `components/ui/ProductSearchSelect.tsx` | open-on-click + list-all | `id: string` | `BatchForm`, `RecipeLineEditor`, `ModifierGroupFormPage` |
| ProductSelector (multi) | `features/products/components/ProductSelector.tsx` | requires typing; multi-select cart | `id[]: string[]` | `CouponFormPage`, `PromotionFormPage`, `CreateCountingPage` |
| DocumentLineEditor search | `features/documents/components/DocumentLineEditor.tsx` | open-on-click + list-all; product/service tabs; quick-create | full `DocumentLine` | `DocumentForm` (all sales/purchase docs), `CreateCreditNotePage` |

Sibling pickers `PartnerPicker`, `ServicePicker`, `VehiclePicker`, `BundlePicker` share the
same molecule pattern and **still have the 2-char gate**.

## Target end state

- **One** canonical single-select = `ProductPicker` (rich object).
- **One** canonical multi-select = new `ProductMultiPicker` that composes the canonical
  search row + a selected-items cart (replaces `ProductSelector`).
- `DocumentLineEditor` keeps its domain-specific add-line flow but its product/service
  search dropdown reuses a shared headless search list (de-duplication, not behavior change).

## Migration steps (each: independent, TDD, own PR + Codex review)

### A. Retire `ProductSearchSelect` → `ProductPicker`  *(low risk, ~0.5d)*
- Migrate `BatchForm`, `RecipeLineEditor`, `ModifierGroupFormPage`.
- Callers currently hold `value: string` and re-fetch the product for display; with the
  rich object they read `.id` and drop the extra fetch.
- Update `components/__tests__/SharedSelectors.tenantScope.test.tsx`.
- Delete `ProductSearchSelect.tsx` once no importers remain.

### B. Replace `ProductSelector` (multi) → `ProductMultiPicker`  *(medium, ~1d)*
- Build `ProductMultiPicker` composing `ProductPicker`'s search row + selected cart.
- Open-on-click (no typing gate).
- Migrate `CouponFormPage`, `PromotionFormPage`, `CreateCountingPage` (`value: string[]`).
- Counting page has its own tests — update them.

### C. `DocumentLineEditor` shares the canonical search list  *(high, optional, ~1–1.5d)*
- Extract the inline product/service dropdown into a shared headless list used by both
  `ProductPicker` and `DocumentLineEditor`.
- Behavior is already correct here — this is purely de-duplication. Largest single
  consumer; mind the **services tab**, **quick-create**, and tax/price defaults.

### D. Sibling pickers consistency sweep  *(~0.5d)*
- Apply the same open-on-click treatment to `Partner/Service/Vehicle/Bundle` pickers
  where a default list is cheap. For large datasets (vehicles, partners) consider keeping
  a small min-chars or relying on pagination rather than full list-on-open.

## Sequencing & risk

`A` → `B` → `C` (optional) → `D`. A and B remove the two genuinely inconsistent
single/multi selectors; C is cleanup; D is a consistency polish.

## Open questions

- List-all-on-open is paginated (`per_page=20`), same as `DocumentLineEditor`, so large
  catalogs are fine. Confirm this holds for the multi-select cart UX in B.
- Keep canonical picker in `components/molecules/pickers/` (atomic-design home) — yes.
