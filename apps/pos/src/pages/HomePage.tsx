import { useState, useEffect, useMemo, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useProductStore } from '@/stores/productStore';
import { useCartStore } from '@/stores/cartStore';
import { usePaymentStore } from '@/stores/paymentStore';
import { useHoldStore } from '@/stores/holdStore';
import { getErrorMessage } from '@/lib/api';
import { ProductGrid } from '@/components/organisms/ProductGrid';
import { TransactionCart } from '@/components/organisms/TransactionCart';
import { CashTenderedModal } from '@/components/organisms/CashTenderedModal';
import { CheckoutSuccessModal } from '@/components/organisms/CheckoutSuccessModal';
import { CardPaymentModal } from '@/components/organisms/CardPaymentModal';
import { HeldTransactionsModal } from '@/components/organisms/HeldTransactionsModal';
import { DiscountModal } from '@/components/organisms/DiscountModal';
import { LineDiscountModal } from '@/components/organisms/LineDiscountModal';
import { ModifierSelectionModal } from '@/components/organisms/ModifierSelectionModal';
import { ReportsMenu } from '@/components/organisms/ReportsMenu';
import { VoidReturnModal } from '@/components/organisms/VoidReturnModal';
import { CashDrawerModal } from '@/components/organisms/CashDrawerModal';
import { QuantityNumpad } from '@/components/organisms/QuantityNumpad';
import type { POSProduct } from '@/types/product';
import type { SelectedModifier } from '@/types/cart';

