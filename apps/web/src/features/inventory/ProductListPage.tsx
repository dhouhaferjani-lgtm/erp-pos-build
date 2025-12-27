import { useState, useMemo, useEffect } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, Package, Grid, List, DollarSign } from 'lucide-react'
import { api } from '../../lib/api'
import { useCompanyStore } from '../../stores/companyStore'
import { formatCurrency } from '../../lib/format'
import { SearchInput } from '../../components/ui/SearchInput'
import { FilterTabs } from '../../components/ui/FilterTabs'
import { Pagination } from '../../components/ui/Pagination'
import { usePagination } from '../../hooks/usePagination'

type ProductType = 'part' | 'service' | 'consumable'

interface Product {
  id: string
  name: string
  sku: string
  type: ProductType
  description: string | null
  sale_price: string | null
  purchase_price: string | null
  tax_rate: string | null
  unit: string | null
  barcode: string | null
  is_active: boolean
  oem_numbers: string[] | null
  cross_references: Array<{ brand: string; reference: string }> | null
  created_at: string
  updated_at: string | null
}

interface ProductsResponse {
  data: Product[]
  meta: {
    per_page: number
    has_more: boolean
    total?: number
  }
  links: {
    next: string | null
    prev: string | null
  }
}

const typeColors: Record<ProductType, string> = {
  part: 'bg-blue-100 text-blue-800',
  service: 'bg-purple-100 text-purple-800',
  consumable: 'bg-orange-100 text-orange-800',
}

// Type labels are loaded from translations

type StatusFilter = 'all' | 'active' | 'inactive'
type TypeFilter = 'all' | ProductType
type ViewMode = 'list' | 'grid'

