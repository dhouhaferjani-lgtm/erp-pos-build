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
import { serializeErrorForLog } from '@/lib/errorLogging';
import type { PaymentMethod, PaymentRepository } from '@/types/payment';
import type { CartItem } from '@/types/cart';
import type { CreateReceiptResponse } from '@/types/receipt';

// ─── Voucher tender types ─────────────────────────────────────────────────────

/**
 * A voucher tender row added to the payment screen alongside Cash/Card.
 * Per spec §6.5: payment_method.code = 'store_voucher', instrument_serial = voucher code.
 * The amount is a decimal string at the currency's scale.
 */
export interface VoucherTenderRow {
  /** The voucher code (= LocalVoucher.code). Also used as the unique row key. */
  code: string;
  /** Formatted decimal amount string, e.g. "12.50". */
  amount: string;
}

// ─── Payment state ────────────────────────────────────────────────────────────

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

  /**
   * Voucher tender rows applied in the current sale session.
   * Each row represents one voucher being used as partial/full payment.
   * Codes are unique per transaction — addVoucherPayment guards against duplicates.
   */
  voucherTenders: VoucherTenderRow[];

  /**
   * Derived set of voucher codes already applied. Exposed so VoucherTenderModal
   * can guard against applying the same voucher twice without scanning the full row list.
   */
  appliedVoucherCodes: ReadonlySet<string>;
}

export interface AdvancedPaymentLine {
  payment_method_id: string;
  amount: number;
  repository_id: string;
  card_last_four?: string;
  transaction_reference?: string;
  /**
   * Codex review B3 (2026-04-30): voucher / instrument discriminator for
   * this tender row. Set with `instrument_serial` when paying with a
   * store-voucher / restaurant-voucher / gift-card; omit for cash / card.
   */
  instrument_type?: 'store_voucher' | 'restaurant_voucher' | 'gift_card';
  /** Voucher serial / gift-card code. Required when `instrument_type` is set. */
  instrument_serial?: string;
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

  /**
   * Add a voucher as a tender row for the current sale.
   * Idempotent guard: throws if `code` is already in `appliedVoucherCodes`.
   *
   * @param code    The LocalVoucher.code being redeemed.
   * @param amount  The decimal amount to apply (string, at currency scale).
   * @throws Error if the same code is added twice in one transaction.
   */
  addVoucherPayment: (code: string, amount: string) => void;

  /**
   * Remove a voucher tender row (e.g. if the cashier cancels before confirming).
   */
  removeVoucherPayment: (code: string) => void;

  /** Clear all voucher tender rows (called by reset()). */
  clearVoucherTenders: () => void;
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
  voucherTenders: [],
  appliedVoucherCodes: new Set<string>(),
};

async function getDb(): Promise<import('@tauri-apps/plugin-sql').default> {
  const { companyId } = useAuthStore.getState();
  return getDatabase(companyId ?? '');
}

/**
 * Build the user-visible error banner for a checkout failure.
 *
 * Tauri-plugin-sql rejects with Rust-side strings (or Error subclasses with
 * empty .message), not native `new Error(...)`. The old fallback collapsed
 * both to a generic i18n string, so the cashier saw "Échec du paiement" with
 * no class hint and the catch had already swallowed the original throwable.
 *
 * Contract (Codex review 2026-05-08 finding (b)):
 *   - real Error with non-empty message → use error.message verbatim
 *   - Error subclass with empty message → "<i18n.checkoutFailed>: <ClassName>"
 *   - non-Error                          → "<i18n.checkoutFailed>" (opaque)
 *
 * Non-Error throwables (raw strings from Tauri IPC, plain objects, etc.) keep
 * the original opaque banner because their content can carry SQL fragments,
 * file paths, or query data that don't belong in the cashier UI. Full detail
 * is surfaced via `serializeErrorForLog` to console.error, which is dev-only.
 */
function formatCheckoutError(error: unknown): string {
  if (error instanceof Error && error.message) {
    return error.message;
  }
  const fallback = i18n.t('errors.checkoutFailed', { ns: 'pos' });
  if (error instanceof Error) {
    return `${fallback}: ${error.constructor.name}`;
  }
  return fallback;
}


