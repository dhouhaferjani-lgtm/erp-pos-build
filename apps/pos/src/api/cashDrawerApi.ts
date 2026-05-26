import { ApiRequestError, apiGet, apiPost } from '@/lib/api';
import { getCurrencyDecimals } from '@/lib/currency';
import { getDatabase } from '@/lib/db';
import { authorZCashDrawerMovement } from '@/lib/fiscal/zSessionAuthoring';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import {
  insertCashDrawerOp,
  getCashDrawerOpsForShift,
} from '@/lib/db/repositories/cashDrawerRepository';
import { ensureApprovalFiscalEventsSynced } from '@/lib/operatorApproval/approvalFiscalSync';

export interface CashDrawerApprovalEvidence {
  approval_id: string;
  approval_fiscal_event_id: string;
  approval_scope: 'cash_drawer_control';
  approval_supervisor_user_id: string;
  approval_target_hash: string;
}

function getDb() {
  const { companyId } = useAuthStore.getState();
  return getDatabase(companyId ?? '');
}

export async function depositCash(data: {
  amount: string;
  reason: string;
  approvalEvidence: CashDrawerApprovalEvidence;
  idempotencyKey?: string;
}): Promise<void> {
  const idempotencyKey = data.idempotencyKey ?? crypto.randomUUID();
  const { companyId } = useAuthStore.getState();
  await ensureApprovalFiscalEventsSynced(companyId ?? '', [data.approvalEvidence.approval_fiscal_event_id]);
  if (isCutoverTerminal()) {
    await authorCashDrawerMovement('deposit', data.amount, data.reason, data.approvalEvidence, idempotencyKey);
    return;
  }

  const payload = cashDrawerPayload(data, idempotencyKey);
  try {
    return await apiPost<void>('/pos/cash-drawer/deposit', payload);
  } catch (error) {
    if (error instanceof ApiRequestError && error.status < 500) {
      throw error;
    }
    await saveOfflineCashDrawerOp('deposit', data.amount, data.reason, data.approvalEvidence, idempotencyKey, idempotencyKey);
  }
}

export async function payoutCash(data: {
  amount: string;
  reason: string;
  approvalEvidence: CashDrawerApprovalEvidence;
  idempotencyKey?: string;
}): Promise<void> {
  const idempotencyKey = data.idempotencyKey ?? crypto.randomUUID();
  const { companyId } = useAuthStore.getState();
  await ensureApprovalFiscalEventsSynced(companyId ?? '', [data.approvalEvidence.approval_fiscal_event_id]);
  if (isCutoverTerminal()) {
    await authorCashDrawerMovement('payout', data.amount, data.reason, data.approvalEvidence, idempotencyKey);
    return;
  }

  const payload = cashDrawerPayload(data, idempotencyKey);
  try {
    return await apiPost<void>('/pos/cash-drawer/payout', payload);
  } catch (error) {
    if (error instanceof ApiRequestError && error.status < 500) {
      throw error;
    }
    await saveOfflineCashDrawerOp('payout', data.amount, data.reason, data.approvalEvidence, idempotencyKey, idempotencyKey);
  }
}

function isCutoverTerminal(): boolean {
  return useTerminalStore.getState().terminal?.fiscal_schema_version === 3;
}

export async function fetchDrawerBalance(shiftId: string): Promise<{ balance: string }> {
  try {
    return await apiGet<{ balance: string }>(`/pos/cash-drawer/${shiftId}/balance`);
  } catch {
    // Offline fallback: compute from local ops
    const db = await getDb();
    const ops = await getCashDrawerOpsForShift(db, shiftId);
    let balance = 0;
    for (const op of ops) {
      if (op.type === 'deposit') {
        balance += parseFloat(op.amount);
      } else {
        balance -= parseFloat(op.amount);
      }
    }
    return { balance: balance.toFixed(3) };
  }
}