export function ProductListPage() {
  const { t } = useTranslation(['common', 'inventory'])

  // Get translated type label
  const getTypeLabel = (type: ProductType) => {
    return t(`inventory:products.types.${type}`, type)
  }
  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())
  const [searchQuery, setSearchQuery] = useState('')
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all')
  const [typeFilter, setTypeFilter] = useState<TypeFilter>('all')
  const [viewMode, setViewMode] = useState<ViewMode>('list')

  const pagination = usePagination({ initialPerPage: 25 })

  const { data, isLoading, error } = useQuery({
    queryKey: ['products', searchQuery, statusFilter, typeFilter, pagination.cursor, pagination.perPage],
    queryFn: async () => {
      const params = new URLSearchParams()
      if (searchQuery) params.append('search', searchQuery)
      if (statusFilter !== 'all') params.append('is_active', statusFilter === 'active' ? '1' : '0')
      if (typeFilter !== 'all') params.append('type', typeFilter)
      params.append('per_page', String(pagination.perPage))
      if (pagination.cursor) params.append('cursor', pagination.cursor)
      const queryString = params.toString()
      const response = await api.get<ProductsResponse>(`/products${queryString ? `?${queryString}` : ''}`)
      return response.data
    },
  })

  // Update pagination state when data arrives
  useEffect(() => {
    if (data?.meta && data?.links) {
      pagination.updateFromResponse(data.meta, data.links)
    }
  }, [data, pagination.updateFromResponse])

  // Reset pagination when filters change
  useEffect(() => {
    pagination.reset()
  }, [searchQuery, statusFilter, typeFilter, pagination.reset])

  const products = data?.data ?? []
  const total = data?.meta?.total ?? products.length

  const filterTabs = useMemo(() => {
    return [
      { value: 'all' as StatusFilter, label: t('filters.all'), count: total },
      { value: 'active' as StatusFilter, label: t('filters.active') },
      { value: 'inactive' as StatusFilter, label: t('filters.inactive') },
    ]
  }, [t, total])

  // Get company currency with fallback
  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale?.replace('_', '-') ?? 'en-US'

  // Format currency using company settings
  const formatAmount = (amount: string | null) => {
    if (!amount) return '-'
    return formatCurrency(parseFloat(amount), {
      currency: companyCurrency,
      locale: companyLocale,
    })
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">{t('inventory:products.title')}</h1>
          <p className="text-gray-500">
            {total} {total === 1 ? t('inventory:products.singular') : t('inventory:products.plural')} {t('common:total')}
          </p>
        </div>
        <Link
          to="/inventory/products/new"
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
        >
          <Plus className="h-4 w-4" />
          {t('inventory:products.new')}
        </Link>
      </div>

      {/* Filters */}
      <div className="flex flex-col gap-4">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <FilterTabs tabs={filterTabs} value={statusFilter} onChange={setStatusFilter} />
          <div className="flex items-center gap-4">
            <SearchInput
              value={searchQuery}
              onChange={setSearchQuery}
              placeholder={t('inventory:products.searchPlaceholder')}
              className="w-full sm:w-72"
            />
            <div className="flex items-center gap-1 rounded-lg border border-gray-200 p-1">
              <button
                onClick={() => { setViewMode('list') }}
                className={`rounded p-1.5 ${viewMode === 'list' ? 'bg-gray-100 text-gray-900' : 'text-gray-400 hover:text-gray-600'}`}
                title={t('common:views.list')}
              >
                <List className="h-4 w-4" />
              </button>
              <button
                onClick={() => { setViewMode('grid') }}
                className={`rounded p-1.5 ${viewMode === 'grid' ? 'bg-gray-100 text-gray-900' : 'text-gray-400 hover:text-gray-600'}`}
                title={t('common:views.grid')}
              >
                <Grid className="h-4 w-4" />
              </button>
            </div>
          </div>
        </div>

        {/* Type filter */}
        <div className="flex items-center gap-2">
          <span className="text-sm text-gray-500">{t('inventory:products.type')}:</span>
          <div className="flex items-center gap-1">
            {(['all', 'part', 'service', 'consumable'] as TypeFilter[]).map((type) => (
              <button
                key={type}
                onClick={() => { setTypeFilter(type) }}
                className={`rounded-full px-3 py-1 text-xs font-medium transition-colors ${
                  typeFilter === type
                    ? 'bg-gray-900 text-white'
                    : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                }`}
              >
                {type === 'all' ? t('filters.all') : getTypeLabel(type)}
              </button>
            ))}
          </div>
        </div>
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="flex items-center justify-center py-12">
          <div className="text-gray-500">{t('status.loading')}</div>
        </div>
      ) : error ? (
        <div className="rounded-lg bg-red-50 p-4 text-red-700">
          {t('errors.loadingFailed', 'Error loading data. Please try again.')}
        </div>
      ) : products.length === 0 ? (
        <div className="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
          <Package className="mx-auto h-12 w-12 text-gray-400" />
          <h3 className="mt-2 text-sm font-semibold text-gray-900">
            {searchQuery ? t('status.noResults') : t('inventory:products.empty.title')}
          </h3>
          <p className="mt-1 text-sm text-gray-500">
            {searchQuery
              ? t('status.tryDifferentSearch')
              : t('inventory:products.empty.description')}
          </p>
          {!searchQuery && (
            <div className="mt-6">
              <Link
                to="/inventory/products/new"
                className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
              >
                <Plus className="h-4 w-4" />
                {t('inventory:products.new')}
              </Link>
            </div>
          )}
        </div>
      ) : viewMode === 'list' ? (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('fields.name', 'Name')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('inventory:products.sku')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('fields.type', 'Type')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('inventory:products.salePrice')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('fields.status', 'Status')}
                </th>
                <th className="relative px-6 py-3">
                  <span className="sr-only">{t('actions.actions')}</span>
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {products.map((product) => (
                <tr key={product.id} className="hover:bg-gray-50">
                  <td className="whitespace-nowrap px-6 py-4">
                    <Link
                      to={`/inventory/products/${product.id}`}
                      className="font-medium text-gray-900 hover:text-blue-600"
                    >
                      {product.name}
                    </Link>
                    {product.description && (
                      <p className="text-sm text-gray-500 truncate max-w-xs">
                        {product.description}
                      </p>
                    )}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                    {product.sku}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <span
                      className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${typeColors[product.type]}`}
                    >
                      {getTypeLabel(product.type)}
                    </span>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm font-medium text-gray-900">
                    {formatAmount(product.sale_price)}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <span
                      className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${
                        product.is_active
                          ? 'bg-green-100 text-green-800'
                          : 'bg-gray-100 text-gray-800'
                      }`}
                    >
                      {product.is_active ? t('status.active') : t('status.inactive')}
                    </span>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm">
                    <Link
                      to={`/inventory/products/${product.id}`}
                      className="text-blue-600 hover:text-blue-900"
                    >
                      {t('actions.view')}
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {products.map((product) => (
            <Link
              key={product.id}
              to={`/inventory/products/${product.id}`}
              className="block rounded-lg border border-gray-200 bg-white p-4 hover:shadow-md transition-shadow"
            >
              <div className="flex items-start justify-between">
                <div className="flex-1 min-w-0">
                  <h3 className="font-medium text-gray-900 truncate">{product.name}</h3>
                  <p className="text-sm text-gray-500">{product.sku}</p>
                </div>
                <span
                  className={`ml-2 inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${typeColors[product.type]}`}
                >
                  {getTypeLabel(product.type)}
                </span>
              </div>
              {product.description && (
                <p className="mt-2 text-sm text-gray-500 line-clamp-2">{product.description}</p>
              )}
              <div className="mt-4 flex items-center justify-between">
                <div className="flex items-center gap-1 text-sm font-medium text-gray-900">
                  <DollarSign className="h-4 w-4 text-gray-400" />
                  {formatAmount(product.sale_price)}
                </div>
                <span
                  className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${
                    product.is_active
                      ? 'bg-green-100 text-green-800'
                      : 'bg-gray-100 text-gray-800'
                  }`}
                >
                  {product.is_active ? t('status.active') : t('status.inactive')}
                </span>
              </div>
            </Link>
          ))}
        </div>
      )}

      {/* Pagination */}
      {!isLoading && !error && products.length > 0 && (
        <Pagination
          hasPrev={pagination.hasPrev}
          hasNext={pagination.hasNext}
          onPrev={pagination.goToPrev}
          onNext={pagination.goToNext}
          perPage={pagination.perPage}
          onPerPageChange={pagination.setPerPage}
          isLoading={isLoading}
        />
      )}
    </div>
  )
}
