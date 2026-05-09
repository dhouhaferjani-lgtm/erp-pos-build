/**
 * Regression coverage for Codex round-1 P2 on PR #98 — the recent-scan
 * LRU must be invalidated whenever the in-memory product catalog
 * changes, otherwise a deleted/updated product keeps resolving via
 * Tier 0 to its stale cached state.
 *
 * Approach: drive `productStore.refreshFromSQLite` and the foreground
 * pull paths with a controlled `getAllProducts` mock; verify that
 * `clearScanCache` is invoked at the moments the in-memory state is
 * updated. The cache module is module-level state, so an external
 * read of `getCachedScan` after the refresh confirms the entry is
 * gone.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import type { POSProduct } from '@/types/product';

const getAllProductsSpy = vi.fn();
const upsertProductsSpy = vi.fn();
const fetchCompanyConfigSpy = vi.fn();
const fetchActiveMenuSpy = vi.fn();
const flattenMenuToProductsSpy = vi.fn();
const fetchPOSProductsSpy = vi.fn();
const pullProductsForegroundSpy = vi.fn();

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
}));

vi.mock('@/lib/db/repositories/productRepository', () => ({
  getAllProducts: (...args: unknown[]) => getAllProductsSpy(...args),
  upsertProducts: (...args: unknown[]) => upsertProductsSpy(...args),
}));

vi.mock('@/api/productApi', () => ({
  fetchCompanyConfig: (...args: unknown[]) => fetchCompanyConfigSpy(...args),
  fetchActiveMenu: (...args: unknown[]) => fetchActiveMenuSpy(...args),
  flattenMenuToProducts: (...args: unknown[]) => flattenMenuToProductsSpy(...args),
  fetchPOSProducts: (...args: unknown[]) => fetchPOSProductsSpy(...args),
}));

vi.mock('@/lib/sync/syncService', () => ({
  pullProductsForeground: (...args: unknown[]) => pullProductsForegroundSpy(...args),
  PullProductsError: class extends Error {},
}));

import { useProductStore } from '../productStore';
import { useAuthStore } from '../authStore';
import {
  setCachedScan,
  getCachedScan,
  clearScanCache,
} from '@/lib/scan/scanResolutionCache';

function makeProduct(over: Partial<POSProduct>): POSProduct {
  return {
    id: 'p-default',
    name: 'Default',
    sku: 'X',
    barcode: '0',
    sale_price: '10.00',
    stock_quantity: 1,
    category: 'Test',
    ...over,
  } as POSProduct;
}

beforeEach(() => {
  getAllProductsSpy.mockReset();
  upsertProductsSpy.mockReset();
  fetchCompanyConfigSpy.mockReset();
  fetchActiveMenuSpy.mockReset();
  flattenMenuToProductsSpy.mockReset();
  fetchPOSProductsSpy.mockReset();
  pullProductsForegroundSpy.mockReset();

  useAuthStore.setState({ companyId: 'company-1' } as never);
  useProductStore.setState({
    products: [],
    categories: [],
    isLoading: false,
    error: null,
    companyConfig: null,
    lastFetched: null,
  } as never);
  clearScanCache();
});

describe('Codex round-1 P2 (PR #98) — scan LRU invalidation on catalog refresh', () => {
  it('refreshFromSQLite clears the scan cache when in-memory products change', async () => {
    // Seed the LRU with a stale entry.
    const stale = makeProduct({ id: 'p-stale', barcode: '999' });
    setCachedScan('999', stale, 'company-1');
    expect(getCachedScan('999', 'company-1')).not.toBeNull();

    // SQLite returns a different product set — refreshFromSQLite must
    // invalidate the cache before applying the new state.
    const fresh = [makeProduct({ id: 'p-fresh', barcode: '001' })];
    getAllProductsSpy.mockResolvedValueOnce(fresh);

    await useProductStore.getState().refreshFromSQLite();

    expect(getCachedScan('999', 'company-1')).toBeNull();
  });

  it('refreshFromSQLite leaves the scan cache alone when the catalog is unchanged', async () => {
    // Seed in-memory products and the LRU.
    const product = makeProduct({ id: 'p1', barcode: '111' });
    useProductStore.setState({ products: [product] } as never);
    setCachedScan('111', product, 'company-1');

    // SQLite returns the same set — no diff, no cache invalidation.
    getAllProductsSpy.mockResolvedValueOnce([product]);

    await useProductStore.getState().refreshFromSQLite();

    expect(getCachedScan('111', 'company-1')).not.toBeNull();
  });

  it('refreshFromSQLite is a no-op when SQLite is empty (and scan cache stays untouched)', async () => {
    // Per the existing semantics: refreshFromSQLite returns early when
    // SQLite has zero rows (the Step A round-1 BLOCKER fix lives in
    // doStandardForegroundPull, NOT here).
    const product = makeProduct({ id: 'p-stale', barcode: '999' });
    setCachedScan('999', product, 'company-1');

    getAllProductsSpy.mockResolvedValueOnce([]);

    await useProductStore.getState().refreshFromSQLite();

    expect(getCachedScan('999', 'company-1')).not.toBeNull();
  });

  it('foreground pull (standard-retail tenant) clears the scan cache when the catalog updates', async () => {
    // No in-memory products initially, no company config (so the
    // companyConfig fetch happens) — but we mock it to return null +
    // not-Menu so the standard branch fires.
    fetchCompanyConfigSpy.mockResolvedValueOnce({ all_enabled_modules: [] });

    const stale = makeProduct({ id: 'p-stale', barcode: '999' });
    setCachedScan('999', stale, 'company-1');

    pullProductsForegroundSpy.mockResolvedValueOnce(undefined);
    const fresh = [makeProduct({ id: 'p-new', barcode: '001' })];
    // Two getAllProducts calls in fetchProducts: (1) the initial cache
    // hydration step (before the API fetch), (2) post-pullProductsForeground
    // re-read. Mock both to return distinct catalogs so the diff fires.
    getAllProductsSpy
      .mockResolvedValueOnce([])     // initial cache hydration → no local data
      .mockResolvedValueOnce(fresh); // post-pull SQLite read

    await useProductStore.getState().fetchProducts();

    expect(getCachedScan('999', 'company-1')).toBeNull();
  });

  it('tombstone-driven catalog wipe (foreground pull, post-pull SQLite empty) clears the scan cache', async () => {
    // Drive the wipe path WITHOUT seeding initial in-memory products,
    // so hasLocalData=false makes doApiFetch awaited end-to-end.
    fetchCompanyConfigSpy.mockResolvedValueOnce({ all_enabled_modules: [] });

    // Simulate a stale entry in the LRU from a previous session-window
    // (the entry can outlive the in-memory products via direct write).
    const stale = makeProduct({ id: 'p-stale', barcode: '999' });
    setCachedScan('999', stale, 'company-1');

    pullProductsForegroundSpy.mockResolvedValueOnce(undefined);
    // Step 1 cache hydration → empty (so hasLocalData=false). Then
    // post-pull SQLite is also empty, so we DON'T traverse the
    // tombstone branch (currentProducts is also []). Instead, prime
    // currentProducts from a non-empty Step 1, which makes
    // hasLocalData=true and the apiFetch fire-and-forget.
    //
    // Workaround: prime products manually AFTER Step 1, before the
    // doApiFetch closure reads get().products. The closure runs
    // asynchronously via fire-and-forget, so the manual setState in
    // between is safe.
    getAllProductsSpy
      .mockResolvedValueOnce([])  // Step 1: SQLite hydration (empty → hasLocalData=false → doApiFetch is awaited).
      .mockResolvedValueOnce([]); // Post-pull: tombstoned, empty.

    // Pre-seed in-memory so the tombstone branch's
    // `if (currentProducts.length > 0)` fires.
    useProductStore.setState({ products: [stale] } as never);

    await useProductStore.getState().fetchProducts();

    // hasLocalData=false → doApiFetch is awaited inside fetchProducts,
    // so by the time fetchProducts returns the tombstone branch has
    // already run.

    expect(getCachedScan('999', 'company-1')).toBeNull();
    expect(useProductStore.getState().products).toEqual([]);
  });
});
