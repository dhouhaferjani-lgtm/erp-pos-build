---
title: POS Catalog State Deep Dive — Category Switch Bug
date: 2026-04-30
auditor: Claude Opus 4.7 (1M context)
scope: apps/pos/src/components/organisms/ProductGrid + apps/pos/src/stores/productStore
type: bug investigation, no-code (read-only)
---

# Executive Summary

**Bug under investigation.** In the Tauri POS desktop app, products visible in category A disappear after the user navigates to category B and back to A. The product list cache (`productStore.products`) does not change during this flow — the bug lives in the component layer.

## Top 3 Candidate Root Causes (ranked)

1. **Virtualizer scroll-element unmount / remount when `filteredProducts.length` transiently passes through 0.** The scroll container at `ProductGrid.tsx:312` is rendered conditionally (`filteredProducts.length === 0 ? <empty-state> : <scroll-container>`). If a search query, in-stock toggle, or any other filter combined with the new category produces 0 results even briefly, `scrollContainerRef.current` becomes `null`. When the user switches back to A and the container remounts, the virtualizer keeps a stale internal element reference and never re-attaches its scroll/resize observers — `getVirtualItems()` returns `[]`, the row container has the right `getTotalSize()` height, but no rows render. **(This is the highest-likelihood deterministic root cause and the one most consistent with the user-visible symptom of "products gone".)**
2. **Stale virtualizer range when `count` shrinks then grows without a scroll event.** When the user scrolls deep into A (e.g., row 25, scrollTop ≈ 2000 px), then switches to a small B, the virtualizer's range is recomputed only on the next scroll/resize event. With small `overscan: 3` (line 152) and the row container scrollTop clamped by the browser, the cached range from B (e.g., `[0, 3]`) can persist when count grows again — visible items are clipped because `getVirtualItems()` returns 3 rows starting at index 0, with no upward overscan. The row container's `getTotalSize()` still reflects A's full height, so the user sees a tall scroll area with the first ~3 rows visible at top *or* an apparently empty area depending on where the cached range sits.
3. **Background API sync replacing `products` with category strings that no longer match the captured `selectedCategory`.** `fetchProducts` (productStore.ts:65–158) runs a background `doApiFetch()` whenever local SQLite has data. The background fetch can land between A→B and B→A. The merged products array is replaced (productStore.ts:121–127) and `extractCategories` is recomputed. If the API normalises category strings (whitespace, casing) differently from SQLite, the local `selectedCategory = "Drinks"` no longer matches any product's `category`, so `filteredProducts` is empty and the empty-state renders. Less likely to be the root cause because it requires specific timing and a server-side normalisation diff, but easy to overlook.

## Recommended Fix

**Reset scroll position and force a virtualizer re-measure whenever `selectedCategory`, `searchQuery`, or `inStockOnly` changes.** This kills causes (1) and (2) at once and is conservative enough to ship in a hotfix.

Concretely (sketch only — not committing code):

```ts
// In ProductGrid.tsx, after the virtualizer is created
useEffect(() => {
  scrollContainerRef.current?.scrollTo({ top: 0 });
  virtualizer.measure();
}, [selectedCategory, searchQuery, inStockOnly]);
```

Plus a defensive change: do **not** unmount the scroll container on empty results. Render the empty state *inside* the same `ref`'d scroll container (or render both, with the empty-state overlaid). This guarantees `scrollContainerRef.current` is never transiently `null`, eliminating cause (1).

---

# Code Trace

## Filter selector (productStore + ProductGrid)

**No filter lives in the store.** `productStore.ts:11-18` exposes only `products`, `categories`, `isLoading`, `error`, `lastFetched`, `companyConfig`. There is no `selectedCategory` or filtered selector.

The active category lives in **component-local state** in `ProductGrid.tsx:68`:
```ts
const [selectedCategory, setSelectedCategory] = useState<string | null>(null);
```

The displayed list is derived in two `useMemo` chains:

`ProductGrid.tsx:89-107` — `sortedProducts`:
```ts
const base = [...products];        // shallow copy ✓ (does not mutate prop)
return base.sort((a, b) => ...);   // sort mutates the copy, not source ✓
```
This is **safe.** `[...products]` is a fresh array; `.sort()` mutates the new array. The source `products` reference passed from the store is untouched.

