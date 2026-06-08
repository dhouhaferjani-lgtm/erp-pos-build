import { describe, it, expect, vi, beforeEach } from 'vitest';

// ── Mocks ────────────────────────────────────────────────────────────────
const enqueueAuditEvent = vi.fn().mockResolvedValue(undefined);
vi.mock('@/lib/db/repositories/queuedAuditEventRepository', () => ({
  enqueueAuditEvent: (...args: unknown[]) => enqueueAuditEvent(...args),
}));

const getDatabase = vi.fn();
vi.mock('@/lib/db', () => ({
  getDatabase: (...args: unknown[]) => getDatabase(...args),
}));

vi.mock('@/lib/device', () => ({
  getDeviceId: () => 'device-abc',
}));

const authState: { user: { id: string; tenantId: string } | null; companyId: string | null } = {
  user: { id: 'user-1', tenantId: 'tenant-1' },
  companyId: 'company-1',
};
vi.mock('@/stores/authStore', () => ({
  useAuthStore: { getState: () => authState },
}));

const terminalState: { terminal: { id: string } | null; shift: { id: string } | null } = {
  terminal: { id: 'terminal-1' },
  shift: { id: 'shift-1' },
};
vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: { getState: () => terminalState },
}));

const operatorState: { operator: { id: string } | null } = {
  operator: { id: 'operator-9' },
};
vi.mock('@/stores/operatorStore', () => ({
  useOperatorStore: { getState: () => operatorState },
}));

const connectivityState: { isOnline: boolean } = { isOnline: true };
vi.mock('@/stores/connectivityStore', () => ({
  useConnectivityStore: { getState: () => connectivityState },
}));

import { recordAuditEvent } from '../recordAuditEvent';

