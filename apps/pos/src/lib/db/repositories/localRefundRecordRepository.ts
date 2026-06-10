/**
 * Local refund records (Phase 4 — fiscal audit B2, record-at-settle).
 *
 * One row per refund SETTLED AT THIS TERMINAL: when the refund checkout
 * settles via POST /pos/receipts/{id}/return, the server return receipt is
 * mirrored here so the device Z-report aggregation can fold real refund
 * totals into the signed Z_REPORT fiscal event instead of hardcoded zeros.
 *
 * Boundary / trade-off (documented for the audit trail):
 *   - Refunds processed at OTHER terminals do not affect this device's drawer
 *     or its Z. The server Z/report side owns global reconciliation.
 *   - Record-at-settle means a device crash between the server settle and the
 *     local write undercounts this device's Z. The server remains the source
 *     of truth; reconciliation is the server's job.
 */
import type Database from '@tauri-apps/plugin-sql';
import { queryAll, execute } from '@/lib/db';
import type { ServerRefundDestination } from '@/lib/refundFlow/refundSettlementService';

export interface LocalRefundRecord {
  /** SERVER return receipt id (pos_receipts.id) — PK, makes inserts idempotent. */
  id: string;
  /** Server return receipt number (the AVOIR number). */
  receipt_number: string;
  /** Receipt number of the original sale that was refunded. */
  original_receipt_number: string;
  /** Local shift the refund settled under (terminalStore shift.id). */
  shift_id: string;
  terminal_id: string;
  destination: ServerRefundDestination;
  /** Signed total exactly as the server returned it (negative for returns). */
  total: string;
  /**
   * POSITIVE amount that physically left this drawer: abs(total) for
   * destination='cash', '0' otherwise (store_voucher moves no cash;
   * original_payment is server-side Treasury proration — no physical cash
   * moves at this terminal).
   */
  cash_impact: string;
  currency: string;
  /** Server posted_at of the return receipt. */
  settled_at: string;
}

/**
 * Insert a settle-time refund record. Idempotent on the server return receipt
 * id (targeted ON CONFLICT(id) DO NOTHING — unlike INSERT OR IGNORE this does
 * NOT swallow CHECK/NOT NULL violations) — a submit retry replaying the same
 * settlement can never double-count a refund in the Z.
 */
export async function insertLocalRefundRecord(
  db: Database,
  record: LocalRefundRecord,
): Promise<void> {
  await execute(
    db,
    `INSERT INTO local_refund_records (
       id, receipt_number, original_receipt_number, shift_id, terminal_id,
       destination, total, cash_impact, currency, settled_at
     ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)
     ON CONFLICT(id) DO NOTHING`,
    [
      record.id,
      record.receipt_number,
      record.original_receipt_number,
      record.shift_id,
      record.terminal_id,
      record.destination,
      record.total,
      record.cash_impact,
      record.currency,
      record.settled_at,
    ],
  );
}

/** All refunds settled at this terminal during the given shift. */
export async function getRefundRecordsForShift(
  db: Database,
  shiftId: string,
): Promise<LocalRefundRecord[]> {
  return queryAll<LocalRefundRecord>(
    db,
    `SELECT id, receipt_number, original_receipt_number, shift_id, terminal_id,
            destination, total, cash_impact, currency, settled_at
     FROM local_refund_records
     WHERE shift_id = $1
     ORDER BY settled_at ASC, id ASC`,
    [shiftId],
  );
}
