import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';

// ─── Connectivity mock — mutable so offline tests can flip it ────────────────
let onlineState = true;
vi.mock('@/stores/connectivityStore', () => ({
  useConnectivityStore: (sel: (s: { isOnline: boolean }) => unknown) =>
    sel({ isOnline: onlineState }),
}));

// ─── Auth store mock ──────────────────────────────────────────────────────────
vi.mock('@/stores/authStore', () => ({
  useAuthStore: (sel: (s: { companyId: string | null }) => unknown) =>
    sel({ companyId: 'company-1' }),
}));

// ─── DB mock ─────────────────────────────────────────────────────────────────
vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn(async () => ({})),
}));

// ─── API mock ─────────────────────────────────────────────────────────────────
const fetchMock = vi.fn();
vi.mock('@/api/stockDistributionApi', () => ({
  fetchStockDistribution: (...a: unknown[]) => fetchMock(...a),
}));

// ─── Repository mocks ─────────────────────────────────────────────────────────
const upsertMock = vi.fn();
const getMock = vi.fn();
vi.mock('@/lib/db/repositories/crossLocationStockRepository', () => ({
  upsertDistribution: (...a: unknown[]) => upsertMock(...a),
  getDistribution: (...a: unknown[]) => getMock(...a),
  getAllForProduct: vi.fn(async () => []),
}));

import { useCrossLocationStock } from '@/hooks/useCrossLocationStock';

const AS_OF = '2026-06-14T10:00:00Z';

const payload = {
  product_id: 'p1',
  variant_id: null,
  variant_label: null,
  locations: [],
  totals: { on_hand: '0.0000', incoming_transfer: '0.0000' },
  as_of: AS_OF,
};

describe('useCrossLocationStock', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    onlineState = true;
    getMock.mockResolvedValue(null);
    upsertMock.mockResolvedValue(undefined);
  });

  // ── Test 1: online → fetch live + cache ────────────────────────────────────
  it('fetches live when online and calls upsert to cache', async () => {
    fetchMock.mockResolvedValue(payload);

    const { result } = renderHook(() =>
      useCrossLocationStock('p1', null, 'loc1', true),
    );

    await waitFor(() => expect(result.current.data).not.toBeNull());

    expect(result.current.source).toBe('live');
    expect(result.current.data).toEqual(payload);
    expect(result.current.fetchedAt).toBe(AS_OF);
    expect(result.current.error).toBeNull();
    expect(upsertMock).toHaveBeenCalledOnce();
    // isStale: AS_OF is recent (within 15 min) → false
    expect(result.current.isStale).toBe(false);
  });

  // ── Test 2: offline → falls through to cache ───────────────────────────────
  it('reads cache when offline', async () => {
    onlineState = false;
    const cachedRow = {
      product_id: 'p1',
      variant_id: '',
      variant_label: null,
      payload: JSON.stringify(payload),
      fetched_at: AS_OF,
    };
    getMock.mockResolvedValue(cachedRow);

    const { result } = renderHook(() =>
      useCrossLocationStock('p1', null, 'loc1', true),
    );

    await waitFor(() => expect(result.current.data).not.toBeNull());

    expect(result.current.source).toBe('cache');
    expect(result.current.data).toEqual(payload);
    expect(result.current.fetchedAt).toBe(AS_OF);
    expect(result.current.error).toBeNull();
    // fetch was never called
    expect(fetchMock).not.toHaveBeenCalled();
    // upsert was never called (offline)
    expect(upsertMock).not.toHaveBeenCalled();
  });

  // ── Test 3: offline + no cache → offline-no-cache error ───────────────────
  it('reports offline-no-cache error when offline and cache empty', async () => {
    onlineState = false;
    getMock.mockResolvedValue(null);

    const { result } = renderHook(() =>
      useCrossLocationStock('p1', null, 'loc1', true),
    );

    await waitFor(() => expect(result.current.isLoading).toBe(false));

    expect(result.current.data).toBeNull();
    expect(result.current.source).toBeNull();
    expect(result.current.error).toBe('offline-no-cache');
    expect(fetchMock).not.toHaveBeenCalled();
  });

  // ── Test 4: online fetch fails → falls through to cache ───────────────────
  it('falls back to cache when fetch throws', async () => {
    onlineState = true;
    fetchMock.mockRejectedValue(new Error('network error'));
    const cachedRow = {
      product_id: 'p1',
      variant_id: '',
      variant_label: null,
      payload: JSON.stringify(payload),
      fetched_at: AS_OF,
    };
    getMock.mockResolvedValue(cachedRow);

    const { result } = renderHook(() =>
      useCrossLocationStock('p1', null, 'loc1', true),
    );

    await waitFor(() => expect(result.current.data).not.toBeNull());

    expect(result.current.source).toBe('cache');
    expect(result.current.error).toBe('fetch-failed');
  });

  // ── Test 5: enabled=false → nothing happens ────────────────────────────────
  it('does nothing when enabled=false', async () => {
    fetchMock.mockResolvedValue(payload);

    const { result } = renderHook(() =>
      useCrossLocationStock('p1', null, 'loc1', false),
    );

    // Small delay to ensure no async work was kicked off
    await new Promise((r) => setTimeout(r, 50));

    expect(result.current.data).toBeNull();
    expect(result.current.source).toBeNull();
    expect(result.current.isLoading).toBe(false);
    expect(fetchMock).not.toHaveBeenCalled();
    expect(getMock).not.toHaveBeenCalled();
  });

  // ── Test 6: isStale true when fetchedAt is old ────────────────────────────
  it('isStale is true when fetchedAt is older than 15 min', async () => {
    const oldDate = new Date(Date.now() - 16 * 60_000).toISOString();
    const stalePayload = { ...payload, as_of: oldDate };
    fetchMock.mockResolvedValue(stalePayload);

    const { result } = renderHook(() =>
      useCrossLocationStock('p1', null, 'loc1', true),
    );

    await waitFor(() => expect(result.current.data).not.toBeNull());

    expect(result.current.isStale).toBe(true);
  });

  // ── Test 7: refresh() re-triggers load ────────────────────────────────────
  it('refresh() triggers a re-fetch', async () => {
    fetchMock.mockResolvedValue(payload);

    const { result } = renderHook(() =>
      useCrossLocationStock('p1', null, 'loc1', true),
    );

    await waitFor(() => expect(result.current.data).not.toBeNull());
    expect(fetchMock).toHaveBeenCalledTimes(1);

    result.current.refresh();

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
  });
});
