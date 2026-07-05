---
title: POS Catalog State Deep Dive
date: 2026-04-30
auditor: Codex
scope: apps/pos catalog state bug
type: read-only audit
---

# Executive Summary

The filter selector itself is not destructively mutating products. The active category lives in `ProductGrid` local state, and the visible list is recomputed with non-mutating `filter()` calls. The most likely root cause is a source-of-truth mismatch: Menu-enabled catalog rendering uses `/active-menu`, but the sync scheduler refreshes the in-memory product store from the generic SQLite `products` table. That table stores one `category` string per product id, so it cannot preserve active-menu category membership, especially when a sellable appears in multiple menu categories.

Top 3 candidate root causes:

1. **Most likely: sync refresh overwrites Menu catalog state from the wrong cache.** `fetchProducts()` uses `fetchActiveMenu()` plus `flattenMenuToProducts()` when the Menu module is enabled, but `SyncScheduler` later calls `refreshFromSQLite()`, which always reads `getAllProducts()` from the generic `products` table (`apps/pos/src/stores/productStore.ts:52-58`, `apps/pos/src/lib/sync/syncScheduler.ts:72-78`, `apps/pos/src/stores/productStore.ts:161-170`).
2. **Strong secondary: generic `products` cache cannot represent menu category membership.** `flattenMenuToProducts()` emits one product-like row per active menu item with `id: item.sellable_id` and `category: category.name`; `upsertProducts()` conflicts only on `id`, so one sellable can retain only the last written category in SQLite (`apps/pos/src/api/productApi.ts:69-88`, `apps/pos/src/lib/db/repositories/productRepository.ts:57-100`).
3. **Lower likelihood: virtualizer keeps stale scroll/range state across category changes.** Category changes alter `filteredProducts.length` and `rowCount`, but `ProductGrid` never resets scroll position or forces a virtualizer re-measure; if the scroll element disappears on an empty filtered result, the virtualizer also temporarily loses its scroll element (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:120-153`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:300-353`).

Recommended fix: make the product store expose one canonical "display catalog" path. For Menu-enabled tenants, both initial load and post-sync refresh should hydrate from the active-menu cache, not from `products`. Then add a narrow ProductGrid guard that clears invalid selected categories and resets/re-measures the virtualizer on filter changes.

# Code Trace

## Filter Selector

`productStore` stores only canonical `products`, `categories`, loading/error flags, `lastFetched`, and `companyConfig`; it does not store an active category or a filtered product subset (`apps/pos/src/stores/productStore.ts:11-18`). The initial state is empty arrays for `products` and `categories` (`apps/pos/src/stores/productStore.ts:29-35`).

Category derivation is not destructive. `extractCategories()` builds a `Set`, then returns `Array.from(uniqueCategories).sort()`; it sorts a new array, not the store's `products` array (`apps/pos/src/stores/productStore.ts:38-46`).

The active grid filter is component-local:

- `selectedCategory` is `useState<string | null>(null)` in `ProductGrid` (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:67-71`).
- The "all categories" button clears it with `setSelectedCategory(null)` (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:251-263`).
- Each category pill sets it with `setSelectedCategory(category)` (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:264-280`).

The displayed list is derived in two memoized steps:

- `sortedProducts` clones `products` with `[...products]` before calling mutating `sort()`, so it does not sort the Zustand array in place (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:88-107`).
- `filteredProducts` starts from `sortedProducts` and applies category, in-stock, and search filters with `filter()` (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:120-142`).

Conclusion: no `splice()`, in-place filter, or canonical-array mutation appears in the selector path. The memo dependencies are also reasonable for the local filter chain: `sortedProducts`, `selectedCategory`, `searchQuery`, and `inStockOnly` (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:142`).

## Virtualizer

The active HomePage imports the organism grid, not the legacy `components/pos/ProductGrid.tsx` (`apps/pos/src/pages/HomePage.tsx:32`, `apps/pos/src/pages/HomePage.tsx:981-994`).

The organism grid virtualizes rows:

- `columns` is derived from `displayMode`, and `rowCount` is `Math.ceil(filteredProducts.length / columns)` (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:144-146`).
- `useVirtualizer()` receives `count: rowCount`, a scroll element from `scrollContainerRef.current`, fixed estimated row height, and overscan 3 (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:148-153`).
- Each virtual row slices `filteredProducts` by row index and column count (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:320-323`).
- Product cards are keyed by `product.id` inside each row (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:338-346`).

