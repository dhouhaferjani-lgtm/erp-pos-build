import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiRequestError, apiPost } from '@/lib/api';
import { getDatabase } from '@/lib/db';
import { getAllOperators, type CachedOperator } from '@/lib/db/repositories/operatorPinRepository';
import { verifyScopedManagerPin } from '../scopedManagerPin';

vi.mock('bcryptjs', () => ({
  default: { compareSync: vi.fn(() => true) },
}));

vi.mock('@/lib/db', () => ({ getDatabase: vi.fn() }));

vi.mock('@/lib/db/repositories/operatorPinRepository', () => ({
  getAllOperators: vi.fn(),
}));

vi.mock('@/lib/api', () => {
  class MockApiRequestError extends Error {
    constructor(
      public readonly status: number,
      public readonly apiMessage: string,
      public readonly code: string,
      public readonly details?: Record<string, unknown>,
    ) {
      super(apiMessage);
      this.name = 'ApiRequestError';
    }
  }
  return { ApiRequestError: MockApiRequestError, apiPost: vi.fn() };
});

const recordAuditEvent = vi.fn().mockResolvedValue(undefined);
vi.mock('@/lib/audit/recordAuditEvent', () => ({
  recordAuditEvent: (...args: unknown[]) => recordAuditEvent(...args),
}));

function operator(overrides: Partial<CachedOperator> = {}): CachedOperator {
  return {
    id: 'supervisor-1',
    tenant_id: 'tenant-1',
    name: 'Supervisor',
    email: 'supervisor@example.test',
    pin_hash: '$2a$10$known',
    roles: ['manager'],
    permissions: ['pos.close_shift_with_variance'],
    company_ids: ['company-1'],
    terminal_ids: ['terminal-1'],
    approval_scopes: ['discount_limit_override'],
    approval_scope_permissions_fetched_at: '2026-05-23T08:00:00.000Z',
    approval_mirror_status: 'fresh',
    can_discount: true,
    max_discount_percent: 50,
    discount_permissions_fetched_at: null,
    discount_permissions_terminal_code: null,
    discount_permissions_status: 'fresh',
    ...overrides,
  };
}

const input = {
  pin: '1234',
  context: {
    tenantId: 'tenant-1',
    companyId: 'company-1',
    terminalId: 'terminal-1',
    cashierUserId: 'cashier-1',
    businessDate: '2026-05-23',
    isTraining: false,
  },
  approvalScope: 'discount_limit_override' as const,
  targetEventType: 'SALE_RECEIPT',
  targetReferenceId: 'receipt-1',
  reason: 'High discount',
};

function lastDenied(): Record<string, unknown> | undefined {
  for (let i = recordAuditEvent.mock.calls.length - 1; i >= 0; i--) {
    const arg = recordAuditEvent.mock.calls[i]![0] as Record<string, unknown>;
    if (arg.type === 'pos.manager_override_denied') return arg;
  }
  return undefined;
}

describe('verifyScopedManagerPin — Task 11 pos.manager_override_denied', () => {
  beforeEach(() => {
    vi.mocked(getDatabase).mockResolvedValue({} as Awaited<ReturnType<typeof getDatabase>>);
    vi.mocked(apiPost).mockReset();
    recordAuditEvent.mockReset();
    recordAuditEvent.mockResolvedValue(undefined);
  });

  it('emits on the scope-mismatch (no matching manager) branch with no secret', async () => {
    // Operator lacks the requested scope → verifyOfflineApprovalPin returns
    // scope_mismatch for everyone → matched === null.
    vi.mocked(getAllOperators).mockResolvedValue([
      operator({ approval_scopes: ['tender_tolerance_override'] }),
    ]);

    await expect(verifyScopedManagerPin(input)).rejects.toThrow('manager_pin_scope_mismatch');

    const call = lastDenied();
    expect(call).toBeDefined();
    expect(call!.aggregateType).toBe('Override');
    expect(call!.aggregateId).toBe('terminal-1');
    expect(call!.tenantId).toBe('tenant-1');
    expect(call!.companyId).toBe('company-1');
    const payload = call!.payload as Record<string, unknown>;
    expect(payload.scope).toBe('discount_limit'); // mapped from discount_limit_override
    expect(payload.reason).toBe('High discount');
    expect(payload).toHaveProperty('requested_amount', null);
    expect(payload).toHaveProperty('cart_total', null);
    // NO pin / NO hash.
    const serialized = JSON.stringify(payload);
    expect(serialized).not.toContain('1234');
    expect(serialized).not.toContain('$2a$10$known');
    expect(payload).not.toHaveProperty('pin');
    expect(payload).not.toHaveProperty('hash');
  });

  it('emits on the online 4xx rejection branch', async () => {
    vi.mocked(getAllOperators).mockResolvedValue([operator()]);
    vi.mocked(apiPost).mockRejectedValue(
      new ApiRequestError(422, 'Invalid approval context', 'VALIDATION_ERROR'),
    );

    await expect(verifyScopedManagerPin(input)).rejects.toBeInstanceOf(ApiRequestError);

    const call = lastDenied();
    expect(call).toBeDefined();
    expect(call!.operatorId).toBe('supervisor-1');
    const payload = call!.payload as Record<string, unknown>;
    expect(payload.scope).toBe('discount_limit');
    expect(JSON.stringify(payload)).not.toContain('1234');
  });

  it('does NOT emit when verification succeeds', async () => {
    vi.mocked(getAllOperators).mockResolvedValue([operator()]);
    vi.mocked(apiPost).mockResolvedValue({ valid: true, user_id: 'supervisor-1' });

    await verifyScopedManagerPin(input);

    expect(lastDenied()).toBeUndefined();
  });

  it('still rejects when the audit emit itself rejects', async () => {
    recordAuditEvent.mockRejectedValue(new Error('audit down'));
    vi.mocked(getAllOperators).mockResolvedValue([
      operator({ approval_scopes: ['tender_tolerance_override'] }),
    ]);

    await expect(verifyScopedManagerPin(input)).rejects.toThrow('manager_pin_scope_mismatch');
  });
});
