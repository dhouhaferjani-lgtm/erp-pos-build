import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import {
  Combobox,
  ComboboxInput,
  ComboboxOptions,
  ComboboxOption,
} from '@headlessui/react'
import { Search, X, Package, Barcode, QrCode } from 'lucide-react'
import { useProducts } from '../hooks/useProducts'
import { cn } from '@/lib/utils'

interface ProductSelectorProps {
  /**
   * Currently selected product IDs
   */
  value: string[]

  /**
   * Callback when product selection changes
   */
  onChange: (productIds: string[]) => void

  /**
   * Label for the selector
   */
  label?: string

  /**
   * Helper text to display below the selector
   */
  helperText?: string

  /**
   * Whether the selector is disabled
   */
  disabled?: boolean

  /**
   * Maximum number of products that can be selected
   */
  maxSelection?: number
}

/**
 * ProductSelector Component
 *
 * Multi-select component for choosing products with search functionality.
 *
 * Features:
 * - Real-time search filtering
 * - Multi-select with visual cart
 * - Keyboard navigation
 * - Loading state
 * - Barcode/SKU display
 * - Active products only
 *
 * @example
 * ```tsx
 * <ProductSelector
 *   value={selectedProductIds}
 *   onChange={setSelectedProductIds}
 *   label="Select Products to Count"
 *   maxSelection={100}
 * />
 * ```
 */
