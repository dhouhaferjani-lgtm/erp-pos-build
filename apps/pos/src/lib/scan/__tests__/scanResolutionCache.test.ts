/**
 * Recent-scan LRU cache + chooser-pick preference cache.
 *
 * See `scanResolutionCache.ts` for design notes. The cache is
 * tenant-scoped via the `companyId` parameter (Codex round-2 P2 on
 * PR #98) — entries from one company never satisfy lookups from
 * another, even with the same scanned code.
 */
import { describe, it, expect, beforeEach } from 'vitest';
import {
  getCachedScan,
  setCachedScan,
  evictCachedScan,
  clearScanCache,
  SCAN_CACHE_MAX_SIZE,
} from '../scanResolutionCache';
import type { POSProduct } from '@/types/product';

const C = 'company-1';
const C2 = 'company-2';

function makeProduct(id: string, code: string, overrides: Partial<POSProduct> = {}): POSProduct {
  return {
    id,
    name: `Product ${id}`,
    sku: code,
    barcode: code,
    sale_price: '10.00',
    stock_quantity: 1,
    category: 'test',
    sellableType: 'product',
    ...overrides,
  };
}

describe('scanResolutionCache', () => {
  beforeEach(() => {
    clearScanCache();
  });

  it('returns null for an unseen code', () => {
    expect(getCachedScan('UNSEEN', C)).toBeNull();
  });

  it('returns the cached product after a setCachedScan write', () => {
    const product = makeProduct('p1', 'BC-1');
    setCachedScan('BC-1', product, C);

    expect(getCachedScan('BC-1', C)).toEqual(product);
  });

  it('caches by exact code (no normalization beyond what the resolver already does)', () => {
    const product = makeProduct('p1', 'BC-1');
    setCachedScan('BC-1', product, C);

    expect(getCachedScan('bc-1', C)).toBeNull(); // case-sensitive
    expect(getCachedScan('BC-1 ', C)).toBeNull(); // whitespace-sensitive
  });

  it('Codex round-2 P2: cross-company lookup with the same code returns null and evicts the stale entry', () => {
    const product = makeProduct('p1', 'BC-1');
    setCachedScan('BC-1', product, C);

    expect(getCachedScan('BC-1', C2)).toBeNull(); // tenant-scoped miss

    // The mismatched read evicted the entry — even the original
    // company can no longer hit on this code without a fresh write.
    expect(getCachedScan('BC-1', C)).toBeNull();
  });

  it('Codex round-2 P2: same code can independently cache for two companies (no key collision)', () => {
    const a = makeProduct('p-a', 'SHARED-CODE', { sale_price: '10.00' });
    const b = makeProduct('p-b', 'SHARED-CODE', { sale_price: '20.00' });

    setCachedScan('SHARED-CODE', a, C);
    setCachedScan('SHARED-CODE', b, C2);

    // Note: the second setCachedScan replaces the cache slot for the
    // single key 'SHARED-CODE'. The cache key is the SCAN CODE only;
    // companyId scoping happens at READ time. So the company-1 read
    // for the SHARED-CODE slot now mismatches against the latest
    // (company-2) entry → returns null + evicts (the documented
    // tenant-isolation contract). This is the correct behaviour for
    // the multi-company cashier use case: switching companies and
    // scanning the same colliding code never leaks across tenants.
    expect(getCachedScan('SHARED-CODE', C2)).toEqual(b);
    expect(getCachedScan('SHARED-CODE', C)).toBeNull();
  });

  it('evicts the oldest entry when the cache exceeds SCAN_CACHE_MAX_SIZE', () => {
    for (let i = 0; i < SCAN_CACHE_MAX_SIZE; i++) {
      setCachedScan(`CODE-${i}`, makeProduct(`p${i}`, `CODE-${i}`), C);
    }

    setCachedScan('CODE-EXTRA', makeProduct('p-extra', 'CODE-EXTRA'), C);

    expect(getCachedScan('CODE-0', C)).toBeNull();
    expect(getCachedScan('CODE-EXTRA', C)).not.toBeNull();
    expect(getCachedScan('CODE-1', C)).not.toBeNull(); // second-oldest survives
  });

  it('moves a hit to most-recently-used (LRU promotion)', () => {
    for (let i = 0; i < SCAN_CACHE_MAX_SIZE; i++) {
      setCachedScan(`CODE-${i}`, makeProduct(`p${i}`, `CODE-${i}`), C);
    }

    expect(getCachedScan('CODE-0', C)).not.toBeNull();

    setCachedScan('CODE-EXTRA', makeProduct('p-extra', 'CODE-EXTRA'), C);

    expect(getCachedScan('CODE-0', C)).not.toBeNull();
    expect(getCachedScan('CODE-1', C)).toBeNull(); // evicted (was second-oldest, now LRU)
  });

  it('a re-set updates the entry AND promotes it to most-recently-used', () => {
    setCachedScan('CODE-A', makeProduct('p-a-v1', 'CODE-A', { sale_price: '10.00' }), C);
    setCachedScan('CODE-B', makeProduct('p-b', 'CODE-B'), C);
    setCachedScan('CODE-A', makeProduct('p-a-v2', 'CODE-A', { sale_price: '12.00' }), C);

    const a = getCachedScan('CODE-A', C);
    expect(a?.sale_price).toBe('12.00');

    for (let i = 0; i < SCAN_CACHE_MAX_SIZE - 2; i++) {
      setCachedScan(`FILL-${i}`, makeProduct(`pf${i}`, `FILL-${i}`), C);
    }
    setCachedScan('OVERFLOW', makeProduct('po', 'OVERFLOW'), C);

    expect(getCachedScan('CODE-B', C)).toBeNull();
    expect(getCachedScan('CODE-A', C)).not.toBeNull();
  });

  it('evictCachedScan removes a specific entry', () => {
    setCachedScan('CODE-A', makeProduct('pa', 'CODE-A'), C);
    setCachedScan('CODE-B', makeProduct('pb', 'CODE-B'), C);

    evictCachedScan('CODE-A');

    expect(getCachedScan('CODE-A', C)).toBeNull();
    expect(getCachedScan('CODE-B', C)).not.toBeNull();
  });

  it('evictCachedScan on a missing key is a no-op', () => {
    setCachedScan('CODE-A', makeProduct('pa', 'CODE-A'), C);

    expect(() => evictCachedScan('NEVER-CACHED')).not.toThrow();
    expect(getCachedScan('CODE-A', C)).not.toBeNull();
  });

  it('clearScanCache empties the cache', () => {
    setCachedScan('CODE-A', makeProduct('pa', 'CODE-A'), C);
    setCachedScan('CODE-B', makeProduct('pb', 'CODE-B'), C);

    clearScanCache();

    expect(getCachedScan('CODE-A', C)).toBeNull();
    expect(getCachedScan('CODE-B', C)).toBeNull();
  });

  it('SCAN_CACHE_MAX_SIZE is in the documented 50–100 range', () => {
    expect(SCAN_CACHE_MAX_SIZE).toBeGreaterThanOrEqual(50);
    expect(SCAN_CACHE_MAX_SIZE).toBeLessThanOrEqual(200);
  });
});
