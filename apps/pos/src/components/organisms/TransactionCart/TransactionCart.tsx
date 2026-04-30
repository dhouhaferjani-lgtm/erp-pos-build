import { useTranslation } from 'react-i18next';
import { ShoppingCart, Trash2, RotateCcw } from 'lucide-react';
import { CartLineItem } from '@/components/molecules/CartLineItem';
import { PaymentSummary } from '@/components/organisms/PaymentSummary';
import { QuickActions } from '@/components/molecules/QuickActions';
import { useCurrency } from '@/lib/currency';
import type { CartItem } from '@/types/cart';
import type { PaymentMethod } from '@/types/payment';

export interface TransactionCartProps {
  items: CartItem[];
  subtotal: number;
  taxAmount: number;
  discountAmount: number;
  total: number;
  itemCount: number;
  hasDiscount: boolean;
  onUpdateQuantity: (itemId: string, quantity: number) => void;
  onRemoveItem: (itemId: string) => void;
  onClearCart: () => void;
  onPayCash: () => void;
  onAdvancedPayments?: () => void;
  onQuantityTap?: (itemId: string) => void;
  onDiscount?: () => void;
  onHold?: () => void;
  onRecall?: () => void;
  /** Opens the Returns / Exchange receipt-locator screen. */
  onReturns?: () => void;
  onLineDiscount?: (itemId: string) => void;
  onRemoveLineDiscount?: (itemId: string) => void;
  onEditModifiers?: (itemId: string) => void;
  onRemoveDiscount?: () => void;
  paymentMethods?: PaymentMethod[];
  checkoutDisabled?: boolean;
  /**
   * Net amount for the refund/exchange flow (sale total minus return total).
   * When provided, the footer shows the net amount and the confirm button label
   * adapts to "Charge X" / "Refund X" / "No payment due".
   * When absent, the normal `total` and "Cash payment" button are shown.
   */
  netTotal?: number;
}

