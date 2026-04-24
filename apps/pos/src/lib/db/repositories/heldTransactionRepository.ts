import type Database from '@tauri-apps/plugin-sql';
import { queryAll, execute } from '@/lib/db';

export interface HeldTransactionRow {
  id: string;
  terminal_id: string;
  operator_id: string;
  label: string;
  /** JSON-encoded CartItem[]. */
  items_json: string;
  /** JSON-encoded { type, value, reason? } or null. */
  transaction_discount_json: string | null;
  subtotal: string;
  total: string;
  item_count: number;
  held_at: string;
}

export async function insertHeldTransaction(
  db: Database,
  row: HeldTransactionRow,
): Promise<void> {
  await execute(
    db,
    `INSERT INTO held_transactions (
       id, terminal_id, operator_id, label, items_json,
       transaction_discount_json, subtotal, total, item_count, held_at
     ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)`,
    [
      row.id,
      row.terminal_id,
      row.operator_id,
      row.label,
      row.items_json,
      row.transaction_discount_json,
      row.subtotal,
      row.total,
      row.item_count,
      row.held_at,
    ],
  );
}

export async function listHeldTransactions(
  db: Database,
  terminalId: string,
): Promise<HeldTransactionRow[]> {
  return queryAll<HeldTransactionRow>(
    db,
    'SELECT * FROM held_transactions WHERE terminal_id = $1 ORDER BY held_at DESC',
    [terminalId],
  );
}

export async function deleteHeldTransaction(
  db: Database,
  id: string,
): Promise<void> {
  await execute(db, 'DELETE FROM held_transactions WHERE id = $1', [id]);
}
