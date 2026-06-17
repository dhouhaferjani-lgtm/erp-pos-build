import type Database from '@tauri-apps/plugin-sql';
import { queryAll, execute } from '@/lib/db';

export interface QueuedPinUpdate {
  id: number;
  userId: string;
  pinHash: string;
  status: 'pending' | 'syncing' | 'synced' | 'failed';
  retryCount: number;
  createdAt: string;
  syncedAt: string | null;
  syncError: string | null;
}

interface QueuedPinUpdateRow {
  id: number;
  user_id: string;
  pin_hash: string;
  status: QueuedPinUpdate['status'];
  retry_count: number;
  created_at: string;
  synced_at: string | null;
  sync_error: string | null;
}

function rowToUpdate(row: QueuedPinUpdateRow): QueuedPinUpdate {
  return {
    id: row.id,
    userId: row.user_id,
    pinHash: row.pin_hash,
    status: row.status,
    retryCount: row.retry_count,
    createdAt: row.created_at,
    syncedAt: row.synced_at,
    syncError: row.sync_error,
  };
}

export async function enqueuePinUpdate(
  db: Database,
  input: { userId: string; pinHash: string },
): Promise<void> {
  await execute(
    db,
    `INSERT INTO queued_pin_updates (user_id, pin_hash, status) VALUES ($1, $2, $3)`,
    [input.userId, input.pinHash, 'pending'],
  );
}

/**
 * Dead-letter cap. A row that has failed this many times stops being retried
 * (it stays in the table for a recovery/inspection path rather than retrying
 * forever). Matches the fiscal-event / cash-drawer / audit outbox cap.
 */
export const MAX_PIN_UPDATE_RETRIES = 5;

export async function getPendingPinUpdates(db: Database): Promise<QueuedPinUpdate[]> {
  // Include `failed` rows under the retry cap so a transient push failure is
  // retried instead of stranded — `markPinUpdateFailed` flips the row to
  // `failed`, and selecting only `pending` would never re-attempt it.
  const rows = await queryAll<QueuedPinUpdateRow>(
    db,
    `SELECT * FROM queued_pin_updates
     WHERE status IN ('pending', 'failed') AND retry_count < $1
     ORDER BY id ASC`,
    [MAX_PIN_UPDATE_RETRIES],
  );
  return rows.map(rowToUpdate);
}

export async function markPinUpdateSynced(db: Database, id: number): Promise<void> {
  await execute(
    db,
    `UPDATE queued_pin_updates SET status = 'synced', synced_at = datetime('now') WHERE id = $1`,
    [id],
  );
}

export async function markPinUpdateFailed(
  db: Database,
  id: number,
  error: string,
): Promise<void> {
  await execute(
    db,
    `UPDATE queued_pin_updates
     SET status = 'failed', retry_count = retry_count + 1, sync_error = $2
     WHERE id = $1`,
    [id, error],
  );
}

export async function deletePinUpdate(db: Database, id: number): Promise<void> {
  await execute(db, `DELETE FROM queued_pin_updates WHERE id = $1`, [id]);
}
