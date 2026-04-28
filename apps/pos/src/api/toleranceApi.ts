import { apiGet, ApiRequestError } from '@/lib/api';

/**
 * Owned by the payment-tolerance v2 session per coordination contract v1.1
 * (docs/superpowers/coordination/2026-04-24-payment-tolerance-shift-interface.md).
 *
 * The endpoint `GET /pos/shifts/{shiftId}/tolerance-receipts` is added by that
 * session. Until then, the EOD modal renders the row only when writeoffCount > 0,
 * which never happens because Task 1 emits zero-shape tolerance_summary.
 *
 * Feature-flag guard: once a feature-flag system is wired into apps/pos, replace
 * the 404-interception below with a flag check:
 *
 *   // TODO(feature-flags): gate this call on a `tolerance_v2` feature flag once
 *   // the flag infrastructure lands in apps/pos. If the flag is OFF, reject early
 *   // with "feature not enabled" before hitting the network.
 *   // Tracking: see docs/superpowers/coordination/2026-04-24-payment-tolerance-shift-interface.md
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
  try {
    return await apiGet<ToleranceReceiptRow[]>(`/pos/shifts/${shiftId}/tolerance-receipts`);
  } catch (err) {
    if (err instanceof ApiRequestError && err.status === 404) {
      // The v2 tolerance-receipts endpoint is not yet deployed on the connected server.
      // This is expected while the backend ships. Contact ops if this persists after
      // the payment-tolerance v2 backend has been deployed.
      throw new ApiRequestError(
        404,
        'Tolerance v2 endpoint not deployed yet — contact ops to verify backend version',
        'TOLERANCE_V2_ENDPOINT_MISSING',
      );
    }
    throw err;
  }
}
