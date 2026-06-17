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
    shiftNumber: 1,
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
import { getDatabase, queryOne } from '@/lib/db';
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

  it('openShift authors device-authoritative fiscal events without a server POST (v3)', async () => {
    // The M3 receipt anchor + local_shifts insert now happen atomically inside
    // authorZSessionOpenWithOpeningFloat (covered in zSessionAuthoring.test.ts);
    // here we assert the store mints a device shift and never hits the server.
    useTerminalStore.setState({ terminal: { ...mockTerminal, fiscal_schema_version: 3 } });

    await useTerminalStore.getState().openShift('100.00');

    expect(apiPost).not.toHaveBeenCalled();
    expect(authorZSessionOpenWithOpeningFloat).toHaveBeenCalledWith(expect.objectContaining({
      tenantId: 'tenant-1',
      companyId: 'company-1',
      terminalId: 'term-1',
      terminalLabel: 'T001',
      shiftId: expect.any(String),
      sessionId: expect.any(String),
      businessDate: expect.any(String),
      operatorId: '22222222-2222-4222-8222-222222222222',
      operatorName: 'Jane',
      currencyCode: 'EUR',
      currencyScale: 2,
      openingFloatAmount: '100.00',
      isTraining: false,
    }));

    // Freshly minted device UUIDv7 id (not a server id, not `offline-`).
    const shift = useTerminalStore.getState().shift;
    expect(shift?.id).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    );
    expect(shift?.fiscal_shift_id).toBe(shift?.id);
    expect(shift?.shift_number).toBe(1);
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

  it('closeShift (v3) clears device state without calling the REST close endpoint', async () => {
    // Device-authoritative close: SESSION_CLOSE + Z_REPORT are authored locally
    // and pos_shifts is closed by the projection; the REST close returns 409.
    useTerminalStore.setState({
      terminal: { ...mockTerminal, fiscal_schema_version: 3 },
      shift: mockShift,
    });

    await useTerminalStore.getState().closeShift('150.00');

    expect(apiPost).not.toHaveBeenCalled();
    expect(useTerminalStore.getState().shift).toBeNull();
    expect(removeStoredValue).toHaveBeenCalledWith('current_shift');
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

  it('openShift (v3) authors the shift fully offline when the device cannot reach the server', async () => {
    // Device-authoritative open never calls the server, so a dead network is a
    // non-event: the shift opens locally and only the fire-and-forget stock
    // pull (swallowed) would fail.
    useTerminalStore.setState({ terminal: { ...mockTerminal, fiscal_schema_version: 3 } });

    await useTerminalStore.getState().openShift('100.00');

    const state = useTerminalStore.getState();
    expect(state.shift).not.toBeNull();
    expect(state.shift!.terminal_id).toBe('term-1');
    expect(state.shift!.opening_cash).toBe('100.00');
    expect(state.shift!.status).toBe('OPEN');
    expect(state.shift!.id).not.toMatch(/^offline-/);
    expect(state.isLoading).toBe(false);
    expect(apiPost).not.toHaveBeenCalled();
    expect(setStoredValue).toHaveBeenCalledWith('current_shift', expect.objectContaining({
      terminal_id: 'term-1',
      status: 'OPEN',
    }));
  });

  it('fetchCurrentShift (v3) reads the open shift from local_shifts with no network', async () => {
    useTerminalStore.setState({ terminal: { ...mockTerminal, fiscal_schema_version: 3 } });
    vi.mocked(getStoredValue).mockResolvedValue(null);
    const localOpen = {
      id: '019700aa-bbbb-7ccc-8ddd-eeeeffff1234',
      terminal_id: 'term-1',
      session_id: '019700aa-bbbb-7ccc-8ddd-eeeeffff1234',
      shift_number: 5,
      status: 'OPEN' as const,
      opening_cash: '100.000',
      opened_at: '2026-06-14T08:00:00.000Z',
      closed_at: null,
      cashier_id: 'cashier-1',
      cashier_name: 'Alice',
    };
    vi.mocked(getDatabase).mockResolvedValue(mockDb);
    vi.mocked(queryOne).mockResolvedValue(localOpen);

    await useTerminalStore.getState().fetchCurrentShift();

    expect(apiGet).not.toHaveBeenCalled();
    const shift = useTerminalStore.getState().shift;
    expect(shift?.id).toBe(localOpen.id);
    expect(shift?.shift_number).toBe(5);
    expect(shift?.fiscal_shift_id).toBe(localOpen.id);
    expect(shift?.user).toEqual({ id: 'cashier-1', name: 'Alice' });
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
  // One-id model (Phase 1–3): shift.id IS the fiscal shift/session id — a v3
  // device-minted UUIDv7 or a v<3 server UUID. The legacy `offline-<uuid>`
  // fork was removed in Phase 1, so resolution collapses onto shift.id.
  // Fiscal canonical payloads still validate shift_id as a lowercase-hex UUID,
  // so a non-UUID id must fail loud rather than mis-attribute fiscal data.
  const base: Shift = {
    id: 'shift-1',
    terminal_id: 'term-1',
    shift_number: 1,
    status: 'OPEN',
    opening_cash: '100.00',
    opened_at: '2026-06-12T08:00:00.000Z',
    user: { id: 'user-1', name: 'Jane' },
  };

  it('returns shift.id when it is a UUID', () => {
    const shift = { ...base, id: '33333333-3333-4333-8333-333333333333' };
    expect(fiscalShiftIdForReceipt(shift)).toBe('33333333-3333-4333-8333-333333333333');
  });

  it('lowercases an upper-case UUID shift.id (payload requires lowercase hex)', () => {
    const shift = { ...base, id: '33333333-3333-4333-8333-3333333333AB' };
    expect(fiscalShiftIdForReceipt(shift)).toBe('33333333-3333-4333-8333-3333333333ab');
  });

  it('ignores fiscal_shift_id and resolves onto shift.id (one-id model)', () => {
    // In the one-id model id == fiscal_shift_id == fiscal_session_id, so
    // returning shift.id is equivalent to the old fiscal_shift_id-first branch.
    const shift = {
      ...base,
      id: '33333333-3333-4333-8333-333333333333',
      fiscal_shift_id: '33333333-3333-4333-8333-333333333333',
    };
    expect(fiscalShiftIdForReceipt(shift)).toBe('33333333-3333-4333-8333-333333333333');
  });

  it('fails loud on a non-UUID shift id instead of minting a random one', () => {
    // A random UUID here would scatter receipts across phantom shift ids and
    // silently corrupt the Z window — fail-closed is the only safe behavior.
    expect(() => fiscalShiftIdForReceipt(base)).toThrow(/fiscal shift id/i);
  });
});
