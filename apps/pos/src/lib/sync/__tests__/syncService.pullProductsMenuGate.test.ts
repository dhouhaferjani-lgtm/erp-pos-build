/**
 * C2 Day 1 — Codex round 1 P1 closure.
 *
 * `pullProducts` (the scheduler-side wrapper) used to unconditionally hit
 * `/products` and write bare-id rows. For Menu tenants the canonical
 * catalog source is `/active-menu` (pulled separately by
 * `pullActiveMenu`); a flat /products write would coexist with the
 * composite-id rows the productStore writes via `flattenMenuToProducts`,
 * polluting the local SQLite cache and surfacing as duplicates in the
 * POS grid.
 *
 * Day 1 fix: `pullProducts` short-circuits returning 0 when the
 * companyConfig advertises the `Menu` module.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/lib/db/repositories/productRepository', () => ({
  upsertProducts: vi.fn(),
  deleteProducts: vi.fn(),
  deleteStaleBareSellableRows: vi.fn(),
  pruneStaleCompositeRows: vi.fn(),
}));

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api');
  return {
    ...actual,
    apiGet: vi.fn(),
  };
});

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn(),
  queryOne: vi.fn(),
  queryAll: vi.fn(),
  execute: vi.fn().mockResolvedValue({ rowsAffected: 0 }),
}));

vi.mock('@/lib/db/repositories/syncLogRepository', () => ({
  getSyncMetadata: vi.fn().mockResolvedValue(null),
  setSyncMetadata: vi.fn(),
  logSyncOperation: vi.fn(),
  cleanupOldSyncLogs: vi.fn(),
}));

vi.mock('@/api/productApi', () => ({
  fetchCompanyConfig: vi.fn(),
  fetchPOSProducts: vi.fn(),
  fetchProductByBarcode: vi.fn(),
  fetchActiveMenu: vi.fn(),
  flattenMenuToProducts: vi.fn(),
}));

import { pullProducts } from '@/lib/sync/syncService';
import { useProductStore } from '@/stores/productStore';
import { apiGet } from '@/lib/api';
import { fetchCompanyConfig } from '@/api/productApi';

describe('pullProducts — Menu-tenant gating (C2 Day 1, Codex r1 P1)', () => {
  const db = {} as import('@tauri-apps/plugin-sql').default;

  beforeEach(() => {
    vi.clearAllMocks();
    useProductStore.setState({ companyConfig: null });
  });

  it('returns 0 without hitting /products when companyConfig.all_enabled_modules contains "Menu"', async () => {
    useProductStore.setState({
      companyConfig: {
        company_id: 'company-1',
        all_enabled_modules: ['POS', 'Menu'],
      } as never,
    });

    const count = await pullProducts(db);

    expect(count).toBe(0);
    expect(apiGet).not.toHaveBeenCalled();
  });

  it('proceeds to hit /products for non-Menu (standard-retail) tenants', async () => {
    useProductStore.setState({
      companyConfig: {
        company_id: 'company-1',
        all_enabled_modules: ['POS'],
      } as never,
    });

    // pullProductsCore reads getSyncMetadata first, then loops apiGet.
    // Returning an empty page exits the loop after one call.
    vi.mocked(apiGet).mockResolvedValueOnce([]);

    await pullProducts(db);

    expect(apiGet).toHaveBeenCalledTimes(1);
    const [path] = vi.mocked(apiGet).mock.calls[0]!;
    expect(path).toBe('/products');
  });

  it('Codex r2 P2: companyConfig null at call time — fetches config inline before deciding (Menu → skip)', async () => {
    useProductStore.setState({ companyConfig: null });
    vi.mocked(fetchCompanyConfig).mockResolvedValueOnce({
      company_id: 'company-1',
      all_enabled_modules: ['POS', 'Menu'],
    } as never);

    const count = await pullProducts(db);

    expect(fetchCompanyConfig).toHaveBeenCalledTimes(1);
    expect(count).toBe(0);
    expect(apiGet).not.toHaveBeenCalled();
    // The fetched config is persisted so subsequent ticks skip the
    // re-fetch.
    expect(useProductStore.getState().companyConfig?.all_enabled_modules).toContain('Menu');
  });

  it('Codex r2 P2: companyConfig null at call time — fetches config inline before deciding (non-Menu → proceed)', async () => {
    useProductStore.setState({ companyConfig: null });
    vi.mocked(fetchCompanyConfig).mockResolvedValueOnce({
      company_id: 'company-1',
      all_enabled_modules: ['POS'],
    } as never);
    vi.mocked(apiGet).mockResolvedValueOnce([]);

    await pullProducts(db);

    expect(fetchCompanyConfig).toHaveBeenCalledTimes(1);
    expect(apiGet).toHaveBeenCalledTimes(1);
    const [path] = vi.mocked(apiGet).mock.calls[0]!;
    expect(path).toBe('/products');
  });

  it('Codex r2 P2: companyConfig null + fetchCompanyConfig fails — defers to next tick (no /products write)', async () => {
    // The defer-on-failure semantic: skipping is safer than guessing,
    // because guessing "non-Menu" would pollute Menu-tenant SQLite
    // during the boot window.
    useProductStore.setState({ companyConfig: null });
    vi.mocked(fetchCompanyConfig).mockRejectedValueOnce(new Error('network'));

    const count = await pullProducts(db);

    expect(count).toBe(0);
    expect(apiGet).not.toHaveBeenCalled();
  });
});
