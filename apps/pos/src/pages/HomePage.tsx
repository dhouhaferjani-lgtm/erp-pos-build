import { useState, useEffect, useMemo, useCallback, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { useTerminalStore } from '@/stores/terminalStore';
import { useAuthStore } from '@/stores/authStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useProductStore } from '@/stores/productStore';
import { useCartStore } from '@/stores/cartStore';
import { usePaymentStore } from '@/stores/paymentStore';
import type { AccountChargeOverrideApprovalInput } from '@/lib/accountCharge/accountChargeService';
import { useHoldStore } from '@/stores/holdStore';
import { useScannerStore } from '@/stores/scannerStore';
import { useBarcodeScanner } from '@/hooks/useBarcodeScanner';
import { useRefundFlowStore } from '@/stores/refundFlowStore';
import { useRefundDraftStore } from '@/stores/refundDraftStore';
import { dispatchScan } from '@/lib/scan/dispatcher';
import { resolveScannedCode } from '@/lib/scan/resolveScannedCode';
import { setCachedScan } from '@/lib/scan/scanResolutionCache';
import { BarcodeChooserModal } from '@/components/molecules/BarcodeChooserModal/BarcodeChooserModal';
import { getDatabase } from '@/lib/db';
import { getOfflineReceiptById } from '@/lib/db/repositories/offlineReceiptRepository';
import { deleteRefundDraft } from '@/lib/db/repositories/refundDraftRepository';
import { hydrateFromReceipt } from '@/lib/refundFlow/hydrateFromReceipt';
import {
  decidePayInterception,
  mustBlockMidModalSettlement,
} from '@/lib/refundFlow/cartClassification';
import { useRefundCheckoutStore } from '@/stores/refundCheckoutStore';
import { RefundCheckoutFlow } from '@/components/pos/RefundCheckoutFlow';
import type { ReturnSettlementResponse } from '@/lib/refundFlow/refundSettlementService';
import { printRefundSettlementArtifacts } from '@/lib/refundFlow/refundReceiptPrinting';
import { ReceiptScanConfirmationSheet } from '@/components/pos/ReceiptScanConfirmationSheet';
import { ReceiptLocatorScreen } from '@/components/pos/ReceiptLocatorScreen';
import { ResumeRefundDraftBanner } from '@/components/pos/ResumeRefundDraftBanner';
import { CustomerAttachPanel } from '@/components/customers/CustomerAttachPanel';
import { MoneyInput } from '@/components/atoms/MoneyInput';
import { getErrorMessage } from '@/lib/api';
import { useCurrency } from '@/lib/currency';
import { resolveDiscountAccess } from '@/lib/discountPermissions';
import { fetchReceipt } from '@/api/receiptApi';
import { buildEscPosReceiptData } from '@/lib/buildReceiptData';
import type { ReceiptVisibilitySettings } from '@/lib/buildReceiptData';
import type { ReceiptData } from '@/lib/printing';
import { hasModule } from '@/stores/productStore';
import { ChainBreakAlert } from '@/components/atoms/ChainBreakAlert';
import { TerminalNotReadyBanner } from '@/components/atoms/TerminalNotReadyBanner';
import { ConsumptionModeToggle } from '@/components/atoms/ConsumptionModeToggle';
import { TableSelector } from '@/components/atoms/TableSelector';
import { ProductGrid } from '@/components/organisms/ProductGrid';
import { TransactionCart } from '@/components/organisms/TransactionCart';
import { CashPaymentScreen } from '@/components/organisms/CashPaymentScreen';
import { CheckoutSuccessModal } from '@/components/organisms/CheckoutSuccessModal';
import { AdvancedPaymentsModal } from '@/components/organisms/AdvancedPaymentsModal';
import { HeldTransactionsModal } from '@/components/organisms/HeldTransactionsModal';
import { DiscountModal } from '@/components/organisms/DiscountModal';
import { LineDiscountModal } from '@/components/organisms/LineDiscountModal';
import { ModifierSelectionModal } from '@/components/organisms/ModifierSelectionModal';
import { VariantPickerModal } from '@/components/pos/VariantPickerModal';
import { VoidReturnModal } from '@/components/organisms/VoidReturnModal';
import { QuantityNumpad } from '@/components/organisms/QuantityNumpad';
import { useSmartPromptsStore } from '@/stores/smartPromptsStore';
import { ToastSmartPrompts } from '@/components/organisms/ToastSmartPrompts';
import { apiGet } from '@/lib/api';
import { serializeErrorForLog } from '@/lib/errorLogging';
import { useSettingsStore } from '@/stores/settingsStore';
import type { ConsumptionMode } from '@/components/atoms/ConsumptionModeToggle';
import type { POSProduct, POSProductVariant } from '@/types/product';
import type { SelectedModifier } from '@/types/cart';
import type { PosOverrideEvidence } from '@/lib/operatorApproval/posOverrideAuthoring';

/**
 * Look up the receipt's signed QR token from the local SQLite index.
 *
 * The token format is `v:kid:receipt_uuid:mac`. When absent (offline-issued
 * receipt whose token has not been signed back yet), callers should treat the
 * value as null — the printer omits the QR section gracefully.
 *
 * @param receiptNumber  The receipt number to look up (e.g. "R-T1-2026-00000001").
 * @param companyId      The active company ID used to scope the local DB instance.
 */
async function lookupQrToken(receiptNumber: string, companyId: string | null): Promise<string | null> {
  try {
    if (!companyId) return null;
    const db = await getDatabase(companyId);
    const { findReceiptByNumber } = await import('@/lib/offline/voucherRepository');
    const entry = await findReceiptByNumber(db, receiptNumber);
    return entry?.qr_token ?? null;
  } catch (lookupErr) {
    console.warn('[POS] QR-token lookup failed:', lookupErr);
    return null;
  }
}

export function HomePage() {
  const { t } = useTranslation();
  const { currency } = useCurrency();
  const { shift, terminal, openShift, isLoading: terminalLoading } = useTerminalStore();
  const hashChainReady = useTerminalStore((s) => s.hashChainReady);
  const operator = useOperatorStore((s) => s.operator);
  const [openingCash, setOpeningCash] = useState('0.00');
  const [shiftError, setShiftError] = useState<string | null>(null);

  // Product store
  const companyConfig = useProductStore((s) => s.companyConfig);
  const isFnB = hasModule(companyConfig, 'Menu');
  const products = useProductStore((s) => s.products);
  const categories = useProductStore((s) => s.categories);
  const productsLoading = useProductStore((s) => s.isLoading);
  const fetchProducts = useProductStore((s) => s.fetchProducts);

  // Cart store
  const cartItems = useCartStore((s) => s.items);
  const transactionDiscount = useCartStore((s) => s.transactionDiscount);
  const addItem = useCartStore((s) => s.addItem);
  const addItemWithDefaults = useCartStore((s) => s.addItemWithDefaults);
  const updateLineModifiers = useCartStore((s) => s.updateLineModifiers);
  const updateQuantity = useCartStore((s) => s.updateQuantity);
  const removeItem = useCartStore((s) => s.removeItem);
  const clearCart = useCartStore((s) => s.clearCart);
  const subtotal = useCartStore((s) => s.subtotal);
  const taxAmount = useCartStore((s) => s.taxAmount);
  const discountAmount = useCartStore((s) => s.discountAmount);
  const total = useCartStore((s) => s.total);
  const itemCount = useCartStore((s) => s.itemCount);

  // Smart Prompts
  const spRecommendations = useSmartPromptsStore((s) => s.recommendations);
  const spIsLoading = useSmartPromptsStore((s) => s.isLoading);
  const spContextFields = useSmartPromptsStore((s) => s.contextFields);
  const spSkinType = useSmartPromptsStore((s) => s.skinType);
  const spFetchForCart = useSmartPromptsStore((s) => s.fetchForCart);
  const spSetSkinType = useSmartPromptsStore((s) => s.setSkinType);
  const spClear = useSmartPromptsStore((s) => s.clear);
  const smartPromptsVariant = companyConfig?.smart_prompts_variant ?? 'off';

  // Payment store
  const fetchPaymentConfig = usePaymentStore((s) => s.fetchPaymentConfig);
  const paymentMethods = usePaymentStore((s) => s.paymentMethods);
  const processCashCheckout = usePaymentStore((s) => s.processCashCheckout);
  const processAdvancedCheckout = usePaymentStore((s) => s.processAdvancedCheckout);
  const processAccountCharge = usePaymentStore((s) => s.processAccountCharge);
  const paymentRepositories = usePaymentStore((s) => s.paymentRepositories);
  const isProcessing = usePaymentStore((s) => s.isProcessing);
  const lastReceipt = usePaymentStore((s) => s.lastReceipt);
  const changeDue = usePaymentStore((s) => s.changeDue);
  const clearLastReceipt = usePaymentStore((s) => s.clearLastReceipt);
  const lastReceiptIdempotencyKey = usePaymentStore((s) => s.lastReceiptIdempotencyKey);
  const lastReceiptServerId = usePaymentStore((s) => s.lastReceiptServerId);
  const lastReceiptPrintData = usePaymentStore((s) => s.lastReceiptPrintData);
  const paymentError = usePaymentStore((s) => s.error);

  // Hold store
  const heldTransactions = useHoldStore((s) => s.heldTransactions);
  const holdCurrentCart = useHoldStore((s) => s.holdCurrentCart);
  const recallTransaction = useHoldStore((s) => s.recallTransaction);
  const discardTransaction = useHoldStore((s) => s.discardTransaction);
  const loadHeldTransactions = useHoldStore((s) => s.loadHeldTransactions);

  // Codex review B5 (2026-05-01): SQLite handle for VoucherTenderModal's
  // local-first lookup. AdvancedPaymentsModal mounts VoucherTenderModal
  // when an instrument-bearing tile is tapped; the modal reads vouchers
  // from this handle via `findByCode(db, code)`. Loaded once when
  // companyId is known and reused across modal opens (getDatabase is
  // idempotent for the same companyId — it returns the cached handle).
  const companyIdForVoucherDb = useAuthStore((s) => s.companyId);
  const activeCompanyId = useAuthStore((s) => s.companyId);
  const activeTenantId = useAuthStore((s) => s.user?.tenantId ?? null);
  const activeUserId = useAuthStore((s) => s.user?.id ?? null);
  const approvalContext = useMemo(() => {
    if (!activeTenantId || !activeCompanyId || !terminal) return undefined;
    const cashierUserId = operator?.id ?? activeUserId;
    if (!cashierUserId) return undefined;

    return {
      tenantId: activeTenantId,
      companyId: activeCompanyId,
      terminalId: terminal.id,
      cashierUserId,
      businessDate: new Date().toISOString().slice(0, 10),
      isTraining: terminal.is_training_mode === true,
    };
  }, [activeTenantId, activeCompanyId, terminal, operator?.id, activeUserId]);
  // Fix 7e: the refund checkout flow needs a real cashier identity for the
  // approval/audit trail. With neither an operator nor an auth user, the flow
  // is NOT mounted (instead of being handed an empty-string id).
  const refundCashierUserId = operator?.id ?? activeUserId ?? null;
  useEffect(() => {
    if (refundCashierUserId === null) {
      console.error(
        '[refundFlow] no cashier identity (operator or auth user) — refund checkout UI not mounted',
      );
    }
  }, [refundCashierUserId]);
  const [voucherDb, setVoucherDb] = useState<import('@tauri-apps/plugin-sql').default | null>(null);
  useEffect(() => {
    let cancelled = false;
    if (!companyIdForVoucherDb) {
      setVoucherDb(null);
      return () => {
        cancelled = true;
      };
    }
    void (async () => {
      try {
        const db = await getDatabase(companyIdForVoucherDb);
        if (!cancelled) setVoucherDb(db);
      } catch (err) {
        console.warn('[POS] Voucher DB handle unavailable; voucher tender flow will fall back to dead-end message:', err);
        if (!cancelled) setVoucherDb(null);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [companyIdForVoucherDb]);

  // Modal state
  const [showCashModal, setShowCashModal] = useState(false);
  const [showSuccessModal, setShowSuccessModal] = useState(false);
  const [showAdvancedModal, setShowAdvancedModal] = useState(false);
  const [showHeldModal, setShowHeldModal] = useState(false);
  const [showDiscountModal, setShowDiscountModal] = useState(false);
  const [showVoidReturnModal, setShowVoidReturnModal] = useState(false);
  // Receipt locator screen — Returns / Exchange entry point (Task 51)
  const [showReceiptLocator, setShowReceiptLocator] = useState(false);
  const [quantityEditItemId, setQuantityEditItemId] = useState<string | null>(null);

  // ESC/POS receipt data for thermal printing
  const [escPosData, setEscPosData] = useState<ReceiptData | null>(null);
  const [escPosSource, setEscPosSource] = useState<'local' | 'server' | null>(null);

  // Modifier selection state
  const [modifierProduct, setModifierProduct] = useState<POSProduct | null>(null);
  const [editingLineId, setEditingLineId] = useState<string | null>(null);

  // T2 — variant picker state: the product whose variants the cashier is
  // currently choosing from (null when the picker is closed).
  const [variantPickerProduct, setVariantPickerProduct] = useState<POSProduct | null>(null);

  // Consumption mode + table selection (F&B only)
  const [consumptionMode, setConsumptionMode] = useState<ConsumptionMode>('SUR_PLACE');
  const [selectedTableId, setSelectedTableId] = useState<string | null>(null);

  // Settings
  const cartPosition = useSettingsStore((s) => s.cartPosition);

  // Line discount state
  const [discountItemId, setDiscountItemId] = useState<string | null>(null);

  // Operator discount permissions
  const transactionDiscountAccess = resolveDiscountAccess(
    operator,
    terminal?.max_discount_percent,
    terminal?.allow_transaction_discounts,
    operator?.can_apply_transaction_discounts,
  );
  const lineDiscountAccess = resolveDiscountAccess(
    operator,
    terminal?.max_discount_percent,
    terminal?.allow_line_discounts,
    operator?.can_apply_line_discounts,
  );

  // Barcode scanner
  const [scanMessage, setScanMessage] = useState<{ text: string; type: 'success' | 'error' | 'info' } | null>(null);

  // T2.1 Step B — chooser modal state for collision UX (>1 product matches a code).
  const [chooserState, setChooserState] = useState<{
    scannedCode: string;
    candidates: POSProduct[];
  } | null>(null);

  // T2.1 Step B — abort controller for in-flight scan resolution. A new
  // scan aborts any prior in-flight call so the cashier doesn't get a
  // stale "looking up..." spinner from a superseded scan.
  const scanControllerRef = useRef<AbortController | null>(null);

  // Refund-flow scan dispatcher state (Task 50). The pending entry drives
  // the Receipt-Scan Confirmation Sheet; cart is NEVER mutated here.
  const pendingScanResult = useRefundFlowStore((s) => s.pendingScanResult);
  const setPendingScanResult = useRefundFlowStore((s) => s.setPendingScanResult);
  const acceptPendingScan = useRefundFlowStore((s) => s.acceptPendingScan);
  // Bug 1 fix: subscribe via selector so React sees each new token value
  // and re-fires the hydration effect when a second scan arrives in the same session.
  const acceptedReceiptToken = useRefundFlowStore((s) => s.acceptedReceiptToken);

  // Refund draft store (Task 52) — persist/restore in-progress refund carts.
  const loadDraft = useRefundDraftStore((s) => s.loadDraft);
  const persistDraft = useRefundDraftStore((s) => s.persistDraft);
  const discardDraftAction = useRefundDraftStore((s) => s.discardDraft);
  const existingDraft = useRefundDraftStore((s) => s.draft);
  const clearDraftState = useRefundDraftStore((s) => s.clearDraftState);

  // Active refund context: which receipt is being refunded (Task 52).
  const [activeRefundReceiptUuid, setActiveRefundReceiptUuid] = useState<string | null>(null);
  const [activeRefundReceiptNumber, setActiveRefundReceiptNumber] = useState<string | null>(null);
  // Task 2b: signed QR token of the receipt being refunded (when the session
  // started from a scan). Improves the settlement's local→server resolution;
  // null for resumed drafts — the receipt number is the fallback identity.
  const [activeRefundReceiptToken, setActiveRefundReceiptToken] = useState<string | null>(null);
  const [activeRefundDraftId, setActiveRefundDraftId] = useState<string | null>(null);
  const [exchangeRequestId, setExchangeRequestId] = useState<string | null>(null);
  const [detailsNotLocalWarning, setDetailsNotLocalWarning] = useState(false);

  /**
   * Add a resolved product to the cart with the success-toast UX.
   * Shared by Tier 1/2/3 hits and chooser-modal picks.
   */
  const addProductToCartWithToast = useCallback(
    (product: POSProduct) => {
      const { autoAddToCart } = useScannerStore.getState();
      if (autoAddToCart) {
        addItem(product);
        setScanMessage({ text: t('barcode.productAdded', { name: product.name }), type: 'success' });
        setTimeout(() => setScanMessage(null), 2000);
      }
    },
    [addItem, t],
  );

  /**
   * T2.1 Step B — three-tier scan resolution. Replaces the in-memory-only
   * find with `resolveScannedCode(code, { db, products, signal })`:
   *
   *   1. In-memory `productStore.products` (preserved barcode OR sku match).
   *   2. SQLite `getProductByBarcode(db, code)` — covers the cold-start
   *      window where SQLite has the product but the in-memory snapshot
   *      doesn't yet.
   *   3. API `fetchProductByBarcode(code, { timeoutMs: 5000, signal })` —
   *      covers the case where neither tier has the product (e.g. brand-new
   *      SKU never synced down). Single result auto-picks; multi-result
   *      mounts the BarcodeChooserModal for the cashier to resolve.
   *
   * Concurrent-scan handling: each call aborts the prior in-flight scan's
   * AbortController so a rapid re-scan doesn't leave a stale "looking up..."
   * spinner attached to a superseded code.
   */
  const handleProductBarcode = useCallback(
    (barcode: string) => {
      // Abort any prior in-flight scan.
      scanControllerRef.current?.abort();
      const controller = new AbortController();
      scanControllerRef.current = controller;

      const companyId = useAuthStore.getState().companyId;
      // No tenant context → fall back to in-memory only (prevents a
      // pre-auth crash from racing the cold-start path).
      if (!companyId) {
        const product = products.find(
          (p) => p.barcode === barcode || p.sku === barcode,
        );
        if (!product) {
          setScanMessage({ text: t('barcode.productNotFound', { code: barcode }), type: 'error' });
          setTimeout(() => setScanMessage(null), 3000);
          return;
        }
        addProductToCartWithToast(product);
        return;
      }

      void (async () => {
        // Show a transient "Looking up..." indicator so the cashier
        // knows the system is working during Tier 3 (up to 5s on a
        // slow network). Cleared on result settle (success or miss).
        setScanMessage({ text: t('barcode.lookingUp'), type: 'info' });

        try {
          const db = await getDatabase(companyId);
          const result = await resolveScannedCode(barcode, {
            db,
            products,
            companyId,
            signal: controller.signal,
          });

          // Drop the result if a subsequent scan superseded this one.
          if (controller.signal.aborted) return;

          if (result.kind === 'miss') {
            setScanMessage({ text: t('barcode.productNotFound', { code: barcode }), type: 'error' });
            setTimeout(() => setScanMessage(null), 3000);
            return;
          }
          if (result.kind === 'choose') {
            setScanMessage(null);
            setChooserState({ scannedCode: barcode, candidates: result.candidates });
            return;
          }
          // result.kind === 'hit'
          addProductToCartWithToast(result.product);
        } catch (err) {
          if (controller.signal.aborted) return;
          console.error(
            '[POS][HomePage] scan resolution failed',
            serializeErrorForLog(err),
          );
          setScanMessage({ text: t('barcode.productNotFound', { code: barcode }), type: 'error' });
          setTimeout(() => setScanMessage(null), 3000);
        }
      })();
    },
    [products, addProductToCartWithToast, t],
  );

  /**
   * Wrapped scan handler (Phase H Task 50, spec §6.1 entry 2):
   *   1. If terminal + companyId are present, run `dispatchScan` first.
   *   2. On `'receipt-token'` kind, set the pending state — the
   *      Receipt-Scan Confirmation Sheet renders and waits for the cashier.
   *      The cart is NOT mutated here.
   *   3. On `'fallthrough'` (or when prerequisites are missing), call the
   *      existing product-barcode logic unchanged.
   */
  const handleBarcodeScan = useCallback(
    (barcode: string) => {
      // Read both companyId and terminalId from store getState() at scan-time
      // (not from the captured React state) so a stale closure on the active
      // terminal can't misroute scans after a terminal switch. Using
      // getState() here mirrors how companyId is read and keeps the snapshot
      // symmetric across the two prerequisites.
      const companyId = useAuthStore.getState().companyId;
      const terminalId = useTerminalStore.getState().terminal?.id ?? null;
      if (!companyId || !terminalId) {
        handleProductBarcode(barcode);
        return;
      }
      void (async () => {
        try {
          const db = await getDatabase(companyId);
          const result = await dispatchScan({ token: barcode, db, terminalId });
          if (result.kind === 'receipt-token') {
            setPendingScanResult(result.entry);
            return;
          }
          handleProductBarcode(barcode);
        } catch (error) {
          // Local SQLite failure → surface a translated toast so the cashier
          // gets feedback (otherwise they'd see the dispatch silently miss
          // and then "product not found" a moment later, which is confusing).
          // After surfacing, we still fall through to product lookup so the
          // cashier is never stuck — the receipt-token path is opportunistic.
          console.error('[POS] scan dispatcher failed, falling back to product lookup:', error);
          setScanMessage({ text: t('receiptScan.dispatchError'), type: 'error' });
          setTimeout(() => setScanMessage(null), 3000);
          handleProductBarcode(barcode);
        }
      })();
    },
    [handleProductBarcode, setPendingScanResult, t],
  );

  useBarcodeScanner({
    onScan: handleBarcodeScan,
    enabled: !!shift,
  });

  // Fetch products and payment config when shift is open. Also refresh
  // companyConfig so a session that started before a server-side config
  // change (e.g., the smart_prompts_variant migration) picks up the new
  // values without requiring a full app restart.
  useEffect(() => {
    if (shift) {
      void fetchProducts();
      void fetchPaymentConfig();
      void useAuthStore.getState().refreshCompanyConfig();
    }
  }, [shift, fetchProducts, fetchPaymentConfig]);

  // Hydrate held transactions from SQLite on mount
  useEffect(() => {
    void loadHeldTransactions();
  }, [loadHeldTransactions]);

  // Task 52: Load refund draft when shift is open so we can offer resume.
  useEffect(() => {
    if (!shift) return;
    const companyId = useAuthStore.getState().companyId;
    const terminalId = useTerminalStore.getState().terminal?.id ?? null;
    if (!companyId || !terminalId) return;
    void loadDraft(companyId, terminalId);
  }, [shift, loadDraft]);

  // Concern #1: Reset refund local state when shift closes or changes.
  // The SQLite refund_drafts row is intentionally NOT deleted so the legitimate
  // operator can resume after restart — only the in-memory projection is cleared.
  useEffect(() => {
    if (shift !== null) return; // only fire on close/absence
    setActiveRefundReceiptUuid(null);
    setActiveRefundReceiptNumber(null);
    setActiveRefundReceiptToken(null);
    setActiveRefundDraftId(null);
    setExchangeRequestId(null);
    setPendingScanResult(null);
    setDetailsNotLocalWarning(false);
    useRefundCheckoutStore.getState().reset();
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [shift]);

  // Concern #1: Reset refund local state when operator switches.
  // Prevents Operator A's pending sheet from leaking into Operator B's session.
  useEffect(() => {
    if (operator !== null) return; // only fire on operator clear
    setActiveRefundReceiptUuid(null);
    setActiveRefundReceiptNumber(null);
    setActiveRefundReceiptToken(null);
    setActiveRefundDraftId(null);
    setExchangeRequestId(null);
    setPendingScanResult(null);
    setDetailsNotLocalWarning(false);
    useRefundCheckoutStore.getState().reset();
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [operator?.id]);

  // Task 52: Consume the acceptedReceiptToken (emitted by Task 50 dispatcher
  // or Task 51 locator). Runs ONCE per token — idempotent via consume+clear.
  // Bug 1 fix: use the `acceptedReceiptToken` selector (subscribed above) in the
  // dep array so React re-fires this effect whenever a new token is set, even
  // within the same session.
  useEffect(() => {
    // The selector value drives the dep array; use it as the early-exit guard.
    const token = acceptedReceiptToken;
    if (token === null) return;

    // Consume atomically (read + clear). A second re-render will not re-fire.
    const event = useRefundFlowStore.getState().consumeAcceptedReceiptToken();
    if (event === null) return;

    // Prevent re-hydrating an already-active refund session.
    if (activeRefundReceiptUuid === event.receiptUuid) return;

    const companyId = useAuthStore.getState().companyId;
    if (!companyId) return;

    void (async () => {
      try {
        const db = await getDatabase(companyId);
        const receipt = await getOfflineReceiptById(db, event.receiptUuid);

        if (receipt === null) {
          // Receipt not in local SQLite — likely synced + pruned or was from
          // a different terminal's DB. Surface a warning message.
          setDetailsNotLocalWarning(true);
          return;
        }

        const returnItems = hydrateFromReceipt(event, receipt);

        // Atomically replace return items in the cart.
        useCartStore.getState().replaceReturnItems(returnItems);
        setActiveRefundReceiptUuid(event.receiptUuid);
        setActiveRefundReceiptNumber(event.receiptNumber);
        setActiveRefundReceiptToken(event.receiptToken);
        setDetailsNotLocalWarning(false);

        // Persist draft immediately so a crash/close can restore.
        const terminalId = useTerminalStore.getState().terminal?.id ?? '';
        const operatorId = useOperatorStore.getState().operator?.id ?? '';
        const draftId = activeRefundDraftId ?? crypto.randomUUID();
        setActiveRefundDraftId(draftId);

        await persistDraft(companyId, {
          id: draftId,
          terminalId,
          operatorId,
          receiptUuid: event.receiptUuid,
          receiptNumber: event.receiptNumber,
          returnItems,
          buyingItems: useCartStore.getState().saleItems(),
          transactionDiscount: useCartStore.getState().transactionDiscount,
          exchangeRequestId: null,
        });
      } catch (err) {
        console.error('[refundFlow] hydrateFromReceipt failed:', err);
        setDetailsNotLocalWarning(true);
      }
    })();
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [acceptedReceiptToken]);

  // Task 52: Auto-persist draft whenever cart items or discount change while
  // a refund is active.
  useEffect(() => {
    if (!activeRefundReceiptUuid || !activeRefundDraftId) return;

    const companyId = useAuthStore.getState().companyId;
    const terminalId = useTerminalStore.getState().terminal?.id ?? '';
    const operatorId = useOperatorStore.getState().operator?.id ?? '';
    if (!companyId) return;

    const returnItems = useCartStore.getState().returnItems();
    const saleItemsList = useCartStore.getState().saleItems();

    // Determine if exchange_request_id should be generated.
    // exchange_request_id: generated lazily on first positive line; preserved for the
    // lifetime of the draft (does NOT regenerate on empty-then-refill cycles).
    // Cleared only on draft discard.
    let currentExchangeId = exchangeRequestId;
    if (saleItemsList.length > 0 && currentExchangeId === null) {
      currentExchangeId = crypto.randomUUID();
      setExchangeRequestId(currentExchangeId);
    }
    // Do NOT clear currentExchangeId when saleItemsList becomes empty — the ID
    // must survive empty-then-refill cycles so the eventual API submit is idempotent.

    void persistDraft(companyId, {
      id: activeRefundDraftId,
      terminalId,
      operatorId,
      receiptUuid: activeRefundReceiptUuid,
      receiptNumber: activeRefundReceiptNumber ?? '',
      returnItems,
      buyingItems: saleItemsList,
      transactionDiscount: useCartStore.getState().transactionDiscount,
      exchangeRequestId: currentExchangeId,
    });
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cartItems, transactionDiscount]);

  // Clear payment error when cash modal opens
  useEffect(() => {
    if (showCashModal) {
      usePaymentStore.setState({ error: null });
    }
  }, [showCashModal]);

  // Derive receipt visibility settings from company config
  const receiptVisibility: ReceiptVisibilitySettings | undefined = useMemo(() => {
    const v = companyConfig?.receipt_visibility;
    if (!v) return undefined;
    return {
      show_vat_breakdown: v.show_vat_breakdown,
      show_fiscal_info: v.show_fiscal_info,
      show_payment_details: v.show_payment_details,
      show_customer: v.show_customer,
    };
  }, [companyConfig?.receipt_visibility]);

  // Fetch full receipt for ESC/POS thermal printing when success modal opens.
  // Prefer API when server ID is known (post-sync); fall back to local SQLite for pending receipts.
  // escPosSource tracks whether we already have 'server' data (no upgrade needed) or only 'local'
  // data (upgrade when lastReceiptServerId becomes available mid-modal).
  useEffect(() => {
    if (!showSuccessModal) {
      setEscPosData(null);
      setEscPosSource(null);
      return;
    }
    if (!lastReceipt) return;
    // Already have the best-available data
    if (escPosSource === 'server') return;
    if (escPosSource === 'local' && !lastReceiptServerId) return;

    let cancelled = false;

    const loader = async () => {
      try {
        const companyId = useAuthStore.getState().companyId;

        if (lastReceiptPrintData) {
          if (!cancelled) {
            setEscPosData(lastReceiptPrintData);
            setEscPosSource('local');
          }
          return;
        }

        if (lastReceiptServerId) {
          const fullReceipt = await fetchReceipt(lastReceiptServerId);
          const qrToken = await lookupQrToken(fullReceipt.receipt_number, companyId);
          if (!cancelled) {
            setEscPosData(
              buildEscPosReceiptData(fullReceipt, receiptVisibility, undefined, {
                qrToken,
              }),
            );
            setEscPosSource('server');
          }
          return;
        }
        if (lastReceiptIdempotencyKey) {
          const { getOfflineReceiptForPrint } = await import('@/lib/offline/getOfflineReceiptForPrint');
          const localReceipt = await getOfflineReceiptForPrint(lastReceiptIdempotencyKey);
          const qrToken = await lookupQrToken(localReceipt.receipt_number, companyId);
          if (!cancelled) {
            setEscPosData(
              buildEscPosReceiptData(localReceipt, receiptVisibility, undefined, {
                qrToken,
              }),
            );
            setEscPosSource('local');
          }
        }
      } catch (err) {
        if (!cancelled) console.error('[POS] Failed to assemble receipt for thermal print:', err);
      }
    };

    void loader();

    return () => { cancelled = true; };
  }, [showSuccessModal, lastReceipt, lastReceiptIdempotencyKey, lastReceiptServerId, lastReceiptPrintData, escPosSource, receiptVisibility]);

  // Smart Prompts: fetch recommendations when cart changes
  useEffect(() => {
    const productIds = cartItems.map((item) => item.product.id);
    if (productIds.length > 0) {
      spFetchForCart(productIds);
    } else {
      spClear();
    }
  }, [cartItems, spFetchForCart, spClear]);

  useEffect(() => {
    const productIds = cartItems.map((item) => item.product.id);
    if (productIds.length > 0 && spSkinType !== null) {
      spFetchForCart(productIds);
    }
  }, [spSkinType]); // eslint-disable-line react-hooks/exhaustive-deps

  // Cart product IDs for highlighting in grid
  const cartProductIds = useMemo(
    () => cartItems.map((item) => item.product.id),
    [cartItems],
  );

  // Task 52: Resume a persisted refund draft on app restart.
  // Bug 3 fix: branch cleanly — replaceCart only when there are buying items
  // (the first replaceReturnItems call was a no-op when replaceCart ran immediately
  // after and overwrote the entire cart). When only return items exist, call
  // replaceReturnItems so the discount state is preserved separately.
  const handleResumeDraft = useCallback(() => {
    if (!existingDraft) return;
    if (existingDraft.buyingItems.length > 0) {
      // Exchange mode: restore both return + sale lines atomically.
      useCartStore.getState().replaceCart(
        [
          ...existingDraft.returnItems,
          ...existingDraft.buyingItems,
        ],
        existingDraft.transactionDiscount,
      );
    } else {
      // Pure-refund mode: only return items, no sale lines in flight.
      useCartStore.getState().replaceReturnItems(existingDraft.returnItems);
    }
    setActiveRefundReceiptUuid(existingDraft.receiptUuid);
    setActiveRefundReceiptNumber(existingDraft.receiptNumber);
    // Drafts do not persist the scanned QR token — the settlement falls back
    // to the receipt-number identity in the local qr-index.
    setActiveRefundReceiptToken(null);
    setActiveRefundDraftId(existingDraft.id);
    setExchangeRequestId(existingDraft.exchangeRequestId);
    clearDraftState();
  }, [existingDraft, clearDraftState]);

  const handleDiscardDraft = useCallback(async () => {
    if (!existingDraft) return;
    const companyId = useAuthStore.getState().companyId;
    if (!companyId) return;
    await discardDraftAction(companyId, existingDraft.id);
  }, [existingDraft, discardDraftAction]);

  // Task 52: Net total for the footer (sale total − abs(return total)).
  const netTotal = useCartStore((s) => s.netTotal)();

  const handleOpenShift = useCallback(async () => {
    setShiftError(null);
    try {
      await openShift(openingCash, operator?.id);
    } catch (err) {
      setShiftError(getErrorMessage(err));
    }
  }, [openShift, openingCash, operator?.id]);

  const handleConsumptionModeChange = useCallback((mode: ConsumptionMode) => {
    setConsumptionMode(mode);
    if (mode === 'A_EMPORTER') {
      setSelectedTableId(null);
    }
  }, []);

  const handleAddToCart = useCallback(
    (product: POSProduct) => {
      // T2 — variant-bearing products require the cashier to pick a specific
      // variant before the line is added. Open the picker instead of adding.
      if (product.has_variants) {
        setVariantPickerProduct(product);
        return;
      }
      // Products with modifiers: quick-add with default selections
      if (product.modifier_groups && product.modifier_groups.length > 0) {
        addItemWithDefaults(product);
      } else {
        addItem(product);
      }
    },
    [addItem, addItemWithDefaults],
  );

  const handleVariantConfirm = useCallback(
    (variant: POSProductVariant) => {
      if (!variantPickerProduct) return;
      addItem(variantPickerProduct, undefined, variant);
      setVariantPickerProduct(null);
    },
    [variantPickerProduct, addItem],
  );

  const handleAddRecommendation = useCallback(
    async (productId: string) => {
      try {
        const productData = await apiGet<POSProduct>(`/products/${productId}`);
        if (productData) {
          addItem(productData);
        }
      } catch (recommendError) {
        // Best-effort — log so a persistent product-fetch failure isn't invisible.
        console.error('[POS][HomePage][handleAddRecommendation] failed', {
          ...serializeErrorForLog(recommendError),
          productId,
        });
      }
    },
    [addItem],
  );

  const handleCustomize = useCallback(
    (product: POSProduct) => {
      setModifierProduct(product);
      setEditingLineId(null);
    },
    [],
  );

  const handleEditModifiers = useCallback(
    (itemId: string) => {
      const cartItem = cartItems.find((i) => i.id === itemId);
      if (!cartItem) return;
      const matchingProduct = products.find((p) => p.id === cartItem.product.id);
      if (!matchingProduct) return;
      setModifierProduct(matchingProduct);
      setEditingLineId(itemId);
    },
    [cartItems, products],
  );

  const handleModifierConfirm = useCallback(
    (selectedModifiers: SelectedModifier[]) => {
      if (!modifierProduct) return;
      if (editingLineId) {
        updateLineModifiers(editingLineId, selectedModifiers);
      } else {
        addItem(modifierProduct, selectedModifiers);
      }
      setModifierProduct(null);
      setEditingLineId(null);
    },
    [modifierProduct, editingLineId, addItem, updateLineModifiers],
  );

  // ── Task 2b: refund checkout interception ──────────────────────────────────
  // An all-return cart must NEVER reach the sale checkout path (negative
  // lines fail the fiscal money invariant inside buildSaleReceiptPayload),
  // so BOTH Pay entry points classify the cart before opening any modal.

  /** Mixed return + sale cart → Pay is blocked with a translated toast. */
  const blockMixedCheckout = useCallback(() => {
    setScanMessage({ text: t('pos:refundFlow.checkout.completeReturnFirst'), type: 'error' });
    setTimeout(() => setScanMessage(null), 4000);
  }, [t]);

  /** Start the refund settlement flow (prepare → destination → confirm → PIN → submit). */
  const startRefundCheckout = useCallback(async () => {
    const companyId = useAuthStore.getState().companyId;
    const receiptNumber = activeRefundReceiptNumber;
    if (!companyId || !receiptNumber) {
      // Return lines without an active refund session — the original receipt
      // identity is unknown so the settlement cannot be resolved. Should not
      // happen (return lines only enter via scan-hydration or draft resume).
      console.error('[refundFlow] Pay pressed on a refund cart without an active refund session');
      setScanMessage({ text: t('pos:refundFlow.confirm.errorGeneric'), type: 'error' });
      setTimeout(() => setScanMessage(null), 3000);
      return;
    }
    try {
      const db = await getDatabase(companyId);
      await useRefundCheckoutStore.getState().begin({
        db,
        receiptToken: activeRefundReceiptToken,
        receiptNumber,
        // The cart's edited return quantities pass through AS-IS — partial
        // refunds are mapped onto the server lines by the settlement service.
        refundItems: useCartStore.getState().returnItems(),
      });
    } catch (beginError) {
      console.error('[refundFlow] failed to start refund checkout', serializeErrorForLog(beginError));
      setScanMessage({ text: t('pos:refundFlow.confirm.errorGeneric'), type: 'error' });
      setTimeout(() => setScanMessage(null), 3000);
    }
  }, [activeRefundReceiptNumber, activeRefundReceiptToken, t]);

  /**
   * Settled seam. The checkout store already cleared the cart's return
   * lines; this handler tears down the refund session, removes the
   * crash-safety draft, shows the success feedback, then prints the AVOIR
   * (+ voucher ticket on store_voucher settlements) fire-and-forget from the
   * /return response — a print failure surfaces a toast but can never block
   * or unwind a refund that already settled server-side.
   */
  const handleRefundSettled = useCallback((response: ReturnSettlementResponse) => {
    const companyId = useAuthStore.getState().companyId;
    const draftId = activeRefundDraftId;
    // Capture the ORIGINAL ticket reference before the teardown nulls it —
    // it is printed on the AVOIR (REMBOURSEMENT header block).
    const originalReceiptNumber = activeRefundReceiptNumber;
    const originalReceiptQrToken = activeRefundReceiptToken;

    setActiveRefundReceiptUuid(null);
    setActiveRefundReceiptNumber(null);
    setActiveRefundReceiptToken(null);
    setActiveRefundDraftId(null);
    setExchangeRequestId(null);
    setDetailsNotLocalWarning(false);
    clearDraftState();

    // Delete the SQLite draft row DIRECTLY — discardDraft would emit the
    // pos.refund_draft_discarded fraud signal, which is wrong for a refund
    // that actually SETTLED.
    if (companyId && draftId) {
      void (async () => {
        try {
          const db = await getDatabase(companyId);
          await deleteRefundDraft(db, draftId);
        } catch (cleanupError) {
          console.error('[refundFlow] settled-draft cleanup failed:', serializeErrorForLog(cleanupError));
        }
      })();
    }

    setScanMessage({
      text: t('pos:refundFlow.checkout.success', { number: response.receipt_number }),
      type: 'success',
    });
    setTimeout(() => setScanMessage(null), 4000);
    useRefundCheckoutStore.getState().acknowledgeSettled();

    // Phase 3: print the AVOIR + voucher ticket. Kicked off AFTER the
    // teardown completed synchronously above — the orchestration never
    // throws, so a print failure only replaces the toast (the settled
    // receipt number is repeated inside the failure message).
    void printRefundSettlementArtifacts({
      response,
      originalReceiptNumber,
      originalReceiptQrToken,
      visibilitySettings: receiptVisibility,
    }).then((outcome) => {
      if (outcome.status === 'failed') {
        console.error('[refundFlow] AVOIR print failed:', serializeErrorForLog(outcome.error));
        setScanMessage({
          text: t('pos:refundFlow.checkout.printFailed', { number: response.receipt_number }),
          type: 'error',
        });
        setTimeout(() => setScanMessage(null), 6000);
      }
    });
  }, [
    activeRefundDraftId,
    activeRefundReceiptNumber,
    activeRefundReceiptToken,
    receiptVisibility,
    clearDraftState,
    t,
  ]);

  const handlePayCash = useCallback(() => {
    switch (decidePayInterception(cartItems)) {
      case 'ignore':
        return;
      case 'block-mixed':
        blockMixedCheckout();
        return;
      case 'start-refund':
        void startRefundCheckout();
        return;
      case 'proceed-sale':
        setShowCashModal(true);
    }
  }, [cartItems, blockMixedCheckout, startRefundCheckout]);

  const handleCashConfirm = useCallback(
    async (tenderedAmount: number) => {
      if (!terminal) return;
      // Defense-in-depth (Task 2b): a receipt scan can hydrate return lines
      // while the cash modal is already open — a non-pure-sale cart must
      // NEVER reach buildSaleReceiptPayload (negative lines fail the fiscal
      // money invariant).
      if (mustBlockMidModalSettlement(cartItems)) {
        setShowCashModal(false);
        blockMixedCheckout();
        return;
      }
      try {
        await processCashCheckout(
          terminal.id,
          cartItems,
          tenderedAmount,
          transactionDiscount,
          isFnB ? consumptionMode : undefined,
          isFnB ? selectedTableId : undefined,
        );
        setShowCashModal(false);
        setShowSuccessModal(true);
      } catch (cashError) {
        // paymentStore.error already holds the user-visible banner, but the
        // raw throwable was previously unreachable from devtools.
        console.error('[POS][HomePage][handleCashConfirm] processCashCheckout threw', {
          ...serializeErrorForLog(cashError),
          terminalId: terminal.id,
          cartItemCount: cartItems.length,
          tenderedAmount,
        });
      }
    },
    [terminal, cartItems, transactionDiscount, processCashCheckout, isFnB, consumptionMode, selectedTableId, blockMixedCheckout],
  );

  const handleAdvancedPayments = useCallback(() => {
    switch (decidePayInterception(cartItems)) {
      case 'ignore':
        return;
      case 'block-mixed':
        blockMixedCheckout();
        return;
      case 'start-refund':
        void startRefundCheckout();
        return;
      case 'proceed-sale':
        setShowAdvancedModal(true);
    }
  }, [cartItems, blockMixedCheckout, startRefundCheckout]);

  const handleAdvancedComplete = useCallback(
    async (
      payments: Parameters<typeof processAdvancedCheckout>[2],
      options?: Parameters<typeof processAdvancedCheckout>[6],
    ) => {
      if (!terminal) return;
      // Defense-in-depth (Task 2b): see handleCashConfirm.
      if (mustBlockMidModalSettlement(cartItems)) {
        setShowAdvancedModal(false);
        blockMixedCheckout();
        return;
      }
      try {
        await processAdvancedCheckout(
          terminal.id,
          cartItems,
          payments,
          transactionDiscount,
          isFnB ? consumptionMode : undefined,
          isFnB ? selectedTableId : undefined,
          options,
        );
        setShowAdvancedModal(false);
        setShowSuccessModal(true);
      } catch (advancedError) {
        // paymentStore.error already holds the user-visible banner, but the
        // raw throwable was previously unreachable from devtools.
        console.error('[POS][HomePage][handleAdvancedComplete] processAdvancedCheckout threw', {
          ...serializeErrorForLog(advancedError),
          terminalId: terminal.id,
          cartItemCount: cartItems.length,
          paymentLineCount: payments.length,
        });
      }
    },
    [terminal, cartItems, transactionDiscount, processAdvancedCheckout, isFnB, consumptionMode, selectedTableId, blockMixedCheckout],
  );

  const handleChargeToAccount = useCallback(
    async (overrideApproval?: AccountChargeOverrideApprovalInput | null) => {
      if (!terminal) return;
      // Defense-in-depth (Task 2b — review Fix 5): same re-classification
      // guard as handleCashConfirm/handleAdvancedComplete. Without it, a
      // scan-hydrated return line entering the cart while the advanced
      // modal is open would be CHARGED to a customer account.
      if (mustBlockMidModalSettlement(cartItems)) {
        setShowAdvancedModal(false);
        blockMixedCheckout();
        return;
      }
      try {
        const result = await processAccountCharge(terminal.id, {
          overrideApproval: overrideApproval ?? null,
        });
        if (result) {
          setShowAdvancedModal(false);
          setShowSuccessModal(true);
        }
      } catch (chargeError) {
        // paymentStore.error already holds the user-visible banner shown in the
        // modal; surface the raw throwable for devtools.
        console.error('[POS][HomePage][handleChargeToAccount] processAccountCharge threw', {
          ...serializeErrorForLog(chargeError),
          terminalId: terminal.id,
        });
      }
    },
    [terminal, cartItems, blockMixedCheckout, processAccountCharge],
  );

  const handleHold = useCallback(async () => {
    if (cartItems.length === 0) return;
    await holdCurrentCart('');
  }, [cartItems.length, holdCurrentCart]);

  const handleRecall = useCallback(
    async (id: string) => {
      const tx = await recallTransaction(id);
      if (!tx) return;

      // Atomic replace — avoids the per-item setState loop that amplified BG3.
      useCartStore.getState().replaceCart(tx.items, tx.transactionDiscount);
      setShowHeldModal(false);
    },
    [recallTransaction],
  );

  const handleDiscard = useCallback(
    async (id: string) => {
      await discardTransaction(id);
    },
    [discardTransaction],
  );

  const handleApplyTransactionDiscount = useCallback(
    (data: { type: 'percentage' | 'fixed'; value: string; reason: string; approvalEvidence?: PosOverrideEvidence }) => {
      useCartStore.getState().setTransactionDiscount({
        type: data.type,
        value: data.value,
        reason: data.reason || undefined,
        approvalEvidence: data.approvalEvidence,
      });
    },
    [],
  );

  const handleRemoveDiscount = useCallback(() => {
    useCartStore.getState().setTransactionDiscount(undefined);
  }, []);

  const handleLineDiscount = useCallback((itemId: string) => {
    setDiscountItemId(itemId);
  }, []);

  const handleRemoveLineDiscount = useCallback((itemId: string) => {
    useCartStore.getState().removeLineDiscount(itemId);
  }, []);

  const handleApplyLineDiscount = useCallback(
    (data: { type: 'percentage' | 'fixed'; value: string; reason: string; approvalEvidence?: PosOverrideEvidence }) => {
      if (!discountItemId) return;

      useCartStore.getState().applyLineDiscount(discountItemId, {
        type: data.type,
        value: data.value,
        reason: data.reason,
        approvalEvidence: data.approvalEvidence,
      });

      setDiscountItemId(null);
    },
    [discountItemId],
  );

  const handleQuantityTap = useCallback((itemId: string) => {
    setQuantityEditItemId(itemId);
  }, []);

  const handleQuantityConfirm = useCallback(
    (qty: number) => {
      if (quantityEditItemId) {
        updateQuantity(quantityEditItemId, qty);
      }
      setQuantityEditItemId(null);
    },
    [quantityEditItemId, updateQuantity],
  );

  const quantityEditItem = quantityEditItemId
    ? cartItems.find((i) => i.id === quantityEditItemId)
    : null;

  const discountItem = discountItemId
    ? cartItems.find((i) => i.id === discountItemId)
    : null;

  const handleNewSale = useCallback(() => {
    setShowSuccessModal(false);
    // Checkout-success teardown — NOT a discard (no fraud signal).
    clearCart('checkout');
    clearLastReceipt();
    setSelectedTableId(null);

    // Lock screen after sale if enabled
    if (useSettingsStore.getState().lockAfterSale) {
      useOperatorStore.getState().lock();
    }
  }, [clearCart, clearLastReceipt]);

  const smartPromptsSharedProps = {
    recommendations: spRecommendations,
    contextFields: spContextFields,
    skinType: spSkinType,
    onSkinTypeChange: spSetSkinType,
    onAdd: handleAddRecommendation,
    isLoading: spIsLoading,
  };

  // Open shift screen
  if (!shift) {
    return (
      <div className="flex h-full flex-col">
        <TerminalNotReadyBanner />
        <ChainBreakAlert />
        <div className="flex flex-1 items-center justify-center">
        <div className="w-full max-w-sm text-center">
          <h2 className="text-xl font-bold text-gray-900">{t('shift.openTitle')}</h2>
          <p className="mt-1 text-sm text-gray-500">
            {t('shift.terminal')} {terminal?.name ?? t('shift.unknown')}
          </p>

          <div className="mt-6">
            <label htmlFor="openingCash" className="block text-sm font-medium text-gray-700">
              {t('shift.openingCash')}
            </label>
            <MoneyInput
              id="openingCash"
              currency={currency}
              min="0"
              value={openingCash}
              onChange={setOpeningCash}
              className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-center text-lg focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none"
            />
          </div>

          {shiftError && (
            <div className="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
              {shiftError}
            </div>
          )}

          <button
            onClick={() => void handleOpenShift()}
            disabled={terminalLoading}
            className="mt-4 w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
          >
            {terminalLoading ? t('shift.openingLoading') : t('shift.openingButton')}
          </button>
        </div>
        </div>
      </div>
    );
  }

  return (
    <div className="flex h-full flex-col">
      <TerminalNotReadyBanner />
      <ChainBreakAlert />

      {/* Task 52: Resume-draft banner — shown when a crashed/closed refund draft is detected. */}
      {existingDraft !== null && activeRefundReceiptUuid === null && (
        <ResumeRefundDraftBanner
          receiptNumber={existingDraft.receiptNumber}
          onResume={handleResumeDraft}
          onDiscard={() => void handleDiscardDraft()}
        />
      )}

      {/* Task 52: Not-local warning when receipt details couldn't be loaded. */}
      {detailsNotLocalWarning && (
        <div className="border-b border-red-300 bg-red-50 px-4 py-2 text-sm text-red-700">
          {t('pos:refundFlow.detailsNotLocal')}
        </div>
      )}

      <div className={`flex flex-1 min-h-0 relative ${cartPosition === 'end' ? 'flex-row-reverse' : 'flex-row'}`}>
      {/* Barcode scan feedback */}
      {scanMessage && (
        <div
          className={`absolute left-1/2 top-2 z-50 -translate-x-1/2 rounded-lg px-4 py-2 text-sm font-medium shadow-lg transition-opacity ${
            scanMessage.type === 'success'
              ? 'bg-green-600 text-white'
              : scanMessage.type === 'info'
                ? 'bg-blue-600 text-white'
                : 'bg-red-600 text-white'
          }`}
        >
          {scanMessage.text}
        </div>
      )}

      {/* Cart - left panel (first in DOM) */}
      <div className="flex min-w-[340px] flex-[4] flex-col border-r border-gray-200">
        <CustomerAttachPanel
          tenantId={activeTenantId}
          companyId={activeCompanyId}
          terminalId={terminal?.id ?? null}
          onAccountPaymentComplete={() => setShowSuccessModal(true)}
        />
        <div className="min-h-0 flex-1">
          <TransactionCart
            items={cartItems}
            subtotal={subtotal()}
            taxAmount={taxAmount()}
            discountAmount={discountAmount()}
            total={total()}
            itemCount={itemCount()}
            hasDiscount={!!transactionDiscount}
            onUpdateQuantity={updateQuantity}
            onRemoveItem={removeItem}
            onClearCart={clearCart}
            onPayCash={handlePayCash}
            onAdvancedPayments={handleAdvancedPayments}
            onQuantityTap={handleQuantityTap}
            onDiscount={() => setShowDiscountModal(true)}
            onHold={() => void handleHold()}
            onRecall={() => setShowHeldModal(true)}
            onReturns={() => setShowReceiptLocator(true)}
            onLineDiscount={handleLineDiscount}
            onRemoveLineDiscount={handleRemoveLineDiscount}
            onEditModifiers={handleEditModifiers}
            onRemoveDiscount={handleRemoveDiscount}
            paymentMethods={paymentMethods}
            paymentRepositories={paymentRepositories}
            checkoutDisabled={!hashChainReady || isProcessing}
            netTotal={activeRefundReceiptUuid !== null ? netTotal : undefined}
          />
        </div>
      </div>

      {/* Product grid - right panel (second in DOM) */}
      <div className="flex flex-[7] flex-col overflow-hidden bg-gray-50 p-2">
        {isFnB && consumptionMode === 'SUR_PLACE' && (
          <div className="mb-2">
            <TableSelector
              selectedTableId={selectedTableId}
              onSelectTable={setSelectedTableId}
            />
          </div>
        )}
        <ProductGrid
          products={products}
          categories={categories}
          onAddToCart={handleAddToCart}
          onCustomize={handleCustomize}
          cartProductIds={cartProductIds}
          isLoading={productsLoading}
          consumptionModeToggle={isFnB ? (
            <ConsumptionModeToggle
              value={consumptionMode}
              onChange={handleConsumptionModeChange}
            />
          ) : undefined}
        />
        {(smartPromptsVariant === 'toast' || smartPromptsVariant === 'both') && (
          <ToastSmartPrompts {...smartPromptsSharedProps} />
        )}
      </div>

      {/* Cash payment screen */}
      <CashPaymentScreen
        isOpen={showCashModal}
        onClose={() => setShowCashModal(false)}
        onConfirm={(amount) => void handleCashConfirm(amount)}
        total={total()}
        discountAmount={discountAmount()}
        isProcessing={isProcessing}
        error={paymentError}
      />

      {/* Checkout success modal */}
      {lastReceipt && (
        <CheckoutSuccessModal
          isOpen={showSuccessModal}
          onClose={handleNewSale}
          receiptNumber={lastReceipt.receipt_number}
          total={lastReceipt.total}
          changeDue={changeDue}
          receiptData={escPosData ?? undefined}
        />
      )}

      {/* Advanced payments modal */}
      <AdvancedPaymentsModal
        isOpen={showAdvancedModal}
        onClose={() => setShowAdvancedModal(false)}
        total={total()}
        paymentMethods={paymentMethods}
        paymentRepositories={paymentRepositories}
        onComplete={handleAdvancedComplete}
        onChargeToAccount={handleChargeToAccount}
        isProcessing={isProcessing}
        error={paymentError}
        voucherDb={voucherDb}
      />

      {/* Held transactions modal */}
      <HeldTransactionsModal
        isOpen={showHeldModal}
        onClose={() => setShowHeldModal(false)}
        heldTransactions={heldTransactions}
        onRecall={(id) => void handleRecall(id)}
        onDiscard={(id) => void handleDiscard(id)}
      />

      {/* Discount modal (transaction-only) */}
      <DiscountModal
        isOpen={showDiscountModal}
        onClose={() => setShowDiscountModal(false)}
        onApplyTransactionDiscount={handleApplyTransactionDiscount}
        canDiscount={transactionDiscountAccess.canDiscount}
        maxDiscountPercent={transactionDiscountAccess.maxDiscountPercent}
        terminalMaxDiscountPercent={terminal?.max_discount_percent ?? 0}
        disabledReason={
          transactionDiscountAccess.disabledReason
            ? t(`pos:${transactionDiscountAccess.disabledReason}`)
            : undefined
        }
        requiresReason={true}
        approvalContext={approvalContext}
      />

      {/* Line discount modal */}
      <LineDiscountModal
        isOpen={discountItemId !== null}
        onClose={() => setDiscountItemId(null)}
        onApply={handleApplyLineDiscount}
        itemName={discountItem?.product.name ?? ''}
        canDiscount={lineDiscountAccess.canDiscount}
        maxDiscountPercent={lineDiscountAccess.maxDiscountPercent}
        terminalMaxDiscountPercent={terminal?.max_discount_percent ?? 0}
        disabledReason={
          lineDiscountAccess.disabledReason
            ? t(`pos:${lineDiscountAccess.disabledReason}`)
            : undefined
        }
        approvalContext={approvalContext}
        lineReferenceId={discountItemId}
      />

      {/* Modifier selection modal */}
      <ModifierSelectionModal
        isOpen={modifierProduct !== null}
        onClose={() => { setModifierProduct(null); setEditingLineId(null); }}
        product={modifierProduct}
        onConfirm={handleModifierConfirm}
      />

      {/* T2 — variant picker: cashier taps a variant-bearing product, picks the
          specific variant, and the confirmed variant is stamped onto the cart
          line at the variant's price + identity. */}
      <VariantPickerModal
        isOpen={variantPickerProduct !== null}
        onClose={() => setVariantPickerProduct(null)}
        product={variantPickerProduct}
        onConfirm={handleVariantConfirm}
      />

      {/* Void/Return modal */}
      <VoidReturnModal
        isOpen={showVoidReturnModal}
        onClose={() => setShowVoidReturnModal(false)}
        approvalContext={approvalContext}
      />

      {/* T2.1 Step B — barcode collision chooser. Mounts when the scan
          resolver finds >1 product matching the scanned code (UPC overlap,
          internal SKU/barcode shared codes, etc.). Cashier picks one →
          add to cart; cashier dismisses → no-op. */}
      <BarcodeChooserModal
        isOpen={chooserState !== null}
        scannedCode={chooserState?.scannedCode ?? ''}
        candidates={chooserState?.candidates ?? []}
        onPick={(product) => {
          // Remember the cashier's pick for this code so the next scan
          // of the same colliding barcode skips the chooser entirely
          // (chooser-pick preference cache; resolves to a Tier 0 LRU
          // hit). Tenant-scoped via companyId — a pick in company A
          // can't bleed into company B after a session switch.
          // Bounded by the cache's session lifetime + LRU eviction
          // window.
          if (chooserState !== null) {
            const companyIdForCache = useAuthStore.getState().companyId;
            if (companyIdForCache) {
              // Codex round-3 P3 (PR #98) — trim parity with the
              // resolver. resolveScannedCode does code = rawCode.trim()
              // before all cache reads/writes, so caching the
              // chooser's scannedCode untrimmed would store under a
              // raw key that the next scan's trimmed lookup never
              // hits — chooser preference would never take effect for
              // scanners that emit surrounding whitespace.
              setCachedScan(chooserState.scannedCode.trim(), product, companyIdForCache);
            }
          }
          setChooserState(null);
          addProductToCartWithToast(product);
        }}
        onDismiss={() => setChooserState(null)}
      />

      {/* Quantity numpad */}
      <QuantityNumpad
        isOpen={quantityEditItemId !== null}
        onClose={() => setQuantityEditItemId(null)}
        currentQuantity={quantityEditItem?.quantity ?? 1}
        onConfirm={handleQuantityConfirm}
      />

      {/* Receipt-Scan Confirmation Sheet (Phase H Task 50, spec §6.1 entry 2).
          Cashier scans an old sale receipt mid-sale → ask before mutating cart. */}
      <ReceiptScanConfirmationSheet
        entry={pendingScanResult}
        onCancel={() => setPendingScanResult(null)}
        onAccept={() => acceptPendingScan()}
      />

      {/* Returns / Exchange receipt-locator screen (Phase H Task 51, spec §6.1).
          Opened by the "Returns / Exchange" quick-action button in the cart.
          Queries are local SQLite only. On "Refund this" the store emits the
          same ReceiptTokenAccepted event as Task 50's scan dispatcher. */}
      <ReceiptLocatorScreen
        isOpen={showReceiptLocator}
        onClose={() => setShowReceiptLocator(false)}
      />

      {/* Task 2b (Task 53 wiring): refund settlement flow — destination picker,
          confirm modal, manager-PIN approval, /return submit. Driven by
          refundCheckoutStore; entered from handlePayCash/handleAdvancedPayments
          when the cart is all-return. Not mounted without a cashier identity
          (review Fix 7e) — never hand the approval trail an empty-string id. */}
      {refundCashierUserId !== null && (
        <RefundCheckoutFlow
          approvalContext={approvalContext}
          terminalId={terminal?.id ?? null}
          cashierUserId={refundCashierUserId}
          onRefundSettled={handleRefundSettled}
        />
      )}
      </div>
    </div>
  );
}
