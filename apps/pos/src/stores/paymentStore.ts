import { create } from 'zustand';
import i18n from '@/lib/i18n';
import { fetchPaymentMethods, fetchPaymentRepositories } from '@/api/paymentApi';
import { useAuthStore } from '@/stores/authStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { getCurrencyDecimals } from '@/lib/currency';
import { getDatabase } from '@/lib/db';
import { getAllPaymentMethods, getAllPaymentRepositories } from '@/lib/db/repositories/paymentRepository';
import { createOfflineReceipt, type OfflineReceiptResult } from '@/lib/offline/receiptService';
import { useSyncStore } from '@/stores/syncStore';
import type { PaymentMethod, PaymentRepository } from '@/types/payment';
import type { CartItem } from '@/types/cart';
import type { CreateReceiptResponse } from '@/types/receipt';

interface PaymentState {
  paymentMethods: PaymentMethod[];
  paymentRepositories: PaymentRepository[];
  isProcessing: boolean;
  lastReceipt: CreateReceiptResponse | null;
  pendingReceiptId: string | null;
  changeDue: number;
  error: string | null;
  /** Idempotency key (= SQLite offline_receipts.idempotency_key) for local-first print lookup. Null before first checkout. */
  lastReceiptIdempotencyKey: string | null;
  /** Server-assigned receipt UUID, populated when sync completes. Null while pending. */
  lastReceiptServerId: string | null;
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
  pendingReceiptId: null,
  changeDue: 0,
  error: null,
  lastReceiptIdempotencyKey: null,
  lastReceiptServerId: null,
};

async function getDb(): Promise<import('@tauri-apps/plugin-sql').default> {
  const { companyId } = useAuthStore.getState();
  return getDatabase(companyId ?? '');
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

  // Fire-and-forget background sync; triggerSync() guards against concurrent calls via isSyncing.
  useSyncStore.getState().triggerSync();

  // Expose the client-generated receipt identity to the UI.
  // id = local SQLite row id; serverReceiptId is null until sync completes.
  set({
    lastReceipt: {
      id: result.localId,
      receipt_number: result.receiptNumber,
      total: result.total,
      subtotal: result.subtotal,
      tax_amount: result.taxAmount,
      discount_amount: result.discountAmount,
      currency,
    } satisfies CreateReceiptResponse,
    lastReceiptIdempotencyKey: result.idempotencyKey,
    lastReceiptServerId: null,
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
      const authState = useAuthStore.getState();
      const company = authState.companies.find((c) => c.id === authState.companyId);
      const currency = company?.currency ?? 'EUR';
      const decimals = getCurrencyDecimals(currency);
      const totalEstimate = cartItems.reduce((sum, i) => sum + parseFloat(i.line_total), 0);

      const result = await createReceiptLocalFirst(
        set,
        terminalId,
        cartItems,
        [{
          methodCode: cashMethod.code,
          amount: totalEstimate.toFixed(decimals),
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
    terminalId,
    cartItems,
    cardData,
    transactionDiscount,
    consumptionMode,
    tableId,
  ) => {
    const { paymentMethods, paymentRepositories } = get();

    const cardMethod = paymentMethods.find(
      (m) =>
        (m.code === 'card' || m.code === 'CARD' ||
          (!m.is_physical && m.requires_third_party && m.is_active)) &&
        m.is_active,
    );
    if (!cardMethod) {
      const msg = i18n.t('errors.noCardMethod', { ns: 'pos' });
      set({ error: msg });
      throw new Error(msg);
    }

    const cardRepo = paymentRepositories.find(
      (r) => (r.type === 'virtual' || r.type === 'bank_account') && r.is_active,
    );
    if (!cardRepo) {
      const msg = i18n.t('errors.noCardRepository', { ns: 'pos' });
      set({ error: msg });
      throw new Error(msg);
    }

    set({ isProcessing: true, error: null });

    try {
      const authState = useAuthStore.getState();
      const company = authState.companies.find((c) => c.id === authState.companyId);
      const currency = company?.currency ?? 'EUR';
      const decimals = getCurrencyDecimals(currency);
      const totalEstimate = cartItems.reduce((sum, i) => sum + parseFloat(i.line_total), 0);

      await createReceiptLocalFirst(
        set,
        terminalId,
        cartItems,
        [{
          methodCode: cardMethod.code,
          amount: totalEstimate.toFixed(decimals),
          paymentMethodId: cardMethod.id,
          repositoryId: cardRepo.id,
          cardLastFour: cardData?.lastFour,
          transactionReference: cardData?.reference,
        }],
        0, // tenderedAmount = 0 for card (no cash in hand)
        transactionDiscount,
        consumptionMode,
        tableId,
      );

      set({ changeDue: 0, isProcessing: false });
    } catch (error) {
      set({
        isProcessing: false,
        error: error instanceof Error ? error.message : i18n.t('errors.checkoutFailed', { ns: 'pos' }),
      });
      throw error;
    }
  },

  processAdvancedCheckout: async (
    terminalId,
    cartItems,
    payments,
    transactionDiscount,
    consumptionMode,
    tableId,
  ) => {
    set({ isProcessing: true, error: null });

    try {
      const authState = useAuthStore.getState();
      const company = authState.companies.find((c) => c.id === authState.companyId);
      const currency = company?.currency ?? 'EUR';
      const decimals = getCurrencyDecimals(currency);

      const { paymentMethods } = get();
      const enriched = payments.map((p) => {
        const method = paymentMethods.find((m) => m.id === p.payment_method_id);
        if (!method) {
          throw new Error(i18n.t('errors.unknownPaymentMethod', { ns: 'pos' }));
        }
        return {
          methodCode: method.code,
          amount: p.amount.toFixed(decimals),
          paymentMethodId: p.payment_method_id,
          repositoryId: p.repository_id,
          cardLastFour: p.card_last_four,
          transactionReference: p.transaction_reference,
        };
      });

      const tenderedAmount = payments.reduce((sum, p) => sum + p.amount, 0);

      const result = await createReceiptLocalFirst(
        set,
        terminalId,
        cartItems,
        enriched,
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

  clearLastReceipt: () => {
    set({ lastReceipt: null, pendingReceiptId: null, changeDue: 0, lastReceiptIdempotencyKey: null, lastReceiptServerId: null });
  },

  reset: () => {
    set(initialState);
  },
}));
