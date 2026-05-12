/**
 * T2.1 Step A — productStore.fetchProducts uses pullProductsForeground for
 * non-Menu tenants instead of the legacy `fetchPOSProducts({ limit: 500 })`
 * 500-cap call. Tests A.1, A.2, A.3 of the kickoff
 * (`docs/superpowers/plans/2026-05-09-pos-t2.1-catalog-truthfulness-kickoff-prompt.md`).
 *
 * Mock surface: `pullProductsForeground` is mocked at the module boundary so
 * the productStore contract can be exercised without a live SQLite or HTTP
 * stack. The mock writes "synced" rows to a fake repository store; the
 * productStore's post-pull `refreshFromSQLite()` reads from that store.
 *
 * For typed-error kinds (A.4 / A.5 / A.6 / A.7 — pullProductsCore-level
 * coverage) see `productStore.t21StepA.typedErrors.test.ts`.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';

// In-memory fake SQLite store — shared by getAllProducts + upsertProducts.
const fakeSqlite: { products: unknown[] } = { products: [] };

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
}));

vi.mock('@/lib/db/repositories/productRepository', () => ({
  getAllProducts: vi.fn(async () => fakeSqlite.products),
  upsertProducts: vi.fn(async (_db: unknown, products: unknown[]) => {
    const indexed = new Map<string, unknown>();
    for (const existing of fakeSqlite.products) {
      const id = (existing as { id: string }).id;
      indexed.set(id, existing);
    }
    for (const p of products) {
      indexed.set((p as { id: string }).id, p);
    }
    fakeSqlite.products = Array.from(indexed.values());
  }),
}));

vi.mock('@/api/productApi', () => ({
  fetchPOSProducts: vi.fn(),
  fetchCompanyConfig: vi.fn().mockResolvedValue({ all_enabled_modules: [] }),
  fetchActiveMenu: vi.fn(),
  flattenMenuToProducts: vi.fn(),
}));

vi.mock('@/lib/sync/syncService', () => ({
  pullProductsForeground: vi.fn(),
}));

import { useProductStore } from '../productStore';
import { useAuthStore } from '@/stores/authStore';
import {
  fetchPOSProducts,
  fetchActiveMenu,
  flattenMenuToProducts,
  fetchCompanyConfig,
} from '@/api/productApi';
import { pullProductsForeground } from '@/lib/sync/syncService';
import { FetchTimeoutError } from '@/lib/fetchWithTimeout';

function makeProduct(i: number, category = 'Electronics'): unknown {
  return {
    id: `p${i}`,
    name: `Product ${i}`,
    sku: `SKU-${i}`,
    barcode: `100000${i}`,
    sale_price: '10.00',
    stock_quantity: 100,
    category,
  };
}

describe('productStore — T2.1 Step A foreground pull contract', () => {
  beforeEach(() => {
    fakeSqlite.products = [];
    useProductStore.getState().reset();
    vi.clearAllMocks();
    useAuthStore.setState({ companyId: 'company-1' } as never);
    vi.mocked(fetchCompanyConfig).mockResolvedValue({ all_enabled_modules: [] });
  });

  it('A.1: non-Menu tenant pulls full catalog via pullProductsForeground (not capped at 500)', async () => {
    // Mock pullProductsForeground to "commit" 5000 rows to SQLite.
    vi.mocked(pullProductsForeground).mockImplementation(async () => {
      fakeSqlite.products = Array.from({ length: 5000 }, (_, i) => makeProduct(i));
      return { ok: true, count: 5000 };
    });

    await useProductStore.getState().fetchProducts(true);

    // Foreground wrapper called exactly once with the 30s timeout.
    expect(pullProductsForeground).toHaveBeenCalledTimes(1);
    expect(pullProductsForeground).toHaveBeenCalledWith(
      expect.anything(),
      expect.objectContaining({ timeoutMs: 30000 }),
    );

    // Legacy 500-cap helper is NOT called.
    expect(fetchPOSProducts).not.toHaveBeenCalled();

    // In-memory products reflect the full SQLite catalog.
    const state = useProductStore.getState();
    expect(state.products).toHaveLength(5000);
    expect(state.isLoading).toBe(false);
    expect(state.error).toBeNull();
    expect(state.lastFetched).not.toBeNull();
  });

  it('A.2: Menu tenant preserves the legacy menu-flatten path (non-regression)', async () => {
    vi.mocked(fetchCompanyConfig).mockResolvedValue({ all_enabled_modules: ['Menu'] });
    const menuStruct = { items: [] };
    const flatProducts = [makeProduct(1), makeProduct(2), makeProduct(3)];
    vi.mocked(fetchActiveMenu).mockResolvedValue(menuStruct as never);
    vi.mocked(flattenMenuToProducts).mockReturnValue(flatProducts as never);

    await useProductStore.getState().fetchProducts(true);

    // Menu helpers exercised; foreground wrapper NOT called.
    expect(pullProductsForeground).not.toHaveBeenCalled();
    expect(fetchActiveMenu).toHaveBeenCalledTimes(1);
    expect(flattenMenuToProducts).toHaveBeenCalledTimes(1);

    // In-memory store reflects the flattened menu products.
    const state = useProductStore.getState();
    expect(state.products).toHaveLength(3);
    expect(state.error).toBeNull();
  });

  it('A.3: foreground timeout surfaces to store.error state (does not throw out of fetchProducts)', async () => {
    // Pre-condition: empty SQLite (no local data), so the catch path
    // commits the error to store state instead of silently surviving on
    // the prior in-memory snapshot.
    fakeSqlite.products = [];
    vi.mocked(pullProductsForeground).mockRejectedValue(
      new FetchTimeoutError('https://example.test/api/v1/products', 30000, 'GET'),
    );

    // The promise resolves cleanly — productStore.fetchProducts swallows
    // the rejection into store state per the existing contract.
    await expect(useProductStore.getState().fetchProducts(true)).resolves.toBeUndefined();

    const state = useProductStore.getState();
    expect(state.isLoading).toBe(false);
    expect(state.error).toBeTruthy();
    // The opaque T0.3 timeout message survives into the store error
    // (the cashier-visible banner reads this verbatim).
    expect(state.error).toMatch(/timed out/i);
    // No products committed (empty SQLite + failed pull).
    expect(state.products).toHaveLength(0);
  });

  it('A.1b (Codex round-1 BLOCKER fix): empty SQLite post-pull clears in-memory products (tombstone-driven catalog wipe)', async () => {
    // Pre-condition: cashier already has 100 products in memory + SQLite
    // (warm-start). Server-side wipes all products; pullProductsForeground
    // tombstones the SQLite rows (post-pull SQLite is empty).
    const seedProducts = Array.from({ length: 100 }, (_, i) => makeProduct(i));
    fakeSqlite.products = seedProducts;
    // Hydrate the in-memory store via the warm-path's SQLite-first read.
    await useProductStore.getState().fetchProducts(true);
    expect(useProductStore.getState().products).toHaveLength(100);

    // Mock the foreground pull to wipe SQLite (simulates tombstones).
    vi.mocked(pullProductsForeground).mockImplementation(async () => {
      fakeSqlite.products = [];
      return { ok: true, count: 0 };
    });
    // Trigger another fetchProducts — warm path fires the foreground
    // pull as fire-and-forget; the post-pull SQLite read sees an empty
    // table and the in-memory store MUST clear.
    await useProductStore.getState().fetchProducts(true);
    // Allow the fire-and-forget chain to settle.
    for (let i = 0; i < 50; i++) await Promise.resolve();

    const state = useProductStore.getState();
    expect(state.products).toHaveLength(0);
    expect(state.categories).toHaveLength(0);
  });

  it('A.3-warm: foreground timeout with prior local data preserves in-memory products', async () => {
    // Warm-start variant: SQLite has products → cashier already saw a
    // populated grid → a transient pull failure must NOT blow away the
    // in-memory snapshot, only clear isLoading.
    const seedProducts = Array.from({ length: 100 }, (_, i) => makeProduct(i));
    fakeSqlite.products = seedProducts;

    vi.mocked(pullProductsForeground).mockRejectedValue(
      new FetchTimeoutError('https://example.test/api/v1/products', 30000, 'GET'),
    );

    await expect(useProductStore.getState().fetchProducts(true)).resolves.toBeUndefined();

    const state = useProductStore.getState();
    // The in-memory snapshot was hydrated from SQLite at fetchProducts
    // entry, then preserved across the pull failure.
    expect(state.products).toHaveLength(100);
    expect(state.isLoading).toBe(false);
    // No error banner in warm mode — the cashier still has a usable grid.
    expect(state.error).toBeNull();
  });
});
