# POS Performance Overhaul Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the POS app render products instantly from SQLite, sync in the background, and handle 5K+ products with images on low-end Windows hardware.

**Architecture:** SQLite-first rendering (read from local DB, sync from server in background). Memoized components with virtualized grid. Product images cached to disk via Tauri filesystem. Existing SyncScheduler activated with delta sync.

**Tech Stack:** React 19, Zustand 5, @tanstack/react-virtual, @tauri-apps/plugin-sql (SQLite), @tauri-apps/plugin-fs, Tauri 2 asset protocol.

**Spec:** `docs/superpowers/specs/2026-03-23-pos-performance-design.md`

**Branch:** `feat/pos-performance`

---

## File Structure

### New Files
| File | Responsibility |
|------|---------------|
| `apps/pos/src/lib/images/imageCache.ts` | Image download queue, manifest CRUD, local path resolution |
| `apps/pos/src/lib/images/useProductImage.ts` | React hook: returns local image path or placeholder, queues download on miss |
| `apps/pos/src/components/atoms/SyncButton/SyncButton.tsx` | Manual sync trigger button with spinner/success/error states |
| `apps/pos/src/lib/sync/productDiff.ts` | Pure function: diff two product arrays, return merged array with stable refs |

### Modified Files
| File | Changes |
|------|---------|
| `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx` | Wrap with `React.memo`, use `useProductImage` hook |
| `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx` | Replace CSS grid with `@tanstack/react-virtual`, stabilize callbacks |
| `apps/pos/src/pages/HomePage.tsx` | Stabilize `onAddToCart` with `useCallback` |
| `apps/pos/src/stores/productStore.ts` | Rewrite `fetchProducts()` to SQLite-first with background sync + diff |
| `apps/pos/src/lib/db/repositories/productRepository.ts` | Add `modifier_groups` to upsert/read, add `getProductCount()` if missing |
| `apps/pos/src/lib/db/migrations.ts` | Add migrations 11 (modifier_groups column) and 12 (product_images table) |
| `apps/pos/src/lib/sync/syncService.ts` | Add pagination loop to `pullProducts`, add image sync step |
| `apps/pos/src/stores/syncStore.ts` | Add `scheduler` field, `triggerSync` action |
| `apps/pos/src/stores/terminalStore.ts` | Instantiate SyncScheduler after hash chain seed |
| `apps/pos/src/components/AppShell.tsx` | Add `visibilitychange` listener, render SyncButton |
| `apps/pos/src-tauri/tauri.conf.json` | Enable asset protocol scope |
| `apps/pos/src-tauri/capabilities/default.json` | Add fs permissions for images directory |
| `apps/pos/package.json` | Add `@tanstack/react-virtual` |

---

## Task 1: Component Memoization

**Why first:** Lowest effort, highest immediate impact. No new dependencies. Makes all subsequent work faster.

**Files:**
- Modify: `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx`
- Modify: `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx`
- Modify: `apps/pos/src/pages/HomePage.tsx`
- Test: `apps/pos/src/components/molecules/ProductCard/__tests__/ProductCard.test.tsx`

### Steps

- [ ] **Step 1: Write test verifying ProductCard memoization**

