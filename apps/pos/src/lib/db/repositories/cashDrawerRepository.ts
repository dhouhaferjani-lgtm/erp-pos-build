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

/**
 * Boot-time recovery for stranded `'syncing'` cash-drawer ops — same shape
 * as the offline-receipt recovery shipped in PR #94 (T2.1 Step D), with
 * one targeted addition (`retry_count` decrement) to compensate for an
 * asymmetry in the cash-drawer status-update path.
 *
 * `syncService.pushCashDrawerOps` advances a row to `'syncing'` BEFORE the
 * sync HTTP call. A SIGKILL / power-cut / OS-level kill BETWEEN the status
 * update and the response handler leaves the row at `'syncing'`
 * permanently. `getPendingCashDrawerOps` filters
 *   `WHERE status IN ('pending', 'failed') AND retry_count < 5`
 * so the orphan is invisible to every retry. Net effect: a deposit/payout
 * that the cashier counted on for till reconciliation is silently dropped.
 *
 * This recovery demotes any `'syncing'` row back to `'pending'` so the
 * next sync tick re-attempts it. Idempotent across multiple boots —
 * subsequent calls find no `'syncing'` rows and are no-ops.
 *
 * **Why retry_count must decrement (Codex round-1 P2):** unlike
 * `offlineReceiptRepository.updateReceiptStatus` which never increments
 * retry_count on non-`'synced'` status changes, the cash-drawer status
 * update increments retry_count for every non-`'synced'` transition
 * (including `'syncing'`). Without compensation, a row at `retry_count
 * = 4` that crashes mid-sync ends up with `retry_count = 5` even after
 * the recovery demotes status — `getPendingCashDrawerOps`'s
 * `retry_count < 5` filter would then skip it forever, defeating the
 * whole point of the recovery on the final attempt. Decrementing by 1
 * (with a `MAX(0, retry_count - 1)` floor for safety) subtracts the
 * spurious increment from the syncing transition and restores the row
 * to its pre-attempt state, eligible for one more genuine retry.
 *
 * Idempotency reasoning matches Step D — a row at `'syncing'` is in one of
 * three post-crash states:
 *   1. HTTP request never left the device → server has nothing →
 *      retry as fresh send. ✓
 *   2. HTTP request succeeded server-side but response was lost →
 *      retry → server detects via T0.2 idempotency_key and returns
 *      the prior result → client advances to `'synced'`. ✓
 *   3. HTTP request succeeded AND response landed but the local
 *      status update failed → retry → same as (2). ✓
 *
 * Returns the number of rows demoted (for boot-time observability).
 */
export async function recoverStrandedSyncingCashDrawerOps(
  db: Database,
): Promise<number> {
  const result = await execute(
    db,
    "UPDATE offline_cash_drawer_ops SET status = 'pending', retry_count = MAX(0, retry_count - 1) WHERE status = 'syncing'",
  );
  return result.rowsAffected;
}
