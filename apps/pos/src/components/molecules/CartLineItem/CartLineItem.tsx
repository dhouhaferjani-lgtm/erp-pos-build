import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { Plus, Minus, Trash2, Tag, SlidersHorizontal, X } from 'lucide-react';
import type { CartItem } from '@/types/cart';

export interface CartLineItemProps {
  item: CartItem;
  onUpdateQuantity: (itemId: string, newQuantity: number) => void;
  onRemove: (itemId: string) => void;
  onQuantityTap?: (itemId: string) => void;
  onDiscount?: (itemId: string) => void;
  onEditModifiers?: (itemId: string) => void;
  onRemoveDiscount?: (itemId: string) => void;
}

export function CartLineItem({ item, onUpdateQuantity, onRemove, onQuantityTap, onDiscount, onEditModifiers, onRemoveDiscount }: CartLineItemProps) {
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
    <div className="bg-white px-1 py-1.5 transition-all duration-150">
      {/* Row 1: Name + Line Total */}
      <div className="flex items-center justify-between gap-2">
        <h4 className="truncate text-sm font-semibold text-gray-900">
          {item.product.name}
        </h4>
        <span className="shrink-0 text-sm font-bold text-gray-900">
          {format(item.line_total)}
        </span>
      </div>

      {/* Modifiers */}
      {item.product.selectedModifiers && item.product.selectedModifiers.length > 0 && (
        <p className="mt-0.5 text-xs text-gray-600">
          {item.product.selectedModifiers.map((m) => m.name).join(', ')}
        </p>
      )}

      {/* Combo Components (fixed bundle) */}
      {item.product.comboComponents && item.product.comboComponents.length > 0 && (
        <div className="mt-1 space-y-0.5">
          {item.product.comboComponents.map((name, idx) => (
            <p key={idx} className="text-xs text-gray-600 pl-2">
              &bull; {name}
            </p>
          ))}
        </div>
      )}

      {/* Line discount info */}
      {item.discount_amount && parseFloat(item.discount_amount) > 0 && (
        <div className="mt-0.5 flex items-center gap-1">
          <p className="text-xs text-red-600">
            {item.discount_type === 'percentage' && item.discount_percent
              ? `−${item.discount_percent}%`
              : `−${format(item.discount_amount)}`}
            {item.discount_reason ? ` (${item.discount_reason})` : ''}
          </p>
          {onRemoveDiscount && (
            <button
              onClick={() => onRemoveDiscount(item.id)}
              className="rounded-md bg-red-50 px-1.5 py-0.5 text-red-700 hover:bg-red-100 active:bg-red-200"
              aria-label={t('pos:discount.removeLineDiscount')}
            >
              <X className="h-3 w-3" />
            </button>
          )}
        </div>
      )}

      {/* Row 2: Price x Qty | Controls | Discount | Delete */}
      <div className="mt-1 flex items-center justify-between">
        <span className="text-xs text-gray-700">
          {format(item.unit_price)} &times; {item.quantity}
        </span>

        <div className="flex items-center gap-1.5">
          {/* Quantity controls */}
          <button
            onClick={handleDecrement}
            className="flex h-7 w-7 items-center justify-center rounded-md border border-gray-300 bg-gray-50 text-gray-700 active:bg-gray-200"
            aria-label={t('cart.decrementQty')}
          >
            <Minus className="h-4 w-4" />
          </button>

          <button
            onClick={() => onQuantityTap?.(item.id)}
            className="min-w-[1.5rem] text-center text-base font-bold text-gray-900"
            type="button"
          >
            {item.quantity}
          </button>

          <button
            onClick={handleIncrement}
            className="flex h-7 w-7 items-center justify-center rounded-md border border-gray-300 bg-gray-50 text-gray-700 active:bg-gray-200"
            aria-label={t('cart.incrementQty')}
          >
            <Plus className="h-4 w-4" />
          </button>

          {/* Edit modifiers button */}
          {onEditModifiers && item.product.selectedModifiers && item.product.selectedModifiers.length > 0 && (
            <button
              onClick={() => onEditModifiers(item.id)}
              className="flex h-7 w-7 items-center justify-center rounded-md bg-blue-50 text-blue-700 active:bg-blue-100"
              aria-label={t('cart.editModifiers')}
              title={t('cart.editModifiers')}
            >
              <SlidersHorizontal className="h-4 w-4" />
            </button>
          )}

          {/* Line discount button */}
          {onDiscount && (
            <button
              onClick={() => onDiscount(item.id)}
              className="flex h-7 w-7 items-center justify-center rounded-md bg-primary-50 text-primary-700 active:bg-primary-100"
              aria-label={t('cart.lineDiscount')}
            >
              <Tag className="h-4 w-4" />
            </button>
          )}

          {/* Delete button */}
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
