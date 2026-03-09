import { useState, useMemo, useEffect, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { cn } from '@/lib/utils'
import { ProductGrid, TransactionCart, Calculator } from '../../organisms'
import { ModifierSelectionModal } from '../../organisms/ModifierSelectionModal'
import { POSLayout } from '../../layouts'
import type { Product } from '../../molecules'
import type { Customer } from '../../organisms/TransactionCart'
import type { CartItem, SelectedModifier } from '../../molecules/CartLineItem'
import { useCurrency } from '@/hooks/useCurrency'
import { useBarcodeScanner } from '../../hooks/useBarcodeScanner'
import { ConsumptionModeToggle, type ConsumptionMode } from '../../atoms/ConsumptionModeToggle/ConsumptionModeToggle'
import { toast } from 'sonner'

export interface POSPageProps {
  products: Product[]
  categories?: string[]
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
  loyaltyMember?: import('../../api/loyaltyApi').LoyaltyMember | null
  loyaltyEnrollment?: import('../../api/loyaltyApi').LoyaltyEnrollment | null
  consumptionMode?: ConsumptionMode
  onConsumptionModeChange?: (mode: ConsumptionMode) => void
}

export function POSPage({
  products,
  categories,
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
  loyaltyMember,
  loyaltyEnrollment,
  consumptionMode,
  onConsumptionModeChange,
}: POSPageProps) {
  const { t } = useTranslation(['common', 'pos'])
  const navigate = useNavigate()
  const { decimals } = useCurrency()
  const [cartItems, setCartItems] = useState<CartItem[]>([])
  const [isCalculatorOpen, setIsCalculatorOpen] = useState(false)
  const [screenWidth, setScreenWidth] = useState(window.innerWidth)
  const [modifierProduct, setModifierProduct] = useState<Product | null>(null)

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

  // Add item to cart (internal — no modifier check)
  const addItemToCart = useCallback(
    (product: Product, selectedModifiers?: SelectedModifier[]) => {
      setCartItems((prev) => {
        // For items with modifiers, always add a new line (different modifier combos)
        const hasModifiers = selectedModifiers && selectedModifiers.length > 0
        const existing = hasModifiers
          ? undefined
          : prev.find((item) => item.product.id === product.id && !item.product.selectedModifiers?.length)

        if (existing) {
          // Increment quantity, recalculate discount if percentage-based
          return prev.map((item): CartItem => {
            if (item.product.id !== product.id || item.product.selectedModifiers?.length) return item

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
          const basePrice = parseFloat(product.sale_price || '0')
          const modifierAdjustment = hasModifiers
            ? selectedModifiers.reduce((sum, m) => sum + parseFloat(m.price_adjustment), 0)
            : 0
          const unitPrice = basePrice + modifierAdjustment
          const priceValue = unitPrice.toFixed(decimals)

          const cartProduct: CartItem['product'] = {
            id: product.id,
            name: product.name,
            sku: product.sku,
            price: priceValue,
          }
          if (product.sellableType) {
            cartProduct.sellableType = product.sellableType
          }
          if (hasModifiers) {
            cartProduct.selectedModifiers = selectedModifiers
          }

          return [
            ...prev,
            {
              id: `cart-${Date.now()}-${product.id}`,
              product: cartProduct,
              quantity: 1,
              unit_price: priceValue,
              line_total: unitPrice.toFixed(decimals),
              tax_amount: (0).toFixed(decimals),
            } satisfies CartItem,
          ]
        }
      })
    },
    [decimals],
  )

  // Barcode scanner: look up product by barcode or SKU, auto-add to cart
  const handleBarcodeScan = useCallback(
    (barcode: string) => {
      const code = barcode.trim()
      // Match by barcode (exact) or SKU (exact, case-insensitive)
      const matches = products.filter(
        (p) =>
          (p.barcode && p.barcode === code) ||
          p.sku.toLowerCase() === code.toLowerCase()
      )

      if (matches.length === 1) {
        addItemToCart(matches[0])
        toast.success(t('pos:barcode.productAdded', { name: matches[0].name }))
      } else if (matches.length === 0) {
        toast.error(t('pos:barcode.productNotFound', { code }))
      } else {
        // Multiple matches — unlikely but handled
        toast.warning(t('pos:barcode.multipleMatches', { code }))
      }
    },
    [products, addItemToCart, t],
  )

  useBarcodeScanner({
    onScan: handleBarcodeScan,
    enabled: !isLoading,
  })

  // Add product to cart — always adds standard version (no modifiers)
  const handleAddToCart = (product: Product) => {
    addItemToCart(product)
  }

  // Open modifier modal for customization
  const handleCustomizeProduct = (product: Product) => {
    setModifierProduct(product)
  }

  // Handle modifier modal confirm
  const handleModifierConfirm = (selectedModifiers: SelectedModifier[]) => {
    if (modifierProduct) {
      addItemToCart(modifierProduct, selectedModifiers)
      setModifierProduct(null)
    }
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
          {/* Consumption Mode Toggle (F&B only) */}
          {consumptionMode && onConsumptionModeChange && (
            <div className="mb-4">
              <ConsumptionModeToggle
                value={consumptionMode}
                onChange={onConsumptionModeChange}
              />
            </div>
          )}

          <ProductGrid
            products={products}
            {...(categories ? { categories } : {})}
            onAddToCart={handleAddToCart}
            onShowProductInfo={onProductInfo}
            onCustomize={handleCustomizeProduct}
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
            loyaltyMember={loyaltyMember}
            loyaltyEnrollment={loyaltyEnrollment}
          />
        </div>

        {/* Calculator Modal */}
        <Calculator
          isOpen={isCalculatorOpen}
          onClose={() => setIsCalculatorOpen(false)}
          touchOptimized={touchOptimized}
        />

        {/* Modifier Selection Modal */}
        {modifierProduct && (
          <ModifierSelectionModal
            isOpen={!!modifierProduct}
            onClose={() => setModifierProduct(null)}
            product={modifierProduct}
            onConfirm={handleModifierConfirm}
          />
        )}
      </div>
    </POSLayout>
  )
}