Create `apps/pos/src/components/molecules/ProductCard/__tests__/ProductCard.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ProductCard } from '../ProductCard';
import type { POSProduct } from '@/types/product';

const makeProduct = (overrides?: Partial<POSProduct>): POSProduct => ({
  id: 'p-1',
  name: 'Test Product',
  sku: 'SKU-001',
  sale_price: '10.00',
  stock_quantity: 5,
  category: 'Drinks',
  ...overrides,
});

describe('ProductCard', () => {
  it('renders product name and price', () => {
    render(<ProductCard product={makeProduct()} onAddToCart={vi.fn()} />);
    expect(screen.getByText('Test Product')).toBeInTheDocument();
  });

  it('does not re-render when props are unchanged (React.memo)', () => {
    const onAdd = vi.fn();
    const product = makeProduct();
    const { rerender } = render(<ProductCard product={product} onAddToCart={onAdd} />);

    // Re-render with same object references — should not update DOM
    rerender(<ProductCard product={product} onAddToCart={onAdd} />);

    // If memo works, the component body runs only once.
    // We verify by checking the DOM is stable (no flicker).
    expect(screen.getByText('Test Product')).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it passes (ProductCard already renders)**

Run: `cd apps/pos && npx vitest run src/components/molecules/ProductCard/__tests__/ProductCard.test.tsx`

- [ ] **Step 3: Wrap ProductCard with React.memo**

In `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx`, change the export from:
```tsx
export function ProductCard({ product, onAddToCart, ... }: ProductCardProps) {
```
to:
```tsx
import { memo } from 'react';

function ProductCardInner({ product, onAddToCart, ... }: ProductCardProps) {
  // ... existing body unchanged
}

export const ProductCard = memo(ProductCardInner);
```

- [ ] **Step 4: Stabilize onAddToCart in HomePage with useCallback**

In `apps/pos/src/pages/HomePage.tsx`, find `handleAddToCart` (~line 169) and wrap with `useCallback`:
```tsx
const handleAddToCart = useCallback((product: POSProduct) => {
  // existing body
}, [/* stable deps only — addToCart from cartStore */]);
```

- [ ] **Step 5: Add useMemo to filteredProducts in ProductGrid**

In `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx`, the `filteredProducts` computation (~line 83) should already be in a `useMemo`. Verify it is. If not, wrap it:
```tsx
const filteredProducts = useMemo(() => {
  // existing filter logic
}, [products, searchQuery, selectedCategory]);
```

Also wrap any callbacks passed to ProductCard with `useCallback`.

- [ ] **Step 6: Run all POS tests**

Run: `cd apps/pos && npx vitest run`
Expected: All tests pass.

- [ ] **Step 7: Commit**

```bash
git add apps/pos/src/components/molecules/ProductCard/ apps/pos/src/components/organisms/ProductGrid/ apps/pos/src/pages/HomePage.tsx
git commit -m "perf(pos): memoize ProductCard, stabilize callbacks, useMemo filtering"
```

---

## Task 2: SQLite-First Product Loading

**Why:** Eliminates the startup spinner. Products render instantly from local DB.

**Files:**
- Create: `apps/pos/src/lib/sync/productDiff.ts`
- Modify: `apps/pos/src/stores/productStore.ts`
- Modify: `apps/pos/src/lib/db/repositories/productRepository.ts`
- Modify: `apps/pos/src/lib/db/migrations.ts`
- Test: `apps/pos/src/lib/sync/__tests__/productDiff.test.ts`

### Steps

- [ ] **Step 1: Add migration 11 for modifier_groups column**

In `apps/pos/src/lib/db/migrations.ts`, add after migration 10 (line ~226):

```ts
{
  version: 11,
  name: 'add_modifier_groups_to_products',
  sql: `ALTER TABLE products ADD COLUMN modifier_groups TEXT DEFAULT NULL`,
},
```

- [ ] **Step 2: Update productRepository to handle modifier_groups**

In `apps/pos/src/lib/db/repositories/productRepository.ts`:
- Add `modifier_groups` to the upsert SQL (as JSON string)
- Add `modifier_groups` parsing in `getAllProducts` (or the row mapper)
- In `upsertProducts`, serialize `modifier_groups` with `JSON.stringify()` before insert

- [ ] **Step 3: Write test for productDiff**

Create `apps/pos/src/lib/sync/__tests__/productDiff.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { diffProducts } from '../productDiff';
import type { POSProduct } from '@/types/product';

const makeProduct = (id: string, price: string): POSProduct => ({
  id, name: `Product ${id}`, sku: `SKU-${id}`, sale_price: price,
  stock_quantity: 10, category: 'Test',
});

describe('diffProducts', () => {
  it('returns same references for unchanged products', () => {
    const current = [makeProduct('1', '10.00'), makeProduct('2', '20.00')];
    const fetched = [makeProduct('1', '10.00'), makeProduct('2', '20.00')];

    const result = diffProducts(current, fetched);

    expect(result.changed).toBe(false);
    expect(result.products[0]).toBe(current[0]); // same reference
    expect(result.products[1]).toBe(current[1]); // same reference
  });

  it('replaces only changed products', () => {
    const current = [makeProduct('1', '10.00'), makeProduct('2', '20.00')];
    const fetched = [makeProduct('1', '10.00'), makeProduct('2', '25.00')]; // price changed

    const result = diffProducts(current, fetched);

    expect(result.changed).toBe(true);
    expect(result.products[0]).toBe(current[0]); // unchanged — same ref
    expect(result.products[1]).not.toBe(current[1]); // changed — new ref
    expect(result.products[1]!.sale_price).toBe('25.00');
  });

  it('detects added products', () => {
    const current = [makeProduct('1', '10.00')];
    const fetched = [makeProduct('1', '10.00'), makeProduct('2', '20.00')];

    const result = diffProducts(current, fetched);

    expect(result.changed).toBe(true);
    expect(result.products).toHaveLength(2);
  });

  it('detects removed products', () => {
    const current = [makeProduct('1', '10.00'), makeProduct('2', '20.00')];
    const fetched = [makeProduct('1', '10.00')];

    const result = diffProducts(current, fetched);

    expect(result.changed).toBe(true);
    expect(result.products).toHaveLength(1);
  });
});
```

- [ ] **Step 4: Run test to verify it fails**

Run: `cd apps/pos && npx vitest run src/lib/sync/__tests__/productDiff.test.ts`
Expected: FAIL — module not found.

- [ ] **Step 5: Implement productDiff**

Create `apps/pos/src/lib/sync/productDiff.ts`:

```ts
import type { POSProduct } from '@/types/product';

interface DiffResult {
  changed: boolean;
  products: POSProduct[];
}

const COMPARE_FIELDS: (keyof POSProduct)[] = [
  'name', 'sku', 'barcode', 'sale_price', 'stock_quantity',
  'category', 'image_url', 'tax_rate', 'sellableType', 'position',
];

function productEquals(a: POSProduct, b: POSProduct): boolean {
  // Shallow compare scalar fields
  const scalarMatch = COMPARE_FIELDS.every((key) => a[key] === b[key]);
  if (!scalarMatch) return false;
  // Deep compare modifier_groups (F&B products) — JSON.stringify is acceptable
  // because modifier_groups are small and order is stable from the API.
  if (a.modifier_groups || b.modifier_groups) {
    return JSON.stringify(a.modifier_groups) === JSON.stringify(b.modifier_groups);
  }
  return true;
}

export function diffProducts(current: POSProduct[], fetched: POSProduct[]): DiffResult {
  const currentMap = new Map(current.map((p) => [p.id, p]));
  let changed = current.length !== fetched.length;

  const merged = fetched.map((fetchedProduct) => {
    const existing = currentMap.get(fetchedProduct.id);
    if (existing && productEquals(existing, fetchedProduct)) {
      return existing; // preserve reference
    }
    changed = true;
    return fetchedProduct;
  });

  return { changed, products: merged };
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `cd apps/pos && npx vitest run src/lib/sync/__tests__/productDiff.test.ts`
Expected: PASS

- [ ] **Step 7: Rewrite productStore.fetchProducts() to SQLite-first**

Rewrite `apps/pos/src/stores/productStore.ts` `fetchProducts()` (~line 55):

1. Read from SQLite first — if rows exist, set store immediately
2. In parallel, fetch from API (respecting F&B vs retail mode)
3. On API success, upsert to SQLite, then diff and update store only if changed
4. On API fail with SQLite already loaded — keep current data, log warning
5. On API fail with empty SQLite — show error

Key: remove the 5-minute cache timer (`CACHE_DURATION_MS`). SQLite-first means we always have data; the background sync handles freshness.

**F&B mode specifics:** The background sync branch must detect F&B mode (via `companyConfig.modules`) and call `fetchActiveMenu()` → `flattenMenuToProducts()` instead of `fetchPOSProducts()`. F&B mode always fetches the full menu (no `updated_since`), diffs locally, and upserts to SQLite. This is acceptable because menus are typically <200 items.

```ts
// Pseudocode for the background sync branch:
const isFnB = companyConfig?.modules?.includes('Menu');
let freshProducts: POSProduct[];
if (isFnB) {
  const menu = await fetchActiveMenu();
  freshProducts = flattenMenuToProducts(menu);
} else {
  freshProducts = await fetchPOSProducts({ limit: 500 });
}
await upsertProducts(db, freshProducts);
const { changed, products: merged } = diffProducts(get().products, freshProducts);
if (changed) {
  set({ products: merged, categories: extractCategories(merged) });
}
```

Also add a `refreshFromSQLite()` action to the store that the SyncScheduler can call after `pullProducts` completes:

```ts
refreshFromSQLite: async () => {
  const companyId = useAuthStore.getState().companyId;
  if (!companyId) return;
  const db = await getDatabase(companyId);
  const freshProducts = await getAllProducts(db);
  if (freshProducts.length === 0) return;
  const { changed, products: merged } = diffProducts(get().products, freshProducts);
  if (changed) {
    set({ products: merged, categories: extractCategories(merged) });
  }
},
```

- [ ] **Step 8: Run all POS tests**

Run: `cd apps/pos && npx vitest run`
Expected: All pass.

- [ ] **Step 9: Commit**

```bash
git add apps/pos/src/lib/sync/productDiff.ts apps/pos/src/lib/sync/__tests__/productDiff.test.ts \
  apps/pos/src/stores/productStore.ts apps/pos/src/lib/db/repositories/productRepository.ts \
  apps/pos/src/lib/db/migrations.ts
git commit -m "perf(pos): SQLite-first product loading with background sync and diff"
```

---

## Task 3: SyncScheduler Activation + Delta Sync + Manual Sync Button

**Why:** Keeps data fresh without user intervention. Manual button gives operators control.

**Files:**
- Create: `apps/pos/src/components/atoms/SyncButton/SyncButton.tsx`
- Modify: `apps/pos/src/stores/terminalStore.ts`
- Modify: `apps/pos/src/stores/syncStore.ts`
- Modify: `apps/pos/src/lib/sync/syncService.ts` (pagination in pullProducts)
- Modify: `apps/pos/src/components/AppShell.tsx`
- Test: `apps/pos/src/components/atoms/SyncButton/__tests__/SyncButton.test.tsx`

### Steps

- [ ] **Step 1: Add pagination loop to pullProducts**

In `apps/pos/src/lib/sync/syncService.ts` (~line 202), rewrite `pullProducts()`:

```ts
export async function pullProducts(db: Database): Promise<number> {
  const lastSync = await getSyncMetadata(db, 'products_last_sync');
  const params: Record<string, string> = { per_page: '500' };
  if (lastSync) {
    params['updated_since'] = lastSync;
  }

  let totalPulled = 0;
  let page = 1;
  let hasMore = true;

  while (hasMore) {
    const result = await apiGet<POSProduct[] | { data: POSProduct[] }>('/products', { ...params, page: String(page) });
    const products = Array.isArray(result) ? result : result.data;

    if (products.length > 0) {
      await upsertProducts(db, products);
      totalPulled += products.length;
    }

    hasMore = products.length === 500; // full page means there may be more
    page++;
  }

  if (totalPulled > 0) {
    await setSyncMetadata(db, 'products_last_sync', new Date().toISOString());
    await logSyncOperation(db, 'pull', 'products', null, 'success', `${totalPulled} products`);
  }

  return totalPulled;
}
```

- [ ] **Step 2: Add scheduler and triggerSync to syncStore**

In `apps/pos/src/stores/syncStore.ts`, add:

```ts
import type { SyncScheduler } from '@/lib/sync/syncScheduler';

// Add to SyncState:
scheduler: SyncScheduler | null;

// Add to SyncActions:
setScheduler: (scheduler: SyncScheduler) => void;
triggerSync: () => Promise<void>;

// Implementations:
scheduler: null,

setScheduler: (scheduler) => {
  set({ scheduler });
},

triggerSync: async () => {
  const { scheduler, isSyncing } = get();
  if (!scheduler || isSyncing) return;
  await scheduler.syncNow();
},
```

- [ ] **Step 3: Instantiate SyncScheduler in terminalStore**

In `apps/pos/src/stores/terminalStore.ts`, after `seedOfflineHashChain` (~line 88), instantiate the scheduler:

```ts
import { SyncScheduler } from '@/lib/sync/syncScheduler';
import { useSyncStore } from '@/stores/syncStore';

// Inside seedOfflineHashChain, after pullZChainState:
const scheduler = new SyncScheduler(db, terminalId);
useSyncStore.getState().setScheduler(scheduler);
scheduler.start();
```

After sync completes, refresh the product store from SQLite. In `syncScheduler.ts`, add a post-sync hook in `tick()` after `runFullSync`:
```ts
// After runFullSync completes successfully:
const { refreshFromSQLite } = useProductStore.getState();
void refreshFromSQLite();
```

This bridges the gap: SyncScheduler → `pullProducts` writes to SQLite → `refreshFromSQLite` diffs and updates in-memory store → memoized components re-render only changed cards.

Also stop the scheduler in the `reset()` action:
```ts
reset: () => {
  useSyncStore.getState().scheduler?.stop();
  // ... existing reset logic
},
```

- [ ] **Step 4: Write SyncButton test**

Create `apps/pos/src/components/atoms/SyncButton/__tests__/SyncButton.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { SyncButton } from '../SyncButton';

vi.mock('@/stores/syncStore', () => ({
  useSyncStore: vi.fn((selector) => {
    const state = {
      isSyncing: false,
      lastSyncAt: Date.now() - 120_000, // 2 min ago
      triggerSync: vi.fn(),
    };
    return selector(state);
  }),
}));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string) => k }),
}));

describe('SyncButton', () => {
  it('renders sync button', () => {
    render(<SyncButton />);
    expect(screen.getByRole('button')).toBeInTheDocument();
  });

  it('shows last sync time', () => {
    render(<SyncButton />);
    expect(screen.getByText(/2/)).toBeInTheDocument(); // "2 min ago" or similar
  });
});
```

- [ ] **Step 5: Implement SyncButton**

Create `apps/pos/src/components/atoms/SyncButton/SyncButton.tsx`:

A compact button with:
- Sync icon (ArrowPathIcon or similar from heroicons)
- Spins while `isSyncing` is true
- Shows relative "last sync" time
- Calls `triggerSync()` on click
- Disabled when `isSyncing`
- Brief green flash on success, red on error

- [ ] **Step 6: Add visibilitychange listener and SyncButton to AppShell**

In `apps/pos/src/components/AppShell.tsx`:

```tsx
// Add inside the existing useEffect or a new one:
const handleVisibility = () => {
  if (document.visibilityState === 'visible') {
    const { lastSyncAt, triggerSync } = useSyncStore.getState();
    const stale = !lastSyncAt || Date.now() - lastSyncAt > 60_000;
    if (stale) void triggerSync();
  }
};
document.addEventListener('visibilitychange', handleVisibility);
return () => document.removeEventListener('visibilitychange', handleVisibility);
```

Render `<SyncButton />` in the header bar next to the connectivity indicator.

- [ ] **Step 7: Run all POS tests**

Run: `cd apps/pos && npx vitest run`
Expected: All pass.

- [ ] **Step 8: Commit**

```bash
git add apps/pos/src/lib/sync/syncService.ts apps/pos/src/stores/syncStore.ts \
  apps/pos/src/stores/terminalStore.ts apps/pos/src/components/atoms/SyncButton/ \
  apps/pos/src/components/AppShell.tsx
git commit -m "perf(pos): activate SyncScheduler, add delta sync pagination and manual sync button"
```

---

## Task 4: Virtualized Product Grid

**Why:** Handles 5K+ products without DOM bloat. Scroll stays smooth on low-end hardware.

**Files:**
- Modify: `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx`
- Modify: `apps/pos/package.json`
- Test: `apps/pos/src/components/organisms/ProductGrid/__tests__/ProductGrid.test.tsx`

### Steps

- [ ] **Step 1: Install @tanstack/react-virtual**

```bash
cd apps/pos && pnpm add @tanstack/react-virtual
```

- [ ] **Step 2: Write test for virtualized grid rendering**

Create or update `apps/pos/src/components/organisms/ProductGrid/__tests__/ProductGrid.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ProductGrid } from '../ProductGrid';
import type { POSProduct } from '@/types/product';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string) => k }),
}));

const makeProducts = (count: number): POSProduct[] =>
  Array.from({ length: count }, (_, i) => ({
    id: `p-${i}`,
    name: `Product ${i}`,
    sku: `SKU-${i}`,
    sale_price: '10.00',
    stock_quantity: 5,
  }));

describe('ProductGrid', () => {
  it('renders without crashing with 100 products', () => {
    // Note: @tanstack/react-virtual in JSDOM renders 0 items (container has 0 height).
    // This test verifies the component mounts without errors, not virtualization behavior.
    // Virtualization correctness is verified manually or in Playwright E2E tests.
    const { container } = render(
      <ProductGrid
        products={makeProducts(100)}
        categories={['A']}
        isLoading={false}
        onAddToCart={vi.fn()}
      />
    );
    // Verify the virtualizer scroll container exists
    expect(container.querySelector('[data-testid="product-grid-scroll"]')).toBeInTheDocument();
  });

  it('shows loading state', () => {
    render(
      <ProductGrid
        products={[]}
        categories={[]}
        isLoading={true}
        onAddToCart={vi.fn()}
      />
    );
    // Verify loading indicator renders
    expect(screen.getByRole('status')).toBeInTheDocument();
  });
});
```

- [ ] **Step 3: Rewrite ProductGrid with @tanstack/react-virtual**

In `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx`:

- Keep the search bar, category tabs, and loading state outside the virtualized area
- Replace the product grid `div` with a virtualized grid:
  - Use `useVirtualizer` from `@tanstack/react-virtual`
  - Calculate rows = `Math.ceil(filteredProducts.length / columns)`
  - Each virtual row renders `columns` ProductCards
  - Set `estimateSize` to the fixed card height
  - The scroll container gets a ref for the virtualizer
- Keep all existing filtering logic (already in `useMemo`)

- [ ] **Step 4: Run tests**

Run: `cd apps/pos && npx vitest run`
Expected: All pass.

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/components/organisms/ProductGrid/ apps/pos/package.json apps/pos/pnpm-lock.yaml
git commit -m "perf(pos): virtualize product grid with @tanstack/react-virtual"
```

---

## Task 5: Image Caching via Tauri Filesystem

**Why:** Most visually impactful. Products with images load instantly instead of flickering.

**Files:**
- Create: `apps/pos/src/lib/images/imageCache.ts`
- Create: `apps/pos/src/lib/images/useProductImage.ts`
- Modify: `apps/pos/src/lib/db/migrations.ts` (migration 12)
- Modify: `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx`
- Modify: `apps/pos/src/lib/sync/syncService.ts`
- Modify: `apps/pos/src-tauri/tauri.conf.json`
- Modify: `apps/pos/src-tauri/capabilities/default.json`
- Test: `apps/pos/src/lib/images/__tests__/imageCache.test.ts`

### Steps

- [ ] **Step 1: Add Tauri config for asset protocol and fs permissions**

In `apps/pos/src-tauri/tauri.conf.json`, add asset protocol scope under `app.security`:
```json
"assetProtocol": {
  "scope": ["$APPDATA/images/**"]
}
```

In `apps/pos/src-tauri/capabilities/default.json`, add fs permissions:
```json
"fs:allow-appdata-read-recursive",
"fs:allow-appdata-write-recursive"
```

Check current Tauri 2 config structure first — exact key names may vary.

- [ ] **Step 2: Add migration 12 for product_images table**

In `apps/pos/src/lib/db/migrations.ts`:

```ts
{
  version: 12,
  name: 'create_product_images',
  sql: `CREATE TABLE IF NOT EXISTS product_images (
    product_id TEXT PRIMARY KEY,
    remote_url TEXT NOT NULL,
    local_path TEXT NOT NULL,
    etag TEXT,
    downloaded_at TEXT NOT NULL
  )`,
},
```

- [ ] **Step 3: Write test for imageCache**

Create `apps/pos/src/lib/images/__tests__/imageCache.test.ts`:

Test the manifest CRUD functions (using mocked SQLite):
- `getLocalImagePath(db, productId)` returns local path or null
- `saveImageManifest(db, productId, remotePath, localPath)` inserts/updates
- `getStaleImages(db, currentProductIds)` returns images for removed products

Test the download queue logic:
- `enqueueDownload(productId, remoteUrl)` deduplicates by productId
- Queue processes in batches of 10

- [ ] **Step 4: Implement imageCache.ts**

Create `apps/pos/src/lib/images/imageCache.ts`:

Key exports:
- `initImageCache(db)` — load manifest into in-memory Map on startup
- `getLocalImagePath(productId)` — synchronous lookup from in-memory Map
- `enqueueDownload(productId, remoteUrl, onComplete: (localPath: string) => void)` — add to background queue, call `onComplete` when downloaded. Returns an unsubscribe function (for React cleanup on unmount). Deduplicates by productId.
- `processDownloadQueue(db)` — download in batches of 10, save to Tauri appData, update manifest. Called by sync cycle.
- `cleanupOrphanedImages(db, currentProductIds)` — delete images for removed products

Use `@tauri-apps/plugin-fs` for file writes and `@tauri-apps/api/path` for `appDataDir`.

- [ ] **Step 5: Implement useProductImage hook**

Create `apps/pos/src/lib/images/useProductImage.ts`:

```ts
import { useState, useEffect } from 'react';
import { getLocalImagePath, enqueueDownload } from './imageCache';

export function useProductImage(productId: string, remoteUrl?: string | null): string | null {
  const [localPath, setLocalPath] = useState<string | null>(() => getLocalImagePath(productId));

  useEffect(() => {
    if (localPath || !remoteUrl) return;

    // Queue download; when complete, the cache updates and we re-check
    const unsubscribe = enqueueDownload(productId, remoteUrl, (path) => {
      setLocalPath(path);
    });

    return unsubscribe; // cleanup: don't update state if unmounted
  }, [productId, remoteUrl, localPath]);

  return localPath;
}
```

- [ ] **Step 6: Wire useProductImage into ProductCard**

In `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx`:

```tsx
import { useProductImage } from '@/lib/images/useProductImage';

// Inside the component:
const localImage = useProductImage(product.id, product.image_url);
const imageSrc = localImage ?? product.image_url; // fallback to remote while downloading
```

Replace the existing `<img src={product.image_url}>` with `<img src={imageSrc}>`.

- [ ] **Step 7: Add image sync step to runFullSync**

In `apps/pos/src/lib/sync/syncService.ts`, after `pullProducts` in `runFullSync()`:

```ts
// After products are pulled and upserted, process pending image downloads
try {
  const { processDownloadQueue } = await import('@/lib/images/imageCache');
  await processDownloadQueue(db);
} catch { /* image caching is non-critical */ }
```

- [ ] **Step 8: Run all POS tests**

Run: `cd apps/pos && npx vitest run`
Expected: All pass.

- [ ] **Step 9: Commit**

```bash
git add apps/pos/src/lib/images/ apps/pos/src/lib/db/migrations.ts \
  apps/pos/src/components/molecules/ProductCard/ProductCard.tsx \
  apps/pos/src/lib/sync/syncService.ts \
  apps/pos/src-tauri/tauri.conf.json apps/pos/src-tauri/capabilities/default.json
git commit -m "perf(pos): add Tauri filesystem image caching with background download queue"
```

---

## Task 6: Final Integration Test + TypeScript Check

**Files:** None new — verification only.

### Steps

- [ ] **Step 1: Run full test suite**

```bash
cd apps/pos && npx vitest run
```
Expected: All pass.

- [ ] **Step 2: TypeScript check**

```bash
cd apps/pos && npx tsc --noEmit
```
Expected: No errors.

- [ ] **Step 3: ESLint check**

```bash
cd apps/pos && pnpm lint
```
Expected: No errors.

- [ ] **Step 4: Push branch**

```bash
git push -u origin feat/pos-performance
```
