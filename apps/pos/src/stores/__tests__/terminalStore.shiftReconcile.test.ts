/**
 * Offline-first shifts Phase 6.2 — the advisory remote-close banner state on the
 * terminal store. The background reconcile (syncService.runFullSync) flags this
 * when the server projection shows the device's open shift CLOSED, and clears it
 * once the conflict resolves. It never auto-closes the local shift.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/lib/api', () => ({ apiGet: vi.fn(), apiPost: vi.fn() }));
vi.mock('@/lib/db', () => ({ getDatabase: vi.fn().mockResolvedValue({}) }));
vi.mock('@/lib/device', () => ({ getDeviceId: vi.fn(() => 'device-abc') }));
vi.mock('@/lib/storage', () => ({
  getStoredValue: vi.fn().mockResolvedValue(null),
  setStoredValue: vi.fn().mockResolvedValue(undefined),
  removeStoredValue: vi.fn().mockResolvedValue(undefined),
  StorageKeys: { TERMINAL: 'terminal', PENDING_TERMINAL_ID: 'pending_terminal_id', SHIFT: 'current_shift' },
}));
vi.mock('@/lib/sync/syncScheduler', () => ({
  SyncScheduler: vi.fn().mockImplementation(() => ({ start: vi.fn() })),
}));
vi.mock('@/lib/sync/syncService', () => ({
  pullTerminalState: vi.fn().mockResolvedValue(undefined),
  pullZChainState: vi.fn().mockResolvedValue(undefined),
  pullLocationStock: vi.fn().mockResolvedValue({ count: 0 }),
}));
vi.mock('@/lib/db/repositories/terminalStateRepository', () => ({
  getTerminalState: vi.fn().mockResolvedValue({}),
}));
vi.mock('@/lib/db/repositories/offlineReceiptRepository', () => ({
  getPendingReceiptCount: vi.fn().mockResolvedValue(0),
  recoverStrandedSyncingReceipts: vi.fn().mockResolvedValue(0),
}));
vi.mock('@/lib/db/repositories/cashDrawerRepository', () => ({
  recoverStrandedSyncingCashDrawerOps: vi.fn().mockResolvedValue(0),
}));
vi.mock('@/lib/db/repositories/syncLogRepository', () => ({
  getSyncMetadata: vi.fn().mockResolvedValue(null),
}));
vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: vi.fn(() => ({
      companyId: 'company-1',
      token: 'token-1',
      user: { id: 'user-1', name: 'Jane', tenantId: 'tenant-1' },
      companies: [{ id: 'company-1', currency: 'EUR' }],
    })),
  },
}));
vi.mock('@/stores/paymentStore', () => ({
  usePaymentStore: { getState: vi.fn(() => ({ fetchPaymentConfig: vi.fn(), refreshFromSQLite: vi.fn() })) },
}));
vi.mock('@/stores/syncStore', () => ({
  useSyncStore: {
    getState: vi.fn(() => ({
      scheduler: null,
      setScheduler: vi.fn(),
      setPendingCount: vi.fn(),
      setLastSyncAt: vi.fn(),
      lastSyncAt: null,
      reset: vi.fn(),
    })),
  },
}));
vi.mock('@/lib/fiscal/zSessionAuthoring', () => ({ authorZSessionOpenWithOpeningFloat: vi.fn() }));

import { useTerminalStore } from '../terminalStore';

describe('terminalStore remote-close conflict banner', () => {
  beforeEach(() => {
    useTerminalStore.setState({
      terminal: null,
      pendingTerminalId: null,
      shift: null,
      isLoading: false,
      hashChainReady: false,
      remoteShiftCloseConflict: null,
    });
  });

  it('defaults to no conflict', () => {
    expect(useTerminalStore.getState().remoteShiftCloseConflict).toBeNull();
  });

  it('flagRemoteShiftClose records the conflicted shift', () => {
    useTerminalStore.getState().flagRemoteShiftClose({ shiftId: 'shift-7', shiftNumber: 7 });
    expect(useTerminalStore.getState().remoteShiftCloseConflict).toEqual({
      shiftId: 'shift-7',
      shiftNumber: 7,
    });
  });

  it('clearRemoteShiftCloseConflict resets it', () => {
    useTerminalStore.getState().flagRemoteShiftClose({ shiftId: 'shift-7', shiftNumber: 7 });
    useTerminalStore.getState().clearRemoteShiftCloseConflict();
    expect(useTerminalStore.getState().remoteShiftCloseConflict).toBeNull();
  });

  it('reset() clears any active conflict', () => {
    useTerminalStore.getState().flagRemoteShiftClose({ shiftId: 'shift-7', shiftNumber: 7 });
    useTerminalStore.getState().reset();
    expect(useTerminalStore.getState().remoteShiftCloseConflict).toBeNull();
  });
});
