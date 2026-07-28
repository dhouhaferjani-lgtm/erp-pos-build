import { cn } from '@/lib/utils'
import { POSButton } from '../../atoms'
import { Plus, Minus, Trash2, Tag } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { tokens, textColors, colors, borderColors } from '@/lib/designTokens'
import { formatCurrency, formatQuantity } from '@/lib/decimal'
import { getQuantityDecimals } from '@/lib/quantityScale'
import { useCurrency } from '@/hooks/useCurrency'

export interface SelectedModifier {
  modifier_id: string
  modifier_group_id: string
  name: string
  group_name: string
  price_adjustment: string
}

export interface CartItem {
  id: string
  product: {
    id: string
    name: string
    sku: string
    price: string
    quantity_decimals?: number | null
    sellableType?: 'product' | 'composite_item'
    selectedModifiers?: SelectedModifier[]
    comboComponents?: string[]
  }
  quantity: number
  unit_price: string
  line_total: string
  tax_amount?: string
  // Discount fields
  discount_type?: 'percentage' | 'fixed' | null
  discount_percent?: string
  discount_amount?: string
  discount_reason?: string
}

export interface CartLineItemProps {
  item: CartItem
  onUpdateQuantity: (productId: string, newQuantity: number) => void
  onRemove: (productId: string) => void
  onEditDiscount?: (productId: string) => void
  touchOptimized?: boolean
  showTax?: boolean
  showDiscount?: boolean
  disabled?: boolean
  className?: string
}

