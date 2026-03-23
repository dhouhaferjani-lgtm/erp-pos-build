# POS Performance Overhaul — Design Spec

> **Date:** 2026-03-23
> **Branch:** `feat/pos-performance`
> **Approach:** A — SQLite-First + Incremental Hardening (stepping stone toward full Offline-First rewrite)
> **Target:** 1,000–5,000+ products with images, snappy on low-end Windows POS hardware

---

## Problem Statement

The POS app feels sluggish when filtering products, navigating between pages, and loading after startup. Root causes:

1. **Fetch-then-render** — products load from API on shift open; the user stares at a spinner for 300–2000ms.
2. **No memoization** — every cart update or filter change re-renders all ProductCards.
3. **No virtualization** — all products rendered into the DOM upfront; 5K cards will choke low-end hardware.
4. **No image caching** — product images re-download on every app restart via naive `<img src>`.
5. **SyncScheduler never activated** — background sync infrastructure exists but is dead code. Products only refresh on shift open.

## Design Principles

- **SQLite is the read source.** The user never waits for a network call to see products.
- **API syncs in the background.** Network calls happen silently; UI updates reactively for changed items only.
- **Each unit is independently shippable and testable.** Memoization can ship alone. Virtualization can ship alone. No big-bang.
- **Design toward Approach C.** Every choice should make the eventual full offline-first rewrite easier, not harder.

---

## Unit 1: SQLite-First Product Loading

### Current Flow
```
Shift opens → API call (300-2000ms) → render products
                ↓ (async, fire-and-forget)
              SQLite upsert
```

### New Flow
```
Shift opens → SQLite read (<50ms) → render products instantly
                ↓ (parallel, non-blocking)
              API delta sync → update SQLite → diff against in-memory →
              re-render ONLY changed products
```

### Changes

**`productStore.fetchProducts()`** rewritten:

1. Read from SQLite via `getAllProducts(db)`. If rows exist, set products in store immediately and mark `isLoading = false`.
2. In parallel (non-blocking), call the API for fresh data.
3. On API success: upsert into SQLite, then **diff** against in-memory products.
4. If diff is empty → no state update, no re-render.
5. If diff has changes → update only the changed product entries in the store, triggering minimal re-renders (enabled by Unit 2 memoization).
6. **First launch (empty SQLite):** Fall back to awaiting the API call as a one-time bootstrap. Write to SQLite, then render.

### Diff Strategy

Compare by `id` + shallow equality on value fields (`name`, `sale_price`, `stock_quantity`, `category`, `image_url`, `tax_rate`, `barcode`). `POSProduct` does not have an `updated_at` field, and the SQLite `updated_at` column reflects local write time, not server modification time. Field-by-field comparison avoids a backend change and is reliable.

When changes are detected, produce a new products array where unchanged items retain their original object references and only changed items are new objects. This ensures `React.memo` on ProductCard skips re-render for unchanged products.

### F&B (Menu) Mode

The current `fetchProducts()` has two branches: retail (`GET /products`) and F&B (`GET /active-menu` + `flattenMenuToProducts()`). Both paths must go through SQLite-first:

1. **Retail mode:** `getAllProducts(db)` → render → background sync via `pullProducts()`.
2. **F&B mode:** `getAllProducts(db)` → render → background call to `fetchActiveMenu()` → `flattenMenuToProducts()` → upsert into SQLite → diff and update.

The SQLite `products` table stores the flattened result regardless of source. This means F&B menu items are stored as regular `POSProduct` rows after flattening. The `modifier_groups` field (present only in F&B) must be added to the SQLite schema as a JSON column so it survives the SQLite round-trip.

Delta sync via `updated_since` does **not** apply to the `/active-menu` endpoint. F&B mode always fetches the full active menu and diffs locally. This is acceptable because active menus are typically small (<200 items).

### Files Affected
- `apps/pos/src/stores/productStore.ts` — rewrite `fetchProducts()` for both retail and F&B paths
- `apps/pos/src/lib/db/repositories/productRepository.ts` — add `modifier_groups` JSON column, ensure `getAllProducts()` returns all fields including modifiers
- `apps/pos/src/lib/db/migrations.ts` — add migration for `modifier_groups` column on `products` table

---

## Unit 2: Component Memoization

### Changes

1. **`ProductCard`** — wrap with `React.memo`. Cards only re-render when their own product data or callbacks change.

2. **Filtered product list** — wrap the filter/search computation in `useMemo` keyed on `[products, searchQuery, selectedCategory]`. Currently recomputes on every render.

3. **Stable callbacks** — `onAddToCart` and similar handlers wrapped in `useCallback` so they don't invalidate ProductCard memos on every render.

### Impact