describe('recordAuditEvent', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    enqueueAuditEvent.mockResolvedValue(undefined);
    getDatabase.mockResolvedValue({});
    authState.user = { id: 'user-1', tenantId: 'tenant-1' };
    authState.companyId = 'company-1';
    terminalState.terminal = { id: 'terminal-1' };
    terminalState.shift = { id: 'shift-1' };
    operatorState.operator = { id: 'operator-9' };
    connectivityState.isOnline = true;
  });

  it('records and enqueues an event with the correct fields', async () => {
    await recordAuditEvent({
      type: 'pos.login',
      aggregateType: 'PosSession',
      aggregateId: 'device-abc',
      payload: { multi_tenant: true, via_picker: false },
    });

    expect(enqueueAuditEvent).toHaveBeenCalledTimes(1);
    const [, row] = enqueueAuditEvent.mock.calls[0] as [
      unknown,
      {
        eventId: string;
        eventType: string;
        aggregateType: string;
        aggregateId: string;
        tenantId: string;
        companyId: string | null;
        operatorId: string | null;
        payload: string;
        metadata: string;
        occurredAt: string;
        status: string;
        retryCount: number;
      },
    ];

    expect(row.eventType).toBe('pos.login');
    expect(row.aggregateType).toBe('PosSession');
    expect(row.aggregateId).toBe('device-abc');
    expect(row.tenantId).toBe('tenant-1');
    expect(row.companyId).toBe('company-1');
    expect(row.operatorId).toBe('operator-9');
    expect(row.status).toBe('pending');
    expect(row.retryCount).toBe(0);

    // event_id is a generated UUID
    expect(row.eventId).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i,
    );

    // occurred_at is a valid ISO timestamp
    expect(Number.isNaN(Date.parse(row.occurredAt))).toBe(false);

    // payload round-trips
    expect(JSON.parse(row.payload)).toEqual({ multi_tenant: true, via_picker: false });

    // metadata carries the device/terminal/shift/offline/version context
    const meta = JSON.parse(row.metadata) as Record<string, unknown>;
    expect(meta.device_id).toBe('device-abc');
    expect(meta.terminal_id).toBe('terminal-1');
    expect(meta.shift_id).toBe('shift-1');
    expect(meta.is_offline).toBe(false);
    expect(meta).toHaveProperty('app_version');
  });

  it('honours explicit tenant/company/operator/occurredAt overrides (login path)', async () => {
    await recordAuditEvent({
      type: 'pos.login',
      aggregateType: 'PosSession',
      aggregateId: 'device-abc',
      tenantId: 'explicit-tenant',
      companyId: 'explicit-company',
      operatorId: 'explicit-operator',
      occurredAt: '2026-06-04T08:00:00.000Z',
      payload: { multi_tenant: true },
    });

    const [, row] = enqueueAuditEvent.mock.calls[0] as [unknown, Record<string, unknown>];
    expect(row.tenantId).toBe('explicit-tenant');
    expect(row.companyId).toBe('explicit-company');
    expect(row.operatorId).toBe('explicit-operator');
    expect(row.occurredAt).toBe('2026-06-04T08:00:00.000Z');
  });

  it('FIX 2: when input.companyId differs from auth.companyId, getDatabase opens input.companyId and row carries it', async () => {
    // auth.companyId is 'company-1' (set in beforeEach); the caller supplies
    // 'just-resolved-company' — simulates pos.login where the store lags.
    authState.companyId = 'stale-company-in-store';

    await recordAuditEvent({
      type: 'pos.login',
      aggregateType: 'PosSession',
      aggregateId: 'device-abc',
      tenantId: 'tenant-1',
      companyId: 'just-resolved-company',
      payload: { multi_tenant: true },
    });

    // getDatabase must be called with the explicitly-supplied companyId, NOT
    // the stale store value — they must be consistent.
    expect(getDatabase).toHaveBeenCalledWith('just-resolved-company');

    // The enqueued row must carry the same companyId.
    const [, row] = enqueueAuditEvent.mock.calls[0] as [unknown, Record<string, unknown>];
    expect(row.companyId).toBe('just-resolved-company');
  });

  it('records is_offline=true when connectivity reports offline', async () => {
    connectivityState.isOnline = false;
    await recordAuditEvent({
      type: 'pos.operator_signin',
      aggregateType: 'Operator',
      aggregateId: 'operator-9',
      payload: { method: 'pin' },
    });
    const [, row] = enqueueAuditEvent.mock.calls[0] as [unknown, { metadata: string }];
    expect((JSON.parse(row.metadata) as { is_offline: boolean }).is_offline).toBe(true);
  });

  it('best-effort: drops (no enqueue, no throw) when tenant is missing', async () => {
    authState.user = null;
    authState.companyId = null;

    await expect(
      recordAuditEvent({
        type: 'pos.login',
        aggregateType: 'PosSession',
        aggregateId: 'device-abc',
        payload: {},
      }),
    ).resolves.toBeUndefined();

    expect(enqueueAuditEvent).not.toHaveBeenCalled();
    expect(getDatabase).not.toHaveBeenCalled();
  });

  it('best-effort: never throws when getDatabase rejects', async () => {
    getDatabase.mockRejectedValue(new Error('db unavailable'));

    await expect(
      recordAuditEvent({
        type: 'pos.login',
        aggregateType: 'PosSession',
        aggregateId: 'device-abc',
        payload: {},
      }),
    ).resolves.toBeUndefined();

    expect(enqueueAuditEvent).not.toHaveBeenCalled();
  });

  it('best-effort: never throws when enqueue rejects', async () => {
    enqueueAuditEvent.mockRejectedValue(new Error('insert failed'));

    await expect(
      recordAuditEvent({
        type: 'pos.cart_discarded',
        aggregateType: 'PosSale',
        aggregateId: 'cart-1',
        payload: { line_count: 2 },
      }),
    ).resolves.toBeUndefined();
  });

  it('manager_pin_failed payload carries no secret (caller-shaped, sanity check)', async () => {
    await recordAuditEvent({
      type: 'pos.manager_pin_failed',
      aggregateType: 'Operator',
      aggregateId: 'context-void',
      payload: { context: 'void', attempt_count: 3 },
    });
    const [, row] = enqueueAuditEvent.mock.calls[0] as [unknown, { payload: string }];
    const payload = JSON.parse(row.payload) as Record<string, unknown>;
    expect(payload).not.toHaveProperty('pin');
    expect(payload).not.toHaveProperty('pin_hash');
    expect(payload).not.toHaveProperty('hash');
    expect(payload).toEqual({ context: 'void', attempt_count: 3 });
  });
});
