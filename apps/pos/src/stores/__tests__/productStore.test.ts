import { describe, it, expect, beforeEach, vi } from 'vitest';
import { useProductStore } from '../productStore';

// Mock the API module
vi.mock('@/api/productApi', () => ({
  fetchPOSProducts: vi.fn(),
  fetchCompanyConfig: vi.fn(),
  fetchActiveMenu: vi.fn(),
  flattenMenuToProducts: vi.fn(),
}));

// Mock the DB module — SQLite not available in test environment
vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockRejectedValue(new Error('No SQLite in test')),
}));

vi.mock('@/lib/db/repositories/productRepository', () => ({
  getAllProducts: vi.fn().mockResolvedValue([]),
  upsertProducts: vi.fn().mockResolvedValue(undefined),
}));

import { fetchPOSProducts } from '@/api/productApi';

const mockProducts = [
  {
    id: 'p1',
    name: 'Widget A',
    sku: 'WA-001',
    sale_price: '10.00',
    stock_quantity: 100,
    category: 'Electronics',
  },
  {
    id: 'p2',
    name: 'Widget B',
    sku: 'WB-001',
    sale_price: '20.00',
    stock_quantity: 50,
    category: 'Electronics',
  },
  {
    id: 'p3',
    name: 'Gadget',
    sku: 'GA-001',
    sale_price: '15.00',
    stock_quantity: 30,
    category: 'Accessories',
  },
];

describe('productStore', () => {
  beforeEach(() => {
    useProductStore.getState().reset();
    vi.clearAllMocks();
  });

  it('fetches products and extracts categories', async () => {
    vi.mocked(fetchPOSProducts).mockResolvedValue(mockProducts);

    await useProductStore.getState().fetchProducts(true);

    const state = useProductStore.getState();
    expect(state.products).toHaveLength(3);
    expect(state.categories).toEqual(['Accessories', 'Electronics']);
    expect(state.isLoading).toBe(false);
    expect(state.error).toBeNull();
    expect(state.lastFetched).not.toBeNull();
  });

  it('C2 Day 2: normalizes products.category to the canonical first-seen name per menu_category_id (Codex r1 P2)', async () => {
    // Without this normalization, ProductGrid filtering by the deduped
    // category name would hide every row whose category label still
    // carries the OLD name during the rename window — making the
    // renamed rows unreachable until reconciliation drops them.
    vi.mocked(fetchPOSProducts).mockResolvedValue([
      {
        id: 'cola_drinks-v1',
        name: 'Cola (old row)',
        sku: 'COLA-1',
        sale_price: '3.00',
        stock_quantity: 100,
        category: 'Drinks',
        menu_category_id: 'cat-drinks',
      },
      {
        id: 'cola_drinks-v2',
        name: 'Cola (new row)',
        sku: 'COLA-1',
        sale_price: '3.00',
        stock_quantity: 100,
        category: 'Beverages',
        menu_category_id: 'cat-drinks',
      },
    ]);

    await useProductStore.getState().fetchProducts(true);

    const state = useProductStore.getState();
    expect(state.categories).toEqual(['Drinks']);
    // Both rows now carry the canonical 'Drinks' label so the filter
    // ('p.category === selectedCategory') matches every row in the
    // logical category, not just the first-seen one.
    expect(state.products.map((p) => p.category)).toEqual(['Drinks', 'Drinks']);
  });

  it('C2 Day 2: dedupes categories by menu_category_id when present (rename-window correctness)', async () => {
    // Mid-shift category rename window: cached SQLite rows still carry
    // the OLD name; the just-flattened sync result carries the NEW name
    // for the same `menu_category_id`. Both reach extractCategories
    // during the reconcile tick — name-only dedupe would emit two
    // category entries for one logical category.
    vi.mocked(fetchPOSProducts).mockResolvedValue([
      {
        id: 'cola_drinks-v1',
        name: 'Cola',
        sku: 'COLA-1',
        sale_price: '3.00',
        stock_quantity: 100,
        category: 'Drinks',
        menu_category_id: 'cat-drinks',
      },
      {
        id: 'cola_drinks-v2',
        name: 'Cola',
        sku: 'COLA-1',
        sale_price: '3.00',
        stock_quantity: 100,
        category: 'Beverages',
        menu_category_id: 'cat-drinks',
      },
    ]);

    await useProductStore.getState().fetchProducts(true);

    // First-seen name wins. Day 1 reconcile drops the stale rows on
    // the next tick; this dedupe guards the in-between render.
    expect(useProductStore.getState().categories).toEqual(['Drinks']);
  });

  it('C2 Day 2: merges Menu rows (by menu_category_id) with non-Menu rows (by name) in the same catalog', async () => {
    // Mixed catalog edge case — covers the migration window where some
    // SQLite rows still pre-date the v30 schema (no menu_category_id)
    // but new flattened rows have it. Both sides must contribute to
    // the category list without duplication of the shared name.
    vi.mocked(fetchPOSProducts).mockResolvedValue([
      {
        id: 'cola_drinks',
        name: 'Cola',
        sku: 'COLA',
        sale_price: '3.00',
        stock_quantity: 100,
        category: 'Drinks',
        menu_category_id: 'cat-drinks',
      },
      {
        id: 'legacy-cola',
        name: 'Cola (legacy)',
        sku: 'OLD-COLA',
        sale_price: '3.00',
        stock_quantity: 100,
        category: 'Drinks',
      },
      {
        id: 'chips',
        name: 'Chips',
        sku: 'CHIPS',
        sale_price: '2.00',
        stock_quantity: 100,
        category: 'Snacks',
      },
    ]);

    await useProductStore.getState().fetchProducts(true);

    // 'Drinks' appears once (covered by Menu side); 'Snacks' once
    // (non-Menu side). Sorted alphabetically.
    expect(useProductStore.getState().categories).toEqual(['Drinks', 'Snacks']);
  });

  it('does not re-fetch while a fetch is in progress', async () => {
    let resolveFirst: (value: typeof mockProducts) => void;
    const firstCall = new Promise<typeof mockProducts>((r) => { resolveFirst = r; });
    vi.mocked(fetchPOSProducts).mockReturnValueOnce(firstCall);

    const first = useProductStore.getState().fetchProducts(true);
    // Second call while first is in-flight should be a no-op (isLoading guard)
    const second = useProductStore.getState().fetchProducts(true);

    resolveFirst!(mockProducts);
    await first;
    await second;

    expect(fetchPOSProducts).toHaveBeenCalledTimes(1);
  });

  it('handles fetch errors', async () => {
    vi.mocked(fetchPOSProducts).mockRejectedValue(new Error('Network error'));

    await useProductStore.getState().fetchProducts(true);

    const state = useProductStore.getState();
    expect(state.products).toHaveLength(0);
    expect(state.error).toBe('Network error');
    expect(state.isLoading).toBe(false);
  });

  it('finds product by id', async () => {
    vi.mocked(fetchPOSProducts).mockResolvedValue(mockProducts);

    await useProductStore.getState().fetchProducts(true);

    expect(useProductStore.getState().getById('p2')?.name).toBe('Widget B');
    expect(useProductStore.getState().getById('nonexistent')).toBeUndefined();
  });
});
