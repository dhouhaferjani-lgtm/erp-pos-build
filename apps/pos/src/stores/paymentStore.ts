import { create } from 'zustand';
import i18n from '@/lib/i18n';
import { fetchPaymentMethods, fetchPaymentRepositories } from '@/api/paymentApi';
import { createReceipt, processReceiptPayments } from '@/api/receiptApi';
import { useCartStore } from '@/stores/cartStore';
import { useAuthStore } from '@/stores/authStore';
import { getCurrencyDecimals } from '@/lib/currency';
import type { PaymentMethod, PaymentRepository } from '@/types/payment';
import type { CartItem } from '@/types/cart';
import type { CreateReceiptResponse, ProcessReceiptPaymentsResponse } from '@/types/receipt';

interface PaymentState {
  paymentMethods: PaymentMethod[];
  paymentRepositories: PaymentRepository[];
  isProcessing: boolean;
  lastReceipt: CreateReceiptResponse | null;
  lastPaymentResponse: ProcessReceiptPaymentsResponse | null;
  pendingReceiptId: string | null;
  changeDue: number;
  error: string | null;
}

export interface AdvancedPaymentLine {
  payment_method_id: string;
  amount: number;
  repository_id: string;
  card_last_four?: string;
  transaction_reference?: string;
}

interface PaymentActions {
  fetchPaymentConfig: () => Promise<void>;
  processCashCheckout: (
    terminalId: string,
    cartItems: CartItem[],
    tenderedAmount: number,
    transactionDiscount?: { type: 'percentage' | 'fixed'; value: string; reason?: string },
    consumptionMode?: string,
    tableId?: string | null,
  ) => Promise<void>;
  processCardCheckout: (
    terminalId: string,
    cartItems: CartItem[],
    cardData?: { lastFour?: string; reference?: string },
    transactionDiscount?: { type: 'percentage' | 'fixed'; value: string; reason?: string },
    consumptionMode?: string,
    tableId?: string | null,
  ) => Promise<void>;
  processAdvancedCheckout: (
    terminalId: string,
    cartItems: CartItem[],
    payments: AdvancedPaymentLine[],
    transactionDiscount?: { type: 'percentage' | 'fixed'; value: string; reason?: string },
    consumptionMode?: string,
    tableId?: string | null,
  ) => Promise<void>;
  reset: () => void;
  clearLastReceipt: () => void;
}

type PaymentStore = PaymentState & PaymentActions;

const initialState: PaymentState = {
  paymentMethods: [],
  paymentRepositories: [],
  isProcessing: false,
  lastReceipt: null,
  lastPaymentResponse: null,
  pendingReceiptId: null,
  changeDue: 0,
  error: null,
};

function buildReceiptData(
  terminalId: string,
  cartItems: CartItem[],
  transactionDiscount?: { type: 'percentage' | 'fixed'; value: string; reason?: string },
  consumptionMode?: string,
  tableId?: string | null,
) {
  return {
    terminal_id: terminalId,
    lines: cartItems.map((item) => ({
      ...(item.product.sellableType === 'composite_item'
        ? { composite_item_id: item.product.id }
        : { product_id: item.product.id }),
      quantity: item.quantity,
      unit_price: item.unit_price,
      ...(item.product.selectedModifiers?.length
        ? {
            modifiers: item.product.selectedModifiers.map((m) => ({
              modifier_id: m.modifier_id,
              modifier_group_id: m.modifier_group_id,
              price_adjustment: m.price_adjustment,
            })),
          }
        : {}),
      ...(item.discount_type
        ? {
            discount_type: item.discount_type,
            discount_percent: item.discount_percent,
            discount_amount: item.discount_amount,
            discount_reason: item.discount_reason,
          }
        : {}),
    })),
    ...(transactionDiscount
      ? {
          transaction_discount_amount: (() => {
            const authState = useAuthStore.getState();
            const company = authState.companies.find((c) => c.id === authState.companyId);
            const decimals = getCurrencyDecimals(company?.currency ?? 'EUR');
            return useCartStore.getState().discountAmount().toFixed(decimals);
          })(),
          transaction_discount_reason: transactionDiscount.reason,
        }
      : {}),
    ...(consumptionMode ? { consumption_mode: consumptionMode } : {}),
    ...(tableId ? { table_id: tableId } : {}),
  };
}

async function getOrCreateReceipt(
  get: () => PaymentState,
  set: (partial: Partial<PaymentState>) => void,
  terminalId: string,
  cartItems: CartItem[],
  transactionDiscount?: { type: 'percentage' | 'fixed'; value: string; reason?: string },
  consumptionMode?: string,
  tableId?: string | null,
): Promise<CreateReceiptResponse> {
  const { pendingReceiptId } = get();
  if (pendingReceiptId && get().lastReceipt) {
    return get().lastReceipt!;
  }

  const receiptData = buildReceiptData(terminalId, cartItems, transactionDiscount, consumptionMode, tableId);
  console.log('[POS] Creating receipt:', JSON.stringify(receiptData, null, 2));
  const receipt = await createReceipt(receiptData);
  set({ lastReceipt: receipt, pendingReceiptId: receipt.id });
  return receipt;
}