`ProductGrid.tsx:120-142` — `filteredProducts`:
```ts
let filtered = sortedProducts;
if (selectedCategory) filtered = filtered.filter((p) => p.category === selectedCategory);
if (inStockOnly)      filtered = filtered.filter((p) => p.stock_quantity > 0);
if (searchQuery.trim()) filtered = filtered.filter(...);
return filtered;
```
`Array.filter` returns a new array — no mutation. **Memo dependencies are correct** (`[sortedProducts, selectedCategory, searchQuery, inStockOnly]`). When the user clicks a category, `selectedCategory` changes, the memo invalidates, and a fresh filtered list is produced.

**Conclusion: the filter selector itself is sound.** The bug is *not* a mutation bug or a memoization-key bug.

## Virtualizer (`@tanstack/react-virtual` ^3.13.23)

`ProductGrid.tsx:148-153`:
```ts
const virtualizer = useVirtualizer({
  count: rowCount,                                  // depends on filteredProducts.length
  getScrollElement: () => scrollContainerRef.current,
  estimateSize: () => rowHeight + GAP,              // constant per displayMode
  overscan: 3,
});
```

Two observations:

- **`getScrollElement` is closure over a `useRef`.** The arrow function is recreated each render but always reads the same ref. As long as the scroll container DOM node persists, the reference is stable. The danger is when the scroll container *unmounts*.

- **Conditional render of the scroll container** (`ProductGrid.tsx:300-353`):
  ```tsx
  {filteredProducts.length === 0 ? (
    <div>...products.notFound...</div>
  ) : (
    <div ref={scrollContainerRef} data-testid="product-grid-scroll" ...>
      <div style={{ height: `${virtualizer.getTotalSize()}px` }}>
        {virtualizer.getVirtualItems().map((virtualRow) => { ... })}
      </div>
    </div>
  )}
  ```
  When `filteredProducts.length === 0`, the entire ref'd `<div>` unmounts. `scrollContainerRef.current` becomes `null`. When it remounts, the new DOM node is attached to the ref. **react-virtual v3 attaches its scroll/resize listeners imperatively via `getScrollElement()` and an internal observer.** When the element reference flips `null → element`, the observer needs to be re-attached, but the virtualizer instance from the prior render is still the *same* object (the hook returns the cached instance). There is a known fragility here: if the listener attachment is gated on the first non-null read, subsequent flips can be missed without an explicit `virtualizer.measure()` call.

  Independently of the precise library bug, the assumption that the scroll container is always mounted is unsafe in this code. The user's reproducible symptom (products go missing on A→B→A) implies that *something* about the second render of A diverges from the first. The most parsimonious explanation is a transient empty state along the path.

- **No `virtualizer.measure()` is ever called.** There is no `useEffect` that resets scroll on filter/category change. Combined with `overscan: 3`, this means the visible range can stay anchored to the previous filter's scroll position even after the row count changes.

## Category state location

- **Active category:** `ProductGrid.tsx:68` — local `useState<string | null>(null)`. **Not** persisted, **not** in Zustand.
- **Available categories:** `productStore.ts:13` — derived in store via `extractCategories(products)` (productStore.ts:38-46), assigned in `set({ ..., categories })` blocks at lines 82-87, 122-127, 170-171.
- **Persistence:** `useProductStore` is **not** wrapped in `persist()` — see `create<ProductStore>()((set, get) => ({ ... }))` at line 62. So categories live in memory only.

There is no stale-closure risk in the category handling itself: `setSelectedCategory(category)` is idiomatic and the handler closes over the current setter via `useState`.

**However:** the local `selectedCategory` is *not invalidated* when `categories` changes. If a background sync replaces `products` (productStore.ts:122-127) with new strings, and the user's `selectedCategory` no longer matches any product's `category`, the filter at line 124 silently returns `[]`. There is no guard like:
```ts
if (selectedCategory && !categories.includes(selectedCategory)) setSelectedCategory(null);
```

## Background sync — relevant timing

`productStore.ts:65-158`:
- Step 1 (lines 73-91): synchronous SQLite read → `set({ products, categories })`.
- Step 3 (lines 105-147): async API fetch via `doApiFetch()`. Runs in background when local data exists (line 149-154); awaited only on first launch (line 156-158).
- The API result is diff'd (line 121) and may replace `products` and `categories` while the user is interacting.

The store is keyed off `companyId`. There is no per-render guard preventing the products array from changing identity mid-interaction.

---

# Reproduction Recipe

**Setup.** A POS company seeded with at least 2 categories where category A has ≥ 30 items and category B has either ≤ 5 items *or* triggers an empty filtered result when combined with another active filter. Run the Tauri POS app to the home screen with shift open. SQLite cache populated.

