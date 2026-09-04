import { useState, useMemo, useEffect, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { cn } from '@/lib/utils'
import { colors, textColors, borderColors , semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { ProductGrid, TransactionCart, Calculator } from '../../organisms'
import { ModifierSelectionModal } from '../../organisms/ModifierSelectionModal'
import { POSLayout } from '../../layouts'
import type { Product } from '../../molecules'
import type { Customer } from '../../organisms/TransactionCart'
import type { CartItem, SelectedModifier } from '../../molecules/CartLineItem'
import { useCurrency } from '@/hooks/useCurrency'
import { bcadd, bcsub, bcmul, bcdiv, bccomp } from '@/lib/decimal'
import { useBarcodeScanner } from '@/hooks/useBarcodeScanner'
import { useBarcodeLookup } from '../../hooks/useBarcodeLookup'
import { ConsumptionModeToggle, type ConsumptionMode } from '../../atoms/ConsumptionModeToggle/ConsumptionModeToggle'
import { TableSelector } from '../../components/TableSelector'
import { toast } from 'sonner'
import type { POSProduct } from '../../api/productApi'
import { useDiscountPreview } from '../../hooks/useDiscountPreview'
import { SmartPromptsContainer } from '../../smart-prompts/containers/SmartPromptsContainer'
import { useCompanyConfig } from '@/contexts/CompanyConfigContext'
import { apiGet } from '@/lib/api'
import { Button } from '@/components/atoms'

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
  selectedTableId?: string | null | undefined
  onSelectedTableIdChange?: ((tableId: string | null) => void) | undefined
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
  selectedTableId,
  onSelectedTableIdChange,
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

  const { config } = useCompanyConfig()

  // Calculate subtotal for discount preview
  const cartSubtotal = useMemo(() => {
    return cartItems.reduce((sum, item) => bcadd(sum, item.line_total, decimals), '0')
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
    return () => { window.removeEventListener('keydown', handleKeyDown); }
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
            const grossTotal = bcmul(item.unit_price, String(newQty), decimals)
            let discountAmount = '0'

            if (item.discount_type === 'percentage' && item.discount_percent) {
              discountAmount = bcdiv(bcmul(grossTotal, item.discount_percent, decimals + 2), '100', decimals)
            } else if (item.discount_amount && item.discount_type === 'fixed') {
              discountAmount = item.discount_amount
            }

            const lineTotal = bccomp(grossTotal, discountAmount) > 0 ? bcsub(grossTotal, discountAmount, decimals) : '0'

            return {
              ...item,
              quantity: newQty,
              ...(item.discount_type === 'percentage' ? { discount_amount: discountAmount } : {}),
              line_total: lineTotal,
            }
          })
        } else {
          // Add new item
          const basePrice = product.sale_price || '0'
          const modifierAdjustment = hasModifiers
            ? selectedModifiers.reduce((sum, m) => bcadd(sum, m.price_adjustment, decimals), '0')
            : '0'
          const priceValue = bcadd(basePrice, modifierAdjustment, decimals)

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
              line_total: priceValue,
              tax_amount: (0).toFixed(decimals),
            } satisfies CartItem,
          ]
        }
      })
    },
    [decimals],
  )

  // Handle adding a recommended product to cart
  const handleAddRecommendation = useCallback(async (productId: string) => {
    try {
      const productData = await apiGet<{
        id: string
        name: string
        sku: string
        barcode?: string | null
        sale_price: string | null
        stock_quantity: number
        image_url?: string
        category?: string
        sellable_type?: string
      }>(`/products/${productId}`)
      if (productData) {
        const mapped: Product = {
          id: productData.id,
          name: productData.name,
          sku: productData.sku,
          barcode: productData.barcode ?? null,
          sale_price: productData.sale_price,
          stock_quantity: productData.stock_quantity,
          ...(productData.image_url ? { image_url: productData.image_url } : {}),
          ...(productData.category ? { category: productData.category } : {}),
          ...(productData.sellable_type ? { sellableType: productData.sellable_type as Product['sellableType'] } : {}),
        }
        addItemToCart(mapped)
      }
    } catch {
      // Silently fail — recommendation add is best-effort
    }
  }, [addItemToCart])

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
    const timer = setTimeout(() => { setScanFlash(false); }, 600)
    return () => { clearTimeout(timer); }
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

        const grossTotal = bcmul(item.unit_price, String(quantity), decimals)
        let discountAmount = '0'

        if (item.discount_type === 'percentage' && item.discount_percent) {
          discountAmount = bcdiv(bcmul(grossTotal, item.discount_percent, decimals + 2), '100', decimals)
        } else if (item.discount_amount && item.discount_type === 'fixed') {
          discountAmount = item.discount_amount
        }

        const lineTotal = bccomp(grossTotal, discountAmount) > 0 ? bcsub(grossTotal, discountAmount, decimals) : '0'

        return {
          ...item,
          quantity,
          ...(item.discount_type === 'percentage' ? { discount_amount: discountAmount } : {}),
          line_total: lineTotal,
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

        const grossTotal = bcmul(item.unit_price, String(item.quantity), decimals)

        if (!discount) {
          // Clear discount — omit discount fields entirely
          const { discount_type: _dt, discount_percent: _dp, discount_amount: _da, discount_reason: _dr, ...rest } = item
          return {
            ...rest,
            line_total: grossTotal,
          }
        }

        let discountAmount: string

        if (discount.type === 'percentage') {
          discountAmount = bcdiv(bcmul(grossTotal, discount.value, decimals + 2), '100', decimals)
        } else {
          discountAmount = discount.value
        }

        const lineTotal = bccomp(grossTotal, discountAmount) > 0 ? bcsub(grossTotal, discountAmount, decimals) : '0'

        return {
          ...item,
          discount_type: discount.type,
          discount_amount: discountAmount,
          line_total: lineTotal,
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
            <div className={cn('text-lg font-medium', textColors.tertiary)}>{t('common:loading')}</div>
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
          'h-full overflow-hidden',
          colors.neutral[50],
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

          {/* Table Selector (dine-in only) */}
          {consumptionMode === 'SUR_PLACE' && onSelectedTableIdChange && (
            <div className="mb-4">
              <TableSelector
                selectedTableId={selectedTableId ?? null}
                onSelectTable={onSelectedTableIdChange}
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

          {/* Smart Prompts — toast variant sticks to bottom of product grid */}
          {(config?.smart_prompts_variant === 'toast' || config?.smart_prompts_variant === 'both') && (
            <SmartPromptsContainer
              cartItems={cartItems}
              customerId={selectedCustomer?.id ?? null}
              onAddRecommendation={handleAddRecommendation}
            />
          )}
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
            smartPromptsSlot={(config?.smart_prompts_variant === 'inline' || config?.smart_prompts_variant === 'both') ? (
              <SmartPromptsContainer
                cartItems={cartItems}
                customerId={selectedCustomer?.id ?? null}
                onAddRecommendation={handleAddRecommendation}
              />
            ) : undefined}
            onUpdateQuantity={handleUpdateQuantity}
            onRemoveItem={handleRemoveItem}
            onEditLineDiscount={handleEditLineDiscount}
            onClearCart={handleClearCart}
            onQuickCheckout={handleQuickCheckout}
            onAdvancedPayments={handleAdvancedPayments}
            onOpenCalculator={() => { setIsCalculatorOpen(true); }}
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
            isDiscountPreviewLoading={discountPreview.isLoading}
            {...(couponCode != null ? { couponCode } : {})}
            {...(onCouponApplied ? { onCouponApplied } : {})}
            {...(onCouponRemoved ? { onCouponRemoved } : {})}
            {...(onLoyaltyRewardRedeemed ? { onLoyaltyRewardRedeemed } : {})}
          />
        </div>

        {/* Barcode Scan Flash Indicator */}
        {scanFlash && (
          <div
            className={`pointer-events-none fixed inset-0 z-50 border-4 ${colorTokens.intent.available.borderActive} rounded-lg animate-pulse`}
            aria-hidden="true"
          />
        )}

        {/* Barcode Multi-Match Selection Modal */}
        {barcodeMatchProducts.length > 1 && (
          <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/50">
            <div className={cn('rounded-xl shadow-2xl w-full max-w-md mx-4 overflow-hidden', colors.white)}>
              <div className={cn('px-6 py-4 border-b', borderColors.light, colors.neutral[50])}>
                <h3 className={cn('text-lg font-semibold', textColors.primary)}>
                  {t('pos:barcode.selectProduct')}
                </h3>
                <p className={cn('text-sm mt-1', textColors.tertiary)}>
                  {t('pos:barcode.multipleMatchesDetail', { code: barcodeMatchCode })}
                </p>
              </div>
              <div className="max-h-80 overflow-y-auto">
                {barcodeMatchProducts.map((product) => (
                  <Button
                    key={product.id}
                    onClick={() => { handleBarcodeMatchSelect(product); }}
                    className={cn('w-full px-6 py-4 text-start border-b last:border-b-0 transition-colors', borderColors.light, colors.hover.gray50)}
                  >
                    <div className={cn('font-medium', textColors.primary)}>{product.name}</div>
                    <div className={cn('text-sm mt-1', textColors.tertiary)}>
                      {t('pos:barcode.matchSku', { sku: product.sku })}
                      {product.barcode && (
                        <span className="ms-3">
                          {t('pos:barcode.matchBarcode', { barcode: product.barcode })}
                        </span>
                      )}
                    </div>
                    {product.sale_price && (
                      <div className={cn('text-sm font-medium mt-1 tabular-nums', textColors.success)}>
                        {product.sale_price}
                      </div>
                    )}
                  </Button>
                ))}
              </div>
              <div className={cn('px-6 py-3 border-t flex justify-end', borderColors.light, colors.neutral[50])}>
                <Button variant="secondary"
                  onClick={handleBarcodeMatchClose}
                  className={cn( 'rounded-lg')}
                >
                  {t('pos:barcode.cancel')}
                </Button>
              </div>
            </div>
          </div>
        )}

        {/* Calculator Modal */}
        <Calculator
          isOpen={isCalculatorOpen}
          onClose={() => { setIsCalculatorOpen(false); }}
          touchOptimized={touchOptimized}
        />

        {/* Modifier Selection Modal */}
        {modifierProduct && (
          <ModifierSelectionModal
            isOpen={!!modifierProduct}
            onClose={() => { setModifierProduct(null); }}
            product={modifierProduct}
            onConfirm={handleModifierConfirm}
          />
        )}
      </div>
    </POSLayout>
  )
}
