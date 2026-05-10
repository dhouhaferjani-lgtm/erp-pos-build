import { useState } from 'react'
import { Link, useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Edit, Trash2, Tag } from 'lucide-react'
import { api, apiDelete } from '../../lib/api'
import { useCompanyStore } from '../../stores/companyStore'
import { useAuthStore } from '../../stores/authStore'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useProductConfig } from '../../contexts/ProductConfigContext'
import { formatCurrency } from '../../lib/format'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import {
  Tabs,
  TabsList,
  TabsTrigger,
  TabsContent,
} from '../../components/molecules/Tabs/Tabs'
import { ProductMovementsTab } from './components/ProductMovementsTab'
import { ProductDocumentsTab } from './components/ProductDocumentsTab'
import { useProductRealtime } from '../products/hooks/useProductRealtime'
import { ProductPrimaryImageDisplay } from '../products/components'
import { ProductStockLevels } from './components'
import { useTaxConfigName } from '../../hooks/useTaxConfigName'
import { inventoryProductsInvalidationPredicate } from './_invalidation'

interface Product {
  id: string
  name: string
  sku: string
  is_physical: boolean
  description: string | null
  sale_price: string | null
  purchase_price: string | null
  cost_price: string | null
  tax_rate: string | null
  default_tax_configuration_id: string | null
  unit: string | null
  barcode: string | null
  is_active: boolean
  oem_numbers: string[] | null
  cross_references: Array<{ brand: string; reference: string }> | null
  created_at: string
  updated_at: string | null
}

interface ProductResponse {
  data: Product
}

