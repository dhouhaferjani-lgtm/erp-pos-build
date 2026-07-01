import { create } from 'zustand';
import i18n from '@/lib/i18n';
import { fetchPaymentMethods, fetchPaymentRepositories } from '@/api/paymentApi';
import { useAuthStore } from '@/stores/authStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useTerminalStore, fiscalShiftIdForReceipt } from '@/stores/terminalStore';
import { getActiveCurrency, getCurrencyDecimals } from '@/lib/currency';
import { bcsum, bcmul, bcdiv, bcsub, bccomp, bcformat } from '@/lib/decimal';
import { getDatabase } from '@/lib/db';
import { getAllPaymentMethods, getAllPaymentRepositories } from '@/lib/db/repositories/paymentRepository';
import { createOfflineReceipt, type OfflineReceiptResult } from '@/lib/offline/receiptService';
import { createAccountPayment, type AccountPaymentResult } from '@/lib/offline/accountPaymentService';
import { authorAccountCharge } from '@/lib/accountCharge/accountChargeService';
import type {
  AccountChargeOverrideApprovalInput,
  AccountChargeResult,
} from '@/lib/accountCharge/accountChargeService';
import { buildAccountChargeCart } from '@/lib/accountCharge/accountChargeCartMapper';
import { buildEscPosAccountChargeReceiptData } from '@/lib/buildReceiptData';
import { isBalanceStale } from '@/lib/db/repositories/customerRepository';
import { useCartStore } from '@/stores/cartStore';
import { lockTerminal } from '@/lib/offline/terminalMutex';
import { ConcurrentChainAdvanceError } from '@/lib/fiscal/FiscalEventEngine';
import { resolveSellerIdentity } from '@/lib/fiscal/sellerIdentity';
import { useSyncStore } from '@/stores/syncStore';
import { serializeErrorForLog } from '@/lib/errorLogging';
import type { PaymentMethod, PaymentRepository } from '@/types/payment';
import type { CartItem } from '@/types/cart';
import type { CartTransactionDiscount } from '@/stores/cartStore';
import type { CreateReceiptResponse } from '@/types/receipt';
import type { CustomerAccountStatus } from '@/lib/customer/customerTypes';
import type { ReceiptData } from '@/lib/printing';
import { verifyScopedManagerPin } from '@/lib/operatorApproval/scopedManagerPin';
import {
  authorPosOverride,
  type PosOverrideEvidence,
} from '@/lib/operatorApproval/posOverrideAuthoring';
import { recordAuditEvent } from '@/lib/audit/recordAuditEvent';

/**
 * Epoch-ms when the currently-attached checkout customer was attached, used to
 * derive `attached_duration_ms` for the `pos.customer_detached` audit emit.
 * Module-local (not part of PaymentState) so it stays out of the sealed fiscal
 * snapshot / reset surface. Set on attachCustomer, cleared on detach.
 */
let customerAttachedAt: number | null = null;

/** Aggregate id for customer attach/detach: the cart session id, else null. */
function checkoutAggregateId(): string | null {
  try {
    return useCartStore.getState().getCartSessionId();
  } catch {
    return null;
  }
}

export class ActiveTerminalRequiredError extends Error {
  constructor() {
    super('Active terminal and open shift are required to create a fiscal receipt.');
    this.name = 'ActiveTerminalRequiredError';
  }
}

export class FiscalChainContentionError extends Error {
  readonly chainCause: unknown;

  constructor(readonly terminalId: string, cause: unknown) {
    super(`Fiscal chain contention on terminal ${terminalId}; retry limit exhausted.`);
    this.name = 'FiscalChainContentionError';
    this.chainCause = cause;
  }
}

// ─── T1.2 — refreshFromSQLite skip-set equality helpers ─────────────────────

/**
 * Per-row shallow equality on the primitive fields of two PaymentMethod
 * arrays. Returns true only when every row at index i has identical
 * primitive fields. Used by `refreshFromSQLite` to skip the `set` call
 * entirely when SQLite returns a structurally-unchanged list — every
 * 60 s scheduler tick would otherwise create new array references and
 * force every Zustand subscriber to re-render. id-set equality alone is
 * insufficient because admin-side renames / position / fee changes
 * keep the id but change the rendered state.
 */
function paymentMethodsShallowEqual(
  a: PaymentMethod[],
  b: PaymentMethod[],
): boolean {
  if (a.length !== b.length) return false;
  for (let i = 0; i < a.length; i++) {
    const ax = a[i];
    const bx = b[i];
    if (!ax || !bx) return false;
    if (ax === bx) continue;
    if (
      ax.id !== bx.id ||
      ax.code !== bx.code ||
      ax.name !== bx.name ||
      ax.is_physical !== bx.is_physical ||
      ax.has_maturity !== bx.has_maturity ||
      ax.requires_third_party !== bx.requires_third_party ||
      ax.is_push !== bx.is_push ||
      ax.has_deducted_fees !== bx.has_deducted_fees ||
      ax.is_restricted !== bx.is_restricted ||
      ax.fee_type !== bx.fee_type ||
      ax.fee_fixed !== bx.fee_fixed ||
      ax.fee_percent !== bx.fee_percent ||
      ax.restriction_type !== bx.restriction_type ||
      ax.is_active !== bx.is_active ||
      ax.position !== bx.position
    ) {
      return false;
    }
  }
  return true;
}