The virtualizer should tolerate normal N-to-M-to-N list changes, but this component gives it no explicit reset on filter changes. There is no effect that scrolls the grid back to top when `selectedCategory`, `searchQuery`, or `inStockOnly` changes, and no effect that calls `virtualizer.measure()` after `rowCount` changes. The empty-state branch also replaces the scroll container entirely when `filteredProducts.length === 0` (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:300-315`).

This is a credible rendering bug when the user is scrolled deep in a large category, switches to a smaller or empty category, then switches back. It is less convincing as the sole explanation for a plain top-of-list A -> B -> A click sequence, because the filter derivation itself should rebuild the A subset.

## Category State

HomePage reads `products`, `categories`, and `isLoading` from `useProductStore`, then passes them to `ProductGrid` (`apps/pos/src/pages/HomePage.tsx:83-89`, `apps/pos/src/pages/HomePage.tsx:981-994`). It does not own or persist the selected category.

The product store is a plain Zustand store created with `create<ProductStore>()`; it is not wrapped in Zustand `persist()` (`apps/pos/src/stores/productStore.ts:62`). Therefore the active category cannot be restored from persistent state, and stale persisted category state is not the bug.

The stale-state risk is local: `selectedCategory` can stay set while the source `products` and `categories` props are replaced underneath it. No code clears `selectedCategory` when `categories` changes and no longer contains the selected value (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:68`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:249-280`).

# Reproduction Recipe

## High-confidence Menu-cache reproduction

This sequence matches the code paths most likely to make products vanish after a category round-trip:

1. Use a Menu-enabled company. `fetchProductsFromAPI()` checks `hasModule(config, 'Menu')`; when true, it fetches `/active-menu` and flattens menu categories into `POSProduct[]` (`apps/pos/src/stores/productStore.ts:52-58`, `apps/pos/src/api/productApi.ts:52-55`, `apps/pos/src/api/productApi.ts:69-88`).
2. Open a shift. HomePage calls `fetchProducts()` once the shift exists (`apps/pos/src/pages/HomePage.tsx:302-312`).
3. ProductStore may first render cached generic products from SQLite, then fetch the API catalog. The SQLite-first path reads `getAllProducts()` and sets `products` and `categories`; the API path later sets fresh flattened active-menu products and categories (`apps/pos/src/stores/productStore.ts:73-87`, `apps/pos/src/stores/productStore.ts:104-127`).
4. Click category A. `selectedCategory` changes from `null` to A; `filteredProducts` becomes `sortedProducts.filter((p) => p.category === A)` (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:123-125`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:267`).
5. Click category B. `selectedCategory` changes to B and the same memoized filter recomputes against the current `products` prop (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:120-142`).
6. During or between those clicks, a sync tick runs. `runFullSync()` pulls generic products, then active menu, and `SyncScheduler` calls `useProductStore.getState().refreshFromSQLite()` afterward (`apps/pos/src/lib/sync/syncService.ts:1212-1219`, `apps/pos/src/lib/sync/syncScheduler.ts:72-78`).
7. `refreshFromSQLite()` always calls `getAllProducts()` and diffs that generic product table into the in-memory product store (`apps/pos/src/stores/productStore.ts:161-170`). It does not branch back through `fetchActiveMenu()` for Menu tenants.
8. Click category A again. `selectedCategory` returns to A, but the `products` prop may now be generic SQLite products whose `category` values are not active-menu category names or have lost multi-category membership. The filter returns an empty A subset, so the grid shows "not found" (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:300-309`).

Expected JS state mutations:

- Initial load: `productStore.products = flattenMenuToProducts(activeMenu)` and `productStore.categories = extractCategories(flattenedMenuProducts)` (`apps/pos/src/api/productApi.ts:69-88`, `apps/pos/src/stores/productStore.ts:123-126`).
- Category A click: `selectedCategory: null -> 'A'`; `filteredProducts = A menu rows`.
- Category B click: `selectedCategory: 'A' -> 'B'`; `filteredProducts = B menu rows`.
- Sync refresh: `productStore.products` is replaced by `getAllProducts()` results from the generic `products` table (`apps/pos/src/lib/db/repositories/productRepository.ts:39-41`, `apps/pos/src/stores/productStore.ts:166-170`).
- Category A click: `selectedCategory: 'B' -> 'A'`; `filteredProducts = genericProducts.filter((p) => p.category === 'A')`, which can be empty.

## Virtualizer-specific reproduction

This is the rendering-only recipe for the lower-likelihood virtualizer issue:

1. Open POS and wait for products to render.
2. Click a large category A.
3. Scroll the product grid deeply.
4. Click a much smaller category B or a category that combines with search/in-stock filters to produce zero results.
5. Click category A again.

State changes:

- Category clicks only update `selectedCategory` (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:251-280`).
- `rowCount` shrinks and expands with `filteredProducts.length` (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:144-146`).
- The scroll container's `scrollTop` is not reset, and the virtualizer is not explicitly remeasured (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:148-153`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:311-320`).

# Top 3 Candidate Root Causes

## 1. Wrong post-sync source for Menu catalog

Lines:

- Menu-aware fetch path: `apps/pos/src/stores/productStore.ts:52-58`
- HomePage fetch trigger: `apps/pos/src/pages/HomePage.tsx:302-312`
- Sync pulls products and active menu: `apps/pos/src/lib/sync/syncService.ts:1212-1219`
- Scheduler refreshes productStore from SQLite: `apps/pos/src/lib/sync/syncScheduler.ts:72-78`
- `refreshFromSQLite()` always reads generic products: `apps/pos/src/stores/productStore.ts:161-170`

Why likely:

- The runtime display source changes from active menu to generic products without a user-visible reset.
- The selected category is local and remains set across that replacement.
- This explains products being present in a category, then disappearing after category interaction when a background sync lands.

## 2. Product SQLite schema collapses multi-category menu rows

Lines:

- Active menu flatten uses `item.sellable_id` as product `id` and category name as `category`: `apps/pos/src/api/productApi.ts:69-88`
- Generic product upsert conflicts on `id`: `apps/pos/src/lib/db/repositories/productRepository.ts:57-100`
- Generic product read returns one row per id ordered by name: `apps/pos/src/lib/db/repositories/productRepository.ts:39-41`
- Active menu cache preserves category items separately by menu item id and `menu_category_id`: `apps/pos/src/lib/db/repositories/menuRepository.ts:105-133`, `apps/pos/src/lib/db/repositories/menuRepository.ts:135-160`

Why likely:

- A menu item and a product are not the same display entity. Active menu rows can have category-specific ordering, price, availability, and category membership.
- The generic `products` table has a single `category` field (`apps/pos/src/lib/db/repositories/productRepository.ts:5-17`), so it cannot safely replace active-menu rows.
- If the same sellable appears in category A and B, the generic cache can only remember one category for that id.

## 3. Virtualizer does not reset on filter changes

Lines:

- Filter derivation: `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:120-142`
- Row count and virtualizer setup: `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:144-153`
- Empty state unmounts the scroll container: `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:300-315`
- Virtual row slicing/rendering: `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:320-348`

Why plausible:

- Filter changes alter count and total scrollable size.
- The component does not reset `scrollTop` on category/search/stock filter changes.
- The component does not call `virtualizer.measure()` after count changes.
- The scroll container is not stable across empty/non-empty filtered states.

# Fix Sketch

Primary fix:

1. In `productStore`, make `refreshFromSQLite()` source-aware. If `companyConfig` has the Menu module enabled, read the active menu cache via `getActiveMenu()` and `flattenMenuToProducts()` instead of `getAllProducts()`. Otherwise keep the current generic product-table path.
2. In `SyncScheduler`, after `runFullSync()` pulls active menu, refresh the in-memory product store from the same source used by `fetchProducts()`. Avoid replacing an active-menu grid with generic products.
3. Do not use the generic `products` table as the canonical display cache for Menu categories. The active menu tables already preserve category ids, item ids, item ordering, and many-to-one sellable membership.

Defensive UI fix:

1. Add an effect in `ProductGrid`: if `selectedCategory` is non-null and `categories` no longer includes it, clear it.
2. Add an effect on `selectedCategory`, `searchQuery`, `inStockOnly`, `displayMode`, and `rowCount` that scrolls `product-grid-scroll` to top and calls `virtualizer.measure()`.
3. Keep the scroll container mounted and render empty state inside it, so `scrollContainerRef.current` stays stable.

# Regression Test Sketch

## Store/sync regression

Test name:
`productStore refreshFromSQLite keeps active-menu categories for Menu tenants`

Assertions:

- Seed `companyConfig` with Menu enabled.
- Mock cached active menu with category A and B rows.
- Mock generic `getAllProducts()` with products whose categories do not match active-menu categories.
- Call `refreshFromSQLite()`.
- Assert `useProductStore.getState().products` comes from `flattenMenuToProducts(activeMenu)`.
- Assert category A products remain present after the refresh.

This test fails on the current code because `refreshFromSQLite()` unconditionally uses `getAllProducts()`.

## ProductGrid filter regression

Test name:
`ProductGrid preserves category A products after A to B to A switch`

Assertions:

- Render products from categories A and B.
- Click A and assert only A cards are visible.
- Click B and assert only B cards are visible.
- Click A again and assert A cards are visible again.
- Rerender with a new `products` prop that still includes A, and assert the selected category still displays A.

This locks the non-mutating filter behavior and catches accidental selector regressions.

## Virtualizer/browser regression

Test name:
`catalog virtualizer re-renders after deep scroll and category round trip`

Assertions:

- Open POS home and wait for products.
- Click a large category A.
- Scroll `[data-testid="product-grid-scroll"]` deep down.
- Click a smaller category B.
- Click A again.
- Assert at least one A product card is rendered and the grid does not show a blank non-empty viewport.

This should cover the scroll/range invalidation path that jsdom unit tests are unlikely to catch.
