import { useState, useEffect, useMemo, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { useTerminalStore } from '@/stores/terminalStore';
import { useAuthStore } from '@/stores/authStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useProductStore } from '@/stores/productStore';
import { useCartStore, computeTaxAmount } from '@/stores/cartStore';
import { usePaymentStore } from '@/stores/paymentStore';
import { useHoldStore } from '@/stores/holdStore';
import { useScannerStore } from '@/stores/scannerStore';
import { useBarcodeScanner } from '@/hooks/useBarcodeScanner';
import { useRefundFlowStore } from '@/stores/refundFlowStore';
import { useRefundDraftStore } from '@/stores/refundDraftStore';
import { dispatchScan } from '@/lib/scan/dispatcher';
import { getDatabase } from '@/lib/db';
import { getOfflineReceiptById } from '@/lib/db/repositories/offlineReceiptRepository';
import { hydrateFromReceipt } from '@/lib/refundFlow/hydrateFromReceipt';
import { ReceiptScanConfirmationSheet } from '@/components/pos/ReceiptScanConfirmationSheet';
import { ReceiptLocatorScreen } from '@/components/pos/ReceiptLocatorScreen';
import { ResumeRefundDraftBanner } from '@/components/pos/ResumeRefundDraftBanner';
import { getErrorMessage } from '@/lib/api';
import { useCurrency } from '@/lib/currency';
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
import { VoidReturnModal } from '@/components/organisms/VoidReturnModal';
import { QuantityNumpad } from '@/components/organisms/QuantityNumpad';
import { useSmartPromptsStore } from '@/stores/smartPromptsStore';
import { ToastSmartPrompts } from '@/components/organisms/ToastSmartPrompts';
import { apiGet } from '@/lib/api';
import { useSettingsStore } from '@/stores/settingsStore';
import type { ConsumptionMode } from '@/components/atoms/ConsumptionModeToggle';
import type { POSProduct } from '@/types/product';
import type { SelectedModifier } from '@/types/cart';

export function HomePage() {
  const { t } = useTranslation();
  const { decimals: currencyDecimals } = useCurrency();
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
  const paymentRepositories = usePaymentStore((s) => s.paymentRepositories);
  const isProcessing = usePaymentStore((s) => s.isProcessing);
  const lastReceipt = usePaymentStore((s) => s.lastReceipt);
  const changeDue = usePaymentStore((s) => s.changeDue);
  const clearLastReceipt = usePaymentStore((s) => s.clearLastReceipt);
  const lastReceiptIdempotencyKey = usePaymentStore((s) => s.lastReceiptIdempotencyKey);
  const lastReceiptServerId = usePaymentStore((s) => s.lastReceiptServerId);
  const paymentError = usePaymentStore((s) => s.error);

  // Hold store
  const heldTransactions = useHoldStore((s) => s.heldTransactions);
  const holdCurrentCart = useHoldStore((s) => s.holdCurrentCart);
  const recallTransaction = useHoldStore((s) => s.recallTransaction);
  const discardTransaction = useHoldStore((s) => s.discardTransaction);
  const loadHeldTransactions = useHoldStore((s) => s.loadHeldTransactions);

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

  // Consumption mode + table selection (F&B only)
  const [consumptionMode, setConsumptionMode] = useState<ConsumptionMode>('SUR_PLACE');
  const [selectedTableId, setSelectedTableId] = useState<string | null>(null);

  // Settings
  const cartPosition = useSettingsStore((s) => s.cartPosition);

  // Line discount state
  const [discountItemId, setDiscountItemId] = useState<string | null>(null);

  // Operator discount permissions
  const canDiscount = operator?.can_discount ?? true;
  const maxDiscountPct = operator?.max_discount_percent ?? 100;

  // Barcode scanner
  const [scanMessage, setScanMessage] = useState<{ text: string; type: 'success' | 'error' } | null>(null);

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
  const [activeRefundDraftId, setActiveRefundDraftId] = useState<string | null>(null);
  const [exchangeRequestId, setExchangeRequestId] = useState<string | null>(null);
  const [detailsNotLocalWarning, setDetailsNotLocalWarning] = useState(false);

  /**
   * Existing product-barcode handler — extracted so the receipt-token
   * dispatcher (below) can fall through to it cleanly.
   */
  const handleProductBarcode = useCallback(
    (barcode: string) => {
      const product = products.find(
        (p) => p.barcode === barcode || p.sku === barcode,
      );
      if (!product) {
        setScanMessage({ text: t('barcode.productNotFound', { code: barcode }), type: 'error' });
        setTimeout(() => setScanMessage(null), 3000);
        return;
      }
      const { autoAddToCart } = useScannerStore.getState();
      if (autoAddToCart) {
        addItem(product);
        setScanMessage({ text: t('barcode.productAdded', { name: product.name }), type: 'success' });
        setTimeout(() => setScanMessage(null), 2000);
      }
    },
    [products, addItem, t],
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
    [terminal?.id, handleProductBarcode, setPendingScanResult, t],
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
    setActiveRefundDraftId(null);
    setExchangeRequestId(null);
    setPendingScanResult(null);
    setDetailsNotLocalWarning(false);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [shift]);

  // Concern #1: Reset refund local state when operator switches.
  // Prevents Operator A's pending sheet from leaking into Operator B's session.
  useEffect(() => {
    if (operator !== null) return; // only fire on operator clear
    setActiveRefundReceiptUuid(null);
    setActiveRefundReceiptNumber(null);
    setActiveRefundDraftId(null);
    setExchangeRequestId(null);
    setPendingScanResult(null);
    setDetailsNotLocalWarning(false);
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
        if (lastReceiptServerId) {
          const fullReceipt = await fetchReceipt(lastReceiptServerId);
          if (!cancelled) {
            setEscPosData(buildEscPosReceiptData(fullReceipt, receiptVisibility));
            setEscPosSource('server');
          }
          return;
        }
        if (lastReceiptIdempotencyKey) {
          const { getOfflineReceiptForPrint } = await import('@/lib/offline/getOfflineReceiptForPrint');
          const localReceipt = await getOfflineReceiptForPrint(lastReceiptIdempotencyKey);
          if (!cancelled) {
            setEscPosData(buildEscPosReceiptData(localReceipt, receiptVisibility));
            setEscPosSource('local');
          }
        }
      } catch (err) {
        if (!cancelled) console.error('[POS] Failed to assemble receipt for thermal print:', err);
      }
    };

    void loader();

    return () => { cancelled = true; };
  }, [showSuccessModal, lastReceipt, lastReceiptIdempotencyKey, lastReceiptServerId, escPosSource, receiptVisibility]);

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
      // Products with modifiers: quick-add with default selections
      if (product.modifier_groups && product.modifier_groups.length > 0) {
        addItemWithDefaults(product);
      } else {
        addItem(product);
      }
    },
    [addItem, addItemWithDefaults],
  );

  const handleAddRecommendation = useCallback(
    async (productId: string) => {
      try {
        const productData = await apiGet<POSProduct>(`/products/${productId}`);
        if (productData) {
          addItem(productData);
        }
      } catch {
        // Silently fail — recommendation add is best-effort
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

  const handlePayCash = useCallback(() => {
    if (cartItems.length === 0) return;
    setShowCashModal(true);
  }, [cartItems.length]);

  const handleCashConfirm = useCallback(
    async (tenderedAmount: number) => {
      if (!terminal) return;
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
      } catch {
        // Error is stored in paymentStore and displayed in the modal
      }
    },
    [terminal, cartItems, transactionDiscount, processCashCheckout, isFnB, consumptionMode, selectedTableId],
  );

  const handleAdvancedPayments = useCallback(() => {
    if (cartItems.length === 0) return;
    setShowAdvancedModal(true);
  }, [cartItems.length]);

  const handleAdvancedComplete = useCallback(
    async (payments: Parameters<typeof processAdvancedCheckout>[2]) => {
      if (!terminal) return;
      try {
        await processAdvancedCheckout(
          terminal.id,
          cartItems,
          payments,
          transactionDiscount,
          isFnB ? consumptionMode : undefined,
          isFnB ? selectedTableId : undefined,
        );
        setShowAdvancedModal(false);
        setShowSuccessModal(true);
      } catch {
        // Error is stored in paymentStore
      }
    },
    [terminal, cartItems, transactionDiscount, processAdvancedCheckout, isFnB, consumptionMode, selectedTableId],
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
    (data: { type: 'percentage' | 'fixed'; value: string; reason: string }) => {
      useCartStore.getState().setTransactionDiscount({
        type: data.type,
        value: data.value,
        reason: data.reason || undefined,
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
    useCartStore.setState((state) => ({
      items: state.items.map((item) => {
        if (item.id !== itemId) return item;
        const grossTotal = parseFloat(item.unit_price) * item.quantity;
        return {
          ...item,
          discount_type: undefined,
          discount_percent: undefined,
          discount_amount: undefined,
          discount_reason: undefined,
          line_total: grossTotal.toFixed(currencyDecimals),
          tax_amount: computeTaxAmount(grossTotal, item.tax_rate),
        };
      }),
    }));
  }, [currencyDecimals]);

  const handleApplyLineDiscount = useCallback(
    (data: { type: 'percentage' | 'fixed'; value: string; reason: string }) => {
      if (!discountItemId) return;

      useCartStore.setState((state) => ({
        items: state.items.map((item) => {
          if (item.id !== discountItemId) return item;
          const grossTotal = parseFloat(item.unit_price) * item.quantity;
          let discountAmount = 0;
          if (data.type === 'percentage') {
            discountAmount = (grossTotal * parseFloat(data.value)) / 100;
          } else {
            discountAmount = parseFloat(data.value);
          }
          const lineTotal = Math.max(0, grossTotal - discountAmount);
          return {
            ...item,
            discount_type: data.type,
            discount_percent: data.type === 'percentage' ? data.value : undefined,
            discount_amount: discountAmount.toFixed(currencyDecimals),
            discount_reason: data.reason || undefined,
            line_total: lineTotal.toFixed(currencyDecimals),
            tax_amount: computeTaxAmount(lineTotal, item.tax_rate),
          };
        }),
      }));

      setDiscountItemId(null);
    },
    [discountItemId, currencyDecimals],
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
    clearCart();
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
            <input
              id="openingCash"
              type="number"
              step="0.01"
              min="0"
              value={openingCash}
              onChange={(e) => setOpeningCash(e.target.value)}
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
              : 'bg-red-600 text-white'
          }`}
        >
          {scanMessage.text}
        </div>
      )}

      {/* Cart - left panel (first in DOM) */}
      <div className="flex-[4] min-w-[340px] border-r border-gray-200">
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
          checkoutDisabled={!hashChainReady || isProcessing}
          netTotal={activeRefundReceiptUuid !== null ? netTotal : undefined}
        />
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
        isProcessing={isProcessing}
        error={paymentError}
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
        canDiscount={canDiscount}
        maxDiscountPercent={maxDiscountPct}
        requiresReason={true}
      />

      {/* Line discount modal */}
      <LineDiscountModal
        isOpen={discountItemId !== null}
        onClose={() => setDiscountItemId(null)}
        onApply={handleApplyLineDiscount}
        itemName={discountItem?.product.name ?? ''}
        canDiscount={canDiscount}
        maxDiscountPercent={maxDiscountPct}
      />

      {/* Modifier selection modal */}
      <ModifierSelectionModal
        isOpen={modifierProduct !== null}
        onClose={() => { setModifierProduct(null); setEditingLineId(null); }}
        product={modifierProduct}
        onConfirm={handleModifierConfirm}
      />

      {/* Void/Return modal */}
      <VoidReturnModal
        isOpen={showVoidReturnModal}
        onClose={() => setShowVoidReturnModal(false)}
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
      </div>
    </div>
  );
}
