import type Database from '@tauri-apps/plugin-sql';
import { queryAll, execute } from '@/lib/db';

export interface OfflineCashDrawerOp {
  id: string;
  idempotency_key: string;
  type: 'deposit' | 'payout';
  amount: string;
  reason: string;
  operator_id: string;
  operator_name: string;
  terminal_id: string;
  shift_id: string;
  status: 'pending' | 'syncing' | 'synced' | 'failed';
  retry_count: number;
  created_at: string;
  synced_at: string | null;
  sync_error: string | null;
}

export async function insertCashDrawerOp(
  db: Database,
  op: Omit<OfflineCashDrawerOp, 'created_at' | 'synced_at' | 'sync_error' | 'retry_count'>,
): Promise<void> {
  await execute(
    db,
    `INSERT INTO offline_cash_drawer_ops (
      id, idempotency_key, type, amount, reason,
      operator_id, operator_name, terminal_id, shift_id, status
    ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)`,
    [
      op.id, op.idempotency_key, op.type, op.amount, op.reason,
      op.operator_id, op.operator_name, op.terminal_id, op.shift_id, op.status,
    ],
  );
}

export async function getPendingCashDrawerOps(db: Database): Promise<OfflineCashDrawerOp[]> {
  return queryAll<OfflineCashDrawerOp>(
    db,
    "SELECT * FROM offline_cash_drawer_ops WHERE status IN ('pending', 'failed') AND retry_count < 5 ORDER BY created_at ASC",
  );
}

export async function getCashDrawerOpsForShift(
  db: Database,
  shiftId: string,
): Promise<OfflineCashDrawerOp[]> {
  return queryAll<OfflineCashDrawerOp>(
    db,
    'SELECT * FROM offline_cash_drawer_ops WHERE shift_id = $1 ORDER BY created_at DESC',
    [shiftId],
  );
}

export async function updateCashDrawerOpStatus(
  db: Database,
  id: string,
  status: OfflineCashDrawerOp['status'],
  syncError?: string,
): Promise<void> {
  if (status === 'synced') {
    await execute(
      db,
      "UPDATE offline_cash_drawer_ops SET status = $1, synced_at = datetime('now'), sync_error = NULL WHERE id = $2",
      [status, id],
    );
  } else {
    await execute(
      db,
      'UPDATE offline_cash_drawer_ops SET status = $1, sync_error = $2, retry_count = retry_count + 1 WHERE id = $3',
      [status, syncError ?? null, id],
    );
  }
}

export async function cleanupSyncedCashDrawerOps(db: Database): Promise<void> {
  await execute(
    db,
    "DELETE FROM offline_cash_drawer_ops WHERE status = 'synced' AND synced_at < datetime('now', '-30 days')",
  );
}