export function ProductSelector({
  value,
  onChange,
  label,
  helperText,
  disabled = false,
  maxSelection,
}: ProductSelectorProps) {
  const { t } = useTranslation(['common', 'inventory', 'products'])
  const [query, setQuery] = useState('')

  // Fetch active products
  const { data: productsResponse, isLoading } = useProducts({
    search: query.length > 0 ? query : undefined,
    active: true,
    per_page: 100,
  })

  const products = productsResponse?.data ?? []

  // Filter out already selected products
  const availableProducts = useMemo(
    () => products.filter((product) => !value.includes(product.id)),
    [products, value]
  )

  // Get selected products for display
  const selectedProducts = useMemo(
    () => products.filter((product) => value.includes(product.id)),
    [products, value]
  )

  const handleToggleProduct = (productId: string) => {
    if (value.includes(productId)) {
      onChange(value.filter((id) => id !== productId))
    } else {
      if (maxSelection && value.length >= maxSelection) {
        return // Don't add if max reached
      }
      onChange([...value, productId])
    }
  }

  const handleRemoveProduct = (productId: string) => {
    onChange(value.filter((id) => id !== productId))
  }

  const handleClearAll = () => {
    onChange([])
  }

  return (
    <div className="w-full space-y-4">
      {label && (
        <div className="flex items-center justify-between">
          <h3 className="text-sm font-medium text-gray-700">{label}</h3>
          {value.length > 0 && (
            <button
              type="button"
              onClick={handleClearAll}
              className="text-sm text-red-600 hover:text-red-700"
            >
              {t('common:clear')} ({value.length})
            </button>
          )}
        </div>
      )}

      {/* Search Combobox */}
      <Combobox value={null} onChange={(productId: string | null) => {
        if (productId) {
          handleToggleProduct(productId)
          setQuery('') // Clear search after selection
        }
      }} disabled={disabled}>
        <div className="relative">
          <div className="relative">
            <Search className="absolute start-3 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400" />
            <ComboboxInput
              className={cn(
                'w-full ps-10 pe-10 py-3 border border-gray-300 rounded-md',
                'focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500',
                'disabled:bg-gray-100 disabled:cursor-not-allowed',
                'transition-colors'
              )}
              onChange={(e: React.ChangeEvent<HTMLInputElement>) => { setQuery(e.target.value); }}
              placeholder={t('products:searchPlaceholder')}
              value={query}
            />
            {isLoading && (
              <div className="absolute end-3 top-1/2 -translate-y-1/2">
                <div className="animate-spin h-4 w-4 border-2 border-gray-300 border-t-blue-600 rounded-full" />
              </div>
            )}
          </div>

          <ComboboxOptions
            className={cn(
              'absolute z-10 mt-1 w-full',
              'max-h-60 overflow-auto',
              'rounded-md bg-white shadow-lg',
              'border border-gray-200',
              'py-1',
              'focus:outline-none'
            )}
          >
            {availableProducts.length === 0 ? (
              <div className="px-4 py-3 text-sm text-gray-500">
                {query
                  ? t('products:noProductsFound')
                  : t('products:noAvailableProducts')}
              </div>
            ) : (
              availableProducts.map((product) => (
                <ComboboxOption
                  key={product.id}
                  value={product.id}
                  className={({ active }: { active: boolean }) =>
                    cn(
                      'cursor-pointer select-none px-4 py-2',
                      active ? 'bg-blue-50 text-blue-900' : 'text-gray-900'
                    )
                  }
                >
                  <div className="flex items-center gap-3">
                    {/* Product Icon */}
                    <div className="flex-shrink-0 h-10 w-10 rounded bg-gray-100 flex items-center justify-center">
                      <Package className="h-5 w-5 text-gray-500" />
                    </div>

                    {/* Product Info */}
                    <div className="flex-1 min-w-0">
                      <div className="font-medium truncate">{product.name}</div>
                      <div className="flex items-center gap-2 text-sm text-gray-500">
                        <span className="truncate">SKU: {product.sku}</span>
                        {product.barcode && (
                          <>
                            <span>•</span>
                            <span className="flex items-center gap-1 truncate">
                              <Barcode className="h-3 w-3" />
                              {product.barcode}
                            </span>
                          </>
                        )}
                      </div>
                    </div>

                    {/* Type Badge */}
                    {product.type && (
                      <span className="text-xs px-2 py-1 bg-gray-100 rounded">
                        {t(`inventory:products.types.${product.type}`)}
                      </span>
                    )}
                  </div>
                </ComboboxOption>
              ))
            )}
          </ComboboxOptions>
        </div>
      </Combobox>

      {/* Selected Products Cart */}
      {value.length > 0 && (
        <div className="border border-gray-200 rounded-lg p-4 bg-gray-50">
          <div className="flex items-center justify-between mb-3">
            <span className="text-sm font-medium text-gray-700">
              {t('products:selectedProducts', { count: value.length })}
              {maxSelection && ` / ${maxSelection}`}
            </span>
          </div>

          <div className="space-y-2 max-h-60 overflow-y-auto">
            {selectedProducts.map((product) => (
              <div
                key={product.id}
                className="flex items-center gap-3 p-2 bg-white rounded border border-gray-200 hover:border-gray-300 transition-colors"
              >
                {/* Product Icon */}
                <div className="flex-shrink-0 h-8 w-8 rounded bg-gray-100 flex items-center justify-center">
                  <Package className="h-4 w-4 text-gray-500" />
                </div>

                {/* Product Info */}
                <div className="flex-1 min-w-0">
                  <div className="text-sm font-medium truncate">{product.name}</div>
                  <div className="text-xs text-gray-500 truncate">
                    {product.sku}
                    {product.barcode && ` • ${product.barcode}`}
                  </div>
                </div>

                {/* Remove Button */}
                <button
                  type="button"
                  onClick={() => { handleRemoveProduct(product.id); }}
                  className="flex-shrink-0 p-1 text-gray-400 hover:text-red-600 transition-colors"
                  title={t('common:actions.delete')}
                >
                  <X className="h-4 w-4" />
                </button>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Helper text */}
      {helperText && (
        <p className="text-sm text-gray-500">
          {helperText}
        </p>
      )}

      {/* Barcode Scanner Hint */}
      <div className="flex items-center gap-2 text-xs text-gray-500">
        <QrCode className="h-4 w-4" />
        <span>{t('products:barcodeScannerHint')}</span>
      </div>
    </div>
  )
}