**Steps and inferred state at each click:**

1. **Initial mount.** `useProductStore.fetchProducts()` runs. `products = [...100 items]`, `categories = ['A', 'B', 'C']`. `ProductGrid` renders with `selectedCategory = null`, `searchQuery = ''`, `inStockOnly = false`. `filteredProducts.length = 100`. Virtualizer attaches listeners to the scroll container (ref attached).

2. **Click "Category A".** `setSelectedCategory('A')`. Re-render. `filteredProducts.length = 50`. Scroll container DOM node is the **same** instance (no unmount/remount because `length > 0` both before and after). Virtualizer's `count` updates from `Math.ceil(100/cols)` to `Math.ceil(50/cols)`. Virtualizer recomputes range on the next scroll/resize event.

3. **Scroll down within Category A.** User scrolls to ~row 25 of 50. `scrollContainerRef.current.scrollTop ≈ 2000 px`. Virtualizer range `[22, 32]` approximately (with `overscan: 3`). Rows render correctly. ✓

4. **Click "Category B".** `setSelectedCategory('B')`. Re-render. `filteredProducts.length = 3` (or 0 — see branch below). `rowCount = 1`. `getTotalSize() ≈ 200 px`. The scroll container is now much shorter than its `scrollTop = 2000`.
    - Browser **clamps** `scrollTop` to `maxScrollTop = max(0, totalSize - clientHeight)`, which is likely `0` (content fits in viewport). A native `scroll` event fires with the clamped value.
    - Virtualizer picks up the scroll event, recomputes range to `[0, 3]`. ✓

   **Branch B1 — Category B has 0 visible items** (e.g., search query is non-empty and matches nothing in B, or every B product has `stock_quantity = 0` and `inStockOnly = true`):
    - `filteredProducts.length === 0`. Conditional at `ProductGrid.tsx:300` renders the empty state. The `ref={scrollContainerRef}` div **unmounts**.
    - `scrollContainerRef.current = null`.
    - The virtualizer's `getScrollElement()` returns `null`. Internal element ref nulled. Listeners detached.

5. **Click "Category A" again.** `setSelectedCategory('A')`. Re-render. `filteredProducts.length = 50` again. The scroll container **remounts** (new DOM node). `ref` callback assigns the new node to `scrollContainerRef.current`.
    - Virtualizer instance from the previous render is still the same JS object (cached by React). Its internal `_scrollElement` may still reference the unmounted node *or* be null.
    - `getVirtualItems()` is called during render. If the virtualizer hasn't observed the new element yet, it returns the cached range from when the element was null/unmounted, which is `[]`.
    - The user sees: a `getTotalSize()`-tall transparent div (correct height for 50 items) but **no row content**. Hence "products gone".

   **Branch B2 — Category B has products but the user scrolls deep, no empty state ever renders:**
    - When the user clicks A again, the virtualizer's range is `[0, 3]` (cached from B). With count back at `Math.ceil(50/cols) ≈ 10` and scrollTop near 0, react-virtual v3 *should* re-derive the range on the next render. But the re-derive happens via a `requestMeasure` flush that requires a scroll/resize event or an explicit `measure()` call. Without one, the user can see only the first 3 rows (or fewer) until they scroll.

**The user-visible symptom "products gone" matches Branch B1 exactly** (zero rows render despite a non-empty filtered list) and partially matches Branch B2 (only the top 3 rows render, looks like an empty mid-scroll area).

---

# Regression Test Sketch

The existing test `ProductGrid.test.tsx` mocks `@tanstack/react-virtual` to bypass jsdom's missing layout (lines 61-72), which means **a unit test alone cannot reproduce the virtualizer-attachment bug** — that requires a real browser. So the test plan needs two layers:

## 1. Unit test (catches Cause 3 + the lack of stale-category guard)

**File:** `apps/pos/src/components/organisms/ProductGrid/__tests__/ProductGrid.test.tsx`

**Test name:** `'preserves visible products after A → B → A category switch'`

**Assertions:**

