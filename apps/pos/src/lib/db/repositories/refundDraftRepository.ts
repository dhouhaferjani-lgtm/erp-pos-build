import type Database from '@tauri-apps/plugin-sql';
import { queryOne, execute } from '@/lib/db';
import type { CartItem } from '@/types/cart';

export interface RefundDraftRow {
  id: string;
  terminal_id: string;
  operator_id: string;
  receipt_uuid: string;
  receipt_number: string;
  /** JSON-encoded CartItem[] for the Returning section. */
  return_items_json: string;
  /** JSON-encoded CartItem[] for the Buying-new section. */
  buying_items_json: string;
  /** JSON-encoded { type, value, reason? } or null. */
  transaction_discount_json: string | null;
  /**
   * exchange_request_id: generated lazily on first positive line; preserved for the
   * lifetime of the draft (does NOT regenerate on empty-then-refill cycles).
   * Cleared only on draft discard.
   */
  exchange_request_id: string | null;
  started_at: string;
  updated_at: string;
}

export interface RefundDraftPayload {
  id: string;
  terminalId: string;
  operatorId: string;
  receiptUuid: string;
  receiptNumber: string;
  returnItems: CartItem[];
  buyingItems: CartItem[];
  transactionDiscount: { type: 'percentage' | 'fixed'; value: string; reason?: string } | undefined;
  exchangeRequestId: string | null;
}

export async function upsertRefundDraft(
  db: Database,
  payload: RefundDraftPayload,
): Promise<void> {
  await execute(
    db,
    `INSERT INTO refund_drafts (
       id, terminal_id, operator_id, receipt_uuid, receipt_number,
       return_items_json, buying_items_json, transaction_discount_json,
       exchange_request_id, started_at, updated_at
     ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, datetime('now'), datetime('now'))
     ON CONFLICT(id) DO UPDATE SET
       return_items_json = excluded.return_items_json,
       buying_items_json = excluded.buying_items_json,
       transaction_discount_json = excluded.transaction_discount_json,
       exchange_request_id = excluded.exchange_request_id,
       updated_at = datetime('now')`,
    [
      payload.id,
      payload.terminalId,
      payload.operatorId,
      payload.receiptUuid,
      payload.receiptNumber,
      JSON.stringify(payload.returnItems),
      JSON.stringify(payload.buyingItems),
      payload.transactionDiscount !== undefined ? JSON.stringify(payload.transactionDiscount) : null,
      payload.exchangeRequestId,
    ],
  );
}

export async function getRefundDraftByTerminal(
  db: Database,
  terminalId: string,
): Promise<RefundDraftRow | null> {
  return queryOne<RefundDraftRow>(
    db,
    'SELECT * FROM refund_drafts WHERE terminal_id = $1 ORDER BY started_at DESC LIMIT 1',
    [terminalId],
  );
}

export async function deleteRefundDraft(
  db: Database,
  id: string,
): Promise<void> {
  await execute(db, 'DELETE FROM refund_drafts WHERE id = $1', [id]);
}

export async function deleteRefundDraftsByTerminal(
  db: Database,
  terminalId: string,
): Promise<void> {
  await execute(db, 'DELETE FROM refund_drafts WHERE terminal_id = $1', [terminalId]);
}