export function HomePage() {
  const { t } = useTranslation();
  const { shift, terminal, openShift, isLoading: terminalLoading } = useTerminalStore();
  const operator = useOperatorStore((s) => s.operator);
  const [openingCash, setOpeningCash] = useState('0.00');
  const [shiftError, setShiftError] = useState<string | null>(null);

  // Product store
  const products = useProductStore((s) => s.products);
  const categories = useProductStore((s) => s.categories);
  const productsLoading = useProductStore((s) => s.isLoading);
  const fetchProducts = useProductStore((s) => s.fetchProducts);

  // Cart store
  const cartItems = useCartStore((s) => s.items);
  const transactionDiscount = useCartStore((s) => s.transactionDiscount);
  const addItem = useCartStore((s) => s.addItem);
  const updateQuantity = useCartStore((s) => s.updateQuantity);
  const removeItem = useCartStore((s) => s.removeItem);
  const clearCart = useCartStore((s) => s.clearCart);
  const subtotal = useCartStore((s) => s.subtotal);
  const taxAmount = useCartStore((s) => s.taxAmount);
  const total = useCartStore((s) => s.total);
  const itemCount = useCartStore((s) => s.itemCount);

  // Payment store
  const fetchPaymentConfig = usePaymentStore((s) => s.fetchPaymentConfig);
  const paymentMethods = usePaymentStore((s) => s.paymentMethods);
  const processCashCheckout = usePaymentStore((s) => s.processCashCheckout);
  const processCardCheckout = usePaymentStore((s) => s.processCardCheckout);
  const isProcessing = usePaymentStore((s) => s.isProcessing);
  const lastReceipt = usePaymentStore((s) => s.lastReceipt);
  const changeDue = usePaymentStore((s) => s.changeDue);
  const clearLastReceipt = usePaymentStore((s) => s.clearLastReceipt);
  const paymentError = usePaymentStore((s) => s.error);

  // Hold store
  const heldTransactions = useHoldStore((s) => s.heldTransactions);
  const holdCurrentCart = useHoldStore((s) => s.holdCurrentCart);
  const recallTransaction = useHoldStore((s) => s.recallTransaction);
  const discardTransaction = useHoldStore((s) => s.discardTransaction);

  // Modal state
  const [showCashModal, setShowCashModal] = useState(false);
  const [showSuccessModal, setShowSuccessModal] = useState(false);
  const [showCardModal, setShowCardModal] = useState(false);
  const [showHeldModal, setShowHeldModal] = useState(false);
  const [showDiscountModal, setShowDiscountModal] = useState(false);
  const [showReportsMenu, setShowReportsMenu] = useState(false);
  const [showVoidReturnModal, setShowVoidReturnModal] = useState(false);
  const [showCashDrawerModal, setShowCashDrawerModal] = useState(false);
  const [quantityEditItemId, setQuantityEditItemId] = useState<string | null>(null);

  // Modifier selection state
  const [modifierProduct, setModifierProduct] = useState<POSProduct | null>(null);

  // Line discount state
  const [discountItemId, setDiscountItemId] = useState<string | null>(null);

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

  const handleAddToCart = useCallback(
    (product: POSProduct) => {
      // Products with modifiers open the modifier selection modal
      if (product.modifier_groups && product.modifier_groups.length > 0) {
        setModifierProduct(product);
      } else {
        addItem(product);
      }
    },
    [addItem],
  );

  const handleModifierConfirm = useCallback(
    (selectedModifiers: SelectedModifier[]) => {
      if (modifierProduct) {
        addItem(modifierProduct, selectedModifiers);
        setModifierProduct(null);
      }
    },
    [modifierProduct, addItem],
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
        );
        setShowCashModal(false);
        setShowSuccessModal(true);
      } catch {
        // Error is stored in paymentStore and displayed in the modal
      }
    },
    [terminal, cartItems, transactionDiscount, processCashCheckout],
  );

  const handlePayCard = useCallback(() => {
    if (cartItems.length === 0) return;
    setShowCardModal(true);
  }, [cartItems.length]);

  const handleCardConfirm = useCallback(
    async (cardData: { lastFour?: string; reference?: string }) => {
      if (!terminal) return;
      try {
        await processCardCheckout(
          terminal.id,
          cartItems,
          cardData,
          transactionDiscount,
        );
        setShowCardModal(false);
        setShowSuccessModal(true);
      } catch {
        // Error is stored in paymentStore
      }
    },
    [terminal, cartItems, transactionDiscount, processCardCheckout],
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
    (data: { amount: string; reason: string }) => {
      useCartStore.getState().setTransactionDiscount({
        amount: data.amount,
        reason: data.reason || undefined,
      });
    },
    [],
  );

  const handleLineDiscount = useCallback((itemId: string) => {
    setDiscountItemId(itemId);
  }, []);

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
            discount_amount: discountAmount.toFixed(2),
            discount_reason: data.reason || undefined,
            line_total: lineTotal.toFixed(2),
          };
        }),
      }));

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
    clearCart();
    clearLastReceipt();
  }, [clearCart, clearLastReceipt]);

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
    <div className="flex h-full">
      {/* Product grid - left panel */}
      <div className="flex flex-[7] flex-col overflow-hidden border-r border-gray-200 bg-gray-50 p-4">
        <ProductGrid
          products={products}
          categories={categories}
          onAddToCart={handleAddToCart}
          cartProductIds={cartProductIds}
          isLoading={productsLoading}
        />
      </div>

      {/* Cart - right panel */}
      <div className="flex-[4] min-w-[400px]">
        <TransactionCart
          items={cartItems}
          subtotal={subtotal()}
          taxAmount={taxAmount()}
          total={total()}
          itemCount={itemCount()}
          onUpdateQuantity={updateQuantity}
          onRemoveItem={removeItem}
          onClearCart={clearCart}
          onPayCash={handlePayCash}
          onPayCard={handlePayCard}
          onQuantityTap={handleQuantityTap}
          onDiscount={() => setShowDiscountModal(true)}
          onHold={handleHold}
          onRecall={() => setShowHeldModal(true)}
          onReports={() => setShowReportsMenu(true)}
          onLineDiscount={handleLineDiscount}
          shiftNumber={shift.shift_number}
          openingCash={shift.opening_cash}
          paymentMethods={paymentMethods}
        />
      </div>

      {/* Cash tendered modal */}
      <CashTenderedModal
        isOpen={showCashModal}
        onClose={() => setShowCashModal(false)}
        onConfirm={(amount) => void handleCashConfirm(amount)}
        total={total()}
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
        />
      )}

      {/* Card payment modal */}
      <CardPaymentModal
        isOpen={showCardModal}
        onClose={() => setShowCardModal(false)}
        total={total()}
        onConfirm={(data) => void handleCardConfirm(data)}
        isProcessing={isProcessing}
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
        maxDiscountPercent={100}
        requiresReason={false}
      />

      {/* Line discount modal */}
      <LineDiscountModal
        isOpen={discountItemId !== null}
        onClose={() => setDiscountItemId(null)}
        onApply={handleApplyLineDiscount}
        itemName={discountItem?.product.name ?? ''}
        maxDiscountPercent={100}
      />

      {/* Modifier selection modal */}
      <ModifierSelectionModal
        isOpen={modifierProduct !== null}
        onClose={() => setModifierProduct(null)}
        product={modifierProduct}
        onConfirm={handleModifierConfirm}
      />

      {/* Reports menu */}
      <ReportsMenu
        isOpen={showReportsMenu}
        onClose={() => setShowReportsMenu(false)}
        onXReport={() => { /* Reports handled by API */ }}
        onZReport={() => { /* Reports handled by API */ }}
        onTransactionHistory={() => { /* Future navigation */ }}
        onCashDrawerOps={() => { setShowReportsMenu(false); setShowCashDrawerModal(true); }}
      />

      {/* Void/Return modal */}
      <VoidReturnModal
        isOpen={showVoidReturnModal}
        onClose={() => setShowVoidReturnModal(false)}
      />

      {/* Cash drawer modal */}
      {shift && (
        <CashDrawerModal
          isOpen={showCashDrawerModal}
          onClose={() => setShowCashDrawerModal(false)}
          shiftId={shift.id}
        />
      )}

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
