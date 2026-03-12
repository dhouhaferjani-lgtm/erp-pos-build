import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { Plus, Minus, Trash2, Tag } from 'lucide-react';
import type { CartItem } from '@/types/cart';

export interface CartLineItemProps {
  item: CartItem;
  onUpdateQuantity: (itemId: string, newQuantity: number) => void;
  onRemove: (itemId: string) => void;
  onQuantityTap?: (itemId: string) => void;
  onDiscount?: (itemId: string) => void;
}

export function CartLineItem({ item, onUpdateQuantity, onRemove, onQuantityTap, onDiscount }: CartLineItemProps) {
  const { t } = useTranslation();
  const { format } = useCurrency();

  const handleIncrement = () => {
    onUpdateQuantity(item.id, item.quantity + 1);
  };

  const handleDecrement = () => {
    if (item.quantity === 1) {
      onRemove(item.id);
    } else {
      onUpdateQuantity(item.id, item.quantity - 1);
    }
  };

  return (
    <div className="rounded-lg border border-gray-200 bg-white p-4 transition-all duration-150">
      {/* Row 1: Name + Line Total */}
      <div className="flex items-center justify-between gap-2">
        <h4 className="truncate text-base font-semibold text-gray-900">
          {item.product.name}
        </h4>
        <span className="shrink-0 text-lg font-bold text-gray-900">
          {format(item.line_total)}
        </span>
      </div>

      {/* Modifiers */}
      {item.product.selectedModifiers && item.product.selectedModifiers.length > 0 && (
        <p className="mt-0.5 text-xs italic text-gray-400">
          {item.product.selectedModifiers.map((m) => m.name).join(', ')}
        </p>
      )}

      {/* Line discount info */}
      {item.discount_amount && parseFloat(item.discount_amount) > 0 && (
        <p className="mt-0.5 text-xs text-primary-600">
          {item.discount_type === 'percentage' && item.discount_percent
            ? `−${item.discount_percent}%`
            : `−${format(item.discount_amount)}`}
          {item.discount_reason ? ` (${item.discount_reason})` : ''}
        </p>
      )}

      {/* Row 2: Price x Qty | Controls | Discount | Delete */}
      <div className="mt-2 flex items-center justify-between">
        <span className="text-sm text-gray-600">
          {format(item.unit_price)} &times; {item.quantity}
        </span>

        <div className="flex items-center gap-2">
          {/* Quantity controls */}
          <button
            onClick={handleDecrement}
            className="flex h-12 w-12 items-center justify-center rounded-lg border border-gray-300 bg-gray-50 text-gray-700 active:bg-gray-200"
            aria-label={t('cart.decrementQty')}
          >
            <Minus className="h-5 w-5" />
          </button>

          <button
            onClick={() => onQuantityTap?.(item.id)}
            className="min-w-[2rem] text-center text-xl font-bold text-gray-900"
            type="button"
          >
            {item.quantity}
          </button>

          <button
            onClick={handleIncrement}
            className="flex h-12 w-12 items-center justify-center rounded-lg border border-gray-300 bg-gray-50 text-gray-700 active:bg-gray-200"
            aria-label={t('cart.incrementQty')}
          >
            <Plus className="h-5 w-5" />
          </button>

          {/* Line discount button */}
          {onDiscount && (
            <button
              onClick={() => onDiscount(item.id)}
              className="flex h-12 w-12 items-center justify-center rounded-lg bg-primary-50 text-primary-600 active:bg-primary-100"
              aria-label={t('cart.lineDiscount')}
            >
              <Tag className="h-5 w-5" />
            </button>
          )}

          {/* Delete button */}
          <button
            onClick={() => onRemove(item.id)}
            className="flex h-12 w-12 items-center justify-center rounded-lg bg-red-50 text-red-600 active:bg-red-100"
            aria-label={t('cart.removeItem')}
          >
            <Trash2 className="h-5 w-5" />
          </button>
        </div>
      </div>
    </div>
  );
}
