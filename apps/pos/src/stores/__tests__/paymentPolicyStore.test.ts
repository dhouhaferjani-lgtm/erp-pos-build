import { beforeEach, describe, expect, it, vi } from 'vitest';

const apiGet = vi.fn();
vi.mock('@/lib/api', () => ({ apiGet: (...args: unknown[]) => apiGet(...args) }));

const upsertPaymentPolicy = vi.fn();
const getPaymentPolicy = vi.fn();
vi.mock('@/lib/db/repositories/paymentPolicyCacheRepository', () => ({
  upsertPaymentPolicy: (...args: unknown[]) => upsertPaymentPolicy(...args),
  getPaymentPolicy: (...args: unknown[]) => getPaymentPolicy(...args),
}));

import {
  getActivePaymentPolicy,
  hydratePaymentPolicyFromCache,
  refreshPaymentPolicy,
  usePaymentPolicyStore,
} from '@/stores/paymentPolicyStore';

const db = {} as never;

/** `YYYY-MM-DD HH:MM:SS` — the ONE on-device timestamp format (rule 20). */
const SQLITE_UTC = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/;

/**
 * The real wire shape of GET /api/v1/pos/payment-policy, verified against
 * apps/api PosPaymentPolicyDTO: `companyId` and `refreshedAt` are present and
 * `cashRoundingDenomination` is NON-nullable (a canonical zero at currency
 * scale stands in for "rounding does not apply").
 */
const wireResponse = {
  companyId: 'company-tnd',
  currencyCode: 'TND',
  currencyScale: 3,
  cashRoundingEnabled: true,
  cashRoundingDenomination: '0.050',
  tenderToleranceEnabled: false,
  tenderTolerancePercentage: '0.0050',
  tenderToleranceMaxAmount: '0.100',
  refreshedAt: '2026-07-27T08:00:00Z',
};

const cachedRow = {
  company_id: 'company-tnd',
  cash_rounding_enabled: true,
  cash_rounding_denomination: '0.050',
  tender_tolerance_enabled: true,
  tender_tolerance_percentage: '0.0050',
  tender_tolerance_max_amount: '0.100',
  currency_code: 'TND',
  currency_scale: 3,
  refreshed_at: '2026-07-27 08:00:00',
};

