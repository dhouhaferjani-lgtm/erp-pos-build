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
    shift: {
      id: 'shift-legacy-1',
      fiscal_shift_id: '33333333-3333-4333-8333-333333333333',
      fiscal_session_id: '44444444-4444-4444-8444-444444444444',
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
}));

vi.mock('@/stores/operatorStore', () => ({
  useOperatorStore: {
    getState: vi.fn(() => storeMocks.operatorState),
  },
}));

import { depositCash } from '@/api/cashDrawerApi';
import { apiPost } from '@/lib/api';
import { authorZCashDrawerMovement } from '@/lib/fiscal/zSessionAuthoring';
import { insertCashDrawerOp } from '@/lib/db/repositories/cashDrawerRepository';
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
    expect(apiPost).not.toHaveBeenCalled();
    expect(insertCashDrawerOp).not.toHaveBeenCalled();
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
      shift_id: 'shift-legacy-1',
      amount: '25.00',
    }));
  });
});