export function ProductDetailPage() {
  const { t } = useTranslation(['inventory', 'common', 'products'])
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const currentCompany = useCompanyStore((state) =>
    state.companies.find((company) => company.id === state.currentCompanyId) ?? null
  )
  const { isOtospex } = useProductConfig()

  // Get company currency with fallback
  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale.replace('_', '-') ?? 'en-US'

  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [showDeleteDialog, setShowDeleteDialog] = useState(false)

  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['product', id]),
    queryFn: async () => {
      if (!id) throw new Error('Product ID is required')
      const response = await api.get<ProductResponse>(`/products/${id}`)
      return response.data.data
    },
    enabled: !!id && !!tenantId && !!companyId,
  })

  // Subscribe to real-time product cost/price updates
  useProductRealtime({
    productId: id || '',
    enabled: !!id,
  })

  const deleteMutation = useMutation({
    mutationFn: () => {
      if (!id) throw new Error('Product ID is required')
      return apiDelete(`/products/${id}`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: inventoryProductsInvalidationPredicate(tenantId, companyId),
      })
      void navigate('/inventory/products')
    },
  })

  const handleDelete = () => {
    setShowDeleteDialog(true)
  }

  const confirmDelete = () => {
    deleteMutation.mutate()
    setShowDeleteDialog(false)
  }

  // Format currency using company settings
  const formatAmount = (amount: string | null) => {
    if (!amount) return '-'
    return formatCurrency(parseFloat(amount), {
      currency: companyCurrency,
      locale: companyLocale,
    })
  }

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    })
  }

  if (!id) {
    return (
      <div className="rounded-lg bg-red-50 p-4 text-red-700">
        {t('common:errors.loadingFailed')}
      </div>
    )
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className="text-gray-500">{t('status.loading')}</div>
      </div>
    )
  }

  if (error || !data) {
    return (
      <div className="rounded-lg bg-red-50 p-4 text-red-700">
        {t('common:errors.loadingFailed')}
      </div>
    )
  }

  const product = data

  const taxConfigName = useTaxConfigName(product?.default_tax_configuration_id)

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link
            to="/inventory/products"
            className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:actions.back')}
          </Link>
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold text-gray-900">{product.name}</h1>
              <span
                className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${
                  product.is_active
                    ? 'bg-green-100 text-green-800'
                    : 'bg-gray-100 text-gray-800'
                }`}
              >
                {product.is_active ? t('common:status.active') : t('common:status.inactive')}
              </span>
            </div>
            <p className="text-sm text-gray-500">SKU: {product.sku}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Link
            to={`/inventory/products/${product.id}/edit`}
            className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
          >
            <Edit className="h-4 w-4" />
            {t('common:actions.edit')}
          </Link>
          <button
            onClick={handleDelete}
            disabled={deleteMutation.isPending}
            className="inline-flex items-center gap-2 rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 transition-colors disabled:opacity-50"
          >
            <Trash2 className="h-4 w-4" />
            {deleteMutation.isPending ? t('common:status.saving') : t('common:actions.delete')}
          </button>
        </div>
      </div>

      {/* Tabs */}
      <Tabs defaultValue="details">
        <TabsList>
          <TabsTrigger value="details">{t('products.tabs.details')}</TabsTrigger>
          <TabsTrigger value="movements">{t('products.tabs.movements')}</TabsTrigger>
          <TabsTrigger value="financialOperations">{t('products.tabs.financialOperations')}</TabsTrigger>
        </TabsList>

        {/* Details Tab */}
        <TabsContent value="details" className="mt-6">
          {/* Top Section: Image + Key Info */}
          <div className="mb-6 grid gap-6 lg:grid-cols-[280px_1fr]">
            {/* Left: Product Image */}
            <div>
              <ProductPrimaryImageDisplay productId={product.id} />
            </div>

            {/* Right: Essential Info - Clean Data Table */}
            <div className="space-y-6">
              {/* Product Information */}
              <div className="rounded-lg border border-gray-200 bg-white">
                <div className="border-b border-gray-200 px-6 py-4">
                  <h2 className="text-base font-semibold text-gray-900">Product Information</h2>
                </div>
                <div className="divide-y divide-gray-100">
                  {product.description && (
                    <div className="px-6 py-3">
                      <div className="text-xs font-medium uppercase tracking-wide text-gray-500">Description</div>
                      <div className="mt-1 text-sm text-gray-900">{product.description}</div>
                    </div>
                  )}
                  <div className="grid grid-cols-2 gap-x-6 px-6 py-3">
                    <div>
                      <div className="text-xs font-medium uppercase tracking-wide text-gray-500">Unit</div>
                      <div className="mt-1 text-sm font-medium text-gray-900">{product.unit ?? '-'}</div>
                    </div>
                    {product.barcode && (
                      <div>
                        <div className="text-xs font-medium uppercase tracking-wide text-gray-500">Barcode</div>
                        <div className="mt-1 font-mono text-sm font-medium text-gray-900">{product.barcode}</div>
                      </div>
                    )}
                  </div>
                </div>
              </div>

              {/* Pricing */}
              <div className="rounded-lg border border-gray-200 bg-white">
                <div className="border-b border-gray-200 px-6 py-4">
                  <h2 className="text-base font-semibold text-gray-900">Pricing</h2>
                </div>
                <div className="divide-y divide-gray-100">
                  <div className="grid grid-cols-3 gap-x-6 px-6 py-3">
                    <div>
                      <div className="text-xs font-medium uppercase tracking-wide text-gray-500">Sale Price</div>
                      <div className="mt-1 text-base font-semibold text-gray-900">{formatAmount(product.sale_price)}</div>
                    </div>
                    <div>
                      <div className="text-xs font-medium uppercase tracking-wide text-gray-500">Cost (WAC)</div>
                      <div className="mt-1 text-base font-semibold text-gray-900">{formatAmount(product.cost_price)}</div>
                    </div>
                    <div>
                      <div className="text-xs font-medium uppercase tracking-wide text-gray-500">Tax Rate</div>
                      <div className="mt-1 text-base font-semibold text-gray-900">{taxConfigName ?? (product.tax_rate ? `${product.tax_rate}%` : '-')}</div>
                    </div>
                  </div>
                  {product.sale_price && product.cost_price && (
                    <div className="px-6 py-3">
                      <div className="text-xs font-medium uppercase tracking-wide text-gray-500">Margin</div>
                      <div className="mt-1 text-base font-semibold text-gray-900">
                        {formatAmount(
                          String(parseFloat(product.sale_price) - parseFloat(product.cost_price))
                        )}
                        <span className="ml-2 text-sm font-normal text-gray-600">
                          ({(
                            ((parseFloat(product.sale_price) - parseFloat(product.cost_price)) /
                              parseFloat(product.cost_price)) *
                            100
                          ).toFixed(1)}%)
                        </span>
                      </div>
                    </div>
                  )}
                </div>
              </div>

              {/* Stock Levels */}
              <ProductStockLevels
                productId={product.id}
                costPrice={product.cost_price}
                currency={companyCurrency}
                locale={companyLocale}
              />
            </div>
          </div>

          {/* Secondary Sections - Below the fold */}
          {isOtospex && (product.oem_numbers?.length || product.cross_references?.length) ? (
            <div className="grid gap-6 lg:grid-cols-3">
              {/* Automotive Info */}
              <div className="lg:col-span-2">
                <div className="rounded-lg border border-gray-200 bg-white p-6">
                  <h3 className="mb-4 flex items-center gap-2 text-lg font-semibold text-gray-900">
                    <Tag className="h-5 w-5 text-gray-400" />
                    {t('products.sections.automotiveInfo')}
                  </h3>
                  <div className="space-y-4">
                    {product.oem_numbers && product.oem_numbers.length > 0 && (
                      <div>
                        <label className="text-sm font-medium text-gray-500">{t('products.oemNumbers')}</label>
                        <div className="mt-2 flex flex-wrap gap-2">
                          {product.oem_numbers.map((oem, index) => (
                            <span
                              key={index}
                              className="inline-flex rounded-md bg-gray-100 px-2.5 py-1 text-sm font-mono text-gray-700"
                            >
                              {oem}
                            </span>
                          ))}
                        </div>
                      </div>
                    )}
                    {product.cross_references && product.cross_references.length > 0 && (
                      <div>
                        <label className="text-sm font-medium text-gray-500">{t('products.crossReferences')}</label>
                        <div className="mt-2 overflow-hidden rounded-lg border border-gray-200">
                          <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                              <tr>
                                <th className="px-4 py-2 text-start text-xs font-medium uppercase text-gray-500">
                                  {t('products.brand')}
                                </th>
                                <th className="px-4 py-2 text-start text-xs font-medium uppercase text-gray-500">
                                  {t('products.reference')}
                                </th>
                              </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200">
                              {product.cross_references.map((ref, index) => (
                                <tr key={index}>
                                  <td className="px-4 py-2 text-sm text-gray-900">{ref.brand}</td>
                                  <td className="px-4 py-2 text-sm font-mono text-gray-600">
                                    {ref.reference}
                                  </td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      </div>
                    )}
                  </div>
                </div>
              </div>

              {/* Metadata Sidebar */}
              <div className="rounded-lg border border-gray-200 bg-white">
                <div className="border-b border-gray-200 px-6 py-4">
                  <h3 className="text-base font-semibold text-gray-900">Metadata</h3>
                </div>
                <div className="divide-y divide-gray-100 px-6">
                  <div className="grid grid-cols-1 gap-y-3 py-3">
                    <div>
                      <div className="text-xs font-medium uppercase tracking-wide text-gray-500">Created</div>
                      <div className="mt-1 text-sm text-gray-900">{formatDate(product.created_at)}</div>
                    </div>
                    {product.updated_at && (
                      <div>
                        <div className="text-xs font-medium uppercase tracking-wide text-gray-500">Last Updated</div>
                        <div className="mt-1 text-sm text-gray-900">{formatDate(product.updated_at)}</div>
                      </div>
                    )}
                  </div>
                  <div className="py-3">
                    <div className="text-xs font-medium uppercase tracking-wide text-gray-500">Product ID</div>
                    <div className="mt-1 font-mono text-xs text-gray-600">{product.id}</div>
                  </div>
                </div>
              </div>
            </div>
          ) : (
            <div className="rounded-lg border border-gray-200 bg-white">
              <div className="border-b border-gray-200 px-6 py-4">
                <h3 className="text-base font-semibold text-gray-900">Metadata</h3>
              </div>
              <div className="divide-y divide-gray-100 px-6">
                <div className="grid grid-cols-2 gap-x-6 py-3">
                  <div>
                    <div className="text-xs font-medium uppercase tracking-wide text-gray-500">Created</div>
                    <div className="mt-1 text-sm text-gray-900">{formatDate(product.created_at)}</div>
                  </div>
                  {product.updated_at && (
                    <div>
                      <div className="text-xs font-medium uppercase tracking-wide text-gray-500">Last Updated</div>
                      <div className="mt-1 text-sm text-gray-900">{formatDate(product.updated_at)}</div>
                    </div>
                  )}
                </div>
                <div className="py-3">
                  <div className="text-xs font-medium uppercase tracking-wide text-gray-500">Product ID</div>
                  <div className="mt-1 font-mono text-xs text-gray-600">{product.id}</div>
                </div>
              </div>
            </div>
          )}
        </TabsContent>

        {/* Inventory Movements Tab */}
        <TabsContent value="movements" className="mt-6">
          <ProductMovementsTab productId={id} />
        </TabsContent>

        {/* Financial Operations Tab */}
        <TabsContent value="financialOperations" className="mt-6">
          <ProductDocumentsTab productId={id} />
        </TabsContent>
      </Tabs>

      {/* Delete Confirmation Dialog */}
      <ConfirmDialog
        isOpen={showDeleteDialog}
        onClose={() => { setShowDeleteDialog(false) }}
        onConfirm={confirmDelete}
        title={t('products.messages.deleteProduct')}
        message={t('products.messages.confirmDeleteProduct', { name: product.name })}
        confirmText={t('common:actions.delete')}
        variant="danger"
        isLoading={deleteMutation.isPending}
      />
    </div>
  )
}
