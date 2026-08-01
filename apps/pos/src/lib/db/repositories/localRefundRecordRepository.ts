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
import { bcabs, bcadd, bcformat } from '@/lib/decimal';
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

/**
 * Wave-2 fix-wave finding 19 (⚖️ orchestrator-adopted from the wave-3
 * payout-cash-bound analysis §M1) — thrown when a LEGACY refund record for
 * the original being refunded cannot be read as a canonical decimal.
 *
 * Fails closed for the same reason finding 18 does: a legacy row that
 * cannot be summed UNDERCOUNTS what has already been refunded, which makes
 * the value bound more permissive in exactly the case where its data is
 * untrustworthy — the direction that loses money.
 */
export class LegacyRefundRecordUnreadableError extends Error {
  readonly i18nKey = 'refundFlow.legacyRefundValueExceeded';

  constructor(
    public readonly originalReceiptNumber: string,
    public readonly recordId: string,
    public readonly rawTotal: string,
  ) {
    super(
      `local_refund_records ${recordId} (original ${originalReceiptNumber}) has a non-canonical total ${JSON.stringify(rawTotal)}. Refusing the refund rather than undercounting the already-refunded value (finding 19 / §M1).`,
    );
    this.name = 'LegacyRefundRecordUnreadableError';
  }
}

/** A signed or unsigned canonical decimal, e.g. `-10.00`, `5`, `0.500`. */
const DECIMAL_PATTERN = /^-?\d+(\.\d+)?$/;

/**
 * Wave-2 fix-wave finding 19 — the LEGACY half of the device-local refund
 * bound.
 *
 * The v4 cumulative-quantity backstop (`refundIntentRepository`) sums
 * `refund_intents` rows only, so it is BLIND to refunds settled through
 * the legacy `/return` path — which stays live on non-acknowledged
 * terminals by ruled design (§9.2's dual-path store). Those refunds land
 * here, keyed by the ORIGINAL's `receipt_number`.
 *
 * **Stated limits (accepted by the ruling):** `local_refund_records` has
 * no per-line quantities and keys the original by receipt NUMBER rather
 * than the local receipt UUID, so this can only be a RECEIPT-LEVEL,
 * VALUE-BASED bound — coarser than the per-line quantity cap. It also only
 * sees legacy refunds settled on THIS device. It converts the hole from
 * "invisible" to "bounded by the original's own value"; §12's server-side
 * cap remains the sole cross-terminal authority.
 *
 * Returns the POSITIVE magnitude already refunded, formatted at `scale`.
 *
 * @throws LegacyRefundRecordUnreadableError on any non-canonical total.
 */
export async function sumLegacyRefundedValueForOriginalReceipt(
  db: Database,
  originalReceiptNumber: string,
  scale: number,
): Promise<string> {
  const rows = await queryAll<{ id: string; total: string }>(
    db,
    `SELECT id, total FROM local_refund_records WHERE original_receipt_number = $1`,
    [originalReceiptNumber],
  );

  let sum = bcformat('0', scale);
  for (const row of rows) {
    if (typeof row.total !== 'string' || !DECIMAL_PATTERN.test(row.total.trim())) {
      throw new LegacyRefundRecordUnreadableError(
        originalReceiptNumber,
        row.id,
        String(row.total),
      );
    }
    sum = bcadd(sum, bcabs(row.total.trim(), scale), scale);
  }
  return sum;
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
