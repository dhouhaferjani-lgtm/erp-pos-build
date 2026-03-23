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