```ts
it('preserves visible products after A → B → A category switch', () => {
  const products = [
    makeProduct({ id: 'a1', name: 'Alpha', category: 'A', ... }),
    makeProduct({ id: 'a2', name: 'Alpha2', category: 'A', ... }),
    makeProduct({ id: 'b1', name: 'Beta', category: 'B', ... }),
  ];

  const { rerender } = render(
    <ProductGrid products={products} categories={['A', 'B']} ... />
  );

  // Click A — Alpha and Alpha2 visible
  fireEvent.click(screen.getByRole('button', { name: /^A/ }));
  expect(screen.getByTestId('product-a1')).toBeInTheDocument();
  expect(screen.getByTestId('product-a2')).toBeInTheDocument();

  // Click B — only Beta visible
  fireEvent.click(screen.getByRole('button', { name: /^B/ }));
  expect(screen.queryByTestId('product-a1')).not.toBeInTheDocument();
  expect(screen.getByTestId('product-b1')).toBeInTheDocument();

  // Click A again — Alpha and Alpha2 must be back
  fireEvent.click(screen.getByRole('button', { name: /^A/ }));
  expect(screen.getByTestId('product-a1')).toBeInTheDocument(); // FAILS if filter mutates
  expect(screen.getByTestId('product-a2')).toBeInTheDocument();
});
```

Because the existing virtualizer mock returns every row in the `count`, this test would catch any *logic* regression in the filter chain (e.g., if a refactor introduced an in-place sort that mutates `products`). It does **not** catch the live virtualizer bug.

## 2. Browser/E2E test (catches Cause 1 + Cause 2 — the real bug)

**File:** new Playwright/MCP test under `apps/pos/tests/e2e/` (or extend an existing POS smoke test).

**Test name:** `'category-switch: products visible after A → B → A round-trip'`

**Assertions:**

```ts
test('products remain visible after category round-trip', async ({ page }) => {
  await loginAndOpenShift(page);
  // Wait for product grid
  await page.waitForSelector('[data-testid="product-grid-scroll"]');

  // Click category A
  await page.getByRole('button', { name: /^Category A/ }).click();
  const aCount = await page.locator('[data-testid^="product-"]').count();
  expect(aCount).toBeGreaterThan(0);

  // Scroll to row 20+
  await page.locator('[data-testid="product-grid-scroll"]').evaluate(
    (el) => el.scrollTo({ top: 2000 })
  );

  // Click category B (small)
  await page.getByRole('button', { name: /^Category B/ }).click();

  // Click category A again
  await page.getByRole('button', { name: /^Category A/ }).click();

  // Critical assertion: at least one product card must be in the DOM
  const aCountAfter = await page.locator('[data-testid^="product-"]').count();
  expect(aCountAfter).toBeGreaterThan(0); // FAILS on the bug — virtualizer renders 0 rows
});
```

This test fails on current `main` (per the user's repro) and passes once Cause 1 is fixed by either (a) keeping the scroll container mounted on empty filtered results, or (b) calling `virtualizer.measure()` + `scrollTo({ top: 0 })` in a `useEffect([selectedCategory, searchQuery, inStockOnly])`.

---

# Files & Lines Cited

- `apps/pos/src/stores/productStore.ts:13, 38-46, 62, 65-158, 122-127, 170-171` — store shape, `extractCategories`, `fetchProducts`, background sync timing.
- `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:68, 79, 89-107, 110-118, 120-142, 144-153, 300-353` — local category state, sort/filter selectors, virtualizer setup, conditional scroll-container render.
- `apps/pos/src/lib/sync/productDiff.ts:22-36` — diff logic that preserves stable references when fields match.
- `apps/pos/src/hooks/useMostSoldCounts.ts:25-65` — sales counts hook (creates new `Map()` on `enabled`/`companyId` change; minor memo thrash, not the bug).
- `apps/pos/src/components/organisms/ProductGrid/__tests__/ProductGrid.test.tsx:61-72` — virtualizer mock that hides this class of bug from unit tests.
- `apps/pos/package.json` — `@tanstack/react-virtual ^3.13.23`.

# Out of Scope but Worth Noting

- The `selectedCategory` should be auto-cleared when `categories` no longer contains it. One-line guard: `useEffect(() => { if (selectedCategory && !categories.includes(selectedCategory)) setSelectedCategory(null); }, [categories, selectedCategory])`. Defensive against Cause 3.
- `useMostSoldCounts` creates a fresh `new Map()` on every `enabled`/`companyId` change (line 33) even when sortMode is `'default'`. This invalidates `sortedProducts` memo on toggle. Minor perf nit, not the bug.
- The unit test's virtualizer mock at lines 61-72 returns *every* row regardless of scroll. This is sensible for jsdom but means PRs that touch virtualizer behaviour need an E2E counterpart. Consider documenting this gap in the test file's header.
