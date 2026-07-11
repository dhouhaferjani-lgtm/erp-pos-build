import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { X, Package, QrCode } from 'lucide-react'
import { useProducts } from '../hooks/useProducts'
import { ProductPicker } from '@/components/molecules/pickers/ProductPicker'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

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

  const { data: productsResponse } = useProducts({
    active: true,
    per_page: 100,
  })

  const products = productsResponse?.data ?? []

  const selectedProducts = useMemo(
    () => products.filter((product) => value.includes(product.id)),
    [products, value]
  )

  const handleAddProduct = (productId: string) => {
    if (value.includes(productId)) {
      return
    }
    if (maxSelection && value.length >= maxSelection) {
      return
    }
    onChange([...value, productId])
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
          <h3 className={`text-sm font-medium ${colorTokens.text.secondary}`}>{label}</h3>
          {value.length > 0 && (
            <button
              type="button"
              onClick={handleClearAll}
              className={`text-sm ${colorTokens.intent.danger.text} ${colorTokens.variants.hoverTextRed700}`}
            >
              {t('common:clear')} ({value.length})
            </button>
          )}
        </div>
      )}

      <ProductPicker
        value={null}
        onChange={(product) => {
          if (product) {
            handleAddProduct(product.id)
          }
        }}
        label=""
        placeholder={t('products:searchPlaceholder')}
        disabled={disabled || (Boolean(maxSelection) && value.length >= (maxSelection ?? 0))}
        productType="all"
      />

      {/* Selected Products Cart */}
      {value.length > 0 && (
        <div className={`border ${colorTokens.border.subtle} rounded-lg p-4 ${colorTokens.surface.page}`}>
          <div className="flex items-center justify-between mb-3">
            <span className={`text-sm font-medium ${colorTokens.text.secondary}`}>
              {t('products:selectedProducts', { count: value.length })}
              {maxSelection && ` / ${maxSelection}`}
            </span>
          </div>

          <div className="space-y-2 max-h-60 overflow-y-auto">
            {selectedProducts.map((product) => (
              <div
                key={product.id}
                className={`flex items-center gap-3 p-2 bg-white rounded border ${colorTokens.border.subtle} ${colorTokens.variants.hoverBorderGray300} transition-colors`}
              >
                {/* Product Icon */}
                <div className={`flex-shrink-0 h-8 w-8 rounded ${colorTokens.surface.muted} flex items-center justify-center`}>
                  <Package className={`h-4 w-4 ${colorTokens.text.subtle}`} />
                </div>

                {/* Product Info */}
                <div className="flex-1 min-w-0">
                  <div className="text-sm font-medium truncate">{product.name}</div>
                  <div className={`text-xs ${colorTokens.text.subtle} truncate`}>
                    {product.sku}
                    {product.barcode && ` • ${product.barcode}`}
                  </div>
                </div>

                {/* Remove Button */}
                <button
                  type="button"
                  onClick={() => { handleRemoveProduct(product.id); }}
                  className={`flex-shrink-0 p-1 ${colorTokens.text.disabled} ${colorTokens.variants.hoverTextRed600} transition-colors`}
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
        <p className={`text-sm ${colorTokens.text.subtle}`}>
          {helperText}
        </p>
      )}

      {/* Barcode Scanner Hint */}
      <div className={`flex items-center gap-2 text-xs ${colorTokens.text.subtle}`}>
        <QrCode className="h-4 w-4" />
        <span>{t('products:barcodeScannerHint')}</span>
      </div>
    </div>
  )
}
