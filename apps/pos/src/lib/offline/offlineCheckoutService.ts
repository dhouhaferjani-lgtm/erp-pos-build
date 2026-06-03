import type Database from '@tauri-apps/plugin-sql';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { useSyncStore } from '@/stores/syncStore';
import { createOfflineReceipt } from '@/lib/offline/receiptService';
import { getCurrencyDecimals } from '@/lib/currency';
import { bcsum } from '@/lib/decimal';
import type { CartItem } from '@/types/cart';
import type { SaleReceiptSellerInput } from '@/lib/fiscal/payloads/SaleReceiptPayload';

export interface CheckoutInput {
  tenantId: string;
  companyId: string;
  terminalId: string;
  operatorId: string;
  operatorName: string;
  shiftId: string;
  cartItems: CartItem[];
  currency: string;
  seller: SaleReceiptSellerInput;
  paymentMethodId: string;
  paymentRepositoryId: string;
  tenderedAmount: number;
  receiptData: Record<string, unknown>;
  transactionDiscount?: {
    type: 'percentage' | 'fixed';
    value: string;
    reason?: string;
  };
  /** Payments breakdown for fiscal hash + sync payload. Defaults to single CASH entry if omitted. */
  payments?: Array<{
    methodCode: string;
    amount: string;
    paymentMethodId?: string;
    repositoryId?: string;
    cardLastFour?: string;
    transactionReference?: string;
    /**
     * B3-followup audit (Finding 1, 2026-05-01): voucher / instrument
     * discriminator. Bound into the v3 fiscal hash and persisted on
     * `pos_receipt_payments.instrument_type`. Omit for cash / card.
     */
    instrumentType?: 'store_voucher' | 'restaurant_voucher' | 'gift_card';
    /**
     * B3-followup audit (Finding 1, 2026-05-01): voucher serial / gift-card
     * code that tendered this row. Required when `instrumentType` is set.
     */
    instrumentSerial?: string;
  }>;
  consumptionMode?: string;
  tableId?: string;
}

export interface CheckoutResult {
  /**
   * Phase 1 Task 27 Pass 1 (spec §14.3): retained for backwards-compatible
   * callers (paymentStore reads other fields), but it now always describes
   * locally-authored receipts. The new-sale server-authoring branch is gone;
   * connectivity only gates whether an immediate sync flush follows. The
   * field name is kept to avoid a downstream-rename churn that Pass 1 is
   * scoped to avoid (the rename happens in Pass 2 / Task 27B when the
   * assembler-rework lands).
   */
  isOffline: boolean;
  receiptId: string;
  receiptNumber: string;
  total: string;
  subtotal: string;
  taxAmount: string;
  discountAmount: string;
  changeDue: number;
  currency: string;
  fiscalHash?: string;
}

/**
 * Phase 1 Task 27 Pass 1 (spec §14.3 chokepoint disposition for new-sale
 * server-authoring callers): connectivity-independent authoring.
 *
 * Pre-Pass-1 the function had two branches:
 *   - ONLINE  → the deleted server-authoring receipt methods on receiptApi
 *   - OFFLINE → `createOfflineReceipt()` (device authors)
 *
 * Post-Pass-1 there is one path. The device always authors locally via
 * `createOfflineReceipt()` (the local-first contract that paymentStore has
 * already followed since the offline-first migration). Connectivity is
 * polled exclusively to decide whether to KICK an immediate sync flush
 * vs leave the SQLite-resident receipt for the next scheduler tick.
 *
 * Pass 2B reworked `receiptService.createOfflineReceipt` into a
 * `FiscalEventEngine.append(SALE_RECEIPT)` assembler, so this wrapper now
 * keeps the local-first checkout contract while delegating fiscal authoring to
 * the single device fiscal-event engine.
 */
export async function executeCheckout(
  db: Database,
  input: CheckoutInput,
): Promise<CheckoutResult> {
  const cartTotal = bcsum(
    input.cartItems.map((item) => item.line_total),
    getCurrencyDecimals(input.currency),
  );
  const defaultPayments = [{ methodCode: 'CASH', amount: cartTotal }];

  const result = await createOfflineReceipt(db, {
    tenantId: input.tenantId,
    companyId: input.companyId,
    terminalId: input.terminalId,
    operatorId: input.operatorId,
    operatorName: input.operatorName,
    shiftId: input.shiftId,
    cartItems: input.cartItems,
    currency: input.currency,
    seller: input.seller,
    paymentMethodId: input.paymentMethodId,
    paymentRepositoryId: input.paymentRepositoryId,
    tenderedAmount: input.tenderedAmount,
    transactionDiscount: input.transactionDiscount,
    // B3-followup audit (Finding 1, 2026-05-01): forward instrument fields
    // verbatim so a voucher tender enters the v3 fiscal hash with its serial
    // bound, and `payments_json` carries the snapshot to the sync layer.
    payments: input.payments ?? defaultPayments,
    consumptionMode: input.consumptionMode,
    tableId: input.tableId,
  });

  // Pass 1: connectivity-aware sync flush. Online → kick an immediate flush
  // so the device-authored receipt reaches the server quickly. Offline →
  // skip the kick; the next scheduler tick / online-recovery flush picks
  // it up. Either way the SQLite row already carries the seal.
  const { isOnline } = useConnectivityStore.getState();
  if (isOnline) {
    // Fire-and-forget; triggerSync guards against concurrent calls.
    useSyncStore.getState().triggerSync();
  }

  return {
    // Pass 1: `isOffline` describes the SYNC posture, not the authoring path
    // (which is always local now). True when the kick was skipped because the
    // device is offline; false when an immediate flush was triggered.
    isOffline: !isOnline,
    receiptId: `local-${result.localId}`,
    receiptNumber: result.receiptNumber,
    total: result.total,
    subtotal: result.subtotal,
    taxAmount: result.taxAmount,
    discountAmount: result.discountAmount,
    changeDue: result.changeDue,
    currency: input.currency,
    fiscalHash: result.fiscalHash,
  };
}
