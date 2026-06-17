import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { Package, Loader2, ShoppingCart } from 'lucide-react'
import { Modal } from '@/components/organisms/Modal'
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/molecules/Tabs/Tabs'
import { tokens, colors, textColors, borderColors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { apiGet } from '@/lib/api'
import { useCurrency } from '@/hooks/useCurrency'
import { useTaxConfigName } from '@/hooks/useTaxConfigName'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { useCompanyConfigOptional } from '@/contexts/CompanyConfigContext'

/**
 * Product Info Modal Props
 */
export interface ProductInfoModalProps {
  isOpen: boolean
  onClose: () => void
  productId: string
  onAddToCart?: (product: ProductDetailResponse) => void
  touchOptimized?: boolean
}

/**
 * Product Detail Response (from GET /products/{id})
 */
export interface ProductDetailResponse {
  id: string
  name: string
  sku: string
  description?: string
  sale_price: string | null
  purchase_price?: string | null
  tax_rate?: string
  default_tax_configuration_id?: string | null
  category?: {
    id: string
    name: string
  }
  image_url?: string
  parapharmacy_metadata?: ParapharmacyMetadata
}

/**
 * Parapharmacy Metadata
 */
interface ParapharmacyMetadata {
  ingredients?: Array<{
    id: string
    name: { en: string; fr: string; ar?: string }
    concentration?: string
  }>
  key_components?: Array<{
    id: string
    name: { en: string; fr: string; ar?: string }
    benefit?: { en: string; fr: string; ar?: string }
  }>
  health_claims?: Array<{
    id: string
    claim: { en: string; fr: string; ar?: string }
    regulation_reference?: string
  }>
  certifications?: Array<{
    id: string
    name: { en: string; fr: string; ar?: string }
    logo_url?: string
    issuing_body?: string
  }>
}

/**
 * Stock Level Response (from GET /products/{id}/stock-levels)
 */
export interface StockLevel {
  id: string
  location_id: string
  location_name: string
  quantity: string
  reserved: string
  available: string
  incoming: string
  projected_available: string
  min_quantity: string | null
  max_quantity: string | null
  is_below_minimum: boolean
}

interface StockLevelsResponse {
  locations: StockLevel[]
  totals: {
    quantity: string
    reserved: string
    available: string
    incoming: string
    projected_available: string
  }
}

/**
 * Fetch product details
 */
async function fetchProductDetails(productId: string): Promise<ProductDetailResponse> {
  return apiGet<ProductDetailResponse>(`/products/${productId}`)
}

/**
 * Fetch product stock levels
 */
async function fetchProductStock(productId: string): Promise<StockLevelsResponse> {
  return apiGet<StockLevelsResponse>(`/products/${productId}/stock-levels`)
}

/**
 * Product Info Modal Component
 *
 * Touch-optimized modal showing product details with tabs:
 * - Details (always shown)
 * - Stock levels
 * - Parapharmacy data (conditional)
 */
export function ProductInfoModal({
  isOpen,
  onClose,
  productId,
  onAddToCart,
  touchOptimized = false,
}: ProductInfoModalProps) {
  const { t, i18n } = useTranslation(['pos', 'products', 'common'])
  const { currency } = useCurrency()
  const [activeTab, setActiveTab] = useState('details')
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  // Fetch product details
  const {
    data: product,
    isLoading: isLoadingProduct,
    error: productError,
  } = useQuery({
    queryKey: tenantScopedKey(['product', productId]),
    queryFn: () => fetchProductDetails(productId),
    enabled: tenantId !== null && companyId !== null && isOpen,
  })

  const taxConfigName = useTaxConfigName(product?.default_tax_configuration_id)

  // Fetch stock levels (lazy load on tab switch)
  const {
    data: stockData,
    isLoading: isLoadingStock,
    error: stockError,
  } = useQuery({
    queryKey: tenantScopedKey(['product-stock', productId]),
    queryFn: () => fetchProductStock(productId),
    enabled: tenantId !== null && companyId !== null && isOpen && activeTab === 'stock',
  })

  const stockLevels = stockData?.locations

  // Gate the parapharmacy tab on BOTH the tenant vertical (the backend
  // authorizes parapharmacy metadata by vertical === 'parapharmacy', not by
  // module) AND the product carrying parapharmacy metadata.
  // useCompanyConfigOptional returns null if this modal is ever mounted outside
  // a CompanyConfigProvider (e.g. a standalone POS shell); in that case we fail
  // closed and hide the parapharmacy-flavored UI.
  const companyConfig = useCompanyConfigOptional()
  const isParapharmacyVertical = companyConfig?.config?.vertical === 'parapharmacy'
  const showParapharmacyTab = isParapharmacyVertical && !!product?.parapharmacy_metadata

  // Get current language for translations
  const currentLang = i18n.language as 'en' | 'fr' | 'ar'

  const handleAddToCart = () => {
    if (product && onAddToCart) {
      onAddToCart(product)
    }
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="xl">
      <div className={tokens.modal.header}>
        <h2 className={tokens.modal.title}>{t('pos:productInfo.title')}</h2>
        <button
          type="button"
          onClick={onClose}
          className={tokens.modal.closeButton}
          aria-label={t('common:actions.close')}
        >
          <span className="sr-only">{t('common:actions.close')}</span>
          ✕
        </button>
      </div>

      {/* Loading State */}
      {isLoadingProduct && (
        <div className="flex items-center justify-center py-12">
          <Loader2 className={cn('h-8 w-8 animate-spin', textColors.brand)} />
        </div>
      )}

      {/* Error State */}
      {productError && (
        <div className="py-8 text-center">
          <p className={textColors.error}>{t('common:errors.loadingFailed')}</p>
        </div>
      )}

      {/* Content */}
      {product && (
        <div className="space-y-4 min-h-[400px]">
          {/* Tabs */}
          <Tabs defaultValue="details" value={activeTab} onChange={setActiveTab}>
            <TabsList>
              <TabsTrigger value="details">{t('pos:productInfo.tabs.details')}</TabsTrigger>
              <TabsTrigger value="stock">{t('pos:productInfo.tabs.stock')}</TabsTrigger>
              {showParapharmacyTab && (
                <TabsTrigger value="parapharmacy">
                  {t('pos:productInfo.tabs.parapharmacy')}
                </TabsTrigger>
              )}
            </TabsList>

            {/* Details Tab */}
            <TabsContent value="details" className="pt-4">
              <div className="space-y-4">
                {/* Product Image */}
                <div className="flex justify-center">
                  {product.image_url ? (
                    <img
                      src={product.image_url}
                      alt={product.name}
                      className={cn(
                        'rounded-lg object-cover',
                        touchOptimized ? 'h-64 w-64' : 'h-48 w-48'
                      )}
                    />
                  ) : (
                    <div
                      className={cn(
                        'flex items-center justify-center rounded-lg',
                        colors.neutral[100],
                        touchOptimized ? 'h-64 w-64' : 'h-48 w-48'
                      )}
                    >
                      <Package className={cn('h-16 w-16', textColors.disabled)} />
                    </div>
                  )}
                </div>

                {/* Product Details Grid */}
                <div className="grid gap-3">
                  {/* Name */}
                  <div>
                    <h3
                      className={cn(
                        'font-semibold',
                        textColors.primary,
                        touchOptimized ? 'text-xl' : 'text-lg'
                      )}
                    >
                      {product.name}
                    </h3>
                  </div>

                  {/* SKU */}
                  <div className="flex items-center gap-2">
                    <span className={cn('text-sm font-medium', textColors.tertiary)}>
                      {t('pos:productInfo.fields.sku')}:
                    </span>
                    <span className={cn('font-mono text-sm', textColors.primary)}>{product.sku}</span>
                  </div>

                  {/* Category */}
                  {product.category && (
                    <div className="flex items-center gap-2">
                      <span className={cn('text-sm font-medium', textColors.tertiary)}>
                        {t('pos:productInfo.fields.category')}:
                      </span>
                      <span className={cn('text-sm', textColors.primary)}>{product.category.name}</span>
                    </div>
                  )}

                  {/* Price */}
                  <div className={cn('grid grid-cols-2 gap-3 rounded-lg p-3', colors.neutral[50])}>
                    <div>
                      <p className={cn('text-xs', textColors.tertiary)}>{t('pos:productInfo.fields.price')}</p>
                      <p className={cn('text-end text-lg font-bold tabular-nums', textColors.primary)}>
                        {product.sale_price ? `${product.sale_price} ${currency}` : 'N/A'}
                      </p>
                    </div>
                    {(product.tax_rate || product.default_tax_configuration_id) && (
                      <div>
                        <p className={cn('text-xs', textColors.tertiary)}>
                          {t('pos:productInfo.fields.taxRate')}
                        </p>
                        <p className={cn('text-end text-lg font-semibold tabular-nums', textColors.secondary)}>
                          {taxConfigName ?? (product.tax_rate ? `${product.tax_rate}%` : '—')}
                        </p>
                      </div>
                    )}
                  </div>

                  {/* Description */}
                  {product.description && (
                    <div>
                      <p className={cn('text-sm font-medium mb-1', textColors.tertiary)}>{t('common:fields.description')}</p>
                      <p className={cn('text-sm', textColors.secondary)}>{product.description}</p>
                    </div>
                  )}
                </div>

                {/* Add to Cart Button */}
                {onAddToCart && (
                  <button
                    onClick={handleAddToCart}
                    className={cn(
                      tokens.button.base,
                      tokens.button.primary,
                      'w-full',
                      touchOptimized ? tokens.button.sizes.lg : tokens.button.sizes.md
                    )}
                  >
                    <ShoppingCart className="h-5 w-5 me-2" />
                    {t('common:actions.add')}
                  </button>
                )}
              </div>
            </TabsContent>

            {/* Stock Tab */}
            <TabsContent value="stock" className="pt-4">
              {isLoadingStock && (
                <div className="flex items-center justify-center py-8">
                  <Loader2 className={cn('h-6 w-6 animate-spin', textColors.brand)} />
                </div>
              )}

              {stockError && (
                <div className="py-8 text-center">
                  <p className={cn('text-sm', textColors.error)}>{t('common:errors.loadingFailed')}</p>
                </div>
              )}

              {stockLevels && (
                <div className="overflow-x-auto">
                  <table className={cn('min-w-full divide-y', borderColors.divideDefault)}>
                    <thead className={tokens.table.header}>
                      <tr>
                        <th className={cn('px-4 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                          {t('pos:productInfo.fields.location')}
                        </th>
                        <th className={cn('px-4 py-3 text-end text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                          {t('pos:productInfo.fields.available')}
                        </th>
                        <th className={cn('px-4 py-3 text-end text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                          {t('pos:productInfo.fields.reserved')}
                        </th>
                        <th className={cn('px-4 py-3 text-end text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                          {t('pos:productInfo.fields.total')}
                        </th>
                      </tr>
                    </thead>
                    <tbody className={cn('divide-y bg-white', borderColors.divideDefault)}>
                      {stockLevels.length === 0 ? (
                        <tr>
                          <td colSpan={4} className={cn('px-4 py-8 text-center text-sm', textColors.tertiary)}>
                            {t('common:noData')}
                          </td>
                        </tr>
                      ) : (
                        stockLevels.map((stock) => {
                          const availableNum = parseFloat(stock.available)
                          return (
                          <tr key={stock.location_id}>
                            <td className={cn('whitespace-nowrap px-4 py-3 text-sm font-medium', textColors.primary)}>
                              {stock.location_name}
                            </td>
                            <td className={cn('whitespace-nowrap px-4 py-3 text-end text-sm tabular-nums', textColors.secondary)}>
                              <span
                                className={cn(
                                  'font-semibold',
                                  availableNum === 0
                                    ? textColors.error
                                    : availableNum < 10
                                      ? textColors.warningDark
                                      : textColors.success
                                )}
                              >
                                {stock.available}
                              </span>
                            </td>
                            <td className={cn('whitespace-nowrap px-4 py-3 text-end text-sm tabular-nums', textColors.tertiary)}>
                              {stock.reserved}
                            </td>
                            <td className={cn('whitespace-nowrap px-4 py-3 text-end text-sm font-medium tabular-nums', textColors.primary)}>
                              {stock.quantity}
                            </td>
                          </tr>
                          )
                        })
                      )}
                    </tbody>
                  </table>
                </div>
              )}
            </TabsContent>

            {/* Parapharmacy Tab */}
            {showParapharmacyTab && (
              <TabsContent value="parapharmacy" className="pt-4">
                <div className="space-y-6">
                  {/* Ingredients */}
                  {product.parapharmacy_metadata?.ingredients &&
                    product.parapharmacy_metadata.ingredients.length > 0 && (
                      <div>
                        <h4 className={cn('mb-2 text-sm font-semibold', textColors.primary)}>
                          {t('products:parapharmacy.activeIngredients')}
                        </h4>
                        <ul className="space-y-1">
                          {product.parapharmacy_metadata.ingredients.map((ingredient) => (
                            <li key={ingredient.id} className="flex items-start gap-2 text-sm">
                              <span className={textColors.disabled}>•</span>
                              <span className={textColors.secondary}>
                                {ingredient.name[currentLang] || ingredient.name.en}
                                {ingredient.concentration && (
                                  <span className={cn('ms-1', textColors.tertiary)}>
                                    ({ingredient.concentration})
                                  </span>
                                )}
                              </span>
                            </li>
                          ))}
                        </ul>
                      </div>
                    )}

                  {/* Key Components */}
                  {product.parapharmacy_metadata?.key_components &&
                    product.parapharmacy_metadata.key_components.length > 0 && (
                      <div>
                        <h4 className={cn('mb-2 text-sm font-semibold', textColors.primary)}>
                          {t('products:parapharmacy.keyComponents')}
                        </h4>
                        <div className="space-y-2">
                          {product.parapharmacy_metadata.key_components.map((component) => (
                            <div key={component.id} className={cn('rounded-md p-3', tokens.alert.info)}>
                              <p className="text-sm font-medium">
                                {component.name[currentLang] || component.name.en}
                              </p>
                              {component.benefit && (
                                <p className="mt-1 text-xs">
                                  {component.benefit[currentLang] || component.benefit.en}
                                </p>
                              )}
                            </div>
                          ))}
                        </div>
                      </div>
                    )}

                  {/* Health Claims */}
                  {product.parapharmacy_metadata?.health_claims &&
                    product.parapharmacy_metadata.health_claims.length > 0 && (
                      <div>
                        <h4 className={cn('mb-2 text-sm font-semibold', textColors.primary)}>{t('products:parapharmacy.healthClaims')}</h4>
                        <div className="space-y-2">
                          {product.parapharmacy_metadata.health_claims.map((claim) => (
                            <div key={claim.id} className={cn('rounded-md p-3', tokens.alert.success)}>
                              <p className="text-sm">
                                {claim.claim[currentLang] || claim.claim.en}
                              </p>
                              {claim.regulation_reference && (
                                <p className="mt-1 text-xs">
                                  {claim.regulation_reference}
                                </p>
                              )}
                            </div>
                          ))}
                        </div>
                      </div>
                    )}

                  {/* Certifications */}
                  {product.parapharmacy_metadata?.certifications &&
                    product.parapharmacy_metadata.certifications.length > 0 && (
                      <div>
                        <h4 className={cn('mb-2 text-sm font-semibold', textColors.primary)}>
                          {t('products:parapharmacy.certifications')}
                        </h4>
                        <div className="grid gap-3 sm:grid-cols-2">
                          {product.parapharmacy_metadata.certifications.map((cert) => (
                            <div
                              key={cert.id}
                              className={cn('flex items-center gap-3 rounded-md border p-3', borderColors.light)}
                            >
                              {cert.logo_url ? (
                                <img
                                  src={cert.logo_url}
                                  alt={cert.name[currentLang] || cert.name.en}
                                  className="h-12 w-12 rounded object-contain"
                                />
                              ) : (
                                <div className={cn('flex h-12 w-12 items-center justify-center rounded', colors.neutral[100])}>
                                  <Package className={cn('h-6 w-6', textColors.disabled)} />
                                </div>
                              )}
                              <div className="flex-1">
                                <p className={cn('text-sm font-medium', textColors.primary)}>
                                  {cert.name[currentLang] || cert.name.en}
                                </p>
                                {cert.issuing_body && (
                                  <p className={cn('text-xs', textColors.tertiary)}>{cert.issuing_body}</p>
                                )}
                              </div>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}
                </div>
              </TabsContent>
            )}
          </Tabs>
        </div>
      )}
    </Modal>
  )
}