function paymentRepositoriesShallowEqual(
  a: PaymentRepository[],
  b: PaymentRepository[],
): boolean {
  if (a.length !== b.length) return false;
  for (let i = 0; i < a.length; i++) {
    const ax = a[i];
    const bx = b[i];
    if (!ax || !bx) return false;
    if (ax === bx) continue;
    if (
      ax.id !== bx.id ||
      ax.code !== bx.code ||
      ax.name !== bx.name ||
      ax.type !== bx.type ||
      ax.bank_name !== bx.bank_name ||
      ax.account_number !== bx.account_number ||
      ax.iban !== bx.iban ||
      ax.bic !== bx.bic ||
      ax.balance !== bx.balance ||
      ax.is_active !== bx.is_active
    ) {
      return false;
    }
  }
  return true;
}

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

export type CustomerSyncStatus = 'synced' | 'pending_create';

export interface AttachedCheckoutCustomer {
  id: string;
  tenant_id: string;
  company_id: string;
  name: string;
  phone: string | null;
  email: string | null;
  tax_number: string | null;
  customer_category: string | null;
  receivable_balance: string;
  credit_balance: string;
  credit_limit: string | null;
  payment_terms_days: number | null;
  charge_account_enabled: boolean | 0 | 1;
  charge_policy_version: string | null;
  account_status: CustomerAccountStatus;
  account_status_changed_at: string | null;
  account_status_reason: string | null;
  account_status_version: number;
  balance_updated_at: string | null;
  is_active: boolean | 0 | 1;
  customer_sync_status: CustomerSyncStatus;
}

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
  /** Pre-built local printable for non-pos_receipts documents such as ACCOUNT_PAYMENT. */
  lastReceiptPrintData: ReceiptData | null;

  /**
   * T0.2: Idempotency key for the current cart submission attempt.
   *
   * Allocated atomically when Confirm is first pressed for a cart, then reused
   * on every retry until the cart is explicitly cleared (= clearLastReceipt is
   * called by HomePage's handleNewSale on success-modal-dismiss / new-sale /
   * manual-cancel). This prevents the cashier-double-click double-billing bug
   * (Opus + Codex sync audits 2026-04-30): if two checkout attempts mint
   * different keys for the same physical sale, the server can't dedup them
   * and both finalize.
   *
   * In-memory only — server-side dedup-on-disk reconciles after a crash.
   */
  pendingIdempotencyKey: string | null;

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

  /**
   * Customer attached to the current checkout/account-payment context.
   * This is a sealed snapshot candidate only; fiscal authoring must not call
   * server-side Customer/Treasury modules while building device events.
   */
  selectedCustomer: AttachedCheckoutCustomer | null;
}

export interface AdvancedPaymentLine {
  payment_method_id: string;
  /**
   * Canonical decimal amount string at the currency's scale (e.g. "12.50",
   * TND "12.500"). String — never a JS number — so the value that enters the
   * fiscal-event canonical hash carries no IEEE-754 jitter (F-FRONTEND-VOUCHER).
   */
  amount: string;
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

export interface AdvancedCheckoutOptions {
  tenderTolerancePin?: string;
}

interface PaymentActions {
  fetchPaymentConfig: () => Promise<void>;
	processCashCheckout: (
	  terminalId: string,
	  cartItems: CartItem[],
	  tenderedAmount: string,
	  transactionDiscount?: CartTransactionDiscount,
    consumptionMode?: string,
    tableId?: string | null,
  ) => Promise<void>;
	processCardCheckout: (
	  terminalId: string,
	  cartItems: CartItem[],
	  cardData?: { lastFour?: string; reference?: string },
	  transactionDiscount?: CartTransactionDiscount,
    consumptionMode?: string,
    tableId?: string | null,
  ) => Promise<void>;
	processAdvancedCheckout: (
	  terminalId: string,
	  cartItems: CartItem[],
	  payments: AdvancedPaymentLine[],
	  transactionDiscount?: CartTransactionDiscount,
    consumptionMode?: string,
    tableId?: string | null,
    options?: AdvancedCheckoutOptions,
  ) => Promise<void>;
  processAccountPayment: (
    terminalId: string,
    amount: string,
    options?: {
      balanceSnapshotStale?: boolean;
      customerSnapshotStale?: boolean;
    },
  ) => Promise<AccountPaymentResult | null>;
  processAccountCharge: (
    terminalId: string,
    options?: { overrideApproval?: AccountChargeOverrideApprovalInput | null },
  ) => Promise<AccountChargeResult | null>;
  reset: () => void;
  clearLastReceipt: () => void;
  /**
   * T0.2 (Codex round-1 finding F-2): targeted discard of `pendingIdempotencyKey`
   * for cart-clear lifecycle transitions that are NOT post-success. Called by:
   *   - Header void-cart actions
   *   - holdStore recall (replacing the cart with a held one)
   *   - shift close
   * These transitions clear `cartStore` but should NOT also clear
   * `lastReceipt` / `lastReceiptIdempotencyKey` (which represent the most
   * recently SUCCESSFUL sale's print state). `discardPendingSubmission` is the
   * narrower clear: only resets the in-flight cart submission attempt's key
   * so the next sale gets a fresh allocation.
   */
  discardPendingSubmission: () => void;

