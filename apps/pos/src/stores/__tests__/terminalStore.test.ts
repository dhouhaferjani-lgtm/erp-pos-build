import { describe, it, expect, beforeEach, vi } from 'vitest';
import { useTerminalStore } from '../terminalStore';
import type { Terminal, Shift } from '../terminalStore';

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}));

vi.mock('@/lib/device', () => ({
  getDeviceId: vi.fn(() => 'device-abc'),
}));

vi.mock('@/lib/storage', () => ({
  getStoredValue: vi.fn().mockResolvedValue(null),
  setStoredValue: vi.fn().mockResolvedValue(undefined),
  removeStoredValue: vi.fn().mockResolvedValue(undefined),
  StorageKeys: {
    TOKEN: 'auth_token',
    SERVER_URL: 'server_url',
    USER: 'user',
    COMPANY_ID: 'company_id',
    COMPANIES: 'companies',
    TERMINAL: 'terminal',
    PENDING_TERMINAL_ID: 'pending_terminal_id',
    SHIFT: 'current_shift',
  },
}));

vi.mock('@/lib/db', () => ({
  execute: vi.fn().mockResolvedValue({ rowsAffected: 0 }),
  getDatabase: vi.fn(),
  queryAll: vi.fn().mockResolvedValue([]),
  queryOne: vi.fn().mockResolvedValue(null),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: vi.fn(() => ({
      companyId: 'company-1',
      token: 'token-1',
      user: {
        id: '22222222-2222-4222-8222-222222222222',
        name: 'Jane',
        tenantId: 'tenant-1',
      },
      companies: [{ id: 'company-1', currency: 'EUR' }],
    })),
  },
}));

vi.mock('@/lib/fiscal/zSessionAuthoring', () => ({
  authorZSessionOpenWithOpeningFloat: vi.fn().mockResolvedValue({
    sessionOpenEvent: { id: 'session-open-event' },
    openingFloatEvent: { id: 'opening-float-event' },
    openingFloatMovementId: 'movement-1',
  }),
}));

vi.mock('@/stores/paymentStore', () => ({
  usePaymentStore: {
    getState: vi.fn(() => ({
      fetchPaymentConfig: vi.fn().mockResolvedValue(undefined),
      refreshFromSQLite: vi.fn().mockResolvedValue(undefined),
    })),
  },
}));

vi.mock('@/lib/db/repositories/operatorPinRepository', () => ({
  invalidateTerminalDiscountPermissions: vi.fn().mockResolvedValue(undefined),
}));

const operatorStoreMocks = vi.hoisted(() => ({
  invalidateDiscountPermissions: vi.fn(),
  refreshDiscountPermissions: vi.fn(),
}));
vi.mock('@/stores/operatorStore', () => ({
  useOperatorStore: {
    getState: vi.fn(() => ({
      invalidateDiscountPermissions: operatorStoreMocks.invalidateDiscountPermissions,
      refreshDiscountPermissions: operatorStoreMocks.refreshDiscountPermissions,
    })),
  },
}));

import { apiGet, apiPost } from '@/lib/api';
import { getDatabase } from '@/lib/db';
import { invalidateTerminalDiscountPermissions } from '@/lib/db/repositories/operatorPinRepository';
import { authorZSessionOpenWithOpeningFloat } from '@/lib/fiscal/zSessionAuthoring';
import { getStoredValue, setStoredValue, removeStoredValue } from '@/lib/storage';

const mockDb = {
  execute: vi.fn().mockResolvedValue({ rowsAffected: 0 }),
  select: vi.fn().mockResolvedValue([]),
  close: vi.fn().mockResolvedValue(undefined),
} as unknown as import('@tauri-apps/plugin-sql').default;

const mockTerminal: Terminal = {
  id: 'term-1',
  code: 'T001',
  name: 'Register 1',
  type: 'pos',
  is_active: true,
  fiscal_schema_version: 2,
  is_training_mode: false,
  hardware_identifier: 'device-abc',
  location: { id: 'loc-1', name: 'Main Store', code: 'LOC-001' },
};

const mockShift: Shift = {
  id: 'shift-1',
  terminal_id: 'term-1',
  shift_number: 1,
  status: 'OPEN',
  opening_cash: '100.00',
  opened_at: '2026-03-12T08:00:00.000Z',
  user: { id: 'user-1', name: 'Jane' },
};