Highest-impact, lowest-effort change. Even without virtualization, memoization alone makes filtering feel instant for ~1K products. Combined with the diff strategy in Unit 1, a background sync that changes 0 products triggers 0 re-renders.

### Files Affected
- `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx` — add `React.memo`
- `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx` — add `useMemo` for filtering, `useCallback` for handlers
- `apps/pos/src/pages/HomePage.tsx` — stabilize callbacks passed to ProductGrid

---

## Unit 3: Virtualized Product Grid

### Problem

Rendering 5K ProductCards into the DOM upfront. Even with memoization, the initial mount and layout calculation is expensive on low-end Windows POS hardware (Celeron/4GB RAM).

### Solution

Replace the current CSS grid with a virtualized grid using `@tanstack/react-virtual`.

- Only render products visible in the viewport + ~2 rows overscan buffer.
- 4 columns x 6 visible rows = **24 cards in DOM** instead of 5,000.
- Scroll performance stays constant regardless of product count.
- Search/filter updates the virtualized list's data array — the virtualizer handles mount/unmount.

### Library Choice

`@tanstack/react-virtual` — headless (no forced wrapper divs), works with existing Tailwind grid, and the TanStack ecosystem is already in the project (Query v5). Lighter than `react-window`.

### Implementation Notes

- The current ProductGrid has a CSS grid with `overflow-y: auto`. Replace the scrolling container with a virtualized one.
- ProductCard component stays identical — it just gets mounted/unmounted as the user scrolls.
- Measure row height once (ProductCard is fixed-height), feed to virtualizer.
- Category tabs and search bar remain outside the virtualized area (sticky header).

### Files Affected
- `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx` — replace with virtualized implementation
- `package.json` — add `@tanstack/react-virtual`

---

## Unit 4: Image Caching via Tauri Filesystem

### Problem

Product images loaded via `<img src={url}>` — every app restart re-downloads all images. With 5K products, that's 5K HTTP requests on startup. Tauri's WebView doesn't guarantee persistent HTTP cache.

### Solution

Download images once to Tauri's app data directory, serve from disk thereafter.

### Flow
```
ProductCard renders → check in-memory URL map for local path
  → HIT:  <img src="asset://localhost/images/products/abc123.jpg">
  → MISS: show color placeholder → queue background download →
          save to app_data/images/products/{id}.{ext} →
          update URL map → card re-renders with local image
```

### Image Manifest (SQLite)

New `product_images` table:
```sql
CREATE TABLE product_images (
  product_id TEXT PRIMARY KEY,
  remote_url TEXT NOT NULL,
  local_path TEXT NOT NULL,
  etag TEXT,
  downloaded_at TEXT NOT NULL
);
```

On sync, compare remote URLs and ETags to detect new/changed images.

### Background Download Queue

- Process downloads in batches of 10 concurrent requests during background sync, not during render.
- **Priority:** Download images for products currently visible in the viewport first (tied to virtualization scroll position), then backfill the rest.
- User never waits for image downloads.

### Serving

Tauri 2 `asset://localhost/` protocol serves local files to the WebView. No base64 encoding, no blob URLs.

**Required Tauri config changes:**
- Enable `asset` protocol scope in `tauri.conf.json` → `app.security.assetProtocol.scope` pointing to the app data images directory.
- Add `fs` plugin permissions in `capabilities/default.json` to allow read/write to the images subdirectory.
- These are config-only changes, no Rust code needed.

### Download Queue Debouncing