  /**
   * T0.5 (2026-05-08): rehydrate the in-memory `paymentMethods` +
   * `paymentRepositories` arrays from the local SQLite cache. Mirrors
   * `productStore.refreshFromSQLite()` and is invoked by the sync scheduler
   * after every successful tick so the in-memory store cannot drift stale
   * relative to whatever the platform side committed since the cashier's
   * last login. Empty SQLite is a no-op (NOT a wipe) — leaves existing
   * in-memory state intact so the cashier doesn't briefly see no payment
   * methods if SQLite is unseeded.
   */
  refreshFromSQLite: () => Promise<void>;

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

  /** Attach a scoped customer snapshot to the current checkout. */
  attachCustomer: (customer: AttachedCheckoutCustomer) => void;

  /** Remove the customer snapshot before sealing/checkout. */
  detachCustomer: () => void;
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
  lastReceiptPrintData: null,
  pendingIdempotencyKey: null,
  voucherTenders: [],
  appliedVoucherCodes: new Set<string>(),
  selectedCustomer: null,
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

function assertAttachedCustomerScope(customer: AttachedCheckoutCustomer): void {
  if (customer.tenant_id.trim() === '') {
    throw new Error('[customer] tenant_id is required');
  }
  if (customer.company_id.trim() === '') {
    throw new Error('[customer] company_id is required');
  }
  if (customer.id.trim() === '') {
    throw new Error('[customer] id is required');
  }
  if (customer.name.trim() === '') {
    throw new Error('[customer] name is required');
  }
}

/** @internal Exported for unit testing. */
export function estimateCartTotal(
  cartItems: CartItem[],
  transactionDiscount?: CartTransactionDiscount,
  currency: string = 'EUR',
): string {
  const decimals = getCurrencyDecimals(currency);
  const subtotal = bcsum(cartItems.map((item) => item.line_total), decimals);
  if (transactionDiscount === undefined) {
    return subtotal;
  }

  // Use bccomp instead of parseFloat to keep the check in the decimal domain.
  // safeBig handles empty string (→ 0); try/catch handles a non-numeric value.
  let discountIsPositive = false;
  try {
    discountIsPositive = bccomp(transactionDiscount.value, '0') > 0;
  } catch {
    /* non-numeric discount value — treat as no discount */
  }
  if (!discountIsPositive) {
    return subtotal;
  }

  const rawDiscount = transactionDiscount.type === 'percentage'
    ? bcdiv(bcmul(subtotal, transactionDiscount.value, decimals), '100', decimals)
    : transactionDiscount.value;
  // Clamp discount to the subtotal so the total can never go negative.
  const discount = bccomp(rawDiscount, subtotal) > 0 ? subtotal : rawDiscount;
  const total = bcsub(subtotal, discount, decimals);
  return bccomp(total, '0') < 0 ? bcformat('0', decimals) : total;
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
   * into the fiscal-event SALE_RECEIPT canonical payload. Pass for
   * store-voucher, restaurant-voucher, gift-card tenders; omit for cash / card.
   */
  instrumentType?: 'store_voucher' | 'restaurant_voucher' | 'gift_card';
  /**
   * Codex review B3 (2026-04-30): voucher serial / gift-card code that
   * tendered this row. Required when instrumentType is set.
   */
  instrumentSerial?: string;
}

async function createOfflineReceiptWithChainRetry(
  terminalId: string,
  operation: () => Promise<OfflineReceiptResult>,
): Promise<OfflineReceiptResult> {
  const delays = [50, 100, 200];
  let lastError: unknown = null;
  for (let attempt = 0; attempt <= delays.length; attempt += 1) {
    try {
      return await operation();
    } catch (error) {
      if (!(error instanceof ConcurrentChainAdvanceError)) {
        throw error;
      }
      lastError = error;
      const delay = delays[attempt];
      if (delay === undefined) {
        break;
      }
      await new Promise((resolve) => setTimeout(resolve, delay));
    }
  }
  throw new FiscalChainContentionError(terminalId, lastError);
}

async function createReceiptLocalFirst(
  set: (partial: Partial<PaymentState>) => void,
  terminalId: string,
  cartItems: CartItem[],
  payments: LocalFirstPaymentLine[],
  tenderedAmount: string,
  /**
   * T0.2: Caller-allocated idempotency key for this cart submission attempt.
   * Same key is reused across cashier double-clicks so the server-side
   * dedup-on-disk catches the second POST as a duplicate. See
   * `pendingIdempotencyKey` on PaymentState for the lifecycle.
   */
  idempotencyKey: string,
  transactionDiscount?: CartTransactionDiscount,
  consumptionMode?: string,
  tableId?: string | null,
  tenderToleranceEvidence?: PosOverrideEvidence,
): Promise<OfflineReceiptResult> {
  const authState = useAuthStore.getState();
  const operatorState = useOperatorStore.getState();

  const companyId = authState.companyId;
  if (!companyId) {
    throw new Error(i18n.t('errors.noCompanySelected', { ns: 'pos' }));
  }
  const company = authState.companies.find((c) => c.id === companyId);
  const currency = company?.currency ?? 'EUR';
  const tenantId = authState.user?.tenantId;
  if (!tenantId) {
    throw new Error(i18n.t('errors.noOperatorIdentified', { ns: 'pos' }));
  }

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

  // T2.7 — read training mode from the terminal store and forward it to
  // createOfflineReceipt. The terminal record is hydrated at boot
  // (terminalStore.initialize) and refreshed in-session via
  // refreshTerminalRecord on every sync tick (T2.5), so this snapshot is
  // current at submission time. A null/undefined terminal defaults to
  // production (the safer fallback — a missing flag should never silently
  // turn a real sale into a training receipt).
  const terminalState = useTerminalStore.getState();
  const terminal = terminalState.terminal;
  const shift = terminalState.shift;
  if (!terminal || terminal.id !== terminalId || !shift) {
    throw new ActiveTerminalRequiredError();
  }
  const isTraining = terminal?.is_training_mode === true;

  const result = await lockTerminal(tenantId, terminalId, () =>
    createOfflineReceiptWithChainRetry(terminalId, () =>
      createOfflineReceipt(db, {
        tenantId,
        companyId,
        terminalId,
        operatorId,
        operatorName,
        shiftId: fiscalShiftIdForReceipt(shift),
        cartItems,
        currency,
        // Atomic seller identity (spec 2026-06-11 §4.6): complete location
        // identity wholesale, otherwise company wholesale — never mixed.
        seller: resolveSellerIdentity(company, terminal?.location ?? null),
        paymentMethodId: primary.paymentMethodId,
        paymentRepositoryId: primary.repositoryId,
        tenderedAmount,
        idempotencyKey,
        transactionDiscount,
        tenderToleranceEvidence,
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
        isTraining,
      }),
    ),
  );

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

async function createAccountPaymentLocalFirst(
  terminalId: string,
  amount: string,
  selectedCustomer: AttachedCheckoutCustomer,
  cashMethod: PaymentMethod,
  cashRegister: PaymentRepository,
  options?: {
    balanceSnapshotStale?: boolean;
    customerSnapshotStale?: boolean;
  },
): Promise<AccountPaymentResult> {
  const authState = useAuthStore.getState();
  const operatorState = useOperatorStore.getState();

  const companyId = authState.companyId;
  if (!companyId) {
    throw new Error(i18n.t('errors.noCompanySelected', { ns: 'pos' }));
  }
  const company = authState.companies.find((c) => c.id === companyId);
  const currency = company?.currency ?? 'EUR';
  const tenantId = authState.user?.tenantId;
  if (!tenantId) {
    throw new Error(i18n.t('errors.noOperatorIdentified', { ns: 'pos' }));
  }
  if (selectedCustomer.tenant_id !== tenantId || selectedCustomer.company_id !== companyId) {
    throw new Error('Customer belongs to a different tenant or company.');
  }

  const operator = operatorState.operator;
  const operatorId = operator?.id ?? authState.user?.id;
  const operatorName = operator?.name ?? authState.user?.name;
  if (!operatorId || !operatorName) {
    throw new Error(i18n.t('errors.noOperatorIdentified', { ns: 'pos' }));
  }

  const terminalState = useTerminalStore.getState();
  const terminal = terminalState.terminal;
  const shift = terminalState.shift;
  if (!terminal || terminal.id !== terminalId || !shift) {
    throw new ActiveTerminalRequiredError();
  }
  const db = await getDatabase(companyId);

  return lockTerminal(tenantId, terminalId, () =>
    createAccountPayment(db, {
      tenantId,
      companyId,
      terminalId,
      terminalName: terminal.name,
      operatorId,
      operatorName,
      shiftId: fiscalShiftIdForReceipt(shift),
      currency,
      // Atomic seller identity (spec 2026-06-11 §4.6) — same resolver as the
      // SALE_RECEIPT path; never a branch tax number with a company address.
      seller: resolveSellerIdentity(company, terminal?.location ?? null),
      customer: selectedCustomer,
      payment: {
        amount,
        methodCode: cashMethod.code,
        repositoryId: cashRegister.id,
      },
      isTraining: terminal.is_training_mode === true,
      balanceSnapshotStale: options?.balanceSnapshotStale,
      customerSnapshotStale: options?.customerSnapshotStale,
    }),
  );
}

async function createAccountChargeLocalFirst(
  terminalId: string,
  selectedCustomer: AttachedCheckoutCustomer,
  options?: { overrideApproval?: AccountChargeOverrideApprovalInput | null },
): Promise<AccountChargeResult> {
  const authState = useAuthStore.getState();
  const operatorState = useOperatorStore.getState();

  const companyId = authState.companyId;
  if (!companyId) {
    throw new Error(i18n.t('errors.noCompanySelected', { ns: 'pos' }));
  }
  const company = authState.companies.find((c) => c.id === companyId);
  const currency = company?.currency ?? 'EUR';
  const tenantId = authState.user?.tenantId;
  if (!tenantId) {
    throw new Error(i18n.t('errors.noOperatorIdentified', { ns: 'pos' }));
  }
  if (selectedCustomer.tenant_id !== tenantId || selectedCustomer.company_id !== companyId) {
    throw new Error('Customer belongs to a different tenant or company.');
  }

  const operator = operatorState.operator;
  const operatorId = operator?.id ?? authState.user?.id;
  const operatorName = operator?.name ?? authState.user?.name;
  if (!operatorId || !operatorName) {
    throw new Error(i18n.t('errors.noOperatorIdentified', { ns: 'pos' }));
  }

  const terminalState = useTerminalStore.getState();
  const terminal = terminalState.terminal;
  const shift = terminalState.shift;
  if (!terminal || terminal.id !== terminalId || !shift) {
    throw new ActiveTerminalRequiredError();
  }

  const cartState = useCartStore.getState();
  const cart = buildAccountChargeCart({
    cartItems: cartState.items,
    currency,
    transactionDiscount: cartState.transactionDiscount ?? null,
  });

  // Mirror CustomerAttachPanel's isBalanceStale call: project the attached
  // snapshot onto a CustomerMirrorRow shape (the panel uses a configurable
  // threshold; the checkout path uses the 30-minute default).
  const balanceSnapshotStale = isBalanceStale(
    {
      ...selectedCustomer,
      is_active: 1,
      sync_version: null,
      updated_at: null,
      synced_at: selectedCustomer.balance_updated_at ?? new Date().toISOString(),
      // Task 21 — skin fields are not part of AttachedCheckoutCustomer;
      // default to null when projecting onto CustomerMirrorRow for staleness check.
      skin_type: null,
      skin_advice_note: null,
    },
    new Date(),
    30,
  );

  const db = await getDatabase(companyId);
  return lockTerminal(tenantId, terminalId, () =>
    authorAccountCharge(db, {
      tenantId,
      companyId,
      terminalId,
      terminalName: terminal.name,
      operatorId,
      operatorName,
      shiftId: fiscalShiftIdForReceipt(shift),
      currency,
      // Atomic seller identity (spec 2026-06-11 §4.6). This closes the
      // ACCOUNT_CHARGE gap: charges now carry the branch identity when the
      // terminal's location is fiscally complete (previously company-only).
      seller: resolveSellerIdentity(company, terminal?.location ?? null),
      customer: selectedCustomer,
      lines: cart.lines,
      vatBreakdown: cart.vatBreakdown,
      subtotal: cart.subtotal,
      vatTotal: cart.vatTotal,
      total: cart.total,
      transactionDiscountAmount: cart.transactionDiscountAmount,
      transactionDiscountReason: cart.transactionDiscountReason,
      isTraining: terminal.is_training_mode === true,
      balanceSnapshotStale,
      overrideApproval: options?.overrideApproval ?? null,
    }),
  );
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

    const totalEstimate = estimateCartTotal(cartItems, transactionDiscount, getActiveCurrency());
    if (bccomp(tenderedAmount, totalEstimate) < 0) {
      const msg = 'Cash tender tolerance requires a manager-authored tender tolerance override and is not available from quick cash checkout.';
      set({ error: msg });
      throw new Error(msg);
    }

    // T0.2: atomically allocate the idempotency key AND gate concurrent
    // entry. Same set() call flips isProcessing, allocates pendingIdempotencyKey
    // if null, and detects a concurrent call (Codex round-2 finding F-1
    // residual): if isProcessing is already true, a previous processX is
    // still running. The async `await getReceiptByIdempotencyKey` inside
    // createOfflineReceipt yields to the event loop, so two overlapping
    // calls could both observe no existing row before either INSERT runs —
    // one would then hit SQLITE_CONSTRAINT_UNIQUE. The gate prevents the
    // second call from reaching the SQLite layer at all. The UI also
    // disables the Confirm button while isProcessing is true (CashPaymentScreen
    // line 171), so this is the secondary guard.
    let idempotencyKey = '';
    let alreadyInFlight = false;
    set((state) => {
      if (state.isProcessing) {
        alreadyInFlight = true;
        return state;
      }
      idempotencyKey = state.pendingIdempotencyKey ?? crypto.randomUUID();
      return {
        isProcessing: true,
        error: null,
        pendingIdempotencyKey: idempotencyKey,
      };
    });
    if (alreadyInFlight) {
      // Concurrent processX call while another checkout is already running.
      // Silently no-op; the in-flight call will complete (success or error)
      // and the UI will reflect the result. The cashier can retry from a
      // stable state once the first attempt resolves.
      return;
    }

    try {
      const authState = useAuthStore.getState();
      const companyId = authState.companyId;
      const company = authState.companies.find((c) => c.id === companyId);
      const currency = company?.currency ?? 'EUR';
      const decimals = getCurrencyDecimals(currency);

      // Bug 2 fix — pos_receipt_payments.amount is the cashier's TENDERED
      // amount per the backend contract documented at
      // CashCountToleranceVarianceRegressionTest.php:30-40 ("The 'amount'
      // column already stores what the cashier physically tendered, so a
      // €100 receipt with €99.70 tender + €0.30 tolerance write-off
      // contributes +€99.70 to drawer cash"). Pre-fix this line wrote
      // totalEstimate (cart total), which:
      //   (a) made the print path's change derivation always zero
      //       (`Σ(payments.amount) − total` = 0 when amount = total) —
      //       this is Bug 2's headline symptom on the ticket; and
      //   (b) broke cash-variance reporting for any over-tender (phantom
      //       shortage of (tendered − total) on the shift close).
      // The advanced/multi-payment path already passes the per-tender
      // amount; the cash quick-path now matches that convention.
      const result = await createReceiptLocalFirst(
        set,
        terminalId,
        cartItems,
        [{
          methodCode: cashMethod.code,
          amount: bcformat(tenderedAmount, decimals),
          paymentMethodId: cashMethod.id,
          repositoryId: cashRegister.id,
        }],
        tenderedAmount,
        idempotencyKey,
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

    // T0.2: atomic key allocation + concurrent-call gate (see
    // processCashCheckout for rationale).
    let idempotencyKey = '';
    let alreadyInFlight = false;
    set((state) => {
      if (state.isProcessing) {
        alreadyInFlight = true;
        return state;
      }
      idempotencyKey = state.pendingIdempotencyKey ?? crypto.randomUUID();
      return {
        isProcessing: true,
        error: null,
        pendingIdempotencyKey: idempotencyKey,
      };
    });
    if (alreadyInFlight) return;

    try {
      const authState = useAuthStore.getState();
      const companyId = authState.companyId;
      const company = authState.companies.find((c) => c.id === companyId);
      const currency = company?.currency ?? 'EUR';
      const decimals = getCurrencyDecimals(currency);
      const totalEstimate = bcsum(cartItems.map((i) => i.line_total), decimals);

      await createReceiptLocalFirst(
        set,
        terminalId,
        cartItems,
        [{
          methodCode: cardMethod.code,
          amount: totalEstimate,
          paymentMethodId: cardMethod.id,
          repositoryId: cardRepo.id,
          cardLastFour: cardData?.lastFour,
          transactionReference: cardData?.reference,
        }],
        '0', // tenderedAmount = '0' for card (no cash in hand)
        idempotencyKey,
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
    options,
  ) => {
    // T0.2: atomic key allocation + concurrent-call gate (see
    // processCashCheckout for rationale).
    let idempotencyKey = '';
    let alreadyInFlight = false;
    set((state) => {
      if (state.isProcessing) {
        alreadyInFlight = true;
        return state;
      }
      idempotencyKey = state.pendingIdempotencyKey ?? crypto.randomUUID();
      return {
        isProcessing: true,
        error: null,
        pendingIdempotencyKey: idempotencyKey,
      };
    });
    if (alreadyInFlight) return;

    try {
      const authState = useAuthStore.getState();
      const companyId = authState.companyId;
      const company = authState.companies.find((c) => c.id === companyId);
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
          amount: bcformat(p.amount, decimals),
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

      // Sum the per-tender amounts exactly (Big.js) from the same
      // currency-scale strings that get persisted/hashed, so the tendered
      // total and the per-line `amount` strings cannot disagree.
      const tenderedAmountStr = bcsum(enriched.map((e) => e.amount), decimals);
      const totalEstimate = estimateCartTotal(cartItems, transactionDiscount, currency);
      let tenderToleranceEvidence: PosOverrideEvidence | undefined;

      if (bccomp(tenderedAmountStr, totalEstimate) < 0) {
        const pin = options?.tenderTolerancePin?.trim() ?? '';
        if (pin === '') {
          const msg = 'Tender tolerance requires a manager PIN.';
          set({ error: msg });
          throw new Error(msg);
        }

        const operatorState = useOperatorStore.getState();
        const terminalState = useTerminalStore.getState();
        const operator = operatorState.operator;
        const operatorId = operator?.id ?? authState.user?.id;
        if (!operatorId || !authState.user?.tenantId || !companyId || !terminalState.terminal) {
          throw new Error(i18n.t('errors.noOperatorIdentified', { ns: 'pos' }));
        }

        const businessDate = new Date().toISOString().slice(0, 10);
        // totalEstimate is already at `decimals` scale (returned by estimateCartTotal).
        const totalEstimateStr = totalEstimate;
        // Exact shortfall = total − tendered, clamped at 0 (Big.js).
        const rawShortfall = bcsub(totalEstimateStr, tenderedAmountStr, decimals);
        const shortfall = bccomp(rawShortfall, '0') < 0 ? (0).toFixed(decimals) : rawShortfall;
        const target = {
          tenant_id: authState.user.tenantId,
          company_id: companyId,
          terminal_id: terminalId,
          target_event_type: 'SALE_RECEIPT',
          target_reference_id: idempotencyKey,
          total_amount: totalEstimateStr,
          tendered_amount: tenderedAmountStr,
          shortfall_amount: shortfall,
          currency,
        };
        const supervisor = await verifyScopedManagerPin({
          pin,
          context: {
            tenantId: authState.user.tenantId,
            companyId,
            terminalId,
            cashierUserId: operatorId,
            businessDate,
            isTraining: terminalState.terminal.is_training_mode === true,
          },
          approvalScope: 'tender_tolerance_override',
          targetEventType: 'SALE_RECEIPT',
          targetReferenceId: idempotencyKey,
          reason: 'Tender tolerance override',
        });

        tenderToleranceEvidence = await authorPosOverride({
          context: {
            tenantId: authState.user.tenantId,
            companyId,
            terminalId,
            cashierUserId: operatorId,
            businessDate,
            isTraining: terminalState.terminal.is_training_mode === true,
          },
          supervisor,
          approvalScope: 'tender_tolerance_override',
          targetEventType: 'SALE_RECEIPT',
          targetReferenceId: idempotencyKey,
          target,
          policyVersion: 'pos-phase-4-v1',
          reasonCode: 'tender_tolerance_shortfall',
          reasonText: `Tendered ${tenderedAmountStr} against ${totalEstimateStr}`,
        });
      }

      const result = await createReceiptLocalFirst(
        set,
        terminalId,
        cartItems,
        enriched,
        tenderedAmountStr,
        idempotencyKey,
        transactionDiscount,
        consumptionMode,
        tableId,
        tenderToleranceEvidence,
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

  processAccountPayment: async (terminalId, amount, options) => {
    const { paymentMethods, paymentRepositories, selectedCustomer } = get();
    if (!selectedCustomer) {
      const msg = 'Customer is required for account payment.';
      set({ error: msg });
      throw new Error(msg);
    }

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

    let alreadyInFlight = false;
    set((state) => {
      if (state.isProcessing) {
        alreadyInFlight = true;
        return state;
      }
      return { isProcessing: true, error: null };
    });
    if (alreadyInFlight) return null;

    try {
      const result = await createAccountPaymentLocalFirst(
        terminalId,
        amount,
        selectedCustomer,
        cashMethod,
        cashRegister,
        options,
      );
      const snapshot = result.payload.local_balance_snapshot;
      set({
        isProcessing: false,
        changeDue: 0,
        lastReceipt: {
          id: result.fiscalEventId,
          receipt_number: result.receiptNumber,
          total: result.total,
          subtotal: '0',
          tax_amount: '0',
          discount_amount: '0',
          currency: result.currency,
        } satisfies CreateReceiptResponse,
        lastReceiptIdempotencyKey: null,
        lastReceiptServerId: null,
        lastReceiptPrintData: result.printableData,
        selectedCustomer: {
          ...selectedCustomer,
          receivable_balance: snapshot.projected_receivable_balance_after,
          credit_balance: snapshot.projected_credit_balance_after,
          balance_updated_at: result.payload.event_time_device,
        },
      });
      return result;
    } catch (error) {
      console.error('[POS][account-payment] failed', {
        ...serializeErrorForLog(error),
        terminalId,
        customerId: selectedCustomer.id,
      });
      set({
        isProcessing: false,
        error: formatCheckoutError(error),
      });
      throw error;
    }
  },

  processAccountCharge: async (terminalId, options) => {
    const { selectedCustomer } = get();
    if (!selectedCustomer) {
      const msg = i18n.t('account_charge.errors.customer_required', {
        ns: 'pos',
        defaultValue: 'Customer is required for account charge.',
      });
      set({ error: msg });
      throw new Error(msg);
    }
    const enabled =
      selectedCustomer.charge_account_enabled === true ||
      selectedCustomer.charge_account_enabled === 1;
    if (!enabled) {
      const msg = i18n.t('account_charge.errors.not_enabled', {
        ns: 'pos',
        defaultValue: 'This customer is not enabled for account charge.',
      });
      set({ error: msg });
      throw new Error(msg);
    }

    let alreadyInFlight = false;
    set((state) => {
      if (state.isProcessing) {
        alreadyInFlight = true;
        return state;
      }
      return { isProcessing: true, error: null };
    });
    if (alreadyInFlight) return null;

    try {
      const result = await createAccountChargeLocalFirst(terminalId, selectedCustomer, options);
      const snapshot = result.payload.local_balance_snapshot;
      set({
        isProcessing: false,
        changeDue: 0,
        lastReceipt: {
          id: result.fiscalEventId,
          receipt_number: result.accountChargeUuid,
          total: result.total,
          subtotal: '0',
          tax_amount: '0',
          discount_amount: '0',
          currency: result.currency,
        } satisfies CreateReceiptResponse,
        lastReceiptIdempotencyKey: null,
        lastReceiptServerId: null,
        lastReceiptPrintData: buildEscPosAccountChargeReceiptData({
          payload: result.printable,
          currencyCode: result.currency,
        }),
        selectedCustomer: {
          ...selectedCustomer,
          receivable_balance: snapshot.projected_receivable_balance_after,
          credit_balance: snapshot.projected_credit_balance_after,
          balance_updated_at: result.payload.event_time_device,
        },
      });
      // authorAccountCharge already increments the pending count and triggers a
      // sync internally — do NOT re-trigger here.
      useCartStore.getState().clearCart();
      // On Account is mutually exclusive with tenders: any voucher tenders the
      // cashier entered before switching to charge mode must not survive into
      // the next sale. Mirror the canonical voucher-clear used elsewhere.
      get().clearVoucherTenders();
      return result;
    } catch (error) {
      console.error('[POS][account-charge] failed', {
        ...serializeErrorForLog(error),
        terminalId,
        customerId: selectedCustomer.id,
      });
      set({
        isProcessing: false,
        error: formatCheckoutError(error),
      });
      throw error;
    }
  },

  clearLastReceipt: () => {
    // T0.2: also clear pendingIdempotencyKey so the next sale gets a fresh
    // key. This is the canonical post-success / new-sale lifecycle hook
    // (called by HomePage's handleNewSale). Leaving the key populated would
    // cause the new sale's first POST to be deduped server-side as a replay
    // of the previous sale.
    set({
      lastReceipt: null,
      pendingReceiptId: null,
      changeDue: 0,
      lastReceiptIdempotencyKey: null,
      lastReceiptServerId: null,
      lastReceiptPrintData: null,
      pendingIdempotencyKey: null,
      selectedCustomer: null,
    });
  },

  discardPendingSubmission: () => {
    // T0.2 (Codex F-2): narrower discard than clearLastReceipt. Only resets
    // the in-flight idempotency key — leaves `lastReceipt` /
    // `lastReceiptIdempotencyKey` (post-success print state) intact. Called
    // by void-cart, hold-recall, and shift-close paths so a stale key from
    // an aborted/voided attempt cannot leak into the next sale's first POST.
    set({ pendingIdempotencyKey: null });
  },

  refreshFromSQLite: async () => {
    try {
      const db = await getDb();
      const [methods, repositories] = await Promise.all([
        getAllPaymentMethods(db),
        getAllPaymentRepositories(db),
      ]);
      // Empty SQLite = unseeded local state. Bail rather than wiping in-
      // memory state to []. The startup `fetchPaymentConfig()` path runs
      // an API fallback when SQLite is empty; no need to clobber whatever
      // it loaded.
      if (methods.length === 0) return;

      // T1.2 (Codex Phase-2 prompt 2.1 item 4 — deferred from T0.5):
      // skip the set entirely when both arrays are observably unchanged.
      // Without this, every 60 s scheduler tick creates new array
      // references and forces every Zustand subscriber to re-render —
      // a render-budget bug, not a correctness bug. Per-row shallow
      // compare on all primitive fields catches all observable changes
      // (id, name, position, is_active, fee fields, etc.); id-set
      // equality alone would miss in-place renames or fee changes.
      // getAllPaymentMethods filters WHERE is_active = 1, so an
      // active→inactive flip shrinks the array and the length check
      // catches it before per-row compare runs.
      const current = get();
      if (
        paymentMethodsShallowEqual(current.paymentMethods, methods) &&
        paymentRepositoriesShallowEqual(current.paymentRepositories, repositories)
      ) {
        return;
      }

      // Atomic single-set so the UI never sees an intermediate state with
      // methods populated and repositories still empty (or vice versa).
      set({ paymentMethods: methods, paymentRepositories: repositories });
    } catch (error) {
      // T0.1 contract: never spread the raw `error` reference into a
      // console payload — `serializeErrorForLog` returns a flat bounded
      // ErrorLogPayload that's safe for crash reports.
      console.error('[POS][paymentStore][refreshFromSQLite] failed', {
        ...serializeErrorForLog(error),
        cachedMethodCount: get().paymentMethods.length,
        cachedRepositoryCount: get().paymentRepositories.length,
      });
    }
  },

  addVoucherPayment: (code: string, amount: string) => {
    const { appliedVoucherCodes } = get();
    if (appliedVoucherCodes.has(code)) {
      // Best-effort audit emit OUTSIDE any set() — the idempotent guard caught
      // a re-add of an already-applied voucher (double-spend attempt). The
      // voucher code is the aggregate id; it is NOT a secret. Never log PAN /
      // card data here.
      void recordAuditEvent({
        type: 'pos.voucher_double_spend_attempt',
        aggregateType: 'Voucher',
        aggregateId: code,
        payload: {
          attempted_amount: amount,
          caught_by: 'idempotent_check',
        },
      }).catch(() => {});
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

  attachCustomer: (customer: AttachedCheckoutCustomer) => {
    assertAttachedCustomerScope(customer);
    // Snapshot the checkout aggregate id BEFORE the set() for the audit emit.
    const aggregateId = checkoutAggregateId() ?? customer.id;
    customerAttachedAt = Date.now();
    set({ selectedCustomer: customer });

    void recordAuditEvent({
      type: 'pos.customer_attached',
      aggregateType: 'Checkout',
      aggregateId,
      payload: { customer_id: customer.id },
    }).catch(() => {});
  },

  detachCustomer: () => {
    // Snapshot the detaching customer + attach time BEFORE the set().
    const detaching = get().selectedCustomer;
    const attachedAt = customerAttachedAt;
    const aggregateId = checkoutAggregateId() ?? detaching?.id ?? null;
    customerAttachedAt = null;
    set({ selectedCustomer: null });

    if (detaching) {
      void recordAuditEvent({
        type: 'pos.customer_detached',
        aggregateType: 'Checkout',
        aggregateId: aggregateId ?? detaching.id,
        payload: {
          customer_id: detaching.id,
          // null when attach time is unknown (e.g. customer hydrated into
          // state without going through attachCustomer this session).
          attached_duration_ms: attachedAt !== null ? Date.now() - attachedAt : null,
        },
      }).catch(() => {});
    }
  },

  reset: () => {
    set(initialState);
  },
}));
