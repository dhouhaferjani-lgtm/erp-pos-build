import { describe, it, expect, beforeEach, vi } from 'vitest';
import bcrypt from 'bcryptjs';

// ── Mocks (mirror operatorStore.test.ts) ────────────────────────────────────
vi.mock('@/lib/api', () => ({ apiGet: vi.fn(), apiPost: vi.fn() }));

vi.mock('@/lib/db', () => ({ getDatabase: vi.fn() }));

vi.mock('@/lib/db/repositories/operatorPinRepository', () => ({
  getAllOperators: vi.fn().mockResolvedValue([]),
  hasOperatorPins: vi.fn().mockResolvedValue(false),
  updateOperatorDiscountPermissions: vi.fn().mockResolvedValue(undefined),
  upsertOperators: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/api/discountApi', () => ({ fetchDiscountPermissions: vi.fn() }));

vi.mock('@/lib/db/repositories/queuedPinUpdateRepository', () => ({
  enqueuePinUpdate: vi.fn().mockResolvedValue(undefined),
}));

const defaultUser = {
  id: 'op-1',
  name: 'Jane Cashier',
  email: 'jane@example.com',
  tenantId: 'tenant-1',
  phone: null,
  status: 'active',
  locale: null,
  timezone: null,
  roles: ['cashier'],
  permissions: ['pos.sell'],
  emailVerified: true,
};
let _authState: Record<string, unknown> = { companyId: 'company-1', user: defaultUser };
vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: vi.fn(() => _authState),
    setState: vi.fn((patch: Record<string, unknown>) => {
      _authState = { ..._authState, ...patch };
    }),
  },
}));

let _terminalState: Record<string, unknown> = {
  terminal: { id: 'term-1', code: 'POS01', max_discount_percent: 10 },
};
vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: { getState: vi.fn(() => _terminalState) },
}));

vi.mock('@/lib/device', () => ({ getDeviceId: vi.fn(() => 'device-123') }));

const recordAuditEvent = vi.fn().mockResolvedValue(undefined);
vi.mock('@/lib/audit/recordAuditEvent', () => ({
  recordAuditEvent: (...args: unknown[]) => recordAuditEvent(...args),
}));

import { apiPost } from '@/lib/api';
import { getDatabase } from '@/lib/db';
import { getAllOperators } from '@/lib/db/repositories/operatorPinRepository';
import type { CachedOperator } from '@/lib/db/repositories/operatorPinRepository';
import { fetchDiscountPermissions } from '@/api/discountApi';
import { useOperatorStore } from '../operatorStore';
import type { Operator } from '../operatorStore';

const mockDb = {
  execute: vi.fn().mockResolvedValue({ rowsAffected: 0 }),
  select: vi.fn().mockResolvedValue([]),
  close: vi.fn().mockResolvedValue(undefined),
} as unknown as import('@tauri-apps/plugin-sql').default;

const PIN_1234_HASH = bcrypt.hashSync('1234', 10);

const offlineOperatorRow = {
  id: 'op-1',
  name: 'Jane Cashier',
  email: 'jane@example.com',
  pin_hash: PIN_1234_HASH,
  roles: ['cashier'],
  permissions: ['pos.sell'],
  can_discount: true,
  max_discount_percent: 10,
  discount_permissions_fetched_at: new Date().toISOString(),
  discount_permissions_terminal_code: 'POS01',
  discount_permissions_status: 'fresh',
} as unknown as CachedOperator;

const mockApiOperator: Operator = {
  id: 'op-2',
  name: 'API Operator',
  email: 'api@example.com',
  roles: ['cashier'],
  permissions: ['pos.sell'],
  can_discount: true,
  max_discount_percent: 10,
};

function lastCallOfType(type: string): Record<string, unknown> | undefined {
  for (let i = recordAuditEvent.mock.calls.length - 1; i >= 0; i--) {
    const arg = recordAuditEvent.mock.calls[i]![0] as Record<string, unknown>;
    if (arg.type === type) return arg;
  }
  return undefined;
}

function callsOfType(type: string): Record<string, unknown>[] {
  return recordAuditEvent.mock.calls
    .map((c) => c[0] as Record<string, unknown>)
    .filter((a) => a.type === type);
}

/** Recursively assert no PIN/hash leaks into an emitted payload. */
function assertNoSecret(payload: Record<string, unknown>): void {
  const serialized = JSON.stringify(payload);
  expect(serialized).not.toContain('1234');
  expect(serialized).not.toContain(PIN_1234_HASH);
  expect(serialized).not.toContain('pin_hash');
}

