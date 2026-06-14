import { describe, it, expect, vi, beforeEach } from 'vitest';
import { companyConfigCacheKey, persistCompanyConfig, loadCachedCompanyConfig } from '@/lib/companyConfigCache';
import { setStoredValue } from '@/lib/storage';
import type { CompanyConfig } from '@/types/companyConfig';

vi.mock('@/lib/storage', () => {
  const store = new Map<string, unknown>();
  return {
    setStoredValue: vi.fn(async (k: string, v: unknown) => { store.set(k, v); }),
    getStoredValue: vi.fn(async (k: string) => store.get(k) ?? null),
    StorageKeys: {},
  };
});

const cfg: CompanyConfig = { all_enabled_modules: [], allow_cross_location_stock_view: true } as CompanyConfig;

describe('companyConfigCache', () => {
  beforeEach(() => vi.clearAllMocks());

  it('round-trips config per company', async () => {
    await persistCompanyConfig('c-1', cfg);
    expect(setStoredValue).toHaveBeenCalledWith(companyConfigCacheKey('c-1'), cfg);
    const loaded = await loadCachedCompanyConfig('c-1');
    expect(loaded?.allow_cross_location_stock_view).toBe(true);
  });

  it('returns null for unknown company', async () => {
    expect(await loadCachedCompanyConfig('nope')).toBeNull();
  });
});
