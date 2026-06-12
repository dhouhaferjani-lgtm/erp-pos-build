import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
}));

vi.mock('@/lib/device', () => ({
  getDeviceId: vi.fn(() => 'device-abc'),
}));

vi.mock('@/lib/storage', () => ({
  getStoredValue: vi.fn().mockResolvedValue(null),
  setStoredValue: vi.fn().mockResolvedValue(undefined),
  removeStoredValue: vi.fn().mockResolvedValue(undefined),
  StorageKeys: {
    TERMINAL: 'terminal',
    PENDING_TERMINAL_ID: 'pending_terminal_id',
    SHIFT: 'current_shift',
  },
}));

vi.mock('@/lib/sync/syncScheduler', () => ({
  SyncScheduler: vi.fn().mockImplementation(() => ({
    start: vi.fn(),
  })),
}));

vi.mock('@/lib/sync/syncService', () => ({
  pullTerminalState: vi.fn().mockResolvedValue(undefined),
  pullZChainState: vi.fn().mockResolvedValue(undefined),
  // Task 9 — terminalStore now also imports pullLocationStock (boot/claim
  // + shift-open full-pull hooks).
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
  usePaymentStore: {
    getState: vi.fn(() => ({
      fetchPaymentConfig: vi.fn().mockResolvedValue(undefined),
      refreshFromSQLite: vi.fn().mockResolvedValue(undefined),
    })),
  },
}));

vi.mock('@/stores/syncStore', () => ({
  useSyncStore: {
    getState: vi.fn(() => ({
      setScheduler: vi.fn(),
      setPendingCount: vi.fn(),
      setLastSyncAt: vi.fn(),
      lastSyncAt: null,
    })),
  },
}));

vi.mock('@/lib/fiscal/zSessionAuthoring', () => ({
  authorZSessionOpenWithOpeningFloat: vi.fn(),
}));

import { apiGet } from '@/lib/api';
import { getStoredValue, setStoredValue, StorageKeys } from '@/lib/storage';
import { useTerminalStore, type Terminal } from '../terminalStore';

const terminalWithBranchTax = {
  id: 'term-1',
  code: 'T001',
  name: 'Register 1',
  type: 'physical',
  is_active: true,
  fiscal_schema_version: 3,
  is_training_mode: false,
  hardware_identifier: 'device-abc',
  location: {
    id: 'loc-1',
    name: 'Paris Shop',
    code: 'PARIS',
    tax_id: '73282932000074',
    vat_number: 'FR40303265045',
    legal_identifiers: { siret: '73282932000074' },
  },
} satisfies Terminal;

describe('terminalStore branch tax identity', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useTerminalStore.setState({
      terminal: null,
      pendingTerminalId: null,
      shift: null,
      isLoading: false,
      hashChainReady: false,
    });
  });

  it('rehydrates and persists terminal.location branch tax fields intact', async () => {
    vi.mocked(getStoredValue).mockImplementation(async (key: string) => {
      if (key === StorageKeys.TERMINAL) return terminalWithBranchTax;
      return null;
    });
    vi.mocked(apiGet).mockRejectedValue(new Error('offline'));

    await useTerminalStore.getState().initialize();

    expect(useTerminalStore.getState().terminal?.location.tax_id).toBe('73282932000074');
    expect(useTerminalStore.getState().terminal?.location.vat_number).toBe('FR40303265045');
    expect(useTerminalStore.getState().terminal?.location.legal_identifiers).toEqual({
      siret: '73282932000074',
    });
    expect(setStoredValue).toHaveBeenCalledWith(StorageKeys.TERMINAL, terminalWithBranchTax);
  });
});