export function TransactionCart({
  items,
  subtotal,
  taxAmount,
  discountAmount,
  total,
  itemCount,
  hasDiscount,
  onUpdateQuantity,
  onRemoveItem,
  onClearCart,
  onPayCash,
  onAdvancedPayments,
  onQuantityTap,
  onDiscount,
  onHold,
  onRecall,
  onReturns,
  onLineDiscount,
  onRemoveLineDiscount,
  onEditModifiers,
  onRemoveDiscount,
  paymentMethods,
  checkoutDisabled = false,
  netTotal,
}: TransactionCartProps) {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();

  const returnItems = items.filter((i) => (i.kind ?? 'sale') === 'return');
  const saleItems = items.filter((i) => (i.kind ?? 'sale') === 'sale');
  const isRefundMode = returnItems.length > 0;

  // Determine confirm button label when in refund/exchange mode
  function getNetLabel(): string {
    if (netTotal === undefined) return '';
    const formatted = format(Math.abs(netTotal));
    if (netTotal > 0.005) return t('refundFlow.confirm.charge', { amount: formatted });
    if (netTotal < -0.005) return t('refundFlow.confirm.refund', { amount: formatted });
    return t('refundFlow.confirm.noPayment');
  }

  return (
    <div className="flex h-full min-h-0 flex-col overflow-hidden bg-white">
      {/* Header */}
      <div className="flex shrink-0 items-center justify-between border-b border-gray-200 px-3 py-2">
        <div className="flex items-center gap-2">
          <ShoppingCart className="h-5 w-5 text-gray-600" />
          <h2 className="text-lg font-bold text-gray-900">{t('cart.title')}</h2>
          {itemCount > 0 && (
            <span className="flex h-7 min-w-[28px] items-center justify-center rounded-full bg-primary-500 px-2 text-sm font-medium text-white">
              {itemCount}
            </span>
          )}
        </div>

        {items.length > 0 && (
          <button
            onClick={onClearCart}
            className="flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 active:bg-red-100"
          >
            <Trash2 className="h-4 w-4" />
            {t('cart.clear')}
          </button>
        )}
      </div>

      {/* Quick actions */}
      {onDiscount && onHold && onRecall && onReturns && (
        <div className="shrink-0">
          <QuickActions
            onDiscount={onDiscount}
            onHold={onHold}
            onRecall={onRecall}
            onReturns={onReturns}
            hasItems={items.length > 0}
            hasDiscount={hasDiscount}
          />
        </div>
      )}

      {/* Cart items */}
      <div className="min-h-0 flex-1 overflow-y-auto px-2 py-0.5">
        {items.length === 0 ? (
          <div className="flex h-full flex-col items-center justify-center text-center text-gray-600">
            <ShoppingCart className="mb-3 h-12 w-12" />
            <p className="text-base font-medium">{t('cart.empty')}</p>
            <p className="mt-1 text-sm">{t('cart.addProducts')}</p>
          </div>
        ) : isRefundMode ? (
          // ── Refund / Exchange mode: two-section layout ──────────────────────
          <div>
            {/* ── Returning section ─────────────────────────────────────── */}
            <div className="mb-1 flex items-center gap-1.5 border-b border-red-200 bg-red-50 px-2 py-1">
              <RotateCcw className="h-4 w-4 text-red-600" aria-hidden="true" />
              <span className="text-sm font-semibold text-red-700">
                {t('refundFlow.returningSection')}
              </span>
            </div>
            <div className="divide-y divide-red-100">
              {returnItems.map((item) => (
                <div key={item.id} className="bg-red-50/50">
                  <ReturnLineItem
                    item={item}
                    onUpdateQuantity={onUpdateQuantity}
                    onRemove={onRemoveItem}
                    onQuantityTap={onQuantityTap}
                    format={format}
                    t={t}
                  />
                </div>
              ))}
            </div>

            {/* ── Buying new section (only when sale items exist) ────────── */}
            {saleItems.length > 0 && (
              <>
                <div className="mb-1 mt-2 flex items-center gap-1.5 border-b border-gray-200 bg-gray-50 px-2 py-1">
                  <ShoppingCart className="h-4 w-4 text-gray-600" aria-hidden="true" />
                  <span className="text-sm font-semibold text-gray-700">
                    {t('refundFlow.buyingNewSection')}
                  </span>
                </div>
                <div className="divide-y divide-gray-200">
                  {saleItems.map((item) => (
                    <CartLineItem
                      key={item.id}
                      item={item}
                      onUpdateQuantity={onUpdateQuantity}
                      onRemove={onRemoveItem}
                      onQuantityTap={onQuantityTap}
                      onDiscount={onLineDiscount}
                      onEditModifiers={onEditModifiers}
                      onRemoveDiscount={onRemoveLineDiscount}
                    />
                  ))}
                </div>
              </>
            )}
          </div>
        ) : (
          // ── Normal sale mode ───────────────────────────────────────────────
          <div className="divide-y divide-gray-200">
            {items.map((item) => (
              <CartLineItem
                key={item.id}
                item={item}
                onUpdateQuantity={onUpdateQuantity}
                onRemove={onRemoveItem}
                onQuantityTap={onQuantityTap}
                onDiscount={onLineDiscount}
                onEditModifiers={onEditModifiers}
                onRemoveDiscount={onRemoveLineDiscount}
              />
            ))}
          </div>
        )}
      </div>

      {/* Payment summary + actions */}
      <div className="shrink-0 px-3 pb-2">
        {items.length > 0 && isRefundMode && netTotal !== undefined ? (
          // ── Net footer for refund/exchange ─────────────────────────────────
          <div className="space-y-1 border-t border-gray-200 pt-1.5">
            <div className="rounded-lg bg-primary-600 px-3 py-2 text-white">
              <div className="flex items-center justify-between">
                <span className="text-lg font-medium">{t('common.total')}</span>
                <span
                  className={`text-2xl font-bold ${netTotal < -0.005 ? 'text-red-200' : ''}`}
                >
                  {netTotal < -0.005 ? '−' : ''}{format(Math.abs(netTotal))}
                </span>
              </div>
            </div>
            <button
              onClick={onPayCash}
              disabled={checkoutDisabled}
              className="w-full rounded-lg bg-green-600 px-3 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-green-700 active:bg-green-800 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {getNetLabel()}
            </button>
          </div>
        ) : (
          items.length > 0 && (
            <PaymentSummary
              subtotal={subtotal}
              taxAmount={taxAmount}
              discountAmount={discountAmount}
              total={total}
              hasDiscount={hasDiscount}
              onPayCash={onPayCash}
              onAdvancedPayments={onAdvancedPayments}
              onRemoveDiscount={onRemoveDiscount}
              paymentMethods={paymentMethods}
              disabled={checkoutDisabled}
            />
          )
        )}
      </div>
    </div>
  );
}

