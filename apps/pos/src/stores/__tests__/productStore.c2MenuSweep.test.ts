/**
 * C2 Day 1 — productStore.fetchProducts Menu branch reconciliation.
 *
 * After the Menu fetch path writes composite-id rows, productStore
 * delegates to `reconcileMenuProducts` (a shared helper used by both
 * this foreground path AND the background `runFullSync` Menu pull at
 * Codex r6 P1). The helper handles success-empty wipe + non-empty
 * upsert + bare-row sweep + composite prune internally; this test
 * only verifies productStore CALLS it.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

const fakeSqlite: { products: unknown[] } = { products: [] };

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
}));

vi.mock('@/lib/db/repositories/productRepository', () => ({
  getAllProducts: vi.fn(async () => fakeSqlite.products),
  reconcileMenuProducts: vi.fn(),
}));

vi.mock('@/api/productApi', () => ({
  fetchPOSProducts: vi.fn(),
  fetchCompanyConfig: vi.fn().mockResolvedValue({ all_enabled_modules: ['Menu'] }),
  fetchActiveMenu: vi.fn(),
  flattenMenuToProducts: vi.fn(),
}));

vi.mock('@/lib/sync/syncService', () => ({
  pullProductsForeground: vi.fn(),
}));

import { useProductStore } from '../productStore';
import { useAuthStore } from '@/stores/authStore';
import {
  fetchActiveMenu,
  flattenMenuToProducts,
} from '@/api/productApi';
import { reconcileMenuProducts } from '@/lib/db/repositories/productRepository';

const SELLABLE_COCA = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const CAT_DRINKS = 'cccc-1111-1111-1111-111111111111';
const CAT_COMBOS = 'cccc-2222-2222-2222-222222222222';

function makeCompositeProduct(sellableId: string, categoryId: string): unknown {
  return {
    id: `${sellableId}_${categoryId}`,
    name: 'Coca',
    sku: 'COCA',
    sale_price: '3.00',
    stock_quantity: 999,
    category: 'Drinks',
    sellable_id: sellableId,
    menu_category_id: categoryId,
  };
}

describe('productStore — C2 Day 1 stale-bare-row sweep', () => {
  beforeEach(() => {
    fakeSqlite.products = [];
    useProductStore.getState().reset();
    vi.clearAllMocks();
    useAuthStore.setState({ companyId: 'company-1' } as never);
  });

  it('Codex r6 P1: productStore.fetchProducts (Menu branch) delegates to reconcileMenuProducts with fresh composite rows', async () => {
    const composite = [
      makeCompositeProduct(SELLABLE_COCA, CAT_DRINKS),
      makeCompositeProduct(SELLABLE_COCA, CAT_COMBOS),
    ];
    vi.mocked(fetchActiveMenu).mockResolvedValue({ categories: [] } as never);
    vi.mocked(flattenMenuToProducts).mockReturnValue(composite as never);

    await useProductStore.getState().fetchProducts(true);

    expect(reconcileMenuProducts).toHaveBeenCalledTimes(1);
    const [, freshProducts] = vi.mocked(reconcileMenuProducts).mock.calls[0]!;
    expect(freshProducts).toHaveLength(2);
  });

  it('Codex r6 P1: productStore.fetchProducts (Menu branch) delegates to reconcileMenuProducts with empty array on success-empty', async () => {
    vi.mocked(fetchActiveMenu).mockResolvedValue({ categories: [] } as never);
    vi.mocked(flattenMenuToProducts).mockReturnValue([] as never);

    await useProductStore.getState().fetchProducts(true);

    expect(reconcileMenuProducts).toHaveBeenCalledTimes(1);
    const [, freshProducts] = vi.mocked(reconcileMenuProducts).mock.calls[0]!;
    expect(freshProducts).toHaveLength(0);
  });
});
