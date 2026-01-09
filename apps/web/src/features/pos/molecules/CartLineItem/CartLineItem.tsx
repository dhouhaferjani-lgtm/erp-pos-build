import { cn } from '@/lib/utils'
import { POSButton } from '../../atoms'
import { Plus, Minus, Trash2 } from 'lucide-react'
import { useState } from 'react'

export interface CartItem {
  id: string
  product: {
    id: string
    name: string
    sku: string
    price: string
  }
  quantity: number
  unit_price: string
  line_total: string
  tax_amount?: string
}

export interface CartLineItemProps {
  item: CartItem
  onUpdateQuantity: (productId: string, newQuantity: number) => void
  onRemove: (productId: string) => void
  touchOptimized?: boolean
  showTax?: boolean
  disabled?: boolean
  className?: string
}

export function CartLineItem({
  item,
  onUpdateQuantity,
  onRemove,
  touchOptimized = false,
  showTax = false,
  disabled = false,
  className,
}: CartLineItemProps) {
  const [touchStart, setTouchStart] = useState<number>(0)
  const [showDelete, setShowDelete] = useState(false)

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
        'bg-white rounded-lg border border-gray-200',
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
            'font-semibold text-gray-900 truncate',
            touchOptimized ? 'text-lg' : 'text-base'
          )}
        >
          {item.product.name}
        </h4>
        <p
          className={cn(
            'text-gray-500 font-mono',
            touchOptimized ? 'text-sm' : 'text-xs'
          )}
        >
          {item.product.sku}
        </p>
        <div className="flex items-center gap-2 mt-1">
          <span
            className={cn(
              'text-gray-600',
              touchOptimized ? 'text-base' : 'text-sm'
            )}
          >
            {item.unit_price} TND × {item.quantity}
          </span>
          {showTax && item.tax_amount && (
            <span
              className={cn(
                'text-gray-500',
                touchOptimized ? 'text-sm' : 'text-xs'
              )}
            >
              (Tax: {item.tax_amount} TND)
            </span>
          )}
        </div>
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
          aria-label="Decrement quantity"
        />

        <span
          className={cn(
            'font-bold text-gray-900 min-w-[2rem] text-center',
            touchOptimized ? 'text-xl' : 'text-lg'
          )}
        >
          {item.quantity}
        </span>

        <POSButton
          variant="secondary"
          size={touchOptimized ? 'md' : 'sm'}
          onClick={handleIncrement}
          disabled={disabled}
          touchOptimized={touchOptimized}
          icon={<Plus className="w-4 h-4" />}
          aria-label="Increment quantity"
        />
      </div>

      {/* Line Total */}
      <div className="flex items-center gap-3">
        <span
          className={cn(
            'font-bold text-blue-600 min-w-[6rem] text-end',
            touchOptimized ? 'text-xl' : 'text-lg'
          )}
        >
          {item.line_total} TND
        </span>

        {/* Remove Button */}
        <POSButton
          variant="danger"
          size={touchOptimized ? 'md' : 'sm'}
          onClick={handleRemove}
          disabled={disabled}
          touchOptimized={touchOptimized}
          icon={<Trash2 className="w-4 h-4" />}
          aria-label="Remove item"
          className={cn(
            showDelete && touchOptimized && 'animate-bounce'
          )}
        />
      </div>
    </div>
  )
}
