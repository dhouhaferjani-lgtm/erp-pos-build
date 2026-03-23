import { useTranslation } from 'react-i18next';
import { ShoppingCart, Trash2 } from 'lucide-react';
import { CartLineItem } from '@/components/molecules/CartLineItem';
import { PaymentSummary } from '@/components/organisms/PaymentSummary';
import { QuickActions } from '@/components/molecules/QuickActions';
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
  onLineDiscount?: (itemId: string) => void;
  onRemoveLineDiscount?: (itemId: string) => void;
  onEditModifiers?: (itemId: string) => void;
  onRemoveDiscount?: () => void;
  paymentMethods?: PaymentMethod[];
  shiftNumber: number;
  openingCash: string;
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
  onLineDiscount,
  onRemoveLineDiscount,
  onEditModifiers,
  onRemoveDiscount,
  paymentMethods,
  shiftNumber,
  openingCash,
}: TransactionCartProps) {
  const { t } = useTranslation('pos');

  return (
    <div className="flex h-full flex-col bg-white">
      {/* Header */}
      <div className="flex items-center justify-between border-b border-gray-200 px-3 py-2">
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
      {onDiscount && onHold && onRecall && (
        <QuickActions
          onDiscount={onDiscount}
          onHold={onHold}
          onRecall={onRecall}
          hasItems={items.length > 0}
          hasDiscount={hasDiscount}
        />
      )}

      {/* Cart items */}
      <div className="flex-1 overflow-y-auto px-3 py-1">
        {items.length === 0 ? (
          <div className="flex h-full flex-col items-center justify-center text-center text-gray-500">
            <ShoppingCart className="mb-3 h-12 w-12" />
            <p className="text-base font-medium">{t('cart.empty')}</p>
            <p className="mt-1 text-sm">{t('cart.addProducts')}</p>
          </div>
        ) : (
          <div className="divide-y divide-gray-100">
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
      <div className="px-3 pb-2">
        {items.length > 0 && (
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
          />
        )}

        {/* Shift info footer */}
        <div className="mt-1 flex justify-between border-t border-gray-100 pt-1 text-xs text-gray-600">
          <span>{t('shift.number', { number: shiftNumber })}</span>
          <span>{t('shift.opening', { amount: openingCash })}</span>
        </div>
      </div>
    </div>
  );
}
