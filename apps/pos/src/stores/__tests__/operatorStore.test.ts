import { describe, it, expect, beforeEach, vi } from 'vitest';
import { useOperatorStore } from '../operatorStore';
import type { Operator } from '../operatorStore';

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn(),
}));

vi.mock('@/lib/db/repositories/operatorPinRepository', () => ({
  getAllOperators: vi.fn(),
  hasOperatorPins: vi.fn(),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: vi.fn().mockReturnValue({ companyId: 'company-1' }),
  },
}));

// bcryptjs is not mocked — we use the real library for hash verification tests
import bcrypt from 'bcryptjs';
import { apiGet, apiPost } from '@/lib/api';
import { getDatabase } from '@/lib/db';
import { getAllOperators, hasOperatorPins } from '@/lib/db/repositories/operatorPinRepository';

const mockDb = {
  execute: vi.fn().mockResolvedValue({ rowsAffected: 0 }),
  select: vi.fn().mockResolvedValue([]),
  close: vi.fn().mockResolvedValue(undefined),
} as unknown as import('@tauri-apps/plugin-sql').default;

const mockOperator: Operator = {
  id: 'op-1',
  name: 'Jane Cashier',
  email: 'jane@example.com',
  roles: ['cashier'],
  permissions: ['pos.sell'],
  can_discount: true,
  max_discount_percent: 10,
};

// Pre-compute a real bcrypt hash of '1234' for offline tests
const PIN_1234_HASH = bcrypt.hashSync('1234', 10);

