import { useState } from 'react'
import { Link, useParams, useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Edit, Trash2, Tag } from 'lucide-react'
import { api, apiDelete } from '../../lib/api'
import { useCompanyStore } from '../../stores/companyStore'
import { useAuthStore } from '../../stores/authStore'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useProductConfig } from '../../contexts/ProductConfigContext'
import { formatCurrency } from '../../lib/format'
import { bccomp, bcdiv, bcmul, bcsub } from '../../lib/decimal'
import { cn } from '../../lib/utils'
import { tokens, textColors, borderColors } from '../../lib/designTokens'
import { Button } from '../../components/atoms/Button'
import { StatusBadge, statusTone } from '../../components/atoms/StatusBadge'
import { PageHeader } from '../../components/molecules/PageHeader'
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
import { ProductStockLevels } from './components'
import { useTaxConfigName } from '../../hooks/useTaxConfigName'
import { inventoryProductsInvalidationPredicate } from './_invalidation'
import { ProductHero } from '../products/editor/components/ProductHero'
import {
  PRODUCT_DETAIL_SECTIONS,
  type EditorSectionRendererRegistry,
} from '../products/editor/viewSections'

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
  primary_image_url?: string | null
  stock_quantity?: string | null
  brand?: { id?: string; name: string; source?: string | null } | null
  category?: { id?: string; name: string } | null
  oem_numbers: string[] | null
  cross_references: { brand: string; reference: string }[] | null
  created_at: string
  updated_at: string | null
}

interface ProductResponse {
  data: Product
}

const PRODUCT_DETAIL_TABS = ['details', 'movements', 'financialOperations'] as const
type ProductDetailTab = typeof PRODUCT_DETAIL_TABS[number]

