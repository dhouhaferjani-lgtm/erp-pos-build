/**
 * Phase 3 — AVOIR + voucher ticket printing after a refund settles.
 *
 * Fired from HomePage's settled seam AFTER the refund session teardown: the
 * refund is already settled server-side, so this orchestration must NEVER
 * block or unwind the settlement. It therefore never throws — print failures
 * come back as a typed `failed` outcome the caller surfaces as a toast
 * (mirroring the sale path's print-failure UX in CheckoutSuccessModal).
 *
 * Printer selection mirrors the sale path's `canEscPosPrint` gate: thermal
 * printing only exists inside Tauri with a configured printer; anything else
 * is a silent skip (the browser POS cannot print and the refund stays valid).
 *
 * When the refund destination was store_voucher the response carries
 * `issued_voucher`; the dedicated voucher ticket prints AFTER the AVOIR so
 * the tape order is deterministic (refund proof first, then the customer's
 * voucher). A failure on either ticket reports `failed` — the voucher is
 * never attempted after a failed AVOIR (no half-ordered tape).
 *
 * autoPrint divergence — do NOT "fix" this into the autoPrint gate:
 * Unlike the sale path (CheckoutSuccessModal gates on usePrinterStore.autoPrint
 * with a manual-print button fallback), the refund path prints unconditionally
 * when Tauri+printer exist. The AVOIR is the customer's legal refund proof and
 * the store-voucher ticket is the customer's money; there is no refund-success
 * modal offering a manual-print fallback, so unconditional printing is correct.
 */
import {
  getPrintSettingsFromStore,
  isTauriEnvironment,
  printReceipt,
  printVoucherTicket,
} from '@/lib/printing';
import {
  buildEscPosRefundReceiptData,
  buildRefundVoucherTicketData,
  type ReceiptVisibilitySettings,
} from '@/lib/buildReceiptData';
import { usePrinterStore } from '@/stores/printerStore';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import type { ReturnSettlementResponse } from './refundSettlementService';

export interface PrintRefundArtifactsInput {
  /** Full POST /pos/receipts/{id}/return response (lines/totals come from HERE, not the live cart). */
  response: ReturnSettlementResponse;
  /** Original (sale) receipt number captured from the refund session before teardown. */
  originalReceiptNumber: string | null;
  /** Original (sale) receipt's scanned QR token, when the session still has it. */
  originalReceiptQrToken: string | null;
  /** Company receipt visibility settings (same source as the sale print path). */
  visibilitySettings?: ReceiptVisibilitySettings;
}

export type PrintRefundArtifactsOutcome =
  | { status: 'printed'; tickets: number }
  | { status: 'skipped'; reason: 'not_tauri' | 'no_printer' }
  | { status: 'failed'; error: unknown };

export async function printRefundSettlementArtifacts(
  input: PrintRefundArtifactsInput,
): Promise<PrintRefundArtifactsOutcome> {
  if (!isTauriEnvironment()) {
    return { status: 'skipped', reason: 'not_tauri' };
  }
  const { printerConfig } = usePrinterStore.getState();
  if (printerConfig === null) {
    return { status: 'skipped', reason: 'no_printer' };
  }

  // Assemble the local header context (same pattern as getOfflineReceiptForPrint).
  const auth = useAuthStore.getState();
  const company = auth.companies.find((c) => c.id === auth.companyId);
  const companyName = company?.name ?? '';
  const companyCountryCode = company?.countryCode ?? '';
  const terminalName = useTerminalStore.getState().terminal?.name ?? '';
  const operatorName = useOperatorStore.getState().operator?.name ?? '';

  try {
    // Builder runs INSIDE the try: if buildEscPosRefundReceiptData throws
    // (e.g. Big() on a malformed monetary string) the promise returns
    // {status:'failed'} instead of leaking an unhandled rejection.
    const receiptData = buildEscPosRefundReceiptData(
      input.response,
      {
        companyName,
        companyCountryCode,
        terminalName,
        operatorName,
        originalReceiptNumber: input.originalReceiptNumber,
        originalReceiptQrToken: input.originalReceiptQrToken,
      },
      input.visibilitySettings,
    );

    const printSettings = getPrintSettingsFromStore();
    await printReceipt(receiptData, printerConfig, printSettings);

    if (input.response.issued_voucher !== null) {
      const ticket = buildRefundVoucherTicketData(input.response.issued_voucher, {
        companyName,
        companyCountryCode,
        terminalName,
        operatorName,
        issuedAt: input.response.posted_at,
      });
      await printVoucherTicket(ticket, printerConfig, printSettings);
      return { status: 'printed', tickets: 2 };
    }

    return { status: 'printed', tickets: 1 };
  } catch (error) {
    console.error('[refundFlow] printRefundSettlementArtifacts failed:', error);
    return { status: 'failed', error };
  }
}