describe('operatorStore', () => {
  beforeEach(() => {
    useOperatorStore.setState({
      operator: null,
      isLocked: false,
      lastActivity: Date.now(),
      hasPins: null,
    });
    vi.clearAllMocks();
    vi.mocked(getDatabase).mockResolvedValue(mockDb);
  });

  it('has correct initial state', () => {
    const state = useOperatorStore.getState();
    expect(state.operator).toBeNull();
    expect(state.isLocked).toBe(false);
    expect(state.hasPins).toBeNull();
  });

  it('verifyPin succeeds via offline bcrypt FIRST without waiting for API', async () => {
    // Make the API call hang forever to prove we don't block on it.
    vi.mocked(apiPost).mockImplementation(() => new Promise(() => {}));
    vi.mocked(getAllOperators).mockResolvedValue([{
      id: 'op-1',
      name: 'Jane Cashier',
      email: 'jane@example.com',
      pin_hash: PIN_1234_HASH,
      roles: ['cashier'],
      permissions: ['pos.sell'],
      can_discount: true,
      max_discount_percent: 10,
    }]);

    await useOperatorStore.getState().verifyPin('1234');

    const state = useOperatorStore.getState();
    expect(state.operator).not.toBeNull();
    expect(state.operator!.id).toBe('op-1');
    expect(state.isLocked).toBe(false);
    // API should have been fire-and-forget invoked (for telemetry), but
    // verifyPin did not await it.
    expect(apiPost).toHaveBeenCalledWith('/pos/auth/verify-pin', { pin: '1234' });
  });

  it('verifyPin falls back to SQLite+bcrypt when API fails (offline)', async () => {
    vi.mocked(apiPost).mockRejectedValue(new Error('Network error'));
    vi.mocked(getAllOperators).mockResolvedValue([{
      id: 'op-1',
      name: 'Jane Cashier',
      email: 'jane@example.com',
      pin_hash: PIN_1234_HASH,
      roles: ['cashier'],
      permissions: ['pos.sell'],
      can_discount: true,
      max_discount_percent: 10,
    }]);

    await useOperatorStore.getState().verifyPin('1234');

    const state = useOperatorStore.getState();
    expect(state.operator).not.toBeNull();
    expect(state.operator!.id).toBe('op-1');
    expect(state.operator!.name).toBe('Jane Cashier');
    expect(state.isLocked).toBe(false);
  });

  it('verifyPin falls through to API when offline bcrypt has no match', async () => {
    vi.mocked(getAllOperators).mockResolvedValue([{
      id: 'op-1',
      name: 'Jane Cashier',
      email: 'jane@example.com',
      pin_hash: PIN_1234_HASH,
      roles: ['cashier'],
      permissions: ['pos.sell'],
      can_discount: true,
      max_discount_percent: 10,
    }]);
    vi.mocked(apiPost).mockResolvedValue(mockOperator);

    await useOperatorStore.getState().verifyPin('9999');

    const state = useOperatorStore.getState();
    // API matched on a different operator (mockOperator).
    expect(state.operator).toEqual(mockOperator);
    expect(state.isLocked).toBe(false);
  });

  it('verifyPin rejects when offline miss AND API miss', async () => {
    vi.mocked(getAllOperators).mockResolvedValue([{
      id: 'op-1',
      name: 'Jane Cashier',
      email: 'jane@example.com',
      pin_hash: PIN_1234_HASH,
      roles: ['cashier'],
      permissions: ['pos.sell'],
      can_discount: true,
      max_discount_percent: 10,
    }]);
    vi.mocked(apiPost).mockRejectedValue(new Error('Network error'));

    await expect(
      useOperatorStore.getState().verifyPin('9999'),
    ).rejects.toThrow('Invalid PIN');
    expect(useOperatorStore.getState().operator).toBeNull();
  });

  it('verifyPin does not throw if fire-and-forget telemetry API call rejects', async () => {
    vi.mocked(getAllOperators).mockResolvedValue([{
      id: 'op-1',
      name: 'Jane Cashier',
      email: 'jane@example.com',
      pin_hash: PIN_1234_HASH,
      roles: ['cashier'],
      permissions: ['pos.sell'],
      can_discount: true,
      max_discount_percent: 10,
    }]);
    vi.mocked(apiPost).mockRejectedValue(new Error('500 server error'));

    // Offline matches — user-visible result is success — the telemetry
    // rejection must NOT propagate.
    await expect(
      useOperatorStore.getState().verifyPin('1234'),
    ).resolves.toBeUndefined();

    expect(useOperatorStore.getState().operator!.id).toBe('op-1');
  });

  it('rejects wrong PIN in offline mode', async () => {
    vi.mocked(apiPost).mockRejectedValue(new Error('Network error'));
    vi.mocked(getAllOperators).mockResolvedValue([{
      id: 'op-1',
      name: 'Jane Cashier',
      email: 'jane@example.com',
      pin_hash: PIN_1234_HASH,
      roles: ['cashier'],
      permissions: ['pos.sell'],
      can_discount: true,
      max_discount_percent: 10,
    }]);

    await expect(
      useOperatorStore.getState().verifyPin('9999'),
    ).rejects.toThrow('Invalid PIN');
    expect(useOperatorStore.getState().operator).toBeNull();
  });

  it('matches correct operator among multiple in offline mode', async () => {
    const secondHash = bcrypt.hashSync('5678', 10);
    vi.mocked(apiPost).mockRejectedValue(new Error('Network error'));
    vi.mocked(getAllOperators).mockResolvedValue([
      {
        id: 'op-1',
        name: 'Jane',
        email: 'jane@example.com',
        pin_hash: PIN_1234_HASH,
        roles: ['cashier'],
        permissions: ['pos.sell'],
        can_discount: false,
        max_discount_percent: null,
      },
      {
        id: 'op-2',
        name: 'Bob',
        email: 'bob@example.com',
        pin_hash: secondHash,
        roles: ['manager'],
        permissions: ['pos.manage'],
        can_discount: true,
        max_discount_percent: 50,
      },
    ]);

    await useOperatorStore.getState().verifyPin('5678');

    expect(useOperatorStore.getState().operator!.id).toBe('op-2');
    expect(useOperatorStore.getState().operator!.name).toBe('Bob');
  });

  it('setupPin sets operator and marks hasPins true', async () => {
    vi.mocked(apiPost).mockResolvedValue(mockOperator);

    await useOperatorStore.getState().setupPin('5678');

    const state = useOperatorStore.getState();
    expect(state.operator).toEqual(mockOperator);
    expect(state.hasPins).toBe(true);
    expect(state.isLocked).toBe(false);
  });

  it('checkHasPins returns and stores result from API', async () => {
    vi.mocked(apiGet).mockResolvedValue({ has_pins: true });

    const result = await useOperatorStore.getState().checkHasPins();

    expect(result).toBe(true);
    expect(useOperatorStore.getState().hasPins).toBe(true);
  });

  it('checkHasPins returns false when no pins (API)', async () => {
    vi.mocked(apiGet).mockResolvedValue({ has_pins: false });

    const result = await useOperatorStore.getState().checkHasPins();

    expect(result).toBe(false);
    expect(useOperatorStore.getState().hasPins).toBe(false);
  });

  it('checkHasPins falls back to SQLite when API fails (offline)', async () => {
    vi.mocked(apiGet).mockRejectedValue(new Error('Network error'));
    vi.mocked(hasOperatorPins).mockResolvedValue(true);

    const result = await useOperatorStore.getState().checkHasPins();

    expect(result).toBe(true);
    expect(useOperatorStore.getState().hasPins).toBe(true);
  });

  it('checkHasPins returns false when both API and SQLite have no pins', async () => {
    vi.mocked(apiGet).mockRejectedValue(new Error('Network error'));
    vi.mocked(hasOperatorPins).mockResolvedValue(false);

    const result = await useOperatorStore.getState().checkHasPins();

    expect(result).toBe(false);
    expect(useOperatorStore.getState().hasPins).toBe(false);
  });

  it('flaky API does not cause user-visible failure when offline hash matches', async () => {
    // Simulate the real-world BG8 failure: the first API call throws
    // (network jitter, CSRF expiry), and the second would succeed.
    vi.mocked(apiPost)
      .mockRejectedValueOnce(new Error('TCP reset'))
      .mockResolvedValueOnce(mockOperator);
    vi.mocked(getAllOperators).mockResolvedValue([{
      id: 'op-1',
      name: 'Jane Cashier',
      email: 'jane@example.com',
      pin_hash: PIN_1234_HASH,
      roles: ['cashier'],
      permissions: ['pos.sell'],
      can_discount: true,
      max_discount_percent: 10,
    }]);

    // Under offline-first, the first try must succeed on the local hash
    // and the user never sees the API failure.
    await expect(
      useOperatorStore.getState().verifyPin('1234'),
    ).resolves.toBeUndefined();
    expect(useOperatorStore.getState().operator!.id).toBe('op-1');
  });

  it('lock sets isLocked to true', () => {
    useOperatorStore.getState().lock();
    expect(useOperatorStore.getState().isLocked).toBe(true);
  });

  it('clearOperator removes operator and resets lock', () => {
    useOperatorStore.setState({ operator: mockOperator, isLocked: true });

    useOperatorStore.getState().clearOperator();

    const state = useOperatorStore.getState();
    expect(state.operator).toBeNull();
    expect(state.isLocked).toBe(false);
  });

  it('resetActivityTimer updates lastActivity', () => {
    const before = Date.now();
    useOperatorStore.setState({ lastActivity: 0 });

    useOperatorStore.getState().resetActivityTimer();

    expect(useOperatorStore.getState().lastActivity).toBeGreaterThanOrEqual(before);
  });
});
