import { ApiRequestError, apiGet, apiPost } from '@/lib/api';
import { getDatabase } from '@/lib/db';
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
  const payload = cashDrawerPayload(data, idempotencyKey);
  try {
    const { companyId } = useAuthStore.getState();
    await ensureApprovalFiscalEventsSynced(companyId ?? '', [data.approvalEvidence.approval_fiscal_event_id]);
    return await apiPost<void>('/pos/cash-drawer/deposit', payload);
  } catch (error) {
    if (error instanceof ApiRequestError && error.status < 500) {
      throw error;
    }
    await saveOfflineCashDrawerOp('deposit', data.amount, data.reason, data.approvalEvidence, idempotencyKey);
  }
}

export async function payoutCash(data: {
  amount: string;
  reason: string;
  approvalEvidence: CashDrawerApprovalEvidence;
  idempotencyKey?: string;
}): Promise<void> {
  const idempotencyKey = data.idempotencyKey ?? crypto.randomUUID();
  const payload = cashDrawerPayload(data, idempotencyKey);
  try {
    const { companyId } = useAuthStore.getState();
    await ensureApprovalFiscalEventsSynced(companyId ?? '', [data.approvalEvidence.approval_fiscal_event_id]);
    return await apiPost<void>('/pos/cash-drawer/payout', payload);
  } catch (error) {
    if (error instanceof ApiRequestError && error.status < 500) {
      throw error;
    }
    await saveOfflineCashDrawerOp('payout', data.amount, data.reason, data.approvalEvidence, idempotencyKey);
  }
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
): Promise<void> {
  const db = await getDb();
  const { shift, terminal } = useTerminalStore.getState();
  const operator = useOperatorStore.getState().operator;

  if (!shift || !terminal) {
    throw new Error('No active shift or terminal');
  }

  await insertCashDrawerOp(db, {
    id: crypto.randomUUID(),
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
