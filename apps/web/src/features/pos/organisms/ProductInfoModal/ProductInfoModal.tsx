import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { Package, Loader2, ShoppingCart } from 'lucide-react'
import { Modal } from '@/components/organisms/Modal'
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/molecules/Tabs/Tabs'
import { tokens } from '@/lib/designTokens'
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
          <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
        </div>
      )}

      {/* Error State */}
      {productError && (
        <div className="py-8 text-center">
          <p className="text-red-600">{t('common:errors.loadingFailed')}</p>
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
                        'flex items-center justify-center rounded-lg bg-gray-100',
                        touchOptimized ? 'h-64 w-64' : 'h-48 w-48'
                      )}
                    >
                      <Package className="h-16 w-16 text-gray-400" />
                    </div>
                  )}
                </div>

                {/* Product Details Grid */}
                <div className="grid gap-3">
                  {/* Name */}
                  <div>
                    <h3
                      className={cn(
                        'font-semibold text-gray-900',
                        touchOptimized ? 'text-xl' : 'text-lg'
                      )}
                    >
                      {product.name}
                    </h3>
                  </div>

                  {/* SKU */}
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-medium text-gray-500">
                      {t('pos:productInfo.fields.sku')}:
                    </span>
                    <span className="font-mono text-sm text-gray-900">{product.sku}</span>
                  </div>

                  {/* Category */}
                  {product.category && (
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-medium text-gray-500">
                        {t('pos:productInfo.fields.category')}:
                      </span>
                      <span className="text-sm text-gray-900">{product.category.name}</span>
                    </div>
                  )}

                  {/* Price */}
                  <div className="grid grid-cols-2 gap-3 rounded-lg bg-gray-50 p-3">
                    <div>
                      <p className="text-xs text-gray-500">{t('pos:productInfo.fields.price')}</p>
                      <p className="text-lg font-bold text-gray-900">
                        {product.sale_price ? `${product.sale_price} ${currency}` : 'N/A'}
                      </p>
                    </div>
                    {(product.tax_rate || product.default_tax_configuration_id) && (
                      <div>
                        <p className="text-xs text-gray-500">
                          {t('pos:productInfo.fields.taxRate')}
                        </p>
                        <p className="text-lg font-semibold text-gray-700">
                          {taxConfigName ?? (product.tax_rate ? `${product.tax_rate}%` : '—')}
                        </p>
                      </div>
                    )}
                  </div>

                  {/* Description */}
                  {product.description && (
                    <div>
                      <p className="text-sm font-medium text-gray-500 mb-1">{t('common:fields.description')}</p>
                      <p className="text-sm text-gray-700">{product.description}</p>
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
                  <Loader2 className="h-6 w-6 animate-spin text-blue-600" />
                </div>
              )}

              {stockError && (
                <div className="py-8 text-center">
                  <p className="text-sm text-red-600">{t('common:errors.loadingFailed')}</p>
                </div>
              )}

              {stockLevels && (
                <div className="overflow-x-auto">
                  <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                      <tr>
                        <th className="px-4 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                          {t('pos:productInfo.fields.location')}
                        </th>
                        <th className="px-4 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                          {t('pos:productInfo.fields.available')}
                        </th>
                        <th className="px-4 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                          {t('pos:productInfo.fields.reserved')}
                        </th>
                        <th className="px-4 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                          {t('pos:productInfo.fields.total')}
                        </th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-200 bg-white">
                      {stockLevels.length === 0 ? (
                        <tr>
                          <td colSpan={4} className="px-4 py-8 text-center text-sm text-gray-500">
                            {t('common:noData')}
                          </td>
                        </tr>
                      ) : (
                        stockLevels.map((stock) => {
                          const availableNum = parseFloat(stock.available)
                          return (
                          <tr key={stock.location_id}>
                            <td className="whitespace-nowrap px-4 py-3 text-sm font-medium text-gray-900">
                              {stock.location_name}
                            </td>
                            <td className="whitespace-nowrap px-4 py-3 text-end text-sm text-gray-700">
                              <span
                                className={cn(
                                  'font-semibold',
                                  availableNum === 0
                                    ? 'text-red-600'
                                    : availableNum < 10
                                      ? 'text-yellow-600'
                                      : 'text-green-600'
                                )}
                              >
                                {stock.available}
                              </span>
                            </td>
                            <td className="whitespace-nowrap px-4 py-3 text-end text-sm text-gray-500">
                              {stock.reserved}
                            </td>
                            <td className="whitespace-nowrap px-4 py-3 text-end text-sm font-medium text-gray-900">
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
                        <h4 className="mb-2 text-sm font-semibold text-gray-900">
                          {t('products:parapharmacy.activeIngredients')}
                        </h4>
                        <ul className="space-y-1">
                          {product.parapharmacy_metadata.ingredients.map((ingredient) => (
                            <li key={ingredient.id} className="flex items-start gap-2 text-sm">
                              <span className="text-gray-400">•</span>
                              <span className="text-gray-700">
                                {ingredient.name[currentLang] || ingredient.name.en}
                                {ingredient.concentration && (
                                  <span className="ms-1 text-gray-500">
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
                        <h4 className="mb-2 text-sm font-semibold text-gray-900">
                          {t('products:parapharmacy.keyComponents')}
                        </h4>
                        <div className="space-y-2">
                          {product.parapharmacy_metadata.key_components.map((component) => (
                            <div key={component.id} className="rounded-md bg-blue-50 p-3">
                              <p className="text-sm font-medium text-blue-900">
                                {component.name[currentLang] || component.name.en}
                              </p>
                              {component.benefit && (
                                <p className="mt-1 text-xs text-blue-700">
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
                        <h4 className="mb-2 text-sm font-semibold text-gray-900">{t('products:parapharmacy.healthClaims')}</h4>
                        <div className="space-y-2">
                          {product.parapharmacy_metadata.health_claims.map((claim) => (
                            <div key={claim.id} className="rounded-md bg-green-50 p-3">
                              <p className="text-sm text-green-900">
                                {claim.claim[currentLang] || claim.claim.en}
                              </p>
                              {claim.regulation_reference && (
                                <p className="mt-1 text-xs text-green-600">
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
                        <h4 className="mb-2 text-sm font-semibold text-gray-900">
                          {t('products:parapharmacy.certifications')}
                        </h4>
                        <div className="grid gap-3 sm:grid-cols-2">
                          {product.parapharmacy_metadata.certifications.map((cert) => (
                            <div
                              key={cert.id}
                              className="flex items-center gap-3 rounded-md border border-gray-200 p-3"
                            >
                              {cert.logo_url ? (
                                <img
                                  src={cert.logo_url}
                                  alt={cert.name[currentLang] || cert.name.en}
                                  className="h-12 w-12 rounded object-contain"
                                />
                              ) : (
                                <div className="flex h-12 w-12 items-center justify-center rounded bg-gray-100">
                                  <Package className="h-6 w-6 text-gray-400" />
                                </div>
                              )}
                              <div className="flex-1">
                                <p className="text-sm font-medium text-gray-900">
                                  {cert.name[currentLang] || cert.name.en}
                                </p>
                                {cert.issuing_body && (
                                  <p className="text-xs text-gray-500">{cert.issuing_body}</p>
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