export const usePaymentStore = create<PaymentStore>()((set, get) => ({
  ...initialState,

  fetchPaymentConfig: async () => {
    try {
      const [methods, repositories] = await Promise.all([
        fetchPaymentMethods(),
        fetchPaymentRepositories(),
      ]);
      set({ paymentMethods: methods, paymentRepositories: repositories });
    } catch (error) {
      console.error('Failed to fetch payment config:', error);
    }
  },

  processCashCheckout: async (
    terminalId: string,
    cartItems: CartItem[],
    tenderedAmount: number,
    transactionDiscount?: { type: 'percentage' | 'fixed'; value: string; reason?: string },
    consumptionMode?: string,
    tableId?: string | null,
  ) => {
    const { paymentMethods, paymentRepositories } = get();

    // Find cash payment method (physical, no maturity = cash)
    const cashMethod = paymentMethods.find(
      (m) => m.is_physical && !m.has_maturity && m.is_active,
    );
    if (!cashMethod) {
      const msg = i18n.t('errors.noCashMethod', { ns: 'pos' });
      set({ error: msg });
      throw new Error(msg);
    }

    // Find cash register repository
    const cashRegister = paymentRepositories.find(
      (r) => r.type === 'cash_register' && r.is_active,
    );
    if (!cashRegister) {
      const msg = i18n.t('errors.noCashRegister', { ns: 'pos' });
      set({ error: msg });
      throw new Error(msg);
    }

    set({ isProcessing: true, error: null });

    try {
      const receipt = await getOrCreateReceipt(get, set, terminalId, cartItems, transactionDiscount, consumptionMode, tableId);

      const totalAmount = parseFloat(receipt.total);
      const paymentResponse = await processReceiptPayments(receipt.id, {
        payments: [
          {
            payment_method_id: cashMethod.id,
            amount: totalAmount,
            repository_id: cashRegister.id,
          },
        ],
      });

      const changeDue = Math.max(0, tenderedAmount - totalAmount);

      set({
        lastReceipt: receipt,
        lastPaymentResponse: paymentResponse,
        pendingReceiptId: null,
        changeDue,
        isProcessing: false,
      });
    } catch (error) {
      set({
        isProcessing: false,
        error: error instanceof Error ? error.message : i18n.t('errors.checkoutFailed', { ns: 'pos' }),
      });
      throw error;
    }
  },

  processCardCheckout: async (
    terminalId: string,
    cartItems: CartItem[],
    cardData?: { lastFour?: string; reference?: string },
    transactionDiscount?: { type: 'percentage' | 'fixed'; value: string; reason?: string },
    consumptionMode?: string,
    tableId?: string | null,
  ) => {
    const { paymentMethods, paymentRepositories } = get();

    const cardMethod = paymentMethods.find(
      (m) =>
        (m.code === 'card' ||
          (!m.is_physical && m.requires_third_party && m.is_active)) &&
        m.is_active,
    );
    if (!cardMethod) {
      const msg = i18n.t('errors.checkoutFailed', { ns: 'pos' });
      set({ error: msg });
      throw new Error(msg);
    }

    const cardRepo = paymentRepositories.find(
      (r) => (r.type === 'virtual' || r.type === 'bank_account') && r.is_active,
    );
    if (!cardRepo) {
      const msg = i18n.t('errors.checkoutFailed', { ns: 'pos' });
      set({ error: msg });
      throw new Error(msg);
    }

    set({ isProcessing: true, error: null });

    try {
      const receipt = await getOrCreateReceipt(get, set, terminalId, cartItems, transactionDiscount, consumptionMode, tableId);

      const totalAmount = parseFloat(receipt.total);
      const paymentResponse = await processReceiptPayments(receipt.id, {
        payments: [
          {
            payment_method_id: cardMethod.id,
            amount: totalAmount,
            repository_id: cardRepo.id,
            ...(cardData?.lastFour ? { card_last_four: cardData.lastFour } : {}),
            ...(cardData?.reference ? { transaction_reference: cardData.reference } : {}),
          },
        ],
      });

      set({
        lastReceipt: receipt,
        lastPaymentResponse: paymentResponse,
        pendingReceiptId: null,
        changeDue: 0,
        isProcessing: false,
      });
    } catch (error) {
      set({
        isProcessing: false,
        error: error instanceof Error ? error.message : i18n.t('errors.checkoutFailed', { ns: 'pos' }),
      });
      throw error;
    }
  },

  processAdvancedCheckout: async (
    terminalId: string,
    cartItems: CartItem[],
    payments: AdvancedPaymentLine[],
    transactionDiscount?: { type: 'percentage' | 'fixed'; value: string; reason?: string },
    consumptionMode?: string,
    tableId?: string | null,
  ) => {
    set({ isProcessing: true, error: null });

    try {
      const receipt = await getOrCreateReceipt(get, set, terminalId, cartItems, transactionDiscount, consumptionMode, tableId);

      const paymentResponse = await processReceiptPayments(receipt.id, {
        payments: payments.map((p) => ({
          payment_method_id: p.payment_method_id,
          amount: p.amount,
          repository_id: p.repository_id,
          ...(p.card_last_four ? { card_last_four: p.card_last_four } : {}),
          ...(p.transaction_reference ? { transaction_reference: p.transaction_reference } : {}),
        })),
      });

      const changeDue = parseFloat(paymentResponse.change_due);

      set({
        lastReceipt: receipt,
        lastPaymentResponse: paymentResponse,
        pendingReceiptId: null,
        changeDue,
        isProcessing: false,
      });
    } catch (error) {
      set({
        isProcessing: false,
        error: error instanceof Error ? error.message : i18n.t('errors.checkoutFailed', { ns: 'pos' }),
      });
      throw error;
    }
  },

  clearLastReceipt: () => {
    set({ lastReceipt: null, lastPaymentResponse: null, pendingReceiptId: null, changeDue: 0 });
  },

  reset: () => {
    set(initialState);
  },
}));
