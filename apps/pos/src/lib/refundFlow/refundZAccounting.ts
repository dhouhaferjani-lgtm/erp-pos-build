/**
 * Record-at-settle refund accounting for the device Z (fiscal audit B2).
 *
 * The device performs refunds via POST /pos/receipts/{id}/return. The
 * settlement response — the server return receipt — is in hand at settle
 * time; this module mirrors it into `local_refund_records` so the device
 * Z-report stops signing hardcoded refund zeros.
 *
 * Boundary / trade-off (the PR carries this documented):
 *   - Refunds processed at OTHER terminals do not affect this device's
 *     drawer or its Z — the server Z/report side owns global reconciliation.
 *   - Record-at-settle means a device crash between the server settle and
 *     this local write undercounts the device Z. The server remains the
 *     source of truth; reconciliation is the server's job. The caller
 *     surfaces a translated warning when the local write fails so the
 *     operator knows the Z needs server reconciliation — the refund itself
 *     is NEVER un-settled.
 *
 * NEVER throws — returns false when the record could not be written.
 */
import { getDatabase } from '@/lib/db';
import { insertLocalRefundRecord } from '@/lib/db/repositories/localRefundRecordRepository';
import {
  toServerRefundDestination,
  type PickerRefundDestination,
  type ReturnSettlementResponse,
} from '@/lib/refundFlow/refundSettlementService';
import { useTerminalStore } from '@/stores/terminalStore';

export interface RecordRefundSettlementInput {
  companyId: string;
  terminalId: string;
  destination: PickerRefundDestination;
  originalReceiptNumber: string;
  response: ReturnSettlementResponse;
}

/** Positive magnitude of a signed decimal string ('-23.80' → '23.80'). */
function absAmount(value: string): string {
  return value.startsWith('-') ? value.slice(1) : value;
}

/**
 * Persist the settled refund for the device Z aggregation. Idempotent on the
 * server return receipt id, so a submit retry replaying the same settlement
 * cannot double-count.
 *
 * Only a CASH destination moves physical cash out of this drawer:
 * store_voucher issues credit, original_payment is settled by server-side
 * Treasury proration — neither touches the till.
 */
export async function recordRefundSettlementForZ(
  input: RecordRefundSettlementInput,
): Promise<boolean> {
  try {
    const shift = useTerminalStore.getState().shift;
    if (shift === null) {
      console.error(
        '[refundZAccounting] no open shift at settle time — refund not attributable to a local Z window',
        { returnReceiptId: input.response.id },
      );
      return false;
    }

    const destination = toServerRefundDestination(input.destination);
    const db = await getDatabase(input.companyId);
    await insertLocalRefundRecord(db, {
      id: input.response.id,
      receipt_number: input.response.receipt_number,
      original_receipt_number: input.originalReceiptNumber,
      shift_id: shift.id,
      terminal_id: input.terminalId,
      destination,
      total: input.response.total,
      cash_impact: destination === 'cash' ? absAmount(input.response.total) : '0',
      currency: input.response.currency,
      settled_at: input.response.posted_at,
    });
    return true;
  } catch (error) {
    console.error('[refundZAccounting] failed to record settled refund for Z accounting', {
      returnReceiptId: input.response.id,
      error,
    });
    return false;
  }
}
