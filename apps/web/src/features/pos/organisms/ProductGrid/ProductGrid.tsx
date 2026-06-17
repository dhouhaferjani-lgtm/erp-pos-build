import { useState, useMemo, useRef, type KeyboardEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { textColors, borderColors, focusRing } from '@/lib/designTokens'
import { ProductCard, type Product } from '../../molecules'
import { POSButton } from '../../atoms'
import { Search, X, Package, ScanBarcode } from 'lucide-react'

export interface ProductGridProps {
  products: Product[]
  categories?: string[]
  onAddToCart: (product: Product) => void
  onShowProductInfo: (product: Product) => void
  onCustomize?: (product: Product) => void
  cartProductIds: string[]
  isLoading?: boolean
  showSearch?: boolean
  showCategoryFilter?: boolean
  showProductCount?: boolean
  touchOptimized?: boolean
  className?: string
  /** Called when user manually enters a barcode/SKU and presses Enter */
  onBarcodeSubmit?: (code: string) => void
  /** Whether a barcode lookup is currently in progress */
  isBarcodeSearching?: boolean
}

export function ProductGrid({
  products,
  categories: orderedCategories,
  onAddToCart,
  onShowProductInfo,
  onCustomize,
  cartProductIds,
  isLoading = false,
  showSearch = true,
  showCategoryFilter = true,
  showProductCount = true,
  touchOptimized = false,
  className,
  onBarcodeSubmit,
  isBarcodeSearching = false,
}: ProductGridProps) {
  const { t } = useTranslation(['pos'])
  const [searchQuery, setSearchQuery] = useState('')
  const [selectedCategory, setSelectedCategory] = useState<string | null>(null)
  const [barcodeInput, setBarcodeInput] = useState('')
  const barcodeInputRef = useRef<HTMLInputElement>(null)

  // Extract unique categories (use ordered categories prop if provided)
  const categories = useMemo(() => {
    if (orderedCategories && orderedCategories.length > 0) {
      return [t('pos:products.allCategories'), ...orderedCategories]
    }
    const uniqueCategories = new Set(
      products
        .map((p) => p.category)
        .filter((c): c is string => c !== undefined)
    )
    return [t('pos:products.allCategories'), ...Array.from(uniqueCategories)]
  }, [products, orderedCategories])

  // Filter products
  const filteredProducts = useMemo(() => {
    let filtered = products

    // Filter by category
    if (selectedCategory && selectedCategory !== t('pos:products.allCategories')) {
      filtered = filtered.filter((p) => p.category === selectedCategory)
    }

    // Filter by search query
    if (searchQuery.trim()) {
      const query = searchQuery.toLowerCase()
      filtered = filtered.filter(
        (p) =>
          p.name.toLowerCase().includes(query) ||
          p.sku.toLowerCase().includes(query) ||
          (p.barcode && p.barcode.toLowerCase().includes(query))
      )
    }

    return filtered
  }, [products, selectedCategory, searchQuery])

  const handleBarcodeKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter' && barcodeInput.trim()) {
      e.preventDefault()
      onBarcodeSubmit?.(barcodeInput.trim())
      setBarcodeInput('')
    }
  }

  const handleClearSearch = () => {
    setSearchQuery('')
  }

  const handleCategorySelect = (category: string) => {
    setSelectedCategory(category === t('pos:products.allCategories') ? null : category)
  }

  // Loading state
  if (isLoading) {
    return (
      <div className={cn('flex items-center justify-center py-12', className)}>
        <div className="text-center">
          <div className={cn('animate-spin rounded-full h-12 w-12 border-b-2 mx-auto mb-4', borderColors.primary)} />
          <p className={textColors.tertiary}>{t('pos:products.loading')}</p>
        </div>
      </div>
    )
  }

  // Empty state
  if (products.length === 0) {
    return (
      <div className={cn('flex items-center justify-center py-12', className)}>
        <div className="text-center">
          <Package className={cn('w-16 h-16 mx-auto mb-4', textColors.disabled)} />
          <p className={cn('text-lg', textColors.tertiary)}>{t('pos:products.empty')}</p>
        </div>
      </div>
    )
  }

  return (
    <div className={cn('flex flex-col gap-4', className)}>
      {/* Header Section */}
      <div className="flex flex-col gap-3">
        {/* Barcode / SKU Input */}
        {onBarcodeSubmit && (
          <div className="relative">
            <ScanBarcode className="absolute start-3 top-1/2 -translate-y-1/2 w-5 h-5 text-emerald-600" />
            <input
              ref={barcodeInputRef}
              type="text"
              value={barcodeInput}
              onChange={(e) => { setBarcodeInput(e.target.value); }}
              onKeyDown={handleBarcodeKeyDown}
              placeholder={t('pos:barcode.inputPlaceholder')}
              className={cn(
                'w-full ps-10 pe-10 py-3 rounded-lg',
                'border-2 border-emerald-300 bg-emerald-50',
                'focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500',
                'placeholder:text-emerald-400',
                isBarcodeSearching && 'animate-pulse',
                touchOptimized && 'py-4 text-lg'
              )}
              aria-label={t('pos:barcode.inputLabel')}
            />
            {barcodeInput && (
              <button
                onClick={() => { setBarcodeInput(''); }}
                className="absolute end-3 top-1/2 -translate-y-1/2"
                aria-label={t('pos:barcode.clearInput')}
              >
                <X className="w-5 h-5 text-emerald-400 hover:text-emerald-600" />
              </button>
            )}
          </div>
        )}

        {/* Search Bar */}
        {showSearch && (
          <div className="relative">
            <Search className={cn('absolute start-3 top-1/2 -translate-y-1/2 w-5 h-5', textColors.disabled)} />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => { setSearchQuery(e.target.value); }}
              placeholder={t('pos:products.searchPlaceholder')}
              className={cn(
                'w-full ps-10 pe-10 py-3 rounded-lg border',
                borderColors.default,
                'focus:outline-none focus:ring-2',
                focusRing.primary,
                touchOptimized && 'py-4 text-lg'
              )}
            />
            {searchQuery && (
              <button
                onClick={handleClearSearch}
                className="absolute end-3 top-1/2 -translate-y-1/2"
                aria-label={t('pos:products.clearSearch')}
              >
                <X className={cn('w-5 h-5', textColors.disabled, textColors.hoverSecondary)} />
              </button>
            )}
          </div>
        )}

        {/* Category Filter */}
        {showCategoryFilter && (
          <div className="flex gap-2 flex-wrap">
            {categories.map((category) => (
              <POSButton
                key={category}
                variant={
                  (selectedCategory === category) ||
                  (selectedCategory === null && category === 'All')
                    ? 'primary'
                    : 'secondary'
                }
                size={touchOptimized ? 'md' : 'sm'}
                onClick={() => { handleCategorySelect(category); }}
              >
                {category}
              </POSButton>
            ))}
          </div>
        )}

        {/* Product Count */}
        {showProductCount && (
          <div className={cn('text-sm', textColors.tertiary)}>
            {filteredProducts.length} {filteredProducts.length === 1 ? t('pos:products.product') : t('pos:products.productPlural')}
          </div>
        )}
      </div>

      {/* Products Grid */}
      {filteredProducts.length === 0 ? (
        <div className="flex items-center justify-center py-12">
          <div className="text-center">
            <Package className={cn('w-16 h-16 mx-auto mb-4', textColors.disabled)} />
            <p className={cn('text-lg', textColors.tertiary)}>{t('pos:products.notFound')}</p>
            <p className={cn('text-sm mt-2', textColors.tertiary)}>
              {t('pos:products.tryAdjusting')}
            </p>
          </div>
        </div>
      ) : (
        <div
          className={cn(
            'grid gap-4',
            touchOptimized
              ? 'grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6'
              : 'grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5'
          )}
        >
          {filteredProducts.map((product) => (
            <ProductCard
              key={product.id}
              product={product}
              onAddToCart={onAddToCart}
              onShowInfo={onShowProductInfo}
              {...(onCustomize ? { onCustomize } : {})}
              isInCart={cartProductIds.includes(product.id)}
              touchOptimized={touchOptimized}
            />
          ))}
        </div>
      )}
    </div>
  )
}