function isProductDetailTab(value: string | null): value is ProductDetailTab {
  return value !== null && (PRODUCT_DETAIL_TABS as readonly string[]).includes(value)
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
  const [searchParams, setSearchParams] = useSearchParams()
  const queryClient = useQueryClient()
  const [showDeleteDialog, setShowDeleteDialog] = useState(false)
  const tabParam = searchParams.get('tab')
  const activeTab: ProductDetailTab = isProductDetailTab(tabParam) ? tabParam : 'details'

  const handleTabChange = (tab: string) => {
    const next = new URLSearchParams(searchParams)
    next.set('tab', tab)
    setSearchParams(next)
  }

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
    productId: id ?? '',
    enabled: !!id,
  })

  // Resolve the tax configuration display name. MUST be called unconditionally
  // here (before the early returns below) — calling it after a `return` makes
  // the hook count change between the loading and loaded renders, which throws
  // "Rendered more hooks than during the previous render". The hook null-guards
  // its argument internally, so `data?.…` is safe before the product loads.
  const taxConfigName = useTaxConfigName(data?.default_tax_configuration_id)

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
    return formatCurrency(amount, {
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
      <div className={cn(tokens.alert.base, tokens.alert.error)}>
        {t('common:errors.loadingFailed')}
      </div>
    )
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className={textColors.tertiary}>{t('status.loading')}</div>
      </div>
    )
  }

  if (error || !data) {
    return (
      <div className={cn(tokens.alert.base, tokens.alert.error)}>
        {t('common:errors.loadingFailed')}
      </div>
    )
  }

  const product = data
  const productMargin = product.sale_price !== null && product.cost_price !== null
    ? bcsub(product.sale_price, product.cost_price, 3)
    : null
  const productMarginPercent = product.sale_price !== null && product.cost_price !== null && bccomp(product.cost_price, '0') > 0
    ? bcmul(
      bcdiv(bcsub(product.sale_price, product.cost_price, 4), product.cost_price, 4),
      '100',
      1,
    )
    : null
  const hasAutomotiveData = (product.oem_numbers?.length ?? 0) > 0 || (product.cross_references?.length ?? 0) > 0
  const sectionGateCtx = { isOtospex, hasAutomotiveData }
  const metadataSection = (
    <div className={cn('rounded-lg border bg-white', borderColors.light)}>
      <div className={cn('border-b px-6 py-4', borderColors.light)}>
        <h3 className={cn('text-base font-semibold', textColors.primary)}>{t('products.sections.metadata')}</h3>
      </div>
      <div className={cn('divide-y px-6', borderColors.divideLight)}>
        <div className="grid grid-cols-2 gap-x-6 py-3">
          <div>
            <div className={cn('text-xs font-medium uppercase tracking-wide', textColors.tertiary)}>{t('products.created')}</div>
            <div className={cn('mt-1 text-sm', textColors.primary)}>{formatDate(product.created_at)}</div>
          </div>
          {product.updated_at && (
            <div>
              <div className={cn('text-xs font-medium uppercase tracking-wide', textColors.tertiary)}>{t('products.lastUpdated')}</div>
              <div className={cn('mt-1 text-sm', textColors.primary)}>{formatDate(product.updated_at)}</div>
            </div>
          )}
        </div>
        <div className="py-3">
          <div className={cn('text-xs font-medium uppercase tracking-wide', textColors.tertiary)}>{t('products.productId')}</div>
          <div className={cn('mt-1 font-mono text-xs', textColors.tertiary)}>{product.id}</div>
        </div>
      </div>
    </div>
  )
  const sectionRenderers: EditorSectionRendererRegistry<typeof sectionGateCtx> = {
    'product-information': () => (
      <div className={cn('rounded-lg border bg-white', borderColors.light)}>
        <div className={cn('border-b px-6 py-4', borderColors.light)}>
          <h2 className={cn('text-base font-semibold', textColors.primary)}>{t('products.productInformation')}</h2>
        </div>
        <div className={cn('divide-y', borderColors.divideLight)}>
          {product.description && (
            <div className="px-6 py-3">
              <div className={cn('text-xs font-medium uppercase tracking-wide', textColors.tertiary)}>{t('products.description')}</div>
              <div className={cn('mt-1 text-sm', textColors.primary)}>{product.description}</div>
            </div>
          )}
          <div className="grid grid-cols-2 gap-x-6 px-6 py-3">
            <div>
              <div className={cn('text-xs font-medium uppercase tracking-wide', textColors.tertiary)}>{t('products.unit')}</div>
              <div className={cn('mt-1 text-sm font-medium', textColors.primary)}>{product.unit ?? '-'}</div>
            </div>
            {product.barcode && (
              <div>
                <div className={cn('text-xs font-medium uppercase tracking-wide', textColors.tertiary)}>{t('products.barcode')}</div>
                <div className={cn('mt-1 font-mono text-sm font-medium', textColors.primary)}>{product.barcode}</div>
              </div>
            )}
          </div>
        </div>
      </div>
    ),
    pricing: () => (
      <div className={cn('rounded-lg border bg-white', borderColors.light)}>
        <div className={cn('border-b px-6 py-4', borderColors.light)}>
          <h2 className={cn('text-base font-semibold', textColors.primary)}>{t('products.sections.pricing')}</h2>
        </div>
        <div className={cn('divide-y', borderColors.divideLight)}>
          <div className="grid grid-cols-3 gap-x-6 px-6 py-3">
            <div>
              <div className={cn('text-xs font-medium uppercase tracking-wide', textColors.tertiary)}>{t('products.salePrice')}</div>
              <div className={cn('mt-1 text-base font-semibold tabular-nums', textColors.primary)}>{formatAmount(product.sale_price)}</div>
            </div>
            <div>
              <div className={cn('text-xs font-medium uppercase tracking-wide', textColors.tertiary)}>{t('products.costWac')}</div>
              <div className={cn('mt-1 text-base font-semibold tabular-nums', textColors.primary)}>{formatAmount(product.cost_price)}</div>
            </div>
            <div>
              <div className={cn('text-xs font-medium uppercase tracking-wide', textColors.tertiary)}>{t('products.fields.taxRate')}</div>
              <div className={cn('mt-1 text-base font-semibold tabular-nums', textColors.primary)}>{taxConfigName ?? (product.tax_rate ? `${product.tax_rate}%` : '-')}</div>
            </div>
          </div>
          {productMargin !== null && (
            <div className="px-6 py-3">
              <div className={cn('text-xs font-medium uppercase tracking-wide', textColors.tertiary)}>{t('products.margin')}</div>
              <div className={cn('mt-1 text-base font-semibold tabular-nums', textColors.primary)}>
                {formatAmount(productMargin)}
                {productMarginPercent !== null && (
                  <span className={cn('ms-2 text-sm font-normal', textColors.tertiary)}>
                    ({productMarginPercent}%)
                  </span>
                )}
              </div>
            </div>
          )}
        </div>
      </div>
    ),
    'stock-levels': () => (
      <ProductStockLevels
        productId={product.id}
        costPrice={product.cost_price}
        currency={companyCurrency}
        locale={companyLocale}
      />
    ),
    automotive: () => (
      <div className={tokens.card.base}>
        <h3 className={cn(tokens.heading.section, 'mb-4 flex items-center gap-2')}>
          <Tag className={cn('h-5 w-5', textColors.disabled)} />
          {t('products.sections.automotiveInfo')}
        </h3>
        <div className="space-y-4">
          {product.oem_numbers && product.oem_numbers.length > 0 && (
            <div>
              <label className={cn('text-sm font-medium', textColors.tertiary)}>{t('products.oemNumbers')}</label>
              <div className="mt-2 flex flex-wrap gap-2">
                {product.oem_numbers.map((oem) => (
                  <span key={oem} className={cn(tokens.table.cellMonoBadge, 'font-normal', textColors.secondary)}>
                    {oem}
                  </span>
                ))}
              </div>
            </div>
          )}
          {product.cross_references && product.cross_references.length > 0 && (
            <div>
              <label className={cn('text-sm font-medium', textColors.tertiary)}>{t('products.crossReferences')}</label>
              <div className={cn('mt-2 overflow-hidden rounded-lg border', borderColors.light)}>
                <table className={cn('min-w-full divide-y', borderColors.divideDefault)}>
                  <thead className={tokens.table.header}>
                    <tr>
                      <th className={cn('px-4 py-2 text-start text-xs font-medium uppercase', textColors.tertiary)}>
                        {t('products.brand')}
                      </th>
                      <th className={cn('px-4 py-2 text-start text-xs font-medium uppercase', textColors.tertiary)}>
                        {t('products.reference')}
                      </th>
                    </tr>
                  </thead>
                  <tbody className={cn('divide-y', borderColors.divideDefault)}>
                    {product.cross_references.map((ref, index) => (
                      <tr key={index}>
                        <td className={cn('px-4 py-2 text-sm', textColors.primary)}>{ref.brand}</td>
                        <td className={cn('px-4 py-2 text-sm font-mono', textColors.tertiary)}>{ref.reference}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </div>
      </div>
    ),
    metadata: () => metadataSection,
  }

  const backLink = (
    <Link
      to="/inventory/products"
      className={cn(
        'inline-flex items-center gap-2 text-sm',
        textColors.tertiary,
        textColors.hoverPrimary,
      )}
    >
      <ArrowLeft className="h-4 w-4" />
      {t('common:actions.back')}
    </Link>
  )

  return (
    <div className="space-y-6">
      {/* Header */}
      <PageHeader
        title={product.name}
        breadcrumb={backLink}
        subtitle={`${t('common:sku')}: ${product.sku}`}
        actions={
          <>
            <StatusBadge tone={statusTone(product.is_active ? 'active' : 'inactive')}>
              {product.is_active ? t('common:status.active') : t('common:status.inactive')}
            </StatusBadge>
            <Link
              to={`/inventory/products/${product.id}/edit`}
              className={cn(
                tokens.button.base,
                tokens.button.secondary,
                tokens.button.sizes.md,
                'gap-2',
              )}
            >
              <Edit className="h-4 w-4" />
              {t('common:actions.edit')}
            </Link>
            <Button
              variant="danger"
              onClick={handleDelete}
              disabled={deleteMutation.isPending}
            >
              <Trash2 className="me-2 h-4 w-4" />
              {deleteMutation.isPending ? t('common:status.saving') : t('common:actions.delete')}
            </Button>
          </>
        }
      />

      {/* Tabs */}
      <Tabs defaultValue="details" value={activeTab} onChange={handleTabChange}>
        <TabsList>
          <TabsTrigger value="details">{t('products.tabs.details')}</TabsTrigger>
          <TabsTrigger value="movements">{t('products.tabs.movements')}</TabsTrigger>
          <TabsTrigger value="financialOperations">{t('products.tabs.financialOperations')}</TabsTrigger>
        </TabsList>

        {/* Details Tab */}
        <TabsContent value="details" className="mt-6">
          <div className="space-y-6">
            <ProductHero
              product={product}
              currency={companyCurrency}
              locale={companyLocale}
            />
            <div className="space-y-6">
              {PRODUCT_DETAIL_SECTIONS
                .filter((section) => section.when?.(sectionGateCtx) ?? true)
                .map((section) => (
                  <section key={section.id} id={section.id}>
                    {sectionRenderers[section.component](sectionGateCtx)}
                  </section>
                ))}
            </div>
          </div>
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
