import { describe, it, expect, beforeEach, vi } from 'vitest';
import { useTerminalStore, fiscalShiftIdForReceipt } from '../terminalStore';
import type { Terminal, Shift } from '../terminalStore';

vi.mock('@/lib/api', () => ({
  // Real-shaped stub so `error instanceof ApiRequestError` works in the store.
  ApiRequestError: class ApiRequestError extends Error {
    constructor(
      public readonly status: number,
      public readonly apiMessage: string,
      public readonly code: string,
    ) {
      super(apiMessage);
      this.name = 'ApiRequestError';
    }
  },
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

import { apiGet, apiPost, ApiRequestError } from '@/lib/api';
import { getDatabase, execute, queryAll } from '@/lib/db';
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
  location: {
    id: 'loc-1',
    name: 'Main Store',
    code: 'LOC-001',
    tax_id: null,
    vat_number: null,
    legal_identifiers: null,
  },
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

  it('openShift does not author Z-session opening fiscal events for pre-cutover terminals', async () => {
    useTerminalStore.setState({ terminal: { ...mockTerminal, fiscal_schema_version: 2 } });
    vi.mocked(apiPost).mockResolvedValue(mockShift);

    await useTerminalStore.getState().openShift('100.00');

    expect(authorZSessionOpenWithOpeningFloat).not.toHaveBeenCalled();
    expect(useTerminalStore.getState().shift).toEqual(expect.objectContaining(mockShift));
  });

  it('openShift (M3) records the shift receipt anchor from the offline_receipts MAX sequence, not terminal_state', async () => {
    useTerminalStore.setState({ terminal: { ...mockTerminal, fiscal_schema_version: 3 } });
    vi.mocked(apiPost).mockResolvedValue(mockShift);
    // The terminal's last receipt is at operational sequence 42.
    vi.mocked(queryAll).mockImplementation(async (_db, sql) =>
      (String(sql).includes('offline_receipts') ? [{ max_seq: 42 }] : []) as unknown as never[],
    );

    await useTerminalStore.getState().openShift('100.00');

    // The boundary query reads offline_receipts.hash_sequence (the same space the
    // Z window bounds by) — NOT terminal_state.hash_sequence (the legacy counter).
    const maxQuery = vi
      .mocked(queryAll)
      .mock.calls.find((c) => String(c[1]).includes('MAX(hash_sequence)'));
    expect(maxQuery).toBeDefined();
    expect(String(maxQuery![1])).toContain('FROM offline_receipts');

    // The anchor INSERT carries that sequence (42).
    const anchorInsert = vi
      .mocked(execute)
      .mock.calls.find((c) => String(c[1]).includes('shift_receipt_anchors'));
    expect(anchorInsert).toBeDefined();
    expect(anchorInsert![2]).toEqual(expect.arrayContaining([42]));
  });

  it('openShift authors Z-session opening fiscal events for cutover terminals', async () => {
    useTerminalStore.setState({ terminal: { ...mockTerminal, fiscal_schema_version: 3 } });
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

  it('openShift synthesizes user from the auth session when the server payload lacks user (2026-06-12 live crash)', async () => {
    // ShiftResource returns cashier_id (+ cashier when loaded) but never
    // `user` — the fiscal v3 open path crashed with "undefined is not an
    // object (evaluating 'shift.user.id')" on every server-opened shift.
    useTerminalStore.setState({ terminal: { ...mockTerminal, fiscal_schema_version: 3 } });
    const serverShift: Record<string, unknown> = {
      ...mockShift,
      cashier_id: '22222222-2222-4222-8222-222222222222',
    };
    delete serverShift['user'];
    vi.mocked(apiPost).mockResolvedValue(serverShift);

    await useTerminalStore.getState().openShift('100.00');

    expect(authorZSessionOpenWithOpeningFloat).toHaveBeenCalledWith(expect.objectContaining({
      operatorId: '22222222-2222-4222-8222-222222222222',
      operatorName: 'Jane',
    }));
    expect(useTerminalStore.getState().shift?.user).toEqual({
      id: '22222222-2222-4222-8222-222222222222',
      name: 'Jane',
    });
  });

  it('openShift adopts the existing server shift on SHIFT_ALREADY_OPEN instead of forking an offline shift', async () => {
    // A reachable server refusing the open means a shift is ALREADY open for
    // this terminal. Treating the 409 as "offline" forked a local `offline-…`
    // shift, splitting the device from the server shift (2026-06-12 live
    // regression: Today Sales 404'd and checkout payloads carried a
    // non-UUID shift_id).
    useTerminalStore.setState({ terminal: mockTerminal });
    vi.mocked(apiPost).mockRejectedValue(
      new ApiRequestError(409, 'A shift is already open', 'SHIFT_ALREADY_OPEN'),
    );
    const serverShift: Record<string, unknown> = {
      ...mockShift,
      cashier_id: '22222222-2222-4222-8222-222222222222',
    };
    delete serverShift['user'];
    vi.mocked(apiGet).mockResolvedValue(serverShift);

    await useTerminalStore.getState().openShift('100.00');

    expect(apiGet).toHaveBeenCalledWith('/pos/shifts/current/T001');
    const shift = useTerminalStore.getState().shift;
    expect(shift?.id).toBe(mockShift.id);
    expect(shift?.id).not.toMatch(/^offline-/);
  });

  it('fetchCurrentShift synthesizes user when the server payload lacks user', async () => {
    useTerminalStore.setState({ terminal: mockTerminal });
    const serverShift: Record<string, unknown> = {
      ...mockShift,
      cashier_id: '22222222-2222-4222-8222-222222222222',
    };
    delete serverShift['user'];
    vi.mocked(getStoredValue).mockResolvedValue(null);
    vi.mocked(apiGet).mockResolvedValue(serverShift);

    await useTerminalStore.getState().fetchCurrentShift();

    expect(useTerminalStore.getState().shift?.user).toEqual({
      id: '22222222-2222-4222-8222-222222222222',
      name: 'Jane',
    });
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

  it('fetchCurrentShift preserves device-local fiscal ids when the server returns the same shift', async () => {
    // fiscal_shift_id / fiscal_session_id are minted on the DEVICE at shift
    // open (Z-session authoring) — the server's shift payload never carries
    // them. Overwriting the cached shift wholesale on app restart severed
    // the live shift from its SESSION_OPEN fiscal event, silently flipping
    // the X report onto the (retired-for-v3) server path.
    useTerminalStore.setState({ terminal: mockTerminal });
    const cachedFiscalShift: Shift = {
      ...mockShift,
      fiscal_shift_id: 'fiscal-shift-1',
      fiscal_session_id: 'fiscal-session-1',
    };
    vi.mocked(getStoredValue).mockResolvedValue(cachedFiscalShift);
    vi.mocked(apiGet).mockResolvedValue({ ...mockShift });

    await useTerminalStore.getState().fetchCurrentShift();

    const shift = useTerminalStore.getState().shift;
    expect(shift?.fiscal_shift_id).toBe('fiscal-shift-1');
    expect(shift?.fiscal_session_id).toBe('fiscal-session-1');
    expect(setStoredValue).toHaveBeenCalledWith('current_shift', expect.objectContaining({
      id: mockShift.id,
      fiscal_shift_id: 'fiscal-shift-1',
      fiscal_session_id: 'fiscal-session-1',
    }));
  });

  it('fetchCurrentShift does NOT carry cached fiscal ids onto a different shift', async () => {
    useTerminalStore.setState({ terminal: mockTerminal });
    const staleCachedShift: Shift = {
      ...mockShift,
      id: 'shift-OLD',
      fiscal_shift_id: 'fiscal-shift-old',
      fiscal_session_id: 'fiscal-session-old',
    };
    vi.mocked(getStoredValue).mockResolvedValue(staleCachedShift);
    vi.mocked(apiGet).mockResolvedValue({ ...mockShift });

    await useTerminalStore.getState().fetchCurrentShift();

    const shift = useTerminalStore.getState().shift;
    expect(shift?.id).toBe(mockShift.id);
    expect(shift?.fiscal_shift_id).toBeUndefined();
    expect(shift?.fiscal_session_id).toBeUndefined();
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

describe('fiscalShiftIdForReceipt', () => {
  // Fiscal canonical payloads validate shift_id as a lowercase-hex UUID.
  // Raw shift.id is NOT safe: offline-opened shifts are `offline-<uuid>`
  // (2026-06-12 live failure: checkout rejected with payload_field_invalid).
  const base: Shift = {
    id: 'shift-1',
    terminal_id: 'term-1',
    shift_number: 1,
    status: 'OPEN',
    opening_cash: '100.00',
    opened_at: '2026-06-12T08:00:00.000Z',
    user: { id: 'user-1', name: 'Jane' },
  };

  it('returns the device-minted fiscal_shift_id when present', () => {
    const shift = { ...base, fiscal_shift_id: '11111111-1111-4111-8111-111111111111' };
    expect(fiscalShiftIdForReceipt(shift)).toBe('11111111-1111-4111-8111-111111111111');
  });

  it('falls back to the shift id when it is already a UUID', () => {
    const shift = { ...base, id: '33333333-3333-4333-8333-333333333333' };
    expect(fiscalShiftIdForReceipt(shift)).toBe('33333333-3333-4333-8333-333333333333');
  });

  it('derives the UUID from an offline- prefixed shift id', () => {
    const shift = { ...base, id: 'offline-44444444-4444-4444-8444-444444444444' };
    expect(fiscalShiftIdForReceipt(shift)).toBe('44444444-4444-4444-8444-444444444444');
  });

  it('fails loud on an underivable shift id instead of minting a random one', () => {
    // A random UUID here would scatter receipts across phantom shift ids and
    // silently corrupt the Z window — fail-closed is the only safe behavior.
    expect(() => fiscalShiftIdForReceipt(base)).toThrow(/fiscal shift id/i);
  });
});
