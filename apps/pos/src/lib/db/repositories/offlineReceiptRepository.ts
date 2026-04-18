import type Database from '@tauri-apps/plugin-sql';
import { queryAll, queryOne, execute } from '@/lib/db';

export type OfflineReceiptStatus = 'pending' | 'syncing' | 'synced' | 'failed';

export interface OfflineReceipt {
  id: string;
  idempotency_key: string;
  receipt_number: string;
  terminal_id: string;
  terminal_code: string;
  operator_id: string;
  operator_name: string;
  lines: string; // JSON
  subtotal: string;
  tax_amount: string;
  discount_amount: string;
  total: string;
  currency: string;
  fiscal_hash: string;
  previous_hash: string;
  hash_sequence: number;
  transaction_discount_amount: string | null;
  transaction_discount_reason: string | null;
  tendered_amount: string | null;
  change_due: string | null;
  payment_method_id: string;
  payment_repository_id: string;
  status: OfflineReceiptStatus;
  retry_count: number;
  /** JSON-encoded array of {payment_method_id, repository_id, amount, card_last_four?, transaction_reference?} */
  payments_json: string;
  consumption_mode: string | null;
  table_id: string | null;
  /** Set after first successful sync; null until then. */
  server_receipt_id: string | null;
  created_at: string;
  synced_at: string | null;
  sync_error: string | null;
}

export async function insertOfflineReceipt(
  db: Database,
  receipt: Omit<OfflineReceipt, 'created_at' | 'synced_at' | 'sync_error' | 'retry_count' | 'server_receipt_id'>,
): Promise<void> {
  await execute(
    db,
    `INSERT INTO offline_receipts (
      id, idempotency_key, receipt_number, terminal_id, terminal_code,
      operator_id, operator_name, lines, subtotal, tax_amount, discount_amount,
      total, currency, fiscal_hash, previous_hash, hash_sequence,
      transaction_discount_amount, transaction_discount_reason,
      tendered_amount, change_due, payment_method_id, payment_repository_id, status,
      payments_json, consumption_mode, table_id
    ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, $17, $18, $19, $20, $21, $22, $23, $24, $25, $26)`,
    [
      receipt.id, receipt.idempotency_key, receipt.receipt_number,
      receipt.terminal_id, receipt.terminal_code,
      receipt.operator_id, receipt.operator_name,
      receipt.lines, receipt.subtotal, receipt.tax_amount, receipt.discount_amount,
      receipt.total, receipt.currency, receipt.fiscal_hash, receipt.previous_hash,
      receipt.hash_sequence, receipt.transaction_discount_amount,
      receipt.transaction_discount_reason, receipt.tendered_amount,
      receipt.change_due, receipt.payment_method_id, receipt.payment_repository_id,
      receipt.status,
      receipt.payments_json, receipt.consumption_mode, receipt.table_id,
    ]
  );
}

export async function getPendingReceipts(db: Database): Promise<OfflineReceipt[]> {
  return queryAll<OfflineReceipt>(
    db,
    "SELECT * FROM offline_receipts WHERE status = 'pending' ORDER BY hash_sequence ASC"
  );
}

export async function getPendingReceiptCount(db: Database): Promise<number> {
  const result = await queryOne<{ count: number }>(
    db,
    "SELECT COUNT(*) as count FROM offline_receipts WHERE status IN ('pending', 'failed')"
  );
  return result?.count ?? 0;
}

export async function updateReceiptStatus(
  db: Database,
  id: string,
  status: OfflineReceiptStatus,
  syncError?: string,
): Promise<void> {
  if (status === 'synced') {
    await execute(
      db,
      "UPDATE offline_receipts SET status = $1, synced_at = datetime('now'), sync_error = NULL WHERE id = $2",
      [status, id]
    );
  } else {
    await execute(
      db,
      'UPDATE offline_receipts SET status = $1, sync_error = $2 WHERE id = $3',
      [status, syncError ?? null, id]
    );
  }
}

export const MAX_SYNC_RETRIES = 5;

export async function getPendingReceiptsForSync(db: Database): Promise<OfflineReceipt[]> {
  return queryAll<OfflineReceipt>(
    db,
    `SELECT * FROM offline_receipts
     WHERE status IN ('pending', 'failed') AND retry_count < $1
     ORDER BY hash_sequence ASC`,
    [MAX_SYNC_RETRIES]
  );
}

export async function incrementRetryCount(db: Database, id: string): Promise<void> {
  await execute(
    db,
    'UPDATE offline_receipts SET retry_count = retry_count + 1 WHERE id = $1',
    [id]
  );
}

export async function getStuckReceipts(db: Database): Promise<OfflineReceipt[]> {
  return queryAll<OfflineReceipt>(
    db,
    `SELECT * FROM offline_receipts
     WHERE status = 'failed' AND retry_count >= $1
     ORDER BY hash_sequence ASC`,
    [MAX_SYNC_RETRIES]
  );
}

export async function getReceiptByIdempotencyKey(
  db: Database,
  key: string,
): Promise<OfflineReceipt | null> {
  return queryOne<OfflineReceipt>(
    db,
    'SELECT * FROM offline_receipts WHERE idempotency_key = $1',
    [key]
  );
}

export async function cleanupSyncedReceipts(db: Database): Promise<void> {
  await execute(
    db,
    "DELETE FROM offline_receipts WHERE status = 'synced' AND synced_at < datetime('now', '-30 days')"
  );
}

export async function cleanupStuckReceipts(db: Database): Promise<void> {
  await execute(
    db,
    `DELETE FROM offline_receipts
     WHERE status = 'failed' AND retry_count >= $1
     AND created_at < datetime('now', '-90 days')`,
    [MAX_SYNC_RETRIES]
  );
}

export async function setServerReceiptId(
  db: Database,
  idempotencyKey: string,
  serverReceiptId: string,
): Promise<void> {
  await execute(
    db,
    'UPDATE offline_receipts SET server_receipt_id = $1 WHERE idempotency_key = $2',
    [serverReceiptId, idempotencyKey]
  );
}

export async function getOfflineReceiptById(
  db: Database,
  id: string,
): Promise<OfflineReceipt | null> {
  return queryOne<OfflineReceipt>(
    db,
    'SELECT * FROM offline_receipts WHERE id = $1',
    [id]
  );
}
