import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiRequestError, apiPost } from '@/lib/api';
import { getDatabase } from '@/lib/db';
import { getAllOperators, type CachedOperator } from '@/lib/db/repositories/operatorPinRepository';
import { verifyScopedManagerPin } from '../scopedManagerPin';

vi.mock('bcryptjs', () => ({
  default: {
    compareSync: vi.fn(() => true),
  },
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn(),
}));

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

  return {
    ApiRequestError: MockApiRequestError,
    apiPost: vi.fn(),
  };
});

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

describe('verifyScopedManagerPin', () => {
  beforeEach(() => {
    vi.mocked(getDatabase).mockResolvedValue({} as Awaited<ReturnType<typeof getDatabase>>);
    vi.mocked(getAllOperators).mockResolvedValue([operator()]);
    vi.mocked(apiPost).mockReset();
  });

  it('fails closed when online manager verification explicitly rejects a cached PIN', async () => {
    vi.mocked(apiPost).mockResolvedValue({
      valid: false,
      failure_code: 'scope_mismatch',
    });

    await expect(verifyScopedManagerPin(input)).rejects.toBeInstanceOf(ApiRequestError);
  });

  it('allows offline fallback only when the online verification transport fails', async () => {
    vi.mocked(apiPost).mockRejectedValue(new Error('Network error'));

    await expect(verifyScopedManagerPin(input)).resolves.toEqual({
      id: 'supervisor-1',
      name: 'Supervisor',
      roles: ['manager'],
    });
  });

  it('does not fall back for client-side API denials', async () => {
    vi.mocked(apiPost).mockRejectedValue(new ApiRequestError(422, 'Invalid approval context', 'VALIDATION_ERROR'));

    await expect(verifyScopedManagerPin(input)).rejects.toBeInstanceOf(ApiRequestError);
  });

  it.each([408, 429])(
    'falls back to the local approval on a "server busy" %i (not an explicit denial)',
    async (status) => {
      // 408 (Request Timeout) / 429 (Too Many Requests) mean the server was
      // busy/couldn't decide — NOT "the PIN is wrong". Offline-is-really-first:
      // keep working off the local manager PIN, same as a 5xx.
      vi.mocked(apiPost).mockRejectedValue(new ApiRequestError(status, 'busy', 'BUSY'));

      await expect(verifyScopedManagerPin(input)).resolves.toEqual({
        id: 'supervisor-1',
        name: 'Supervisor',
        roles: ['manager'],
      });
    },
  );

  it('falls back to the local manager-PIN approval on a server 5xx (offline-first)', async () => {
    // Offline-is-really-first: a 5xx means the server could not authorize, so
    // keep working off the valid LOCAL manager PIN — exactly like a genuine
    // transport failure. The server-unconfirmed approval is made visible to
    // fraud via pos.manager_override_offline_approved (see the audit test).
    // Only an EXPLICIT server denial (valid:false / 4xx) blocks the override.
    vi.mocked(apiPost).mockRejectedValue(
      new ApiRequestError(503, 'Service Unavailable', 'SERVICE_UNAVAILABLE'),
    );

    await expect(verifyScopedManagerPin(input)).resolves.toEqual({
      id: 'supervisor-1',
      name: 'Supervisor',
      roles: ['manager'],
    });
  });
});
