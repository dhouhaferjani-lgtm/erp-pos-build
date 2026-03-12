import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import { useConnectivityStore } from '../connectivityStore';

vi.mock('@/lib/connectivity', () => ({
  checkServerHealth: vi.fn(),
  isBrowserOnline: vi.fn(() => true),
}));

import { checkServerHealth, isBrowserOnline } from '@/lib/connectivity';

describe('connectivityStore', () => {
  beforeEach(() => {
    useConnectivityStore.setState({
      isOnline: true,
      serverReachable: false,
      lastCheckedAt: null,
    });
    vi.clearAllMocks();
    vi.mocked(isBrowserOnline).mockReturnValue(true);
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('has correct initial state', () => {
    const state = useConnectivityStore.getState();
    expect(state.isOnline).toBe(true);
    expect(state.serverReachable).toBe(false);
    expect(state.lastCheckedAt).toBeNull();
  });

  it('checkNow sets online when server is reachable', async () => {
    vi.mocked(checkServerHealth).mockResolvedValue(true);

    await useConnectivityStore.getState().checkNow();

    const state = useConnectivityStore.getState();
    expect(state.isOnline).toBe(true);
    expect(state.serverReachable).toBe(true);
    expect(state.lastCheckedAt).not.toBeNull();
  });

  it('checkNow sets offline when server is unreachable', async () => {
    vi.mocked(checkServerHealth).mockResolvedValue(false);

    await useConnectivityStore.getState().checkNow();

    const state = useConnectivityStore.getState();
    expect(state.isOnline).toBe(false);
    expect(state.serverReachable).toBe(false);
  });

  it('checkNow short-circuits when browser is offline', async () => {
    vi.mocked(isBrowserOnline).mockReturnValue(false);

    await useConnectivityStore.getState().checkNow();

    expect(checkServerHealth).not.toHaveBeenCalled();
    const state = useConnectivityStore.getState();
    expect(state.isOnline).toBe(false);
    expect(state.serverReachable).toBe(false);
    expect(state.lastCheckedAt).not.toBeNull();
  });

  it('startMonitoring returns cleanup function', () => {
    vi.mocked(checkServerHealth).mockResolvedValue(true);
    const cleanup = useConnectivityStore.getState().startMonitoring();
    expect(typeof cleanup).toBe('function');
    cleanup();
  });

  it('startMonitoring triggers initial check', () => {
    vi.mocked(checkServerHealth).mockResolvedValue(true);
    const cleanup = useConnectivityStore.getState().startMonitoring();

    expect(checkServerHealth).toHaveBeenCalled();
    cleanup();
  });
});
