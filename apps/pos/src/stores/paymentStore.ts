import { create } from 'zustand';
import i18n from '@/lib/i18n';
import { fetchPaymentMethods, fetchPaymentRepositories } from '@/api/paymentApi';
import { useCartStore } from '@/stores/cartStore';
import { useAuthStore } from '@/stores/authStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { getCurrencyDecimals } from '@/lib/currency';
import { getDatabase } from '@/lib/db';
import { getAllPaymentMethods, getAllPaymentRepositories } from '@/lib/db/repositories/paymentRepository';
import { executeCheckout, type CheckoutResult } from '@/lib/offline/offlineCheckoutService';
import { createOfflineReceipt, type OfflineReceiptResult } from '@/lib/offline/receiptService';
import { useSyncStore } from '@/stores/syncStore';
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

  // Update pending sync count when receipt was created offline
  if (result.isOffline) {
    const { pendingReceiptCount } = useSyncStore.getState();
    useSyncStore.getState().setPendingCount(pendingReceiptCount + 1);
  }

  return result;
}

interface LocalFirstPaymentLine {
  methodCode: string;
  amount: string;
  paymentMethodId: string;
  repositoryId: string;
  cardLastFour?: string;
  transactionReference?: string;
}

async function createReceiptLocalFirst(
  set: (partial: Partial<PaymentState>) => void,
  terminalId: string,
  cartItems: CartItem[],
  payments: LocalFirstPaymentLine[],
  tenderedAmount: number,
  transactionDiscount?: { type: 'percentage' | 'fixed'; value: string; reason?: string },
  consumptionMode?: string,
  tableId?: string | null,
): Promise<OfflineReceiptResult> {
  const authState = useAuthStore.getState();
  const operatorState = useOperatorStore.getState();

  const companyId = authState.companyId;
  if (!companyId) {
    throw new Error(i18n.t('errors.noCompanySelected', { ns: 'pos' }));
  }
  const company = authState.companies.find((c) => c.id === companyId);
  const currency = company?.currency ?? 'EUR';

  // Prefer PIN-verified operator; fall back to logged-in user for pre-PIN terminals
  const operator = operatorState.operator;
  const operatorId = operator?.id ?? authState.user?.id;
  const operatorName = operator?.name ?? authState.user?.name;
  if (!operatorId || !operatorName) {
    throw new Error(i18n.t('errors.noOperatorIdentified', { ns: 'pos' }));
  }

  const primary = payments[0];
  if (!primary) {
    throw new Error(i18n.t('errors.noPaymentProvided', { ns: 'pos' }));
  }

  const db = await getDatabase(companyId);

  const result = await createOfflineReceipt(db, {
    terminalId,
    operatorId,
    operatorName,
    cartItems,
    currency,
    paymentMethodId: primary.paymentMethodId,
    paymentRepositoryId: primary.repositoryId,
    tenderedAmount,
    transactionDiscount,
    payments: payments.map((p) => ({
      methodCode: p.methodCode,
      amount: p.amount,
      paymentMethodId: p.paymentMethodId,
      repositoryId: p.repositoryId,
      cardLastFour: p.cardLastFour,
      transactionReference: p.transactionReference,
    })),
    consumptionMode,
    tableId: tableId ?? undefined,
  });

  // Fire-and-forget background sync; errors are surfaced by the scheduler, not at checkout
  const scheduler = useSyncStore.getState().scheduler;
  if (scheduler) {
    void scheduler.syncNow().catch((err: unknown) => {
      console.warn('[POS] Background sync attempt failed (will retry on next tick):', err);
    });
  }

  // Expose the client-generated receipt identity to the UI.
  // id = local SQLite row id; serverReceiptId is null until sync completes.
  set({
    lastReceipt: {
      id: result.receiptNumber, // using receipt_number as a stable identifier for UI; real UUID is idempotencyKey
      receipt_number: result.receiptNumber,
      total: result.total,
      subtotal: result.subtotal,
      tax_amount: result.taxAmount,
      discount_amount: result.discountAmount,
      currency,
    } satisfies CreateReceiptResponse,
    pendingReceiptId: null,
  });

  return result;
}

export const usePaymentStore = create<PaymentStore>()((set, get) => ({
  ...initialState,

  fetchPaymentConfig: async () => {
    // Step 1: Load from SQLite immediately (instant, always available)
    try {
      const db = await getDb();
      const [cachedMethods, cachedRepos] = await Promise.all([
        getAllPaymentMethods(db),
        getAllPaymentRepositories(db),
      ]);
      if (cachedMethods.length > 0) {
        set({ paymentMethods: cachedMethods, paymentRepositories: cachedRepos });
      }
    } catch {
      // SQLite not ready yet — continue to API
    }

    // Step 2: Try API for fresh data (updates SQLite cache for next time)
    try {
      const [methods, repositories] = await Promise.all([
        fetchPaymentMethods(),
        fetchPaymentRepositories(),
      ]);
      set({ paymentMethods: methods, paymentRepositories: repositories });

      // Persist to SQLite so offline fallback has fresh data
      try {
        const db = await getDb();
        const { upsertPaymentMethods, upsertPaymentRepositories } = await import('@/lib/db/repositories/paymentRepository');
        await upsertPaymentMethods(db, methods);
        await upsertPaymentRepositories(db, repositories);
      } catch {
        // Non-critical — sync scheduler also handles this
      }
    } catch (error) {
      // API failed — SQLite data (if loaded in Step 1) is already in state
      if (get().paymentMethods.length === 0) {
        console.warn('[POS] No payment config available — neither API nor SQLite cache');
      }
    }
  },

  processCashCheckout: async (
    terminalId,
    cartItems,
    tenderedAmount,
    transactionDiscount,
    consumptionMode,
    tableId,
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
      const totalEstimate = cartItems.reduce((sum, i) => sum + parseFloat(i.line_total), 0);

      const result = await createReceiptLocalFirst(
        set,
        terminalId,
        cartItems,
        [{
          methodCode: cashMethod.code,
          amount: totalEstimate.toFixed(2),
          paymentMethodId: cashMethod.id,
          repositoryId: cashRegister.id,
        }],
        tenderedAmount,
        transactionDiscount,
        consumptionMode,
        tableId,
      );

      set({ changeDue: result.changeDue, isProcessing: false });
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
