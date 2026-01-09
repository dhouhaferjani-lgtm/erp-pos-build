import { useMemo } from 'react'
import { cn } from '@/lib/utils'
import { CartLineItem, type CartItem } from '../../molecules'
import { POSButton } from '../../atoms'
import { ShoppingCart, Trash2, User, UserPlus } from 'lucide-react'

export interface Customer {
  id: string
  name: string
  phone?: string
}

export interface TransactionCartProps {
  items: CartItem[]
  onUpdateQuantity: (productId: string, newQuantity: number) => void
  onRemoveItem: (productId: string) => void
  onQuickCheckout: () => void
  onAdvancedPayments: () => void
  onOpenCalculator?: () => void
  selectedCustomer?: Customer | null
  onChangeCustomer?: () => void
  onClearCart?: () => void
  touchOptimized?: boolean
  className?: string
}

export function TransactionCart({
  items,
  onUpdateQuantity,
  onRemoveItem,
  onQuickCheckout,
  onAdvancedPayments,
  onOpenCalculator,
  selectedCustomer,
  onChangeCustomer,
  onClearCart,
  touchOptimized = false,
  className,
}: TransactionCartProps) {
  // Calculate item count for header badge
  const itemCount = useMemo(() => {
    return items.reduce((sum, item) => sum + item.quantity, 0)
  }, [items])

  const isEmpty = items.length === 0

  return (
    <div
      className={cn(
        'flex flex-col h-full bg-gray-50 rounded-lg border border-gray-200',
        touchOptimized ? 'p-6' : 'p-4',
        className
      )}
    >
      {/* Header */}
      <div className="flex items-center justify-between mb-4">
        <div className="flex items-center gap-2">
          <ShoppingCart className="w-6 h-6 text-gray-700" />
          <h2
            className={cn(
              'font-bold text-gray-900',
              touchOptimized ? 'text-2xl' : 'text-xl'
            )}
          >
            Cart
          </h2>
          {!isEmpty && (
            <span
              className={cn(
                'px-2 py-1 bg-blue-100 text-blue-800 rounded-full font-medium',
                touchOptimized ? 'text-base' : 'text-sm'
              )}
            >
              {itemCount} {itemCount === 1 ? 'item' : 'items'}
            </span>
          )}
        </div>

        {onClearCart && !isEmpty && (
          <POSButton
            variant="secondary"
            size="sm"
            onClick={onClearCart}
            icon={<Trash2 className="w-4 h-4" />}
            aria-label="Clear cart"
          >
            Clear
          </POSButton>
        )}
      </div>

      {/* Customer Section */}
      <div className="mb-4">
        <div
          className={cn(
            'flex items-center justify-between p-3 bg-white rounded-lg border',
            selectedCustomer ? 'border-green-300' : 'border-gray-300'
          )}
        >
          <div className="flex items-center gap-3">
            {selectedCustomer ? (
              <User className="w-5 h-5 text-green-600" />
            ) : (
              <UserPlus className="w-5 h-5 text-gray-400" />
            )}
            <div>
              <div
                className={cn(
                  'font-medium',
                  selectedCustomer ? 'text-gray-900' : 'text-gray-500'
                )}
              >
                {selectedCustomer ? selectedCustomer.name : 'Walk-in Customer'}
              </div>
              {selectedCustomer?.phone && (
                <div className="text-sm text-gray-500">
                  {selectedCustomer.phone}
                </div>
              )}
            </div>
          </div>

          {onChangeCustomer && (
            <POSButton
              variant="secondary"
              size="sm"
              onClick={onChangeCustomer}
              aria-label="Change customer"
            >
              Change
            </POSButton>
          )}
        </div>
      </div>

      {/* Cart Items - Add bottom padding for fixed PaymentPanel */}
      <div className="flex-1 overflow-y-auto pb-[200px] space-y-3">
        {isEmpty ? (
          <div className="flex flex-col items-center justify-center py-12 text-center">
            <ShoppingCart className="w-16 h-16 text-gray-300 mb-4" />
            <p className="text-gray-500 text-lg font-medium">Cart is empty</p>
            <p className="text-gray-400 text-sm mt-2">
              Add products to get started
            </p>
          </div>
        ) : (
          items.map((item) => (
            <CartLineItem
              key={item.product.id}
              item={item}
              onUpdateQuantity={onUpdateQuantity}
              onRemove={onRemoveItem}
              touchOptimized={touchOptimized}
            />
          ))
        )}
      </div>
    </div>
  )
}