export function CartLineItem({
  item,
  onUpdateQuantity,
  onRemove,
  onEditDiscount,
  touchOptimized = false,
  showTax = false,
  showDiscount = false,
  disabled = false,
  className,
}: CartLineItemProps) {
  const { t } = useTranslation(['pos', 'common'])
  const { currency } = useCurrency()
  const [touchStart, setTouchStart] = useState<number>(0)
  const [showDelete, setShowDelete] = useState(false)

  // Check if item has discount
  const hasDiscount = item.discount_type && (item.discount_percent || item.discount_amount)
  const quantity = formatQuantity(item.quantity, getQuantityDecimals(item.product))

  const handleIncrement = () => {
    onUpdateQuantity(item.product.id, item.quantity + 1)
  }

  const handleDecrement = () => {
    if (item.quantity === 1) {
      onRemove(item.product.id)
    } else {
      onUpdateQuantity(item.product.id, item.quantity - 1)
    }
  }

  const handleRemove = () => {
    onRemove(item.product.id)
  }

  const handleTouchStart = (e: React.TouchEvent) => {
    if (touchOptimized) {
      setTouchStart(e.touches[0].clientX)
    }
  }

  const handleTouchEnd = (e: React.TouchEvent) => {
    if (touchOptimized) {
      const touchEnd = e.changedTouches[0].clientX
      const diff = touchStart - touchEnd

      // Swipe left (diff > 80 means significant swipe)
      if (diff > 80) {
        setShowDelete(true)
      }
      // Swipe right to cancel delete
      else if (diff < -80) {
        setShowDelete(false)
      }
    }
  }

  return (
    <div
      onTouchStart={handleTouchStart}
      onTouchEnd={handleTouchEnd}
      className={cn(
        'relative flex items-center gap-3',
        colors.white,
        'rounded-lg border',
        borderColors.light,
        'transition-all duration-150',
        touchOptimized ? 'p-4' : 'p-3',
        disabled && 'opacity-50',
        className
      )}
    >
      {/* Product Info */}
      <div className="flex-1 min-w-0">
        <h4
          className={cn(
            'font-semibold truncate',
            textColors.primary,
            touchOptimized ? 'text-lg' : 'text-base'
          )}
        >
          {item.product.name}
        </h4>
        <p
          className={cn(
            textColors.tertiary,
            'font-mono',
            touchOptimized ? 'text-sm' : 'text-xs'
          )}
        >
          {item.product.sku}
        </p>

        {/* Selected Modifiers */}
        {item.product.selectedModifiers && item.product.selectedModifiers.length > 0 && (
          <p
            className={cn(
              textColors.tertiary,
              'italic',
              touchOptimized ? 'text-sm' : 'text-xs'
            )}
          >
            {item.product.selectedModifiers.map((m) => m.name).join(', ')}
          </p>
        )}

        {/* Combo Components (fixed bundle) */}
        {item.product.comboComponents && item.product.comboComponents.length > 0 && (
          <div className={cn('mt-1 space-y-0.5', touchOptimized ? 'text-sm' : 'text-xs')}>
            {item.product.comboComponents.map((name, idx) => (
              <p key={idx} className={cn(textColors.tertiary, 'pl-2')}>
                &bull; {name}
              </p>
            ))}
          </div>
        )}

        {/* Price and Quantity */}
        <div className="flex items-center gap-2 mt-1">
          <span
            className={cn(
              'tabular-nums',
              textColors.tertiary,
              touchOptimized ? 'text-base' : 'text-sm'
            )}
          >
            {item.unit_price} {currency} × {quantity}
          </span>
          {showTax && item.tax_amount && (
            <span
              className={cn(
                'tabular-nums',
                textColors.tertiary,
                touchOptimized ? 'text-sm' : 'text-xs'
              )}
            >
              ({t('cart.tax')}: {item.tax_amount} {currency})
            </span>
          )}
        </div>

        {/* Discount Badge and Info */}
        {showDiscount && hasDiscount && (
          <div className="mt-2 space-y-1">
            <div className="flex items-center gap-2">
              <span className={cn(tokens.badge.yellow, 'text-xs flex items-center gap-1')}>
                <Tag className="w-3 h-3" />
                {t('cart.discount')}:{' '}
                {item.discount_type === 'percentage'
                  ? `-${item.discount_percent}%`
                  : `-${formatCurrency(item.discount_amount || '0', false, currency)} ${currency}`}
              </span>
              {onEditDiscount && (
                <button
                  onClick={() => { onEditDiscount(item.product.id); }}
                  className={cn(
                    'text-xs',
                    textColors.brand,
                    'hover:underline'
                  )}
                  disabled={disabled}
                >
                  {t('cart.editDiscount')}
                </button>
              )}
            </div>
            {item.discount_reason && (
              <p className={cn('text-xs', textColors.tertiary, 'italic')}>
                {item.discount_reason}
              </p>
            )}
          </div>
        )}
      </div>

      {/* Quantity Controls */}
      <div className="flex items-center gap-2">
        <POSButton
          variant="secondary"
          size={touchOptimized ? 'md' : 'sm'}
          onClick={handleDecrement}
          disabled={disabled}
          touchOptimized={touchOptimized}
          icon={<Minus className="w-4 h-4" />}
          aria-label={t('common:pos.decrementQuantity')}
        />

        <span
          className={cn(
            'font-bold tabular-nums min-w-[2rem] text-center',
            textColors.primary,
            touchOptimized ? 'text-xl' : 'text-lg'
          )}
        >
          {quantity}
        </span>

        <POSButton
          variant="secondary"
          size={touchOptimized ? 'md' : 'sm'}
          onClick={handleIncrement}
          disabled={disabled}
          touchOptimized={touchOptimized}
          icon={<Plus className="w-4 h-4" />}
          aria-label={t('common:pos.incrementQuantity')}
        />
      </div>

      {/* Line Total */}
      <div className="flex items-center gap-3">
        <span
          className={cn(
            'font-bold tabular-nums min-w-[6rem] text-end',
            textColors.brand,
            touchOptimized ? 'text-xl' : 'text-lg'
          )}
        >
          {item.line_total} {currency}
        </span>

        {/* Remove Button */}
        <POSButton
          variant="danger"
          size={touchOptimized ? 'md' : 'sm'}
          onClick={handleRemove}
          disabled={disabled}
          touchOptimized={touchOptimized}
          icon={<Trash2 className="w-4 h-4" />}
          aria-label={t('common:pos.removeItem')}
          className={cn(
            showDelete && touchOptimized && 'animate-bounce'
          )}
        />
      </div>
    </div>
  )
}