describe('terminalStore', () => {
  beforeEach(() => {
    useTerminalStore.setState({
      terminal: null,
      pendingTerminalId: null,
      shift: null,
      isLoading: false,
    });
    vi.clearAllMocks();
    vi.mocked(getDatabase).mockResolvedValue(mockDb);
    operatorStoreMocks.invalidateDiscountPermissions.mockReturnValue(undefined);
    operatorStoreMocks.refreshDiscountPermissions.mockResolvedValue(undefined);
  });

  it('has correct initial state', () => {
    const state = useTerminalStore.getState();
    expect(state.terminal).toBeNull();
    expect(state.pendingTerminalId).toBeNull();
    expect(state.shift).toBeNull();
    expect(state.isLoading).toBe(false);
  });

  it('claimTerminal stores terminal', async () => {
    vi.mocked(apiPost).mockResolvedValue(mockTerminal);

    await useTerminalStore.getState().claimTerminal('term-1', 'device-abc');

    const state = useTerminalStore.getState();
    expect(state.terminal).toEqual(mockTerminal);
    expect(state.isLoading).toBe(false);
  });

  it('claimTerminal propagates errors', async () => {
    vi.mocked(apiPost).mockRejectedValue(new Error('Terminal already claimed'));

    await expect(
      useTerminalStore.getState().claimTerminal('term-1', 'device-abc'),
    ).rejects.toThrow('Terminal already claimed');

    expect(useTerminalStore.getState().isLoading).toBe(false);
  });

  it('fetchAvailable returns terminal list', async () => {
    vi.mocked(apiGet).mockResolvedValue([mockTerminal]);

    const terminals = await useTerminalStore.getState().fetchAvailable();

    expect(terminals).toHaveLength(1);
    expect(terminals[0]!.code).toBe('T001');
  });

  it('requestTerminal stores pending terminal id', async () => {
    vi.mocked(apiPost).mockResolvedValue({ ...mockTerminal, is_active: false });

    const terminal = await useTerminalStore.getState().requestTerminal('loc-1', 'Register 2', 'device-abc');

    expect(terminal).toBeDefined();
    expect(useTerminalStore.getState().pendingTerminalId).toBe('term-1');
  });

  it('checkTerminalStatus activates terminal when is_active', async () => {
    vi.mocked(apiGet).mockResolvedValue(mockTerminal);

    const terminal = await useTerminalStore.getState().checkTerminalStatus('term-1');

    expect(terminal.is_active).toBe(true);
    expect(useTerminalStore.getState().terminal).toEqual(mockTerminal);
    expect(useTerminalStore.getState().pendingTerminalId).toBeNull();
  });

  it('checkTerminalStatus does not activate when not active', async () => {
    vi.mocked(apiGet).mockResolvedValue({ ...mockTerminal, is_active: false });

    await useTerminalStore.getState().checkTerminalStatus('term-1');

    expect(useTerminalStore.getState().terminal).toBeNull();
  });

  it('openShift creates a shift', async () => {
    useTerminalStore.setState({ terminal: mockTerminal });
    vi.mocked(apiPost).mockResolvedValue(mockShift);

    await useTerminalStore.getState().openShift('100.00');

    expect(useTerminalStore.getState().shift).toEqual(expect.objectContaining(mockShift));
    expect(useTerminalStore.getState().isLoading).toBe(false);
  });

  it('openShift authors Z-session opening fiscal events', async () => {
    useTerminalStore.setState({ terminal: mockTerminal });
    vi.mocked(apiPost).mockResolvedValue(mockShift);

    await useTerminalStore.getState().openShift('100.00');

    expect(authorZSessionOpenWithOpeningFloat).toHaveBeenCalledWith(expect.objectContaining({
      tenantId: 'tenant-1',
      companyId: 'company-1',
      terminalId: 'term-1',
      terminalLabel: 'T001',
      shiftId: expect.any(String),
      sessionId: expect.any(String),
      businessDate: '2026-03-12',
      operatorId: 'user-1',
      operatorName: 'Jane',
      currencyCode: 'EUR',
      currencyScale: 2,
      openingFloatAmount: '100.00',
      isTraining: false,
    }));
  });

  it('openShift throws when no terminal configured', async () => {
    await expect(useTerminalStore.getState().openShift('100.00')).rejects.toThrow(
      'No terminal configured',
    );
  });

  it('closeShift clears shift', async () => {
    useTerminalStore.setState({ terminal: mockTerminal, shift: mockShift });
    vi.mocked(apiPost).mockResolvedValue({ ...mockShift, status: 'CLOSED' });

    await useTerminalStore.getState().closeShift('150.00');

    expect(useTerminalStore.getState().shift).toBeNull();
  });

  it('closeShift throws when no active shift', async () => {
    await expect(useTerminalStore.getState().closeShift('150.00')).rejects.toThrow(
      'No active shift',
    );
  });

  it('reset clears terminal state and storage', () => {
    useTerminalStore.setState({ terminal: mockTerminal, shift: mockShift });

    useTerminalStore.getState().reset();

    const state = useTerminalStore.getState();
    expect(state.terminal).toBeNull();
    expect(state.shift).toBeNull();
    expect(state.pendingTerminalId).toBeNull();
    expect(removeStoredValue).toHaveBeenCalledWith('terminal');
    expect(removeStoredValue).toHaveBeenCalledWith('pending_terminal_id');
    expect(removeStoredValue).toHaveBeenCalledWith('current_shift');
  });

  it('openShift persists shift to storage', async () => {
    useTerminalStore.setState({ terminal: mockTerminal });
    vi.mocked(apiPost).mockResolvedValue(mockShift);

    await useTerminalStore.getState().openShift('100.00');

    expect(setStoredValue).toHaveBeenCalledWith('current_shift', expect.objectContaining(mockShift));
  });

  it('openShift creates local shift when API fails (offline)', async () => {
    useTerminalStore.setState({ terminal: mockTerminal });
    vi.mocked(apiPost).mockRejectedValue(new Error('Network error'));

    await useTerminalStore.getState().openShift('100.00');

    const state = useTerminalStore.getState();
    expect(state.shift).not.toBeNull();
    expect(state.shift!.terminal_id).toBe('term-1');
    expect(state.shift!.opening_cash).toBe('100.00');
    expect(state.shift!.status).toBe('OPEN');
    expect(state.shift!.id).toMatch(/^offline-/);
    expect(state.shift!.fiscal_shift_id).toMatch(/^[0-9a-f-]{36}$/);
    expect(state.shift!.fiscal_session_id).toMatch(/^[0-9a-f-]{36}$/);
    expect(state.isLoading).toBe(false);
    expect(setStoredValue).toHaveBeenCalledWith('current_shift', expect.objectContaining({
      terminal_id: 'term-1',
      status: 'OPEN',
    }));
  });

  it('fetchCurrentShift restores shift from storage when API fails (offline)', async () => {
    useTerminalStore.setState({ terminal: mockTerminal });
    vi.mocked(apiGet).mockRejectedValue(new Error('Network error'));
    vi.mocked(getStoredValue).mockResolvedValue(mockShift);

    await useTerminalStore.getState().fetchCurrentShift();

    expect(useTerminalStore.getState().shift).toEqual(mockShift);
  });

  it('fetchCurrentShift sets shift to null when API fails and no cached shift', async () => {
    useTerminalStore.setState({ terminal: mockTerminal });
    vi.mocked(apiGet).mockRejectedValue(new Error('Network error'));
    vi.mocked(getStoredValue).mockResolvedValue(null);

    await useTerminalStore.getState().fetchCurrentShift();

    expect(useTerminalStore.getState().shift).toBeNull();
  });

  it('closeShift clears shift even when API fails (offline)', async () => {
    useTerminalStore.setState({ terminal: mockTerminal, shift: mockShift });
    vi.mocked(apiPost).mockRejectedValue(new Error('Network error'));

    await useTerminalStore.getState().closeShift('150.00');

    expect(useTerminalStore.getState().shift).toBeNull();
    expect(removeStoredValue).toHaveBeenCalledWith('current_shift');
  });

  it('fetchCurrentShift persists shift to storage when API succeeds', async () => {
    useTerminalStore.setState({ terminal: mockTerminal });
    vi.mocked(apiGet).mockResolvedValue(mockShift);

    await useTerminalStore.getState().fetchCurrentShift();

    expect(setStoredValue).toHaveBeenCalledWith('current_shift', mockShift);
    expect(useTerminalStore.getState().shift).toEqual(mockShift);
  });

  it('refreshTerminalRecord publishes discount setting changes during an active session', async () => {
    const cached = {
      ...mockTerminal,
      max_discount_percent: 20,
      allow_line_discounts: true,
      allow_transaction_discounts: true,
    };
    const fresh = {
      ...cached,
      max_discount_percent: 5,
      allow_line_discounts: false,
    };
    useTerminalStore.setState({ terminal: cached });
    vi.mocked(apiGet).mockResolvedValue(fresh);

    await useTerminalStore.getState().refreshTerminalRecord();

    expect(setStoredValue).toHaveBeenCalledWith('terminal', fresh);
    expect(useTerminalStore.getState().terminal).toEqual(fresh);
    expect(operatorStoreMocks.invalidateDiscountPermissions).toHaveBeenCalled();
    expect(invalidateTerminalDiscountPermissions).toHaveBeenCalledWith(mockDb, 'T001');
    expect(operatorStoreMocks.refreshDiscountPermissions).toHaveBeenCalled();
  });
});
