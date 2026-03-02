import { useState, useMemo, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { cn } from '@/lib/utils'
import { ProductGrid, TransactionCart, Calculator } from '../../organisms'
import { POSLayout } from '../../layouts'
import type { Product } from '../../molecules'
import type { Customer } from '../../organisms/TransactionCart'
import type { CartItem } from '../../molecules/CartLineItem'
import { useCurrency } from '@/hooks/useCurrency'

export interface POSPageProps {
  products: Product[]
  onQuickCheckout: (items: CartItem[]) => void
  onAdvancedPayments: (items: CartItem[]) => void
  onProductInfo: (product: Product) => void
  selectedCustomer?: Customer | null
  onChangeCustomer?: () => void
  touchOptimized?: boolean
  isLoading?: boolean
  className?: string
  terminalCode?: string
  transactionDiscount?: { amount: string; reason?: string }
  onTransactionDiscountChange?: (discount: { amount: string; reason?: string } | undefined) => void
  onEditLineDiscount?: (productId: string, discount: { type: 'percentage' | 'fixed'; value: string; reason?: string } | undefined) => void
}

export function POSPage({
  products,
  onQuickCheckout,
  onAdvancedPayments,
  onProductInfo,
  selectedCustomer = null,
  onChangeCustomer,
  touchOptimized = false,
  isLoading = false,
  className,
  terminalCode,
  transactionDiscount,
  onTransactionDiscountChange,
  onEditLineDiscount: externalEditLineDiscount,
}: POSPageProps) {
  const { t } = useTranslation(['common'])
  const navigate = useNavigate()
  const { decimals } = useCurrency()
  const [cartItems, setCartItems] = useState<CartItem[]>([])
  const [isCalculatorOpen, setIsCalculatorOpen] = useState(false)
  const [screenWidth, setScreenWidth] = useState(window.innerWidth)

  // Responsive breakpoint: stack vertically on tablets < 768px
  const isNarrowScreen = screenWidth < 768

  // Handle exit POS - return to dashboard
  const handleExitPOS = () => {
    navigate('/dashboard')
  }

  // Window resize listener for responsive layout
  useEffect(() => {
    const handleResize = () => {
      setScreenWidth(window.innerWidth)
    }

    window.addEventListener('resize', handleResize)
    return () => {
      window.removeEventListener('resize', handleResize)
    }
  }, [])

  // Keyboard shortcuts
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      // Ctrl+K or Cmd+K to open calculator
      if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault()
        setIsCalculatorOpen(true)
      }
    }

    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [])

  // Get array of product IDs in cart
  const cartProductIds = useMemo(() => {
    return cartItems.map((item) => item.product.id)
  }, [cartItems])

  // Add product to cart
  const handleAddToCart = (product: Product) => {
    setCartItems((prev) => {
      const existing = prev.find((item) => item.product.id === product.id)
      if (existing) {
        // Increment quantity, recalculate discount if percentage-based
        return prev.map((item): CartItem => {
          if (item.product.id !== product.id) return item

          const newQty = item.quantity + 1
          const grossTotal = parseFloat(item.unit_price) * newQty
          let discountAmount = 0

          if (item.discount_type === 'percentage' && item.discount_percent) {
            discountAmount = (grossTotal * parseFloat(item.discount_percent)) / 100
          } else if (item.discount_amount && item.discount_type === 'fixed') {
            discountAmount = parseFloat(item.discount_amount)
          }

          const lineTotal = Math.max(0, grossTotal - discountAmount)

          return {
            ...item,
            quantity: newQty,
            ...(item.discount_type === 'percentage' ? { discount_amount: discountAmount.toFixed(decimals) } : {}),
            line_total: lineTotal.toFixed(decimals),
          }
        })
      } else {
        // Add new item
        const priceValue = product.sale_price || '0'
        const unitPrice = parseFloat(priceValue)
        return [
          ...prev,
          {
            id: `cart-${Date.now()}-${product.id}`,
            product: {
              id: product.id,
              name: product.name,
              sku: product.sku,
              price: priceValue,
            },
            quantity: 1,
            unit_price: priceValue,
            line_total: unitPrice.toFixed(decimals),
            tax_amount: (0).toFixed(decimals),
          },
        ]
      }
    })
  }

  // Update cart item quantity
  const handleUpdateQuantity = (productId: string, quantity: number) => {
    if (quantity <= 0) {
      handleRemoveItem(productId)
      return
    }

    setCartItems((prev) =>
      prev.map((item): CartItem => {
        if (item.product.id !== productId) return item

        const grossTotal = parseFloat(item.unit_price) * quantity
        let discountAmount = 0

        if (item.discount_type === 'percentage' && item.discount_percent) {
          discountAmount = (grossTotal * parseFloat(item.discount_percent)) / 100
        } else if (item.discount_amount && item.discount_type === 'fixed') {
          discountAmount = parseFloat(item.discount_amount)
        }

        const lineTotal = Math.max(0, grossTotal - discountAmount)

        return {
          ...item,
          quantity,
          ...(item.discount_type === 'percentage' ? { discount_amount: discountAmount.toFixed(decimals) } : {}),
          line_total: lineTotal.toFixed(decimals),
        }
      })
    )
  }

  // Remove item from cart
  const handleRemoveItem = (productId: string) => {
    setCartItems((prev) => prev.filter((item) => item.product.id !== productId))
  }

  // Edit line discount
  const handleEditLineDiscount = (
    productId: string,
    discount: { type: 'percentage' | 'fixed'; value: string; reason?: string } | undefined,
  ) => {
    if (externalEditLineDiscount) {
      externalEditLineDiscount(productId, discount)
      return
    }

    setCartItems((prev) =>
      prev.map((item): CartItem => {
        if (item.product.id !== productId) return item

        const grossTotal = parseFloat(item.unit_price) * item.quantity

        if (!discount) {
          // Clear discount — omit discount fields entirely
          const { discount_type: _dt, discount_percent: _dp, discount_amount: _da, discount_reason: _dr, ...rest } = item
          return {
            ...rest,
            line_total: grossTotal.toFixed(decimals),
          }
        }

        let discountAmount: number

        if (discount.type === 'percentage') {
          discountAmount = (grossTotal * parseFloat(discount.value)) / 100
        } else {
          discountAmount = parseFloat(discount.value)
        }

        const lineTotal = Math.max(0, grossTotal - discountAmount)

        return {
          ...item,
          discount_type: discount.type,
          discount_amount: discountAmount.toFixed(decimals),
          line_total: lineTotal.toFixed(decimals),
          ...(discount.type === 'percentage' ? { discount_percent: discount.value } : {}),
          ...(discount.reason != null ? { discount_reason: discount.reason } : {}),
        }
      })
    )
  }

  // Clear cart
  const handleClearCart = () => {
    setCartItems([])
  }

  // Handle quick checkout
  const handleQuickCheckout = () => {
    if (cartItems.length === 0) return
    onQuickCheckout(cartItems)
  }

  // Handle advanced payments
  const handleAdvancedPayments = () => {
    if (cartItems.length === 0) return
    onAdvancedPayments(cartItems)
  }

  if (isLoading) {
    return (
      <POSLayout
        onExitPOS={handleExitPOS}
        terminalCode={terminalCode}
      >
        <div className="flex h-full items-center justify-center">
          <div className="text-center">
            <div className="text-lg font-medium text-gray-600">{t('common:loading')}</div>
          </div>
        </div>
      </POSLayout>
    )
  }

  return (
    <POSLayout
      onExitPOS={handleExitPOS}
      terminalCode={terminalCode}
    >
      <div
        className={cn(
          'h-full overflow-hidden bg-gray-50',
          isNarrowScreen ? 'flex flex-col' : 'flex',
          touchOptimized ? 'p-6' : 'p-4',
          className
        )}
      >
        {/* Product Grid - 60% wide on desktop, 50% tall on narrow screens */}
        <div
          className={cn(
            'overflow-y-auto overflow-x-hidden',
            isNarrowScreen ? 'h-1/2' : 'flex-[3]'
          )}
        >
          <ProductGrid
            products={products}
            onAddToCart={handleAddToCart}
            onShowProductInfo={onProductInfo}
            cartProductIds={cartProductIds}
            touchOptimized={touchOptimized}
          />
        </div>

        {/* Transaction Cart - 40% wide on desktop, 50% tall on narrow screens */}
        <div
          className={cn(
            'overflow-hidden',
            isNarrowScreen ? 'h-1/2' : 'flex-[2]'
          )}
        >
          <TransactionCart
            items={cartItems}
            onUpdateQuantity={handleUpdateQuantity}
            onRemoveItem={handleRemoveItem}
            onEditLineDiscount={handleEditLineDiscount}
            onClearCart={handleClearCart}
            onQuickCheckout={handleQuickCheckout}
            onAdvancedPayments={handleAdvancedPayments}
            onOpenCalculator={() => setIsCalculatorOpen(true)}
            selectedCustomer={selectedCustomer}
            onChangeCustomer={onChangeCustomer}
            touchOptimized={touchOptimized}
            terminalCode={terminalCode}
            transactionDiscount={transactionDiscount}
            onUpdateTransactionDiscount={onTransactionDiscountChange}
          />
        </div>

        {/* Calculator Modal */}
        <Calculator
          isOpen={isCalculatorOpen}
          onClose={() => setIsCalculatorOpen(false)}
          touchOptimized={touchOptimized}
        />
      </div>
    </POSLayout>
  )
}
