/**
 * T2.1 Step A — typed-error contract for the foreground pull path.
 * Tests A.4 / A.5 / A.6 / A.7 of the kickoff
 * (`docs/superpowers/plans/2026-05-09-pos-t2.1-catalog-truthfulness-kickoff-prompt.md`).
 *
 * Mock surface: `@/lib/api`'s `apiGet` is mocked at the HTTP boundary so
 * the FULL chain — real `pullProductsForeground` → real `pullProductsCore`
 * → mocked `apiGet` — runs end-to-end. This exercises the typed-error
 * classifier in `pullProductsCore` (HTTP 5xx → `PullProductsError('http_5xx')`,
 * network → `PullProductsError('network')`, parse → `PullProductsError('parse')`,
 * timeout → `FetchTimeoutError` propagated verbatim).
 *
 * Mocking `pullProductsCore` directly via `vi.mock` does NOT intercept the
 * real `pullProductsForeground`'s sibling-call (lexical binding); see the
 * Codex round-3 NRC-2 note in the kickoff for the rationale.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';

const fakeSqlite: { products: unknown[] } = { products: [] };
const fakeMetadata: Record<string, string | null> = {};

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
}));

vi.mock('@/lib/db/repositories/productRepository', () => ({
  getAllProducts: vi.fn(async () => fakeSqlite.products),
  upsertProducts: vi.fn(async (_db: unknown, products: unknown[]) => {
    const indexed = new Map<string, unknown>();
    for (const existing of fakeSqlite.products) {
      indexed.set((existing as { id: string }).id, existing);
    }
    for (const p of products) {
      indexed.set((p as { id: string }).id, p);
    }
    fakeSqlite.products = Array.from(indexed.values());
  }),
  deleteProducts: vi.fn(async () => undefined),
}));

vi.mock('@/lib/db/repositories/syncLogRepository', () => ({
  getSyncMetadata: vi.fn(async (_db: unknown, key: string) => fakeMetadata[key] ?? null),
  setSyncMetadata: vi.fn(async (_db: unknown, key: string, value: string) => {
    fakeMetadata[key] = value;
  }),
  logSyncOperation: vi.fn(async () => undefined),
}));

vi.mock('@/api/productApi', () => ({
  fetchPOSProducts: vi.fn(),
  fetchCompanyConfig: vi.fn().mockResolvedValue({ all_enabled_modules: [] }),
  fetchActiveMenu: vi.fn(),
  flattenMenuToProducts: vi.fn(),
}));

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  ApiRequestError: class ApiRequestError extends Error {
    constructor(
      public readonly status: number,
      public readonly apiMessage: string,
      public readonly code: string,
      public readonly details?: Record<string, unknown>,
    ) {
      super(apiMessage);
      this.name = 'ApiRequestError';
    }
  },
}));

import { useProductStore } from '../productStore';
import { useAuthStore } from '@/stores/authStore';
import { apiGet, ApiRequestError } from '@/lib/api';

function makeProduct(i: number): unknown {
  return {
    id: `p${i}`,
    name: `Product ${i}`,
    sku: `SKU-${i}`,
    barcode: `100000${i}`,
    sale_price: '10.00',
    stock_quantity: 100,
    category: 'Electronics',
  };
}

/**
 * Flush queued microtasks so warm-path fire-and-forget API fetches can
 * settle. The chain `pullProductsForeground → pullProductsCore (N page
 * fetches) → upserts → refreshFromSQLite → store update` typically
 * settles within ~20 microtask hops; 50 is generous.
 */
async function flushMicrotasks(times = 50): Promise<void> {
  for (let i = 0; i < times; i++) {
    await Promise.resolve();
  }
}

