/**
 * Bug 1 — coverage for the catalog-channel WebSocket hook.
 *
 * Mocks `@/lib/echo` because the real Echo client needs a live Reverb
 * broker. Verifies:
 *   - subscribe lifecycle (mount → subscribe, unmount → leave)
 *   - the catalog.changed event triggers a debounced fetchProducts(true)
 *   - rapid bursts of events coalesce to a single refresh
 *   - no subscription happens before companyId is set
 */

import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useAuthStore } from '@/stores/authStore';

const mockListenHandlers: Record<string, (payload: unknown) => void> = {};
const mockSubscribed = vi.fn();
const mockError = vi.fn();
const mockListen = vi.fn((event: string, cb: (payload: unknown) => void) => {
  mockListenHandlers[event] = cb;
  return channelChain;
});
const channelChain = {
  subscribed: vi.fn((cb: () => void) => {
    mockSubscribed.mockImplementation(cb);
    return channelChain;
  }),
  error: vi.fn((cb: (err: unknown) => void) => {
    mockError.mockImplementation(cb);
    return channelChain;
  }),
  listen: mockListen,
};
const mockPrivate = vi.fn(() => channelChain);
const mockLeave = vi.fn();

vi.mock('@/lib/echo', () => ({
  getEcho: () => ({
    private: mockPrivate,
    leave: mockLeave,
  }),
}));

const fetchProductsMock = vi.fn().mockResolvedValue(undefined);
vi.mock('@/stores/productStore', () => ({
  useProductStore: (selector: (s: { fetchProducts: typeof fetchProductsMock }) => unknown) =>
    selector({ fetchProducts: fetchProductsMock }),
}));

function seedAuth(): void {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Houssem',
      email: 'h@example.com',
      tenantId: 'tenant-1',
      phone: null,
      status: 'active',
      locale: null,
      timezone: null,
      roles: [],
      permissions: [],
      emailVerified: true,
    },
    companyId: 'company-1',
    companies: [],
    token: 'tok',
    serverUrl: 'http://localhost',
    isAuthenticated: true,
    isLoading: false,
    isInitialized: true,
  });
}

function clearAuth(): void {
  useAuthStore.setState({
    user: null,
    companyId: null,
  } as never);
}

describe('useCatalogChannel', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.useFakeTimers();
    for (const k of Object.keys(mockListenHandlers)) delete mockListenHandlers[k];
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('subscribes to private-tenant.{tenantId}.company.{companyId}.catalog when auth set', async () => {
    seedAuth();
    const { useCatalogChannel } = await import('@/hooks/useCatalogChannel');

    renderHook(() => useCatalogChannel());

    expect(mockPrivate).toHaveBeenCalledWith('tenant.tenant-1.company.company-1.catalog');
    expect(channelChain.listen).toHaveBeenCalledWith('.catalog.changed', expect.any(Function));
  });

  it('does not subscribe when companyId is null', async () => {
    clearAuth();
    const { useCatalogChannel } = await import('@/hooks/useCatalogChannel');

    renderHook(() => useCatalogChannel());

    expect(mockPrivate).not.toHaveBeenCalled();
  });

  it('debounces catalog.changed events and triggers fetchProducts(true) after 500ms', async () => {
    seedAuth();
    const { useCatalogChannel } = await import('@/hooks/useCatalogChannel');

    renderHook(() => useCatalogChannel());

    // Burst of events — should coalesce to one fetchProducts call.
    act(() => {
      mockListenHandlers['.catalog.changed']?.({ reason: 'Product.saved' });
      mockListenHandlers['.catalog.changed']?.({ reason: 'Product.saved' });
      mockListenHandlers['.catalog.changed']?.({ reason: 'MenuCategory.saved' });
    });

    expect(fetchProductsMock).not.toHaveBeenCalled();

    act(() => {
      vi.advanceTimersByTime(499);
    });
    expect(fetchProductsMock).not.toHaveBeenCalled();

    act(() => {
      vi.advanceTimersByTime(1);
    });
    expect(fetchProductsMock).toHaveBeenCalledTimes(1);
    expect(fetchProductsMock).toHaveBeenCalledWith(true);
  });

  it('leaves the channel on unmount', async () => {
    seedAuth();
    const { useCatalogChannel } = await import('@/hooks/useCatalogChannel');

    const { unmount } = renderHook(() => useCatalogChannel());

    unmount();

    expect(mockLeave).toHaveBeenCalledWith('tenant.tenant-1.company.company-1.catalog');
  });

  it('does not call fetchProducts if unmount fires before the debounce settles', async () => {
    seedAuth();
    const { useCatalogChannel } = await import('@/hooks/useCatalogChannel');

    const { unmount } = renderHook(() => useCatalogChannel());

    act(() => {
      mockListenHandlers['.catalog.changed']?.({ reason: 'Product.saved' });
    });

    unmount();

    act(() => {
      vi.advanceTimersByTime(2000);
    });

    expect(fetchProductsMock).not.toHaveBeenCalled();
  });
});