describe('paymentPolicyStore', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    usePaymentPolicyStore.getState().reset();
  });

  it('starts fail-closed: no policy means both mechanisms are off', () => {
    expect(getActivePaymentPolicy()).toBeNull();
  });

  it('hydrates from SQLite without touching the network', async () => {
    getPaymentPolicy.mockResolvedValue(cachedRow);

    await hydratePaymentPolicyFromCache(db, 'company-tnd');

    expect(apiGet).not.toHaveBeenCalled();
    expect(getActivePaymentPolicy()).toEqual({
      cashRoundingEnabled: true,
      cashRoundingDenomination: '0.050',
      tenderToleranceEnabled: true,
      tenderTolerancePercentage: '0.0050',
      tenderToleranceMaxAmount: '0.100',
      currencyCode: 'TND',
      currencyScale: 3,
      refreshedAt: '2026-07-27 08:00:00',
    });
  });

  it('an empty cache leaves the policy null (fail-closed, never a fabricated default)', async () => {
    getPaymentPolicy.mockResolvedValue(null);
    await hydratePaymentPolicyFromCache(db, 'company-tnd');
    expect(getActivePaymentPolicy()).toBeNull();
  });

  it('refresh writes the API response through to SQLite and to the slice', async () => {
    apiGet.mockResolvedValue(wireResponse);

    await refreshPaymentPolicy(db, 'company-tnd');

    expect(apiGet).toHaveBeenCalledWith('/pos/payment-policy');
    expect(upsertPaymentPolicy).toHaveBeenCalledWith(db, expect.objectContaining({
      company_id: 'company-tnd',
      cash_rounding_denomination: '0.050',
      tender_tolerance_enabled: false,
      currency_scale: 3,
    }));
    expect(getActivePaymentPolicy()?.cashRoundingDenomination).toBe('0.050');
  });

  it('carries the canonical-zero denomination through as an unmutated STRING', async () => {
    // The server never sends null; when rounding does not apply it sends a
    // canonical zero at currency scale. '0.000' must NOT become 0 / '0' — the
    // denomination is signed into the SALE_RECEIPT canonical bytes.
    apiGet.mockResolvedValue({
      ...wireResponse,
      cashRoundingEnabled: false,
      cashRoundingDenomination: '0.000',
    });

    await refreshPaymentPolicy(db, 'company-tnd');

    expect(upsertPaymentPolicy).toHaveBeenCalledWith(db, expect.objectContaining({
      cash_rounding_enabled: false,
      cash_rounding_denomination: '0.000',
    }));
    const denomination = getActivePaymentPolicy()?.cashRoundingDenomination;
    expect(denomination).toBe('0.000');
    expect(typeof denomination).toBe('string');
  });

  it('hydrate and refresh agree on ONE refreshedAt format (SQLite UTC, space separator)', async () => {
    getPaymentPolicy.mockResolvedValue(cachedRow);
    await hydratePaymentPolicyFromCache(db, 'company-tnd');
    const hydrated = getActivePaymentPolicy()?.refreshedAt;
    expect(hydrated).toMatch(SQLITE_UTC);

    apiGet.mockResolvedValue(wireResponse);
    await refreshPaymentPolicy(db, 'company-tnd');
    const refreshed = getActivePaymentPolicy()?.refreshedAt;

    // ISO ('T' separator) here would silently mis-order against the
    // datetime('now') column SQLite compares lexicographically (' ' < 'T').
    expect(refreshed).toMatch(SQLITE_UTC);
    expect(refreshed).not.toContain('T');
  });

  it('a failed refresh keeps the previously hydrated policy (offline tolerance)', async () => {
    getPaymentPolicy.mockResolvedValue(cachedRow);
    await hydratePaymentPolicyFromCache(db, 'company-tnd');

    apiGet.mockRejectedValue(new Error('offline'));
    await expect(refreshPaymentPolicy(db, 'company-tnd')).rejects.toThrow('offline');

    expect(getActivePaymentPolicy()?.cashRoundingEnabled).toBe(true);
  });
});

describe('paymentPolicyStore — cross-company staleness', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    usePaymentPolicyStore.getState().reset();
  });

  it('CLEARS a previously-loaded policy when the new company has no cached row', async () => {
    // The traced path: sign out -> setCompany(companyB) -> terminal activation
    // hydrates -> companyB has no cached row. Pre-fix this returned early and
    // companyA's denomination kept deciding companyB's checkouts offline
    // (refreshPaymentPolicy is the only other writer).
    getPaymentPolicy.mockResolvedValueOnce(cachedRow);
    await hydratePaymentPolicyFromCache(db, 'company-tnd');
    expect(getActivePaymentPolicy()).not.toBeNull();

    getPaymentPolicy.mockResolvedValueOnce(null);
    await hydratePaymentPolicyFromCache(db, 'company-b');

    expect(getActivePaymentPolicy()).toBeNull();
  });

  it('replaces, never merges, when the new company HAS a cached row', async () => {
    getPaymentPolicy.mockResolvedValueOnce(cachedRow);
    await hydratePaymentPolicyFromCache(db, 'company-tnd');

    getPaymentPolicy.mockResolvedValueOnce({
      ...cachedRow,
      company_id: 'company-b',
      currency_code: 'EUR',
      currency_scale: 2,
      cash_rounding_denomination: '0.05',
    });
    await hydratePaymentPolicyFromCache(db, 'company-b');

    expect(getActivePaymentPolicy()).toMatchObject({
      currencyCode: 'EUR',
      cashRoundingDenomination: '0.05',
    });
  });
});
