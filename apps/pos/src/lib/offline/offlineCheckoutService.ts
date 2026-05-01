import type Database from '@tauri-apps/plugin-sql';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { createReceipt, processReceiptPayments } from '@/api/receiptApi';
import { createOfflineReceipt } from '@/lib/offline/receiptService';
import { getCurrencyDecimals } from '@/lib/currency';
import type { CartItem } from '@/types/cart';
import type {
  CreateReceiptResponse,
  ProcessReceiptPaymentsResponse,
} from '@/types/receipt';

const ONLINE_CHECKOUT_TIMEOUT_MS = 5_000;

export interface CheckoutInput {
  terminalId: string;
  operatorId: string;
  operatorName: string;
  cartItems: CartItem[];
  currency: string;
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
  onlineReceipt: CreateReceiptResponse | null;
  onlinePayment: ProcessReceiptPaymentsResponse | null;
}

async function withTimeout<T>(promise: Promise<T>, ms: number): Promise<T> {
  let timeoutId: ReturnType<typeof setTimeout>;
  const timeout = new Promise<never>((_, reject) => {
    timeoutId = setTimeout(
      () => reject(new Error('Checkout timeout')),
      ms,
    );
  });
  try {
    return await Promise.race([promise, timeout]);
  } finally {
    clearTimeout(timeoutId!);
  }
}

async function onlineCheckout(
  input: CheckoutInput,
): Promise<CheckoutResult> {
  const receipt = await createReceipt(
    input.receiptData as unknown as Parameters<typeof createReceipt>[0],
  );

  // B3-followup audit (Finding 1, 2026-05-01): when split-payment data is
  // supplied via `input.payments` (e.g. from AdvancedPaymentsModal +
  // VoucherTender flow), forward each row to the online endpoint with its
  // instrument fields populated. Without this, a voucher-bearing online sale
  // produces a v3 receipt whose `pos_receipt_payments.instrument_serial` is
  // null — defeating the v3 hash binding (the original B3 production bug,
  // re-surfacing here at the entry point).
  const onlinePayments = input.payments && input.payments.length > 0
    ? input.payments.map((p) => ({
      payment_method_id: p.paymentMethodId ?? input.paymentMethodId,
      amount: parseFloat(p.amount),
      repository_id: p.repositoryId ?? input.paymentRepositoryId,
      ...(p.cardLastFour ? { card_last_four: p.cardLastFour } : {}),
      ...(p.transactionReference ? { transaction_reference: p.transactionReference } : {}),
      ...(p.instrumentType ? { instrument_type: p.instrumentType } : {}),
      ...(p.instrumentSerial ? { instrument_serial: p.instrumentSerial } : {}),
    }))
    : [{
      payment_method_id: input.paymentMethodId,
      amount: parseFloat(receipt.total),
      repository_id: input.paymentRepositoryId,
    }];

  const paymentResponse = await processReceiptPayments(receipt.id, {
    payments: onlinePayments,
  });

  const totalAmount = parseFloat(receipt.total);
  const changeDue = Math.max(0, input.tenderedAmount - totalAmount);

  return {
    isOffline: false,
    receiptId: receipt.id,
    receiptNumber: receipt.receipt_number,
    total: receipt.total,
    subtotal: receipt.subtotal,
    taxAmount: receipt.tax_amount,
    discountAmount: receipt.discount_amount,
    changeDue,
    currency: input.currency,
    onlineReceipt: receipt,
    onlinePayment: paymentResponse,
  };
}

async function offlineCheckout(
  db: Database,
  input: CheckoutInput,
): Promise<CheckoutResult> {
  const cartTotal = input.cartItems
    .reduce((sum, item) => sum + parseFloat(item.line_total), 0)
    .toFixed(getCurrencyDecimals(input.currency));
  const defaultPayments = [{ methodCode: 'CASH', amount: cartTotal }];

  const result = await createOfflineReceipt(db, {
    terminalId: input.terminalId,
    operatorId: input.operatorId,
    operatorName: input.operatorName,
    cartItems: input.cartItems,
    currency: input.currency,
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

  return {
    isOffline: true,
    receiptId: `offline-${result.receiptNumber}`,
    receiptNumber: result.receiptNumber,
    total: result.total,
    subtotal: result.subtotal,
    taxAmount: result.taxAmount,
    discountAmount: result.discountAmount,
    changeDue: result.changeDue,
    currency: input.currency,
    fiscalHash: result.fiscalHash,
    onlineReceipt: null,
    onlinePayment: null,
  };
}

export async function executeCheckout(
  db: Database,
  input: CheckoutInput,
): Promise<CheckoutResult> {
  const { isOnline } = useConnectivityStore.getState();

  if (isOnline) {
    try {
      return await withTimeout(
        onlineCheckout(input),
        ONLINE_CHECKOUT_TIMEOUT_MS,
      );
    } catch (error) {
      console.warn(
        '[POS] Online checkout failed, falling back to offline:',
        error,
      );
      return await offlineCheckout(db, input);
    }
  }

  return await offlineCheckout(db, input);
}