describe('productStore — T2.1 Step A typed-error contract (real wrapper, mocked apiGet)', () => {
  beforeEach(() => {
    fakeSqlite.products = [];
    for (const k of Object.keys(fakeMetadata)) delete fakeMetadata[k];
    useProductStore.getState().reset();
    vi.clearAllMocks();
    useAuthStore.setState({ companyId: 'company-1' } as never);
  });

  it('A.4: mid-pull failure leaves committed pages + retry is idempotent (cursor unchanged across rejection)', async () => {
    const pageSize = 500;
    let call = 0;

    // Call 1: page 1 + 2 succeed, page 3 throws ApiRequestError(503)
    // (so pullProductsCore classifies as PullProductsError('http_5xx')).
    vi.mocked(apiGet).mockImplementation(async () => {
      call++;
      if (call === 1) {
        return Array.from({ length: pageSize }, (_, i) => makeProduct(i));
      }
      if (call === 2) {
        return Array.from({ length: pageSize }, (_, i) => makeProduct(pageSize + i));
      }
      // Page 3 throws.
      throw new ApiRequestError(503, 'Service Unavailable', 'UPSTREAM_5XX');
    });

    await useProductStore.getState().fetchProducts(true);

    // Pages 1+2 committed → 1000 rows in fake SQLite.
    expect(fakeSqlite.products).toHaveLength(1000);
    // Cursor NOT advanced (mid-pull throw skipped the per-pull
    // setSyncMetadata write).
    expect(fakeMetadata['products_last_sync']).toBeUndefined();

    let state = useProductStore.getState();
    expect(state.error).toBeTruthy();
    expect(state.error).toMatch(/5xx|503/i);
    expect(state.isLoading).toBe(false);

    // Call 2: all 5 pages succeed (page sizes 500, 500, 500, 500, 500 → 2500
    // unique rows; final page < 500 ends the loop).
    call = 0;
    vi.mocked(apiGet).mockReset();
    vi.mocked(apiGet).mockImplementation(async () => {
      call++;
      if (call < 5) {
        return Array.from({ length: pageSize }, (_, i) => makeProduct((call - 1) * pageSize + i));
      }
      if (call === 5) {
        // Last page — fewer than 500 ends the loop.
        return Array.from({ length: 100 }, (_, i) => makeProduct(2000 + i));
      }
      return [];
    });

    // Reset in-memory store. SQLite still has the 1000 rows from
    // call 1 → fetchProducts goes down the warm-path and fires the
    // foreground pull as fire-and-forget. Flush microtasks so the
    // background pull + refreshFromSQLite settles before assertions.
    useProductStore.getState().reset();
    await useProductStore.getState().fetchProducts(true);
    await flushMicrotasks();

    state = useProductStore.getState();
    // 2100 unique rows in fake SQLite (4 × 500 + 100); idempotent
    // upsert deduped the first 1000 already present.
    expect(fakeSqlite.products).toHaveLength(2100);
    // In-memory store reflects the full catalog.
    expect(state.products).toHaveLength(2100);
    expect(state.error).toBeNull();
    expect(state.isLoading).toBe(false);
    // Cursor finally advanced.
    expect(fakeMetadata['products_last_sync']).toBeDefined();
  });

  it('A.5: HTTP 5xx error surfaces as PullProductsError(http_5xx) to fetchProducts.error state', async () => {
    vi.mocked(apiGet).mockRejectedValue(
      new ApiRequestError(503, 'Internal Server Error', 'UPSTREAM_5XX'),
    );

    await useProductStore.getState().fetchProducts(true);

    const state = useProductStore.getState();
    expect(state.error).toBeTruthy();
    expect(state.error).toMatch(/5xx|503/i);
    expect(state.isLoading).toBe(false);
  });

  it('A.6: network error surfaces as PullProductsError(network)', async () => {
    vi.mocked(apiGet).mockRejectedValue(new TypeError('fetch failed'));

    await useProductStore.getState().fetchProducts(true);

    const state = useProductStore.getState();
    expect(state.error).toBeTruthy();
    expect(state.error).toMatch(/network|fetch failed/i);
    expect(state.isLoading).toBe(false);
  });

  it('A.7: parse error (non-JSON response body) surfaces as PullProductsError(parse)', async () => {
    vi.mocked(apiGet).mockRejectedValue(new SyntaxError('Unexpected token < in JSON at position 0'));

    await useProductStore.getState().fetchProducts(true);

    const state = useProductStore.getState();
    expect(state.error).toBeTruthy();
    expect(state.error).toMatch(/parse|JSON|unexpected token/i);
    expect(state.isLoading).toBe(false);
  });
});
