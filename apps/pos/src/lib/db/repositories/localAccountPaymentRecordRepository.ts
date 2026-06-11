/**
 * Local account-payment records (H2 — 2026-06-11 NF525/DSFinV-K cash
 * reconciliation, record-at-author).
 *
 * One row per customer ACCOUNT_PAYMENT authored at this terminal: when the
 * payment's fiscal event is appended, it is mirrored here so the device
 * Z-report aggregation can fold CASH account collections into the signed
 * expected_cash. Money received into the drawer against a customer credit
 * account is drawer cash — NOT a sales payment-method total.
 *
 * Boundary / trade-off (documented for the audit trail, mirrors
 * local_refund_records): account payments authored at OTHER terminals do not
 * affect this device's drawer or its Z. A device crash between the fiscal-event
 * append and this mirror write undercounts this device's Z. The server remains
 * the source of truth; reconciliation is the server's job.
 */
import type Database from '@tauri-apps/plugin-sql';
import { queryAll, execute } from '@/lib/db';

export interface LocalAccountPaymentRecord {
  /** account_payment_uuid (source_event_id) — PK, makes inserts idempotent. */
  id: string;
  /** Local shift the payment was authored under (terminalStore shift.id). */
  shift_id: string;
  terminal_id: string;
  /** Tender method code (e.g. 'CASH', 'CARD'). */
  method_code: string;
  /** The payment amount exactly as authored. */
  amount: string;
  /**
   * POSITIVE cash that physically entered this drawer: amount when
   * method_code='CASH', '0' otherwise (card/voucher account payments move no
   * till cash at this terminal).
   */
  cash_impact: string;
  currency: string;
}

/**
 * Insert an author-time account-payment record. Idempotent on the account
 * payment uuid (targeted ON CONFLICT(id) DO NOTHING — unlike INSERT OR IGNORE
 * this does NOT swallow CHECK/NOT NULL violations) so a submit retry replaying
 * the same payment can never double-count cash in the Z.
 */
export async function insertLocalAccountPaymentRecord(
  db: Database,
  record: LocalAccountPaymentRecord,
): Promise<void> {
  await execute(
    db,
    `INSERT INTO local_account_payment_records (
       id, shift_id, terminal_id, method_code, amount, cash_impact, currency
     ) VALUES ($1, $2, $3, $4, $5, $6, $7)
     ON CONFLICT(id) DO NOTHING`,
    [
      record.id,
      record.shift_id,
      record.terminal_id,
      record.method_code,
      record.amount,
      record.cash_impact,
      record.currency,
    ],
  );
}

/** All account payments authored at this terminal during the given shift. */
export async function getAccountPaymentRecordsForShift(
  db: Database,
  shiftId: string,
): Promise<LocalAccountPaymentRecord[]> {
  return queryAll<LocalAccountPaymentRecord>(
    db,
    `SELECT id, shift_id, terminal_id, method_code, amount, cash_impact, currency
     FROM local_account_payment_records
     WHERE shift_id = $1
     ORDER BY created_at ASC, id ASC`,
    [shiftId],
  );
}