interface LocalFirstPaymentLine {
  methodCode: string;
  amount: string;
  paymentMethodId: string;
  repositoryId: string;
  cardLastFour?: string;
  transactionReference?: string;
  /**
   * Codex review B3 (2026-04-30): voucher / instrument discriminator. Bound
   * into the v3 fiscal hash by `buildCanonicalPayload`. Pass for store-voucher,
   * restaurant-voucher, gift-card tenders; omit for cash / card.
   */
  instrumentType?: 'store_voucher' | 'restaurant_voucher' | 'gift_card';
  /**
   * Codex review B3 (2026-04-30): voucher serial / gift-card code that
   * tendered this row. Required when instrumentType is set.
   */
  instrumentSerial?: string;
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
      // Codex review B3 (2026-04-30): forward instrument fields end-to-end
      // so a voucher tender's serial enters createOfflineReceipt's v3 hash
      // input AND its payments_json. Cash / card tenders omit these.
      instrumentType: p.instrumentType,
      instrumentSerial: p.instrumentSerial,
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
    } catch (sqliteError) {
      // SQLite not ready yet — continue to API. Log so a corrupted DB during
      // a startup race is visible in devtools instead of silently invisible.
      console.error('[POS][paymentStore][fetchPaymentConfig] SQLite read failed', {
        ...serializeErrorForLog(sqliteError),
      });
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
      } catch (writebackError) {
        // Non-critical — sync scheduler also handles this. Log so a persistent
        // SQLite write failure isn't invisible.
        console.error('[POS][paymentStore][fetchPaymentConfig] SQLite writeback failed', {
          ...serializeErrorForLog(writebackError),
          methodCount: methods.length,
          repositoryCount: repositories.length,
        });
      }
    } catch (apiError) {
      // API failed — SQLite data (if loaded in Step 1) is already in state.
      console.error('[POS][paymentStore][fetchPaymentConfig] API failed', {
        ...serializeErrorForLog(apiError),
        cachedMethodCount: get().paymentMethods.length,
      });
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
      console.error('[POS][checkout][cash] failed', {
        ...serializeErrorForLog(error),
        cartItemCount: cartItems.length,
        tenderedAmount,
        terminalId,
        cashMethodId: cashMethod.id,
        cashRegisterId: cashRegister.id,
      });
      set({
        isProcessing: false,
        error: formatCheckoutError(error),
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
      console.error('[POS][checkout][card] failed', {
        ...serializeErrorForLog(error),
        cartItemCount: cartItems.length,
        terminalId,
        cardMethodId: cardMethod.id,
        cardRepoId: cardRepo.id,
      });
      set({
        isProcessing: false,
        error: formatCheckoutError(error),
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
          // Codex review B3 (2026-04-30): forward instrument fields so a
          // voucher tender enters the v3 fiscal hash with its serial bound.
          instrumentType: p.instrument_type,
          instrumentSerial: p.instrument_serial,
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
      console.error('[POS][checkout][advanced] failed', {
        ...serializeErrorForLog(error),
        cartItemCount: cartItems.length,
        terminalId,
        paymentLineCount: payments.length,
      });
      set({
        isProcessing: false,
        error: formatCheckoutError(error),
      });
      throw error;
    }
  },

  clearLastReceipt: () => {
    set({ lastReceipt: null, pendingReceiptId: null, changeDue: 0, lastReceiptIdempotencyKey: null, lastReceiptServerId: null });
  },

  addVoucherPayment: (code: string, amount: string) => {
    const { appliedVoucherCodes } = get();
    if (appliedVoucherCodes.has(code)) {
      throw new Error(
        i18n.t('voucherTender.alreadyApplied', { ns: 'pos', defaultValue: 'This voucher has already been applied to this sale.' }),
      );
    }
    const nextCodes = new Set(appliedVoucherCodes);
    nextCodes.add(code);
    set((state) => ({
      voucherTenders: [...state.voucherTenders, { code, amount }],
      appliedVoucherCodes: nextCodes,
    }));
  },

  removeVoucherPayment: (code: string) => {
    set((state) => {
      const nextCodes = new Set(state.appliedVoucherCodes);
      nextCodes.delete(code);
      return {
        voucherTenders: state.voucherTenders.filter((v) => v.code !== code),
        appliedVoucherCodes: nextCodes,
      };
    });
  },

  clearVoucherTenders: () => {
    set({ voucherTenders: [], appliedVoucherCodes: new Set<string>() });
  },

  reset: () => {
    set(initialState);
  },
}));
