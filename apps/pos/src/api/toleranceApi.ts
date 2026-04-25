import { apiGet } from '@/lib/api';

/**
 * Owned by the payment-tolerance v2 session per coordination contract v1.1
 * (docs/superpowers/coordination/2026-04-24-payment-tolerance-shift-interface.md).
 *
 * The endpoint `GET /pos/shifts/{shiftId}/tolerance-receipts` is added by that
 * session. Until then, the EOD modal renders the row only when writeoffCount > 0,
 * which never happens because Task 1 emits zero-shape tolerance_summary.
 */
export interface ToleranceReceiptRow {
  receiptId: string;
  receiptNumber: string;
  cashierName: string;
  occurredAt: string;
  writeoffAmount: string;
  currencyCode: string;
}

export async function fetchToleranceReceiptsForShift(
  shiftId: string,
): Promise<ToleranceReceiptRow[]> {
  return apiGet<ToleranceReceiptRow[]>(`/pos/shifts/${shiftId}/tolerance-receipts`);
}