In a virtualized list, rapid scrolling mounts/unmounts cards quickly. The `useProductImage` hook must **not** queue a download on every mount. Instead: the download queue accepts requests but deduplicates by `product_id`. Downloads are processed in priority order (currently visible products first). When a card unmounts before its download completes, the download finishes anyway (it's useful for future scrolling) but the component's state update is skipped (standard React cleanup pattern).

### Disk Budget

5K products x ~50KB average = ~250MB. Acceptable for POS hardware. Add cleanup for products removed from the catalog.

### Files Affected
- `apps/pos/src-tauri/tauri.conf.json` — enable `asset` protocol scope for images directory
- `apps/pos/src-tauri/capabilities/default.json` — add `fs` read/write permissions for images subdirectory
- `apps/pos/src/lib/db/migrations.ts` — add `product_images` table migration
- `apps/pos/src/lib/images/imageCache.ts` — new: download queue, manifest check, local path resolution
- `apps/pos/src/lib/images/useProductImage.ts` — new: hook that returns local path or placeholder, triggers background download on miss
- `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx` — use `useProductImage` hook instead of raw `image_url`
- `apps/pos/src/lib/sync/syncService.ts` — add image sync step to `runFullSync`

---

## Unit 5: SyncScheduler Activation + Delta Sync + Manual Sync Button

### SyncScheduler Activation

- Instantiate `SyncScheduler` in `terminalStore` after `seedOfflineHashChain` completes.
- Store the scheduler instance so it can be stopped on logout/terminal reset.
- The existing scheduler already has: 1-minute base interval, exponential backoff on failure, connectivity check.

### Delta Sync for Products

- `pullProducts()` in `syncService.ts` already supports `updated_since` — switch the product store to use the sync service instead of its own direct API call.
- On each sync cycle: fetch only products modified since last sync → upsert into SQLite → diff against in-memory store → update only changed entries.
- Sync with 0 changes = one lightweight API call + zero re-renders.

**Pagination for initial bootstrap:** `pullProducts()` currently fetches `per_page: 1000` in a single call. For 5K+ products on first sync (empty SQLite, no `updated_since`), this misses everything beyond page 1. Add a pagination loop: fetch page 1, check if there are more pages (via response meta or `data.length === per_page`), continue until all pages fetched. Subsequent delta syncs will typically return small result sets and won't need pagination.

### Focus-Recovery Sync

- Add a `visibilitychange` listener in AppShell.
- When app regains focus after being minimized, trigger `syncNow()` if last sync was >1 minute ago.
- Covers: cashier minimizes app → admin updates price → cashier returns → fresh data.

### Manual Sync Button

**Placement:** POS header bar (AppShell top bar), next to the connectivity indicator.

**Behavior:**
- Tap → `syncScheduler.syncNow()` → spinner on icon while syncing → checkmark flash on success.
- Disabled if already syncing (prevents double-trigger).
- Tooltip shows last sync time: "Last sync: 2 min ago".
- On error, icon briefly turns red with error in tooltip.

**No modal, no page navigation.** Cashier taps and keeps working. Product grid updates reactively as sync completes in background.

### Files Affected
- `apps/pos/src/stores/terminalStore.ts` — instantiate SyncScheduler after hash chain seed
- `apps/pos/src/components/AppShell.tsx` — add `visibilitychange` listener, render SyncButton
- `apps/pos/src/components/atoms/SyncButton/SyncButton.tsx` — new: manual sync trigger with status feedback
- `apps/pos/src/lib/sync/syncService.ts` — add pagination loop to `pullProducts`, ensure delta mode with `updated_since`
- `apps/pos/src/lib/sync/syncScheduler.ts` — existing file (lowercase), no changes needed to the class itself
- `apps/pos/src/stores/syncStore.ts` — existing file; modify to add a `scheduler` field and `triggerSync` action for the manual button

---

## Implementation Order

Units are ordered by dependency and impact:

| Order | Unit | Depends On | Rationale |
|-------|------|------------|-----------|
| 1 | Memoization (Unit 2) | Nothing | Lowest effort, highest immediate impact. Makes everything after it faster. |
| 2 | SQLite-first loading (Unit 1) | Unit 2 (for diff to be effective) | Eliminates the startup spinner. |
| 3 | SyncScheduler + delta sync + button (Unit 5) | Unit 1 (SQLite is the read source) | Keeps data fresh without user intervention. |
| 4 | Virtualized grid (Unit 3) | Unit 2 (memoized cards) | Handles the 5K product scale target. |
| 5 | Image caching (Unit 4) | Units 3 + 5 (virtualization for priority, sync for schedule) | Most complex unit; benefits from all prior work. |

## Testing Strategy

- **Unit 2:** Verify with React DevTools Profiler that ProductCard doesn't re-render when sibling state changes. Vitest snapshot tests for memoized components.
- **Unit 1:** Test SQLite-first path: mock API delay of 5s, verify products render instantly from SQLite. Test diff logic: verify unchanged products keep same object references.
- **Unit 5:** Test SyncScheduler lifecycle (start/stop/backoff). Test manual sync button states. Test visibilitychange triggers sync.
- **Unit 3:** Visual test with 5K mock products — verify scroll smoothness and correct rendering. Test that filter/search works with virtualized list.
- **Unit 4:** Test image download queue (batch concurrency, retry). Test cache hit/miss paths. Test disk cleanup.

## Future Direction (Approach C)

This design intentionally keeps SQLite as a cache layer managed by the product store. In Approach C, SQLite becomes the **single source of truth**:

- Stores subscribe to SQLite changes reactively (via a SQLite change notification layer).
- API calls are removed from stores entirely — only the sync layer talks to the server.
- All reads go through SQLite queries, not in-memory arrays.

Each unit in this spec moves us closer to that end state:
- Unit 1 makes SQLite the primary read source.
- Unit 5 centralizes API calls in the sync layer.
- Unit 4 adds the first file-system-level cache (pattern reusable for other assets).