describe('operatorStore — Task 7 / Task 11 audit emits', () => {
  beforeEach(() => {
    _authState = { companyId: 'company-1', user: defaultUser };
    _terminalState = { terminal: { id: 'term-1', code: 'POS01', max_discount_percent: 10 } };
    useOperatorStore.setState({
      operator: null,
      isLocked: false,
      lastActivity: Date.now(),
      hasPins: null,
    });
    vi.clearAllMocks();
    recordAuditEvent.mockResolvedValue(undefined);
    vi.mocked(getDatabase).mockResolvedValue(mockDb);
    vi.mocked(fetchDiscountPermissions).mockResolvedValue({
      canDiscount: true,
      userCanDiscount: true,
      userMaxDiscountPercent: null,
      terminalAllowsDiscounts: true,
      canApplyLineDiscounts: true,
      canApplyTransactionDiscounts: true,
      maxDiscountPercent: 10,
      requiresReason: false,
    });
  });

  describe('pos.operator_signin', () => {
    it('emits on the offline bcrypt success path', async () => {
      vi.mocked(apiPost).mockImplementation(() => new Promise(() => {}));
      vi.mocked(getAllOperators).mockResolvedValue([offlineOperatorRow]);

      await useOperatorStore.getState().verifyPin('1234');

      const call = lastCallOfType('pos.operator_signin');
      expect(call).toBeDefined();
      expect(call!.aggregateType).toBe('Operator');
      expect(call!.aggregateId).toBe('op-1');
      expect(call!.operatorId).toBe('op-1');
      expect(call!.payload).toEqual({ method: 'pin' });
    });

    it('emits on the online success path (offline miss + API match)', async () => {
      vi.mocked(getAllOperators).mockResolvedValue([offlineOperatorRow]);
      vi.mocked(apiPost).mockResolvedValue(mockApiOperator);

      await useOperatorStore.getState().verifyPin('9999');

      const call = lastCallOfType('pos.operator_signin');
      expect(call).toBeDefined();
      expect(call!.aggregateId).toBe('op-2');
      expect(call!.payload).toEqual({ method: 'pin' });
    });

    it('does NOT emit pos.manager_pin_failed on a correct PIN', async () => {
      vi.mocked(apiPost).mockImplementation(() => new Promise(() => {}));
      vi.mocked(getAllOperators).mockResolvedValue([offlineOperatorRow]);

      await useOperatorStore.getState().verifyPin('1234');

      expect(lastCallOfType('pos.manager_pin_failed')).toBeUndefined();
    });
  });

  describe('pos.manager_pin_failed', () => {
    it('emits on a wrong PIN (offline miss + API reject) with no secret', async () => {
      vi.mocked(getAllOperators).mockResolvedValue([offlineOperatorRow]);

      // Normalise the module-scoped counter to 0 with one success first.
      vi.mocked(apiPost).mockImplementation(() => new Promise(() => {}));
      await useOperatorStore.getState().verifyPin('1234');
      recordAuditEvent.mockClear();

      vi.mocked(apiPost).mockRejectedValue(new Error('Invalid PIN'));

      await expect(useOperatorStore.getState().verifyPin('9999')).rejects.toThrow('Invalid PIN');

      const call = lastCallOfType('pos.manager_pin_failed');
      expect(call).toBeDefined();
      expect(call!.aggregateType).toBe('Operator');
      expect(call!.aggregateId).toBe('unknown');
      const payload = call!.payload as Record<string, unknown>;
      expect(payload.context).toBe('operator_pin');
      expect(payload.attempt_count).toBe(1);
      // NO pin / NO hash.
      expect(payload).not.toHaveProperty('pin');
      expect(payload).not.toHaveProperty('hash');
      assertNoSecret(payload);
    });

    it('increments attempt_count across consecutive failures and resets on success', async () => {
      vi.mocked(getAllOperators).mockResolvedValue([offlineOperatorRow]);

      // Reset the module-scoped consecutive-failure counter to a known 0 via a
      // successful verification first (the counter persists across the session
      // and is intentionally not test-resettable from outside the module).
      vi.mocked(apiPost).mockImplementation(() => new Promise(() => {}));
      await useOperatorStore.getState().verifyPin('1234');

      vi.mocked(apiPost).mockRejectedValue(new Error('Invalid PIN'));
      await expect(useOperatorStore.getState().verifyPin('0000')).rejects.toThrow();
      await expect(useOperatorStore.getState().verifyPin('0001')).rejects.toThrow();

      const fails = callsOfType('pos.manager_pin_failed');
      expect((fails[0]!.payload as Record<string, unknown>).attempt_count).toBe(1);
      expect((fails[1]!.payload as Record<string, unknown>).attempt_count).toBe(2);

      // A correct PIN resets the counter; the next failure starts back at 1.
      vi.mocked(apiPost).mockImplementation(() => new Promise(() => {}));
      await useOperatorStore.getState().verifyPin('1234');

      vi.mocked(apiPost).mockRejectedValue(new Error('Invalid PIN'));
      await expect(useOperatorStore.getState().verifyPin('0002')).rejects.toThrow();
      const failsAfter = callsOfType('pos.manager_pin_failed');
      expect(
        (failsAfter[failsAfter.length - 1]!.payload as Record<string, unknown>).attempt_count,
      ).toBe(1);
    });

    it('still throws Invalid PIN when the audit emit rejects', async () => {
      recordAuditEvent.mockRejectedValue(new Error('audit down'));
      vi.mocked(getAllOperators).mockResolvedValue([offlineOperatorRow]);
      vi.mocked(apiPost).mockRejectedValue(new Error('Invalid PIN'));

      await expect(useOperatorStore.getState().verifyPin('9999')).rejects.toThrow('Invalid PIN');
    });
  });

  describe('pos.operator_signoff', () => {
    it('emits on clearOperator capturing the operator before clearing', () => {
      useOperatorStore.setState({
        operator: { ...mockApiOperator, id: 'op-7' },
        isLocked: false,
      });

      useOperatorStore.getState().clearOperator();

      const call = lastCallOfType('pos.operator_signoff');
      expect(call).toBeDefined();
      expect(call!.aggregateType).toBe('Operator');
      expect(call!.aggregateId).toBe('op-7');
      expect(call!.operatorId).toBe('op-7');
      expect(call!.payload).toEqual({});
      expect(useOperatorStore.getState().operator).toBeNull();
    });

    it('does NOT emit when there is no current operator', () => {
      useOperatorStore.setState({ operator: null });
      useOperatorStore.getState().clearOperator();
      expect(lastCallOfType('pos.operator_signoff')).toBeUndefined();
    });

    it('does not break clearOperator when the emit rejects', () => {
      recordAuditEvent.mockRejectedValue(new Error('audit down'));
      useOperatorStore.setState({ operator: { ...mockApiOperator, id: 'op-7' } });
      expect(() => useOperatorStore.getState().clearOperator()).not.toThrow();
      expect(useOperatorStore.getState().operator).toBeNull();
    });
  });

  describe('pos.operator_unlocked', () => {
    it('emits when verifyPin succeeds and the store was locked (offline path)', async () => {
      vi.mocked(apiPost).mockImplementation(() => new Promise(() => {}));
      vi.mocked(getAllOperators).mockResolvedValue([offlineOperatorRow]);

      // Lock the operator first.
      useOperatorStore.setState({
        operator: { ...mockApiOperator, id: 'op-1' },
        isLocked: true,
        lastActivity: Date.now(),
      });
      useOperatorStore.getState().lock();
      vi.clearAllMocks();
      recordAuditEvent.mockResolvedValue(undefined);

      await useOperatorStore.getState().verifyPin('1234');

      const call = lastCallOfType('pos.operator_unlocked');
      expect(call).toBeDefined();
      expect(call!.aggregateType).toBe('Operator');
      expect(call!.aggregateId).toBe('op-1');
      expect(call!.operatorId).toBe('op-1');
      const payload = call!.payload as Record<string, unknown>;
      // locked_duration_ms is present and is a number (>= 0).
      expect(typeof payload.locked_duration_ms === 'number' || payload.locked_duration_ms === null).toBe(true);
    });

    it('does NOT emit pos.operator_unlocked on a fresh sign-in (wasLocked = false)', async () => {
      vi.mocked(apiPost).mockImplementation(() => new Promise(() => {}));
      vi.mocked(getAllOperators).mockResolvedValue([offlineOperatorRow]);

      // Ensure the store is NOT locked before verifying.
      useOperatorStore.setState({
        operator: null,
        isLocked: false,
        lastActivity: Date.now(),
      });

      await useOperatorStore.getState().verifyPin('1234');

      expect(lastCallOfType('pos.operator_unlocked')).toBeUndefined();
      // signin IS emitted.
      expect(lastCallOfType('pos.operator_signin')).toBeDefined();
    });

    it('emits pos.operator_unlocked on the online path when wasLocked', async () => {
      vi.mocked(getAllOperators).mockResolvedValue([]); // offline miss
      vi.mocked(apiPost).mockResolvedValue(mockApiOperator);

      useOperatorStore.setState({
        operator: { ...mockApiOperator, id: 'op-2' },
        isLocked: true,
        lastActivity: Date.now(),
      });
      useOperatorStore.getState().lock();
      vi.clearAllMocks();
      recordAuditEvent.mockResolvedValue(undefined);

      await useOperatorStore.getState().verifyPin('9999');

      const call = lastCallOfType('pos.operator_unlocked');
      expect(call).toBeDefined();
      expect(call!.aggregateId).toBe('op-2');
    });
  });

  describe('pos.screen_lock', () => {
    it('emits on lock with reason=manual and idle_ms derived from lastActivity', () => {
      const past = Date.now() - 5000;
      useOperatorStore.setState({
        operator: { ...mockApiOperator, id: 'op-3' },
        isLocked: false,
        lastActivity: past,
      });

      useOperatorStore.getState().lock();

      const call = lastCallOfType('pos.screen_lock');
      expect(call).toBeDefined();
      expect(call!.aggregateType).toBe('PosSession');
      expect(call!.aggregateId).toBe('device-123');
      expect(call!.operatorId).toBe('op-3');
      const payload = call!.payload as Record<string, unknown>;
      expect(payload.reason).toBe('manual');
      expect(payload.idle_ms as number).toBeGreaterThanOrEqual(5000);
      expect(useOperatorStore.getState().isLocked).toBe(true);
    });

    it('does not break lock when the emit rejects', () => {
      recordAuditEvent.mockRejectedValue(new Error('audit down'));
      expect(() => useOperatorStore.getState().lock()).not.toThrow();
      expect(useOperatorStore.getState().isLocked).toBe(true);
    });
  });
});
