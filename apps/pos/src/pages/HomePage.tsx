import { useState, useEffect, useMemo, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useProductStore } from '@/stores/productStore';
import { useCartStore, computeTaxAmount } from '@/stores/cartStore';
import { usePaymentStore } from '@/stores/paymentStore';
import { useHoldStore } from '@/stores/holdStore';
import { useScannerStore } from '@/stores/scannerStore';
import { useBarcodeScanner } from '@/hooks/useBarcodeScanner';
import { getErrorMessage } from '@/lib/api';
import { useCurrency } from '@/lib/currency';
import { fetchReceipt } from '@/api/receiptApi';
import { buildEscPosReceiptData } from '@/lib/buildReceiptData';
import type { ReceiptVisibilitySettings } from '@/lib/buildReceiptData';
import type { ReceiptData } from '@/lib/printing';
import { hasModule } from '@/stores/productStore';
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
import { InlineSmartPrompts } from '@/components/organisms/InlineSmartPrompts';
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
  const isOfflineReceipt = usePaymentStore((s) => s.isOfflineReceipt);
  const clearLastReceipt = usePaymentStore((s) => s.clearLastReceipt);
  const lastReceiptIdempotencyKey = usePaymentStore((s) => s.lastReceiptIdempotencyKey);
  const lastReceiptServerId = usePaymentStore((s) => s.lastReceiptServerId);
  const paymentError = usePaymentStore((s) => s.error);

  // Hold store
  const heldTransactions = useHoldStore((s) => s.heldTransactions);
  const holdCurrentCart = useHoldStore((s) => s.holdCurrentCart);
  const recallTransaction = useHoldStore((s) => s.recallTransaction);
  const discardTransaction = useHoldStore((s) => s.discardTransaction);

  // Modal state
  const [showCashModal, setShowCashModal] = useState(false);
  const [showSuccessModal, setShowSuccessModal] = useState(false);
  const [showAdvancedModal, setShowAdvancedModal] = useState(false);
  const [showHeldModal, setShowHeldModal] = useState(false);
  const [showDiscountModal, setShowDiscountModal] = useState(false);
  const [showVoidReturnModal, setShowVoidReturnModal] = useState(false);
  const [quantityEditItemId, setQuantityEditItemId] = useState<string | null>(null);

  // ESC/POS receipt data for thermal printing
  const [escPosData, setEscPosData] = useState<ReceiptData | null>(null);

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

  const handleBarcodeScan = useCallback(
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

  useBarcodeScanner({
    onScan: handleBarcodeScan,
    enabled: !!shift,
  });

  // Fetch products and payment config when shift is open
  useEffect(() => {
    if (shift) {
      void fetchProducts();
      void fetchPaymentConfig();
    }
  }, [shift, fetchProducts, fetchPaymentConfig]);

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
  useEffect(() => {
    if (!showSuccessModal) {
      setEscPosData(null);
      return;
    }
    if (!lastReceipt || escPosData) return;

    let cancelled = false;

    const loader = async () => {
      try {
        // Prefer API when server ID is known (post-sync); fall back to local SQLite for pending receipts
        if (lastReceiptServerId) {
          const fullReceipt = await fetchReceipt(lastReceiptServerId);
          if (!cancelled) setEscPosData(buildEscPosReceiptData(fullReceipt, receiptVisibility));
          return;
        }
        if (lastReceiptIdempotencyKey) {
          const { getOfflineReceiptForPrint } = await import('@/lib/offline/getOfflineReceiptForPrint');
          const localReceipt = await getOfflineReceiptForPrint(lastReceiptIdempotencyKey);
          if (!cancelled) setEscPosData(buildEscPosReceiptData(localReceipt, receiptVisibility));
        }
      } catch (err) {
        if (!cancelled) console.error('[POS] Failed to assemble receipt for thermal print:', err);
      }
    };

    void loader();

    return () => { cancelled = true; };
  }, [showSuccessModal, lastReceipt, lastReceiptIdempotencyKey, lastReceiptServerId, escPosData, receiptVisibility]);

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

  const handleHold = useCallback(() => {
    if (cartItems.length === 0) return;
    holdCurrentCart('');
  }, [cartItems.length, holdCurrentCart]);

  const handleRecall = useCallback(
    (id: string) => {
      const tx = recallTransaction(id);
      if (!tx) return;

      // Load held items into the cart
      clearCart();
      for (const item of tx.items) {
        useCartStore.setState((state) => ({
          items: [...state.items, item],
        }));
      }
      if (tx.transactionDiscount) {
        useCartStore.getState().setTransactionDiscount(tx.transactionDiscount);
      }
      setShowHeldModal(false);
    },
    [recallTransaction, clearCart],
  );

  const handleDiscard = useCallback(
    (id: string) => {
      discardTransaction(id);
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

  const smartPromptsInline =
    smartPromptsVariant === 'inline' || smartPromptsVariant === 'both' ? (
      <InlineSmartPrompts {...smartPromptsSharedProps} />
    ) : undefined;

  // Open shift screen
  if (!shift) {
    return (
      <div className="flex h-full items-center justify-center">
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
    );
  }

  return (
    <div className={`flex h-full relative ${cartPosition === 'end' ? 'flex-row-reverse' : 'flex-row'}`}>
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
          onHold={handleHold}
          onRecall={() => setShowHeldModal(true)}
          onLineDiscount={handleLineDiscount}
          onRemoveLineDiscount={handleRemoveLineDiscount}
          onEditModifiers={handleEditModifiers}
          onRemoveDiscount={handleRemoveDiscount}
          paymentMethods={paymentMethods}
          smartPromptsSlot={smartPromptsInline}
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
          receiptId={lastReceipt.id}
          receiptData={escPosData ?? undefined}
          isOfflineReceipt={isOfflineReceipt}
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
        onRecall={handleRecall}
        onDiscard={handleDiscard}
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
    </div>
  );
}