async function saveOfflineCashDrawerOp(
  type: 'deposit' | 'payout',
  amount: string,
  reason: string,
  approvalEvidence: CashDrawerApprovalEvidence,
  idempotencyKey: string,
  operationId: string,
): Promise<void> {
  const db = await getDb();
  const { shift, terminal } = useTerminalStore.getState();
  const operator = useOperatorStore.getState().operator;

  if (!shift || !terminal) {
    throw new Error('No active shift or terminal');
  }

  await insertCashDrawerOp(db, {
    id: operationId,
    idempotency_key: idempotencyKey,
    type,
    amount,
    reason,
    operator_id: operator?.id ?? '',
    operator_name: operator?.name ?? 'Operator',
    terminal_id: terminal.id,
    shift_id: shift.id,
    status: 'pending',
    approval_id: approvalEvidence.approval_id,
    approval_fiscal_event_id: approvalEvidence.approval_fiscal_event_id,
    approval_scope: approvalEvidence.approval_scope,
    approval_supervisor_user_id: approvalEvidence.approval_supervisor_user_id,
    approval_target_hash: approvalEvidence.approval_target_hash,
  });
}

async function authorCashDrawerMovement(
  type: 'deposit' | 'payout',
  amount: string,
  reason: string,
  approvalEvidence: CashDrawerApprovalEvidence,
  movementId: string,
): Promise<void> {
  const auth = useAuthStore.getState();
  const { shift, terminal } = useTerminalStore.getState();
  const operator = useOperatorStore.getState().operator;

  if (!auth.user?.tenantId || !auth.companyId) {
    throw new Error('Cannot author cash drawer movement without active tenant and company.');
  }
  if (!shift || !terminal || !shift.fiscal_shift_id || !shift.fiscal_session_id) {
    throw new Error('Cannot author cash drawer movement without an active fiscal session.');
  }
  if (!operator) {
    throw new Error('Cannot author cash drawer movement without an active operator.');
  }

  const company = auth.companies.find((candidate) => candidate.id === auth.companyId);
  const currencyCode = company?.currency ?? 'EUR';
  const currencyScale = fiscalCurrencyScale(currencyCode);
  await authorZCashDrawerMovement({
    tenantId: auth.user.tenantId,
    companyId: auth.companyId,
    terminalId: terminal.id,
    shiftId: shift.fiscal_shift_id,
    sessionId: shift.fiscal_session_id,
    businessDate: shift.opened_at.slice(0, 10),
    operatorId: operator.id,
    operatorName: operator.name,
    movementType: type === 'deposit' ? 'CASH_IN' : 'CASH_OUT',
    amount,
    currencyCode,
    currencyScale,
    reasonCode: type === 'deposit' ? 'cash_drawer_deposit' : 'cash_drawer_payout',
    reasonText: reason,
    cashDrawerOperationId: movementId,
    approval: {
      approval_event_id: approvalEvidence.approval_fiscal_event_id,
      approval_id: approvalEvidence.approval_id,
      policy_version: 'pos-cash-drawer-policy-v1',
      scope: approvalEvidence.approval_scope,
      supervisor_user_id: approvalEvidence.approval_supervisor_user_id,
      target_hash: approvalEvidence.approval_target_hash,
    },
    isTraining: terminal.is_training_mode === true,
    movementId,
  });
}

function fiscalCurrencyScale(currencyCode: string): 0 | 2 | 3 {
  const scale = getCurrencyDecimals(currencyCode);
  if (scale === 0 || scale === 2 || scale === 3) return scale;
  throw new Error(`Unsupported fiscal currency scale ${scale} for ${currencyCode}`);
}

function cashDrawerPayload(data: {
  amount: string;
  reason: string;
  approvalEvidence: CashDrawerApprovalEvidence;
}, idempotencyKey: string): Record<string, unknown> {
  const shift = useTerminalStore.getState().shift;
  const operator = useOperatorStore.getState().operator;
  if (!shift) {
    throw new Error('No active shift');
  }
  if (!operator) {
    throw new Error('No active operator');
  }

  return {
    idempotency_key: idempotencyKey,
    shift_id: shift.id,
    operator_id: operator.id,
    amount: data.amount,
    reason: data.reason,
    ...(data.approvalEvidence ?? {}),
  };
}
