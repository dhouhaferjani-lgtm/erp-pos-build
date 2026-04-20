import type Database from '@tauri-apps/plugin-sql';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { createReceipt, processReceiptPayments } from '@/api/receiptApi';
import { createOfflineReceipt } from '@/lib/offline/receiptService';
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
    input.receiptData as Parameters<typeof createReceipt>[0],
  );
  const paymentResponse = await processReceiptPayments(receipt.id, {
    payments: [
      {
        payment_method_id: input.paymentMethodId,
        amount: parseFloat(receipt.total),
        repository_id: input.paymentRepositoryId,
      },
    ],
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
