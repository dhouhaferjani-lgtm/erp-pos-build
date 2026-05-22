import { apiGet, apiPost } from '@/lib/api';
import { getDatabase } from '@/lib/db';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import {
  insertCashDrawerOp,
  getCashDrawerOpsForShift,
} from '@/lib/db/repositories/cashDrawerRepository';

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
  approvalEvidence?: CashDrawerApprovalEvidence | null;
}): Promise<void> {
  const payload = cashDrawerPayload(data);
  try {
    return await apiPost<void>('/pos/cash-drawer/deposit', payload);
  } catch {
    await saveOfflineCashDrawerOp('deposit', data.amount, data.reason, data.approvalEvidence ?? null);
  }
}

export async function payoutCash(data: {
  amount: string;
  reason: string;
  approvalEvidence?: CashDrawerApprovalEvidence | null;
}): Promise<void> {
  const payload = cashDrawerPayload(data);
  try {
    return await apiPost<void>('/pos/cash-drawer/payout', payload);
  } catch {
    await saveOfflineCashDrawerOp('payout', data.amount, data.reason, data.approvalEvidence ?? null);
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
  approvalEvidence: CashDrawerApprovalEvidence | null,
): Promise<void> {
  const db = await getDb();
  const { shift, terminal } = useTerminalStore.getState();
  const operator = useOperatorStore.getState().operator;

  if (!shift || !terminal) {
    throw new Error('No active shift or terminal');
  }

  await insertCashDrawerOp(db, {
    id: crypto.randomUUID(),
    idempotency_key: crypto.randomUUID(),
    type,
    amount,
    reason,
    operator_id: operator?.id ?? '',
    operator_name: operator?.name ?? 'Operator',
    terminal_id: terminal.id,
    shift_id: shift.id,
    status: 'pending',
    approval_id: approvalEvidence?.approval_id ?? null,
    approval_fiscal_event_id: approvalEvidence?.approval_fiscal_event_id ?? null,
    approval_scope: approvalEvidence?.approval_scope ?? null,
    approval_supervisor_user_id: approvalEvidence?.approval_supervisor_user_id ?? null,
    approval_target_hash: approvalEvidence?.approval_target_hash ?? null,
  });
}

function cashDrawerPayload(data: {
  amount: string;
  reason: string;
  approvalEvidence?: CashDrawerApprovalEvidence | null;
}): Record<string, unknown> {
  return {
    amount: data.amount,
    reason: data.reason,
    ...(data.approvalEvidence ?? {}),
  };
}
