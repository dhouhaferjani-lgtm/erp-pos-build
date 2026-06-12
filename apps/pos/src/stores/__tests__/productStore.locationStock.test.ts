/**
 * Task 12 — productStore.refreshLocationStock (the grid stock join).
 *
 * The store action batch-reads `location_stock` ONCE for the loaded catalog
 * and publishes the per-tile display map. Gates:
 *   - Menu tenants NEVER get a map (tiles keep the legacy 999 chrome);
 *   - unknown tenant kind (companyConfig still null) → no map — fail toward
 *     unchanged display rather than flashing zeros at a Menu tenant;
 *   - missing companyId / DB failure → previous map kept (browser dev).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { makeProduct } from '@/test/helpers';
import type { LocationStockRow } from '@/lib/db/repositories/locationStockRepository';

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
}));

vi.mock('@/lib/db/repositories/locationStockRepository', () => ({
  getStockForProducts: vi.fn(),
}));

vi.mock('@/api/productApi', () => ({
  fetchPOSProducts: vi.fn(),
  fetchCompanyConfig: vi.fn(),
  fetchActiveMenu: vi.fn(),
  flattenMenuToProducts: vi.fn(),
}));

vi.mock('@/lib/sync/syncService', () => ({
  pullProductsForeground: vi.fn(),
}));

import { getStockForProducts } from '@/lib/db/repositories/locationStockRepository';
import { useProductStore } from '@/stores/productStore';
import { useAuthStore } from '@/stores/authStore';
import type { CompanyConfig } from '@/types/companyConfig';

function makeRow(overrides: Partial<LocationStockRow> = {}): LocationStockRow {
  return {
    product_id: 'p1',
    variant_id: '',
    quantity: '5.0000',
    reserved: '0',
    available: '5.0000',
    incoming_transfer: '0',
    incoming_po: '0',
    updated_at: null,
    ...overrides,
  };
}

const retailConfig = { all_enabled_modules: ['POS'] } as CompanyConfig;
const menuConfig = { all_enabled_modules: ['Menu'] } as CompanyConfig;

describe('productStore.refreshLocationStock', () => {
  beforeEach(() => {
    vi.mocked(getStockForProducts).mockReset();
    vi.mocked(getStockForProducts).mockResolvedValue([]);
    useProductStore.getState().reset();
    useAuthStore.setState({ companyId: 'company-1' });
  });

  it('batch-reads once for the loaded ids and publishes the map', async () => {
    vi.mocked(getStockForProducts).mockResolvedValue([
      makeRow({ product_id: 'p1', available: '5.0000', incoming_po: '2.0000' }),
    ]);
    useProductStore.setState({
      companyConfig: retailConfig,
      products: [makeProduct({ id: 'p1' }), makeProduct({ id: 'p2' })],
    });

    await useProductStore.getState().refreshLocationStock();

    expect(getStockForProducts).toHaveBeenCalledTimes(1);
    expect(getStockForProducts).toHaveBeenCalledWith(expect.anything(), ['p1', 'p2']);
    const map = useProductStore.getState().locationStock;
    expect(map['p1']).toEqual({
      available: '5.0000',
      incoming_transfer: '0',
      incoming_po: '2.0000',
    });
    // No local row → zeros, not undefined (still stock-managed).
    expect(map['p2']).toEqual({ available: '0', incoming_transfer: '0', incoming_po: '0' });
  });

  it('skips entirely for Menu tenants (legacy 999 chrome preserved)', async () => {
    useProductStore.setState({
      companyConfig: menuConfig,
      products: [makeProduct({ id: 'm1', stock_quantity: 999 })],
    });

    await useProductStore.getState().refreshLocationStock();

    expect(getStockForProducts).not.toHaveBeenCalled();
    expect(useProductStore.getState().locationStock).toEqual({});
  });

  it('skips while the tenant kind is unknown (companyConfig null)', async () => {
    useProductStore.setState({
      companyConfig: null,
      products: [makeProduct({ id: 'p1' })],
    });

    await useProductStore.getState().refreshLocationStock();

    expect(getStockForProducts).not.toHaveBeenCalled();
    expect(useProductStore.getState().locationStock).toEqual({});
  });

  it('keeps the previous map when the local DB read fails (browser dev)', async () => {
    useProductStore.setState({
      companyConfig: retailConfig,
      products: [makeProduct({ id: 'p1' })],
      locationStock: { p1: { available: '4.0000', incoming_transfer: '0', incoming_po: '0' } },
    });
    vi.mocked(getStockForProducts).mockRejectedValue(new Error('no sqlite'));

    await useProductStore.getState().refreshLocationStock();

    expect(useProductStore.getState().locationStock['p1']).toEqual({
      available: '4.0000',
      incoming_transfer: '0',
      incoming_po: '0',
    });
  });
});
