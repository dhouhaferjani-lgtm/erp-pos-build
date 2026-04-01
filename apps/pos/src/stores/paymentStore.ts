import { create } from 'zustand';
import i18n from '@/lib/i18n';
import { fetchPaymentMethods, fetchPaymentRepositories } from '@/api/paymentApi';
import { useCartStore } from '@/stores/cartStore';
import { useAuthStore } from '@/stores/authStore';
import { getCurrencyDecimals } from '@/lib/currency';
import { getDatabase } from '@/lib/db';
import { getAllPaymentMethods, getAllPaymentRepositories } from '@/lib/db/repositories/paymentRepository';
import { executeCheckout, type CheckoutResult } from '@/lib/offline/offlineCheckoutService';
import type { PaymentMethod, PaymentRepository } from '@/types/payment';
import type { CartItem } from '@/types/cart';
import type { CreateReceiptResponse, ProcessReceiptPaymentsResponse } from '@/types/receipt';

interface PaymentState {
  paymentMethods: PaymentMethod[];
  paymentRepositories: PaymentRepository[];
  isProcessing: boolean;
  isOfflineReceipt: boolean;
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
  isOfflineReceipt: false,
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

function resultToReceiptResponse(result: CheckoutResult): CreateReceiptResponse {
  if (result.onlineReceipt) {
    return result.onlineReceipt;
  }
  return {
    id: result.receiptId,
    receipt_number: result.receiptNumber,
    total: result.total,
    subtotal: result.subtotal,
    tax_amount: result.taxAmount,
    discount_amount: result.discountAmount,
    currency: result.currency,
  };
}

function getCompanyCurrency(): string {
  const authState = useAuthStore.getState();
  const company = authState.companies.find((c) => c.id === authState.companyId);
  return company?.currency ?? 'EUR';
}

async function getDb(): Promise<import('@tauri-apps/plugin-sql').default> {
  const { companyId } = useAuthStore.getState();
  return getDatabase(companyId ?? '');
}

async function runCheckout(
  set: (partial: Partial<PaymentState>) => void,
  terminalId: string,
  cartItems: CartItem[],
  paymentMethodId: string,
  paymentRepositoryId: string,
  tenderedAmount: number,
  transactionDiscount?: { type: 'percentage' | 'fixed'; value: string; reason?: string },
  consumptionMode?: string,
  tableId?: string | null,
): Promise<CheckoutResult> {
  const authState = useAuthStore.getState();
  const operator = authState.user ?? { id: authState.userId ?? '', name: 'Operator' };
  const db = await getDb();

  const result = await executeCheckout(db, {
    terminalId,
    operatorId: operator.id ?? '',
    operatorName: operator.name ?? 'Operator',
    cartItems,
    currency: getCompanyCurrency(),
    paymentMethodId,
    paymentRepositoryId,
    tenderedAmount,
    receiptData: buildReceiptData(terminalId, cartItems, transactionDiscount, consumptionMode, tableId),
    transactionDiscount,
  });

  set({
    lastReceipt: resultToReceiptResponse(result),
    lastPaymentResponse: result.onlinePayment,
    isOfflineReceipt: result.isOffline,
    pendingReceiptId: null,
    changeDue: result.changeDue,
    isProcessing: false,
  });

  return result;
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
      console.warn('[POS] API payment config failed, loading from SQLite:', error);
      try {
        const db = await getDb();
        const [methods, repositories] = await Promise.all([
          getAllPaymentMethods(db),
          getAllPaymentRepositories(db),
        ]);
        if (methods.length > 0) {
          set({ paymentMethods: methods, paymentRepositories: repositories });
          console.info('[POS] Loaded payment config from SQLite cache');
        }
      } catch (dbError) {
        console.error('[POS] SQLite fallback also failed:', dbError);
      }
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

    const cashMethod = paymentMethods.find(
      (m) => m.is_physical && !m.has_maturity && m.is_active,
    );
    if (!cashMethod) {
      const msg = i18n.t('errors.noCashMethod', { ns: 'pos' });
      set({ error: msg });
      throw new Error(msg);
    }

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
      await runCheckout(
        set, terminalId, cartItems,
        cashMethod.id, cashRegister.id, tenderedAmount,
        transactionDiscount, consumptionMode, tableId,
      );
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
      await runCheckout(
        set, terminalId, cartItems,
        cardMethod.id, cardRepo.id, 0,
        transactionDiscount, consumptionMode, tableId,
      );
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
      // For advanced checkout, use the first payment line for the offline receipt
      const primaryPayment = payments[0];
      if (!primaryPayment) {
        throw new Error('No payment lines provided');
      }

      await runCheckout(
        set, terminalId, cartItems,
        primaryPayment.payment_method_id, primaryPayment.repository_id,
        payments.reduce((sum, p) => sum + p.amount, 0),
        transactionDiscount, consumptionMode, tableId,
      );
    } catch (error) {
      set({
        isProcessing: false,
        error: error instanceof Error ? error.message : i18n.t('errors.checkoutFailed', { ns: 'pos' }),
      });
      throw error;
    }
  },

  clearLastReceipt: () => {
    set({ lastReceipt: null, lastPaymentResponse: null, pendingReceiptId: null, changeDue: 0, isOfflineReceipt: false });
  },

  reset: () => {
    set(initialState);
  },
}));