// ── Returning-section line item ────────────────────────────────────────────────
// Displays with red "−" prefix on total + red text. NO strike-through (spec §6.2).
// Removing (via Trash2) = keeping the item (don't refund) — cashier intent is preserved.

interface ReturnLineItemProps {
  item: CartItem;
  onUpdateQuantity: (itemId: string, quantity: number) => void;
  onRemove: (itemId: string) => void;
  onQuantityTap?: (itemId: string) => void;
  format: (val: string | number) => string;
  t: (key: string) => string;
}

function ReturnLineItem({
  item,
  onUpdateQuantity,
  onRemove,
  onQuantityTap,
  format,
  t,
}: ReturnLineItemProps) {
  const absQty = Math.abs(item.quantity);
  const absTotal = Math.abs(parseFloat(item.line_total));

  // Decrement on a return line means reducing the return qty (closer to 0)
  const handleDecrement = () => {
    const newAbsQty = absQty - 1;
    if (newAbsQty === 0) {
      // Removing the return line = keeping the item (not returning it)
      onRemove(item.id);
    } else {
      onUpdateQuantity(item.id, -newAbsQty);
    }
  };

  const handleIncrement = () => {
    onUpdateQuantity(item.id, -(absQty + 1));
  };

  return (
    <div className="px-1 py-1.5">
      {/* Row 1: Name + Line Total (red with − prefix) */}
      <div className="flex items-center justify-between gap-2">
        <h4 className="truncate text-sm font-semibold text-red-800">
          {item.product.name}
        </h4>
        <span className="shrink-0 text-sm font-bold text-red-700">
          −{format(absTotal)}
        </span>
      </div>

      {/* Row 2: unit price × qty | controls */}
      <div className="mt-1 flex items-center justify-between">
        <span className="text-xs text-red-600">
          {format(item.unit_price)} × {absQty}
        </span>

        <div className="flex items-center gap-1.5">
          {/* Decrement (reduce return qty or keep item) */}
          <button
            onClick={handleDecrement}
            className="flex h-7 w-7 items-center justify-center rounded-md border border-red-300 bg-red-50 text-red-700 active:bg-red-200"
            aria-label={t('cart.decrementQty')}
          >
            <span className="text-base font-bold leading-none">−</span>
          </button>

          <button
            onClick={() => onQuantityTap?.(item.id)}
            className="min-w-[1.5rem] text-center text-base font-bold text-red-800"
            type="button"
          >
            {absQty}
          </button>

          {/* Increment (return more) */}
          <button
            onClick={handleIncrement}
            className="flex h-7 w-7 items-center justify-center rounded-md border border-red-300 bg-red-50 text-red-700 active:bg-red-200"
            aria-label={t('cart.incrementQty')}
          >
            <span className="text-base font-bold leading-none">+</span>
          </button>

          {/* Remove = keep item, don't refund */}
          <button
            onClick={() => onRemove(item.id)}
            className="flex h-7 w-7 items-center justify-center rounded-md bg-red-50 text-red-700 active:bg-red-100"
            aria-label={t('cart.removeItem')}
          >
            <Trash2 className="h-4 w-4" />
          </button>
        </div>
      </div>
    </div>
  );
}
