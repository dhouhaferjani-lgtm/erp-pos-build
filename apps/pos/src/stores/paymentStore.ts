import { create } from 'zustand';
import i18n from '@/lib/i18n';
import { fetchPaymentMethods, fetchPaymentRepositories } from '@/api/paymentApi';
import { createReceipt, processReceiptPayments } from '@/api/receiptApi';
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

interface PaymentActions {
  fetchPaymentConfig: () => Promise<void>;
  processCashCheckout: (
    terminalId: string,
    cartItems: CartItem[],
    tenderedAmount: number,
    transactionDiscount?: { amount: string; reason?: string },
  ) => Promise<void>;
  processCardCheckout: (
    terminalId: string,
    cartItems: CartItem[],
    cardData?: { lastFour?: string; reference?: string },
    transactionDiscount?: { amount: string; reason?: string },
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
    transactionDiscount?: { amount: string; reason?: string },
  ) => {
    const { paymentMethods, paymentRepositories, pendingReceiptId } = get();

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
      let receipt: CreateReceiptResponse;

      // Reuse pending receipt if step 1 succeeded but step 2 failed previously
      if (pendingReceiptId && get().lastReceipt) {
        receipt = get().lastReceipt!;
      } else {
        // Step 1: Create receipt
        const receiptData = {
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
                transaction_discount_amount: transactionDiscount.amount,
                transaction_discount_reason: transactionDiscount.reason,
              }
            : {}),
        };

        console.log('[POS] Creating receipt:', JSON.stringify(receiptData, null, 2));
        receipt = await createReceipt(receiptData);
        // Cache the receipt so we can retry payment without creating a duplicate
        set({ lastReceipt: receipt, pendingReceiptId: receipt.id });
      }

      // Step 2: Process payment
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
    transactionDiscount?: { amount: string; reason?: string },
  ) => {
    const { paymentMethods, paymentRepositories, pendingReceiptId } = get();

    // Find card payment method: non-physical, requires third party, or code === 'card'
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

    // Find a virtual or bank repository for card payments
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
      let receipt: CreateReceiptResponse;

      if (pendingReceiptId && get().lastReceipt) {
        receipt = get().lastReceipt!;
      } else {
        const receiptData = {
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
                transaction_discount_amount: transactionDiscount.amount,
                transaction_discount_reason: transactionDiscount.reason,
              }
            : {}),
        };

        console.log('[POS] Creating receipt:', JSON.stringify(receiptData, null, 2));
        receipt = await createReceipt(receiptData);
        set({ lastReceipt: receipt, pendingReceiptId: receipt.id });
      }

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

  clearLastReceipt: () => {
    set({ lastReceipt: null, lastPaymentResponse: null, pendingReceiptId: null, changeDue: 0 });
  },

  reset: () => {
    set(initialState);
  },
}));
