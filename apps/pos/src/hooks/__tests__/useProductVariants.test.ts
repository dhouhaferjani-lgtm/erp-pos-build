/**
 * FV3 — useProductVariants local-first hook contract.
 *
 * The hook reads local SQLite first. Online cold-fetch fires only when the
 * local cache is empty and the device is online. Offline with no cache yields
 * status 'offline-empty'. All mocks are scoped to this file.
 *
 * Review fixes (M3):
 *   FV3-H1 — no background refresh on local hit (fetchProductVariants must NOT
 *             be called when local rows exist).
 *   FV3-M1 — db-read failure (getVariantsForProduct rejects) → status 'error',
 *             variants [], not stuck 'loading'.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import type { POSProductVariant } from '@/types/product';

// ── mocks ──────────────────────────────────────────────────────────────────

const getVariantsForProductMock = vi.fn((..._args: unknown[]) => Promise.resolve([] as POSProductVariant[]));
vi.mock('@/lib/db/repositories/variantRepository', () => ({
  getVariantsForProduct: (...args: unknown[]) => getVariantsForProductMock(...args),
}));

const fetchProductVariantsMock = vi.fn((..._args: unknown[]) => Promise.resolve([] as POSProductVariant[]));
vi.mock('@/api/variantApi', () => ({
  fetchProductVariants: (...args: unknown[]) => fetchProductVariantsMock(...args),
}));

// isOnline is read via the selector (s) => s.isOnline. Mock the store itself
// so the selector receives the right value.
let _isOnline = true;
vi.mock('@/stores/connectivityStore', () => ({
  useConnectivityStore: (selector: (s: { isOnline: boolean }) => boolean) =>
    selector({ isOnline: _isOnline }),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: () => ({ companyId: 'company-abc' }),
  },
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({ _mock: 'db' }),
}));

// ── fixture ────────────────────────────────────────────────────────────────

function makeVariant(id: string): POSProductVariant {
  return {
    id,
    product_id: 'prod-1',
    variant_code: id,
    sku: id,
    barcode: null,
    name_suffix: ` — ${id}`,
    is_default: false,
    is_active: true,
    display_order: 0,
    price_override: null,
    image_url: null,
    stock_quantity: 5,
  };
}

// ── import hook AFTER mocks ────────────────────────────────────────────────
import { useProductVariants } from '../useProductVariants';

// ── tests ──────────────────────────────────────────────────────────────────

describe('useProductVariants — local-first contract', () => {
  beforeEach(() => {
    _isOnline = true;
    getVariantsForProductMock.mockReset();
    fetchProductVariantsMock.mockReset();
  });

  it('returns variants from local cache and status "local" when online + local rows exist', async () => {
    const localVariants = [makeVariant('v1'), makeVariant('v2')];
    getVariantsForProductMock.mockResolvedValue(localVariants);

    const { result } = renderHook(() => useProductVariants('prod-1'));

    await waitFor(() => expect(result.current.status).toBe('local'));
    expect(result.current.variants).toEqual(localVariants);
    expect(result.current.isLoading).toBe(false);
  });

  // FV3-H1: on a local hit, fetchProductVariants must NOT be called at all —
  // the 60s pullProductVariants sync keeps local fresh; an extra network call
  // here is a wasted round-trip that violates offline-first.
  it('FV3-H1: does NOT call fetchProductVariants when local rows exist', async () => {
    const localVariants = [makeVariant('v1'), makeVariant('v2')];
    getVariantsForProductMock.mockResolvedValue(localVariants);

    const { result } = renderHook(() => useProductVariants('prod-1'));

    await waitFor(() => expect(result.current.status).toBe('local'));
    expect(fetchProductVariantsMock).not.toHaveBeenCalled();
  });

  // FV3-M1: if getVariantsForProduct rejects (db read failure), the hook must
  // resolve to status 'error' with variants [] — never stay stuck at 'loading'.
  it('FV3-M1: db read failure → status "error" and variants [], not stuck loading', async () => {
    getVariantsForProductMock.mockRejectedValue(new Error('SQLite read failed'));

    const { result } = renderHook(() => useProductVariants('prod-1'));

    await waitFor(() => expect(result.current.status).toBe('error'));
    expect(result.current.variants).toEqual([]);
    expect(result.current.isLoading).toBe(false);
    // Must not have attempted a network call after the db failure
    expect(fetchProductVariantsMock).not.toHaveBeenCalled();
  });

  it('cold-fetches from server when online but no local cache, then status "local"', async () => {
    const serverVariants = [makeVariant('sv1')];
    // Local returns empty → triggers cold-fetch path
    getVariantsForProductMock.mockResolvedValue([]);
    fetchProductVariantsMock.mockResolvedValue(serverVariants);

    const { result } = renderHook(() => useProductVariants('prod-1'));

    // Wait for the effect to complete: status ends at 'local' with server data
    await waitFor(() => expect(result.current.status).toBe('local'));
    expect(result.current.variants).toEqual(serverVariants);
    expect(result.current.isLoading).toBe(false);
    // fetchProductVariants was called since we were online + no local cache
    expect(fetchProductVariantsMock).toHaveBeenCalledWith('prod-1');
  });

  it('returns status "offline-empty" with [] when offline and no local cache', async () => {
    _isOnline = false;
    getVariantsForProductMock.mockResolvedValue([]);

    const { result } = renderHook(() => useProductVariants('prod-1'));

    await waitFor(() => expect(result.current.status).toBe('offline-empty'));
    expect(result.current.variants).toEqual([]);
    expect(result.current.isLoading).toBe(false);
    // Must NOT have called the network
    expect(fetchProductVariantsMock).not.toHaveBeenCalled();
  });

  it('returns status "idle" with [] when productId is null', () => {
    const { result } = renderHook(() => useProductVariants(null));
    expect(result.current.status).toBe('idle');
    expect(result.current.variants).toEqual([]);
    expect(result.current.isLoading).toBe(false);
  });

  it('isLoading is true during loading phase', async () => {
    // Delay local query so we can observe the loading state
    let resolveLocal!: (v: POSProductVariant[]) => void;
    getVariantsForProductMock.mockReturnValue(
      new Promise<POSProductVariant[]>((res) => { resolveLocal = res; }),
    );

    const { result } = renderHook(() => useProductVariants('prod-1'));

    // Synchronously after mount, status should be 'loading'
    expect(result.current.isLoading).toBe(true);
    expect(result.current.status).toBe('loading');

    // Resolve to end the test cleanly
    resolveLocal([]);
    fetchProductVariantsMock.mockResolvedValue([]);
    await waitFor(() => expect(result.current.status).not.toBe('loading'));
  });
});
