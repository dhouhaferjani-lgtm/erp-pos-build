import { apiGet, apiPost } from '@/lib/api';
import { getDatabase } from '@/lib/db';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import {
  insertCashDrawerOp,
  getCashDrawerOpsForShift,
} from '@/lib/db/repositories/cashDrawerRepository';

function getDb() {
  const { companyId } = useAuthStore.getState();
  return getDatabase(companyId ?? '');
}

export async function depositCash(data: { amount: string; reason: string }): Promise<void> {
  try {
    return await apiPost<void>('/pos/cash-drawer/deposit', data);
  } catch {
    await saveOfflineCashDrawerOp('deposit', data.amount, data.reason);
  }
}

export async function payoutCash(data: { amount: string; reason: string }): Promise<void> {
  try {
    return await apiPost<void>('/pos/cash-drawer/payout', data);
  } catch {
    await saveOfflineCashDrawerOp('payout', data.amount, data.reason);
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
  });
}
