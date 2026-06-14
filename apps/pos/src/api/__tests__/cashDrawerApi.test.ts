import { beforeEach, describe, expect, it, vi } from 'vitest';

const storeMocks = vi.hoisted(() => ({
  authState: {
    user: { id: 'user-1', name: 'Alice', tenantId: 'tenant-1' },
    companyId: 'company-1',
    companies: [{ id: 'company-1', currency: 'EUR' }],
  },
  terminalState: {
    terminal: {
      id: 'terminal-1',
      fiscal_schema_version: 3,
      is_training_mode: false,
    },
    // One-id model: shift.id IS the fiscal shift/session id.
    shift: {
      id: '33333333-3333-4333-8333-333333333333',
      fiscal_shift_id: '33333333-3333-4333-8333-333333333333',
      fiscal_session_id: '33333333-3333-4333-8333-333333333333',
      opened_at: '2026-05-24T08:00:00.000Z',
    },
  },
  operatorState: {
    operator: { id: 'operator-1', name: 'Alice' },
  },
}));

vi.mock('@/lib/api', () => ({
  ApiRequestError: class ApiRequestError extends Error {
    constructor(public status: number) {
      super('api request failed');
    }
  },
  apiGet: vi.fn(),
  apiPost: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/currency', () => ({
  getCurrencyDecimals: vi.fn().mockReturnValue(2),
  getActiveCurrencyDecimals: vi.fn().mockReturnValue(2),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
}));

vi.mock('@/lib/db/repositories/cashDrawerRepository', () => ({
  getCashDrawerOpsForShift: vi.fn().mockResolvedValue([]),
  insertCashDrawerOp: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/operatorApproval/approvalFiscalSync', () => ({
  ensureApprovalFiscalEventsSynced: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/fiscal/zSessionAuthoring', () => ({
  authorZCashDrawerMovement: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: vi.fn(() => storeMocks.authState),
  },
}));

vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: {
    getState: vi.fn(() => storeMocks.terminalState),
  },
  // One-id model: fiscal shift/session id IS shift.id (fail loud on non-UUID).
  fiscalShiftIdForReceipt: (shift: { id: string }) => {
    if (/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(shift.id)) {
      return shift.id.toLowerCase();
    }
    throw new Error(`Shift ${shift.id} has no usable fiscal shift id; cannot author fiscal events.`);
  },
}));

vi.mock('@/stores/operatorStore', () => ({
  useOperatorStore: {
    getState: vi.fn(() => storeMocks.operatorState),
  },
}));

import { depositCash, fetchDrawerBalance } from '@/api/cashDrawerApi';
import { apiGet, apiPost } from '@/lib/api';
import { authorZCashDrawerMovement } from '@/lib/fiscal/zSessionAuthoring';
import { getCashDrawerOpsForShift, insertCashDrawerOp } from '@/lib/db/repositories/cashDrawerRepository';
import { ensureApprovalFiscalEventsSynced } from '@/lib/operatorApproval/approvalFiscalSync';

const approvalEvidence = {
  approval_id: 'approval-1',
  approval_fiscal_event_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
  approval_scope: 'cash_drawer_control' as const,
  approval_supervisor_user_id: 'supervisor-1',
  approval_target_hash: 'target-hash',
};

describe('cashDrawerApi cutover ownership', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    storeMocks.terminalState.terminal.fiscal_schema_version = 3;
  });

  it('authors only the Z-session movement for cutover terminals', async () => {
    await depositCash({
      amount: '25.00',
      reason: 'Change refill',
      approvalEvidence,
      idempotencyKey: 'movement-1',
    });

    expect(ensureApprovalFiscalEventsSynced).toHaveBeenCalledWith('company-1', [
      approvalEvidence.approval_fiscal_event_id,
    ]);
    expect(authorZCashDrawerMovement).toHaveBeenCalledOnce();
    // One-id model: shiftId and sessionId are both shift.id.
    expect(authorZCashDrawerMovement).toHaveBeenCalledWith(
      expect.objectContaining({
        shiftId: '33333333-3333-4333-8333-333333333333',
        sessionId: '33333333-3333-4333-8333-333333333333',
      }),
    );
    expect(apiPost).not.toHaveBeenCalled();
    expect(insertCashDrawerOp).not.toHaveBeenCalled();
  });

  it('computes the offline drawer balance at the EUR currency scale (2, not 3)', async () => {
    // Server balance fetch fails → offline fallback sums local ops. The prior
    // implementation hardcoded toFixed(3); for an EUR drawer the balance must
    // canonicalize at scale 2 to match the server's CurrencyScale.
    vi.mocked(apiGet).mockRejectedValueOnce(new Error('offline'));
    vi.mocked(getCashDrawerOpsForShift).mockResolvedValueOnce([
      { type: 'deposit', amount: '10.10' },
      { type: 'deposit', amount: '0.20' },
      { type: 'payout', amount: '0.05' },
    ] as never);

    const result = await fetchDrawerBalance('shift-1');

    // 10.10 + 0.20 − 0.05 = 10.25 → scale 2, no float drift, no trailing 0.
    expect(result.balance).toBe('10.25');
  });

  it('uses the legacy API path without Z-session authoring for pre-cutover terminals', async () => {
    storeMocks.terminalState.terminal.fiscal_schema_version = 2;

    await depositCash({
      amount: '25.00',
      reason: 'Change refill',
      approvalEvidence,
      idempotencyKey: 'legacy-op-1',
    });

    expect(authorZCashDrawerMovement).not.toHaveBeenCalled();
    expect(apiPost).toHaveBeenCalledWith('/pos/cash-drawer/deposit', expect.objectContaining({
      idempotency_key: 'legacy-op-1',
      shift_id: '33333333-3333-4333-8333-333333333333',
      amount: '25.00',
    }));
  });
});
