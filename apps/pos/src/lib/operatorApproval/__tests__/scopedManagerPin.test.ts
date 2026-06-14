import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiRequestError, apiPost } from '@/lib/api';
import { getDatabase } from '@/lib/db';
import { getAllOperators, type CachedOperator } from '@/lib/db/repositories/operatorPinRepository';
import { FetchTimeoutError } from '@/lib/fetchWithTimeout';
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

// Default: terminal is online. Individual tests can override via
// mockConnectivityOnline(false) to simulate a genuinely-offline terminal.
const mockGetState = vi.fn(() => ({ isOnline: true }));
vi.mock('@/stores/connectivityStore', () => ({
  useConnectivityStore: { getState: () => mockGetState() },
}));

function mockConnectivityOnline(isOnline: boolean): void {
  mockGetState.mockReturnValue({ isOnline });
}

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
    mockConnectivityOnline(true);
  });

  it('returns the server-confirmed approval when online verification succeeds', async () => {
    vi.mocked(apiPost).mockResolvedValue({
      valid: true,
      user_id: 'supervisor-1',
      user_name: 'Supervisor',
    });

    await expect(verifyScopedManagerPin(input)).resolves.toEqual({
      id: 'supervisor-1',
      name: 'Supervisor',
      roles: ['manager'],
    });
    expect(vi.mocked(apiPost)).toHaveBeenCalledTimes(1);
  });

  it('with targetOperatorId, matches ONLY that operator (EOD selected-manager close)', async () => {
    // Two scope-holding managers; bcrypt mock returns true for both. Without
    // targetOperatorId the loop would match the first (supervisor-1). With it,
    // the match is pinned to supervisor-2 → the online confirm carries that id.
    vi.mocked(getAllOperators).mockResolvedValue([
      operator({ id: 'supervisor-1', name: 'First', approval_scopes: ['close_shift_variance'] }),
      operator({ id: 'supervisor-2', name: 'Second', approval_scopes: ['close_shift_variance'] }),
    ]);
    vi.mocked(apiPost).mockResolvedValue({
      valid: true,
      user_id: 'supervisor-2',
      user_name: 'Second',
    });

    await expect(
      verifyScopedManagerPin({
        ...input,
        approvalScope: 'close_shift_variance',
        targetOperatorId: 'supervisor-2',
      }),
    ).resolves.toMatchObject({ id: 'supervisor-2' });
    expect(vi.mocked(apiPost)).toHaveBeenCalledWith(
      '/pos/verify-manager-pin',
      expect.objectContaining({ user_id: 'supervisor-2', approval_scope: 'close_shift_variance' }),
    );
  });

  it('with targetOperatorId, a non-matching selected manager is a scope mismatch (no online call)', async () => {
    vi.mocked(getAllOperators).mockResolvedValue([
      operator({ id: 'supervisor-1', approval_scopes: ['close_shift_variance'] }),
    ]);

    await expect(
      verifyScopedManagerPin({
        ...input,
        approvalScope: 'close_shift_variance',
        targetOperatorId: 'someone-else',
      }),
    ).rejects.toThrow('manager_pin_scope_mismatch');
    expect(vi.mocked(apiPost)).not.toHaveBeenCalled();
  });

  it('fails closed when online manager verification explicitly rejects a cached PIN', async () => {
    vi.mocked(apiPost).mockResolvedValue({
      valid: false,
      failure_code: 'scope_mismatch',
    });

    await expect(verifyScopedManagerPin(input)).rejects.toBeInstanceOf(ApiRequestError);
    // valid:false → 403 explicit denial. No retry.
    expect(vi.mocked(apiPost)).toHaveBeenCalledTimes(1);
  });

  it('falls back to local approval when genuinely offline (connectivity isOnline=false)', async () => {
    // Positive offline evidence: the POS connectivity store says offline.
    mockConnectivityOnline(false);
    vi.mocked(apiPost).mockRejectedValue(new Error('Network error'));

    await expect(verifyScopedManagerPin(input)).resolves.toEqual({
      id: 'supervisor-1',
      name: 'Supervisor',
      roles: ['manager'],
    });
    // Genuine offline → straight to local fallback, NO retry.
    expect(vi.mocked(apiPost)).toHaveBeenCalledTimes(1);
  });

  it('falls back to local approval on a read timeout (FetchTimeoutError)', async () => {
    // Positive offline evidence: a typed read-timeout is an unreachable server.
    // Connectivity may still report online (race between probe and this request).
    vi.mocked(apiPost).mockRejectedValue(
      new FetchTimeoutError('/pos/verify-manager-pin', 10_000, 'POST'),
    );

    await expect(verifyScopedManagerPin(input)).resolves.toEqual({
      id: 'supervisor-1',
      name: 'Supervisor',
      roles: ['manager'],
    });
    expect(vi.mocked(apiPost)).toHaveBeenCalledTimes(1);
  });

  it('FAILS CLOSED on an unexpected error while online (regression for MAJOR security finding)', async () => {
    // Connectivity is online (default), and apiPost throws a generic error
    // (not FetchTimeoutError, not ApiRequestError). This is the MAJOR:
    // previously this path fell back to a local-PIN approval (fail-open).
    // After the fix it must FAIL CLOSED with a 503 and never return an approval.
    vi.mocked(apiPost).mockRejectedValue(new Error('boom'));

    await expect(verifyScopedManagerPin(input)).rejects.toMatchObject({
      status: 503,
      code: 'MANAGER_OVERRIDE_SERVICE_UNAVAILABLE',
    });
    expect(vi.mocked(apiPost)).toHaveBeenCalledTimes(1);
  });

  it('does not fall back for client-side API denials (4xx 422), no retry', async () => {
    vi.mocked(apiPost).mockRejectedValue(
      new ApiRequestError(422, 'Invalid approval context', 'VALIDATION_ERROR'),
    );

    await expect(verifyScopedManagerPin(input)).rejects.toBeInstanceOf(ApiRequestError);
    // Explicit authorization denial → no retry, no fallback.
    expect(vi.mocked(apiPost)).toHaveBeenCalledTimes(1);
  });

  describe('anti-downgrade: reachable-but-erroring server fails closed after retries', () => {
    beforeEach(() => {
      vi.useFakeTimers();
    });

    afterEach(() => {
      vi.useRealTimers();
    });

    it.each([408, 429, 503])(
      'rejects (fails closed) after 3 attempts on a reachable-but-erroring %i',
      async (status) => {
        // 408 / 429 / 5xx mean the server is REACHABLE but could not authorize.
        // Anti-downgrade: retry up to a cap, then FAIL CLOSED — never silently
        // downgrade to the local manager PIN. A forgeable PIN must not bypass a
        // reachable server's caps/revocation checks.
        vi.mocked(apiPost).mockRejectedValue(
          new ApiRequestError(status, 'busy/erroring', 'ERR'),
        );

        const promise = verifyScopedManagerPin(input);
        const assertion = expect(promise).rejects.toBeInstanceOf(ApiRequestError);
        await vi.runAllTimersAsync();
        await assertion;

        // 1 initial + 2 retries = 3 attempts total.
        expect(vi.mocked(apiPost)).toHaveBeenCalledTimes(3);
      },
    );

    it('rejects with a 503 service-unavailable error after exhausting retries on a 5xx', async () => {
      vi.mocked(apiPost).mockRejectedValue(
        new ApiRequestError(503, 'Service Unavailable', 'SERVICE_UNAVAILABLE'),
      );

      const promise = verifyScopedManagerPin(input);
      const assertion = expect(promise).rejects.toMatchObject({
        status: 503,
        code: 'MANAGER_OVERRIDE_SERVICE_UNAVAILABLE',
      });
      await vi.runAllTimersAsync();
      await assertion;
    });
  });
});
