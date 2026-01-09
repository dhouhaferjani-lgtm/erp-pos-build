import { useState, useMemo, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { cn } from '@/lib/utils'
import { ProductGrid, TransactionCart, Calculator, PaymentPanel } from '../../organisms'
import { POSLayout } from '../../layouts'
import type { Product } from '../../molecules'
import type { Customer } from '../../organisms/TransactionCart'
import type { CartItem } from '../../molecules/CartLineItem'

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
  shiftId?: string
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
  shiftId,
}: POSPageProps) {
  const navigate = useNavigate()
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
        // Increment quantity
        return prev.map((item) =>
          item.product.id === product.id
            ? {
                ...item,
                quantity: item.quantity + 1,
                line_total: (
                  parseFloat(item.unit_price) *
                  (item.quantity + 1)
                ).toFixed(3),
              }
            : item
        )
      } else {
        // Add new item
        const unitPrice = parseFloat(product.price)
        return [
          ...prev,
          {
            id: `cart-${Date.now()}-${product.id}`,
            product: {
              id: product.id,
              name: product.name,
              sku: product.sku,
              price: product.price,
            },
            quantity: 1,
            unit_price: product.price,
            line_total: unitPrice.toFixed(3),
            tax_amount: '0.000',
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
      prev.map((item) =>
        item.product.id === productId
          ? {
              ...item,
              quantity,
              line_total: (parseFloat(item.unit_price) * quantity).toFixed(3),
            }
          : item
      )
    )
  }

  // Remove item from cart
  const handleRemoveItem = (productId: string) => {
    setCartItems((prev) => prev.filter((item) => item.product.id !== productId))
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
        shiftId={shiftId}
      >
        <div className="flex h-full items-center justify-center">
          <div className="text-center">
            <div className="text-lg font-medium text-gray-600">Loading...</div>
          </div>
        </div>
      </POSLayout>
    )
  }

  return (
    <POSLayout
      onExitPOS={handleExitPOS}
      terminalCode={terminalCode}
      shiftId={shiftId}
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
            'overflow-hidden',
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
            onClearCart={handleClearCart}
            onQuickCheckout={handleQuickCheckout}
            onAdvancedPayments={handleAdvancedPayments}
            onOpenCalculator={() => setIsCalculatorOpen(true)}
            selectedCustomer={selectedCustomer}
            onChangeCustomer={onChangeCustomer}
            touchOptimized={touchOptimized}
          />
        </div>

        {/* Calculator Modal */}
        <Calculator
          isOpen={isCalculatorOpen}
          onClose={() => setIsCalculatorOpen(false)}
          touchOptimized={touchOptimized}
        />
      </div>

      {/* Fixed Payment Panel - Responsive positioning */}
      <PaymentPanel
        items={cartItems}
        onQuickCheckout={handleQuickCheckout}
        onAdvancedPayments={handleAdvancedPayments}
        onOpenCalculator={() => setIsCalculatorOpen(true)}
        touchOptimized={touchOptimized}
        isNarrowScreen={isNarrowScreen}
      />
    </POSLayout>
  )
}
