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
import { useBarcodeLookup } from '../../hooks/useBarcodeLookup'
import { ConsumptionModeToggle, type ConsumptionMode } from '../../atoms/ConsumptionModeToggle/ConsumptionModeToggle'
import { toast } from 'sonner'
import type { POSProduct } from '../../api/productApi'
import { useDiscountPreview } from '../../hooks/useDiscountPreview'

export interface POSPageProps {
  products: Product[]
  categories?: string[] | undefined
  onQuickCheckout: (items: CartItem[]) => void
  onAdvancedPayments: (items: CartItem[]) => void
  onProductInfo: (product: Product) => void
  selectedCustomer?: Customer | null | undefined
  onChangeCustomer?: (() => void) | undefined
  touchOptimized?: boolean | undefined
  isLoading?: boolean | undefined
  className?: string | undefined
  terminalCode?: string | undefined
  transactionDiscount?: { amount: string; reason?: string | undefined } | undefined
  onTransactionDiscountChange?: ((discount: { amount: string; reason?: string | undefined } | undefined) => void) | undefined
  onEditLineDiscount?: ((productId: string, discount: { type: 'percentage' | 'fixed'; value: string; reason?: string | undefined } | undefined) => void) | undefined
  loyaltyMember?: import('../../api/loyaltyApi').LoyaltyMember | null | undefined
  loyaltyEnrollment?: import('../../api/loyaltyApi').LoyaltyEnrollment | null | undefined
  consumptionMode?: ConsumptionMode | undefined
  onConsumptionModeChange?: ((mode: ConsumptionMode) => void) | undefined
  couponCode?: string | null | undefined
  onCouponApplied?: ((code: string, discountAmount: string, promotionName: string) => void) | undefined
  onCouponRemoved?: (() => void) | undefined
  onLoyaltyRewardRedeemed?: ((rewardValue: string, rewardName: string, rewardId: string) => void) | undefined
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
  couponCode,
  onCouponApplied,
  onCouponRemoved,
  onLoyaltyRewardRedeemed,
}: POSPageProps) {
  const { t } = useTranslation(['common', 'pos'])
  const navigate = useNavigate()
  const { decimals } = useCurrency()
  const [cartItems, setCartItems] = useState<CartItem[]>([])
  const [isCalculatorOpen, setIsCalculatorOpen] = useState(false)
  const [screenWidth, setScreenWidth] = useState(window.innerWidth)
  const [modifierProduct, setModifierProduct] = useState<Product | null>(null)
  const [scanFlash, setScanFlash] = useState(false)
  const [barcodeMatchProducts, setBarcodeMatchProducts] = useState<POSProduct[]>([])
  const [barcodeMatchCode, setBarcodeMatchCode] = useState<string | null>(null)

  // Calculate subtotal for discount preview
  const cartSubtotal = useMemo(() => {
    return cartItems.reduce((sum, item) => sum + parseFloat(item.line_total), 0).toFixed(decimals)
  }, [cartItems, decimals])

  // Discount preview — calls backend to resolve promotions, coupons, loyalty
  const discountPreview = useDiscountPreview({
    cartItems,
    subtotal: cartSubtotal,
    manualDiscountAmount: transactionDiscount?.amount,
    couponCode: couponCode ?? undefined,
    customerId: selectedCustomer?.id,
  })

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

  // Convert Product to POSProduct shape for the barcode lookup hook
  const posProducts: POSProduct[] = useMemo(
    () =>
      products.map((p) => {
        const item: POSProduct = {
          id: p.id,
          name: p.name,
          sku: p.sku,
          barcode: p.barcode ?? null,
          sale_price: p.sale_price,
          stock_quantity: p.stock_quantity,
        }
        if (p.category !== undefined) item.category = p.category
        if (p.image_url !== undefined) item.image_url = p.image_url
        return item
      }),
    [products],
  )

  // Resolve a POSProduct match back to the full Product (from props) if possible,
  // otherwise adapt the POSProduct to the Product shape for cart addition.
  const resolveProduct = useCallback(
    (match: POSProduct): Product => {
      const full = products.find((p) => p.id === match.id)
      if (full) return full
      return {
        id: match.id,
        name: match.name,
        sku: match.sku,
        barcode: match.barcode ?? null,
        sale_price: match.sale_price,
        stock_quantity: match.stock_quantity,
      }
    },
    [products],
  )

  // Flash indicator when a scan is detected
  const triggerScanFlash = useCallback(() => {
    setScanFlash(true)
    const timer = setTimeout(() => setScanFlash(false), 600)
    return () => clearTimeout(timer)
  }, [])

  const { lookup, status: barcodeStatus } = useBarcodeLookup({
    localProducts: posProducts,
    onSingleMatch: (match) => {
      triggerScanFlash()
      const product = resolveProduct(match)
      addItemToCart(product)
      toast.success(t('pos:barcode.productAdded', { name: match.name }))
    },
    onNoMatch: (code) => {
      triggerScanFlash()
      toast.error(t('pos:barcode.productNotFound', { code }))
    },
    onMultipleMatches: (matches, code) => {
      triggerScanFlash()
      setBarcodeMatchProducts(matches)
      setBarcodeMatchCode(code)
    },
  })

  // Handle barcode scanner hardware input
  useBarcodeScanner({
    onScan: lookup,
    enabled: !isLoading,
  })

  // Handle manual barcode input from ProductGrid
  const handleBarcodeSubmit = useCallback(
    (code: string) => {
      lookup(code)
    },
    [lookup],
  )

  // Handle selection from multi-match modal
  const handleBarcodeMatchSelect = useCallback(
    (match: POSProduct) => {
      const product = resolveProduct(match)
      addItemToCart(product)
      toast.success(t('pos:barcode.productAdded', { name: match.name }))
      setBarcodeMatchProducts([])
      setBarcodeMatchCode(null)
    },
    [resolveProduct, addItemToCart, t],
  )

  const handleBarcodeMatchClose = useCallback(() => {
    setBarcodeMatchProducts([])
    setBarcodeMatchCode(null)
  }, [])

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
    discount: { type: 'percentage' | 'fixed'; value: string; reason?: string | undefined } | undefined,
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
            onBarcodeSubmit={handleBarcodeSubmit}
            isBarcodeSearching={barcodeStatus === 'searching'}
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
            {...(discountPreview.breakdown ? { discountBreakdown: discountPreview.breakdown } : {})}
            discountSavings={discountPreview.totalSavings}
            {...(couponCode != null ? { couponCode } : {})}
            {...(onCouponApplied ? { onCouponApplied } : {})}
            {...(onCouponRemoved ? { onCouponRemoved } : {})}
            {...(onLoyaltyRewardRedeemed ? { onLoyaltyRewardRedeemed } : {})}
          />
        </div>

        {/* Barcode Scan Flash Indicator */}
        {scanFlash && (
          <div
            className="pointer-events-none fixed inset-0 z-50 border-4 border-emerald-400 rounded-lg animate-pulse"
            aria-hidden="true"
          />
        )}

        {/* Barcode Multi-Match Selection Modal */}
        {barcodeMatchProducts.length > 1 && (
          <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/50">
            <div className="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4 overflow-hidden">
              <div className="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h3 className="text-lg font-semibold text-gray-900">
                  {t('pos:barcode.selectProduct')}
                </h3>
                <p className="text-sm text-gray-500 mt-1">
                  {t('pos:barcode.multipleMatchesDetail', { code: barcodeMatchCode })}
                </p>
              </div>
              <div className="max-h-80 overflow-y-auto">
                {barcodeMatchProducts.map((product) => (
                  <button
                    key={product.id}
                    onClick={() => handleBarcodeMatchSelect(product)}
                    className="w-full px-6 py-4 text-start hover:bg-blue-50 border-b border-gray-100 last:border-b-0 transition-colors"
                  >
                    <div className="font-medium text-gray-900">{product.name}</div>
                    <div className="text-sm text-gray-500 mt-1">
                      {t('pos:barcode.matchSku', { sku: product.sku })}
                      {product.barcode && (
                        <span className="ms-3">
                          {t('pos:barcode.matchBarcode', { barcode: product.barcode })}
                        </span>
                      )}
                    </div>
                    {product.sale_price && (
                      <div className="text-sm font-medium text-emerald-600 mt-1">
                        {product.sale_price}
                      </div>
                    )}
                  </button>
                ))}
              </div>
              <div className="px-6 py-3 border-t border-gray-200 bg-gray-50 flex justify-end">
                <button
                  onClick={handleBarcodeMatchClose}
                  className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50"
                >
                  {t('pos:barcode.cancel')}
                </button>
              </div>
            </div>
          </div>
        )}

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
