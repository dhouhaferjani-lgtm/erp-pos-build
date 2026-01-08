import { useState, useMemo, useEffect } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, Package, Grid, List } from 'lucide-react'
import { api } from '../../lib/api'
import { useCompanyStore } from '../../stores/companyStore'
import { formatCurrency } from '../../lib/format'
import { useTableState } from '../../hooks/useTableState'
import { SortableTableHeader } from '../../components/ui/SortableTableHeader'
import { FilterPanel } from '../../components/ui/FilterPanel'
import { ActiveFilters } from '../../components/ui/ActiveFilters'
import { OffsetPagination } from '../../components/ui/OffsetPagination'
import { StatCard } from '../../components/ui/StatCard'
import { EnumFilter } from '../../components/ui/filters/EnumFilter'
import { BooleanFilter } from '../../components/ui/filters/BooleanFilter'
import { RangeFilter } from '../../components/ui/filters/RangeFilter'
import { SearchFilter } from '../../components/ui/filters/SearchFilter'

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
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
  }
  aggregates: {
    total_products: number
    total_active: number
    average_price: string | null
  }
}

const typeColors: Record<ProductType, string> = {
  part: 'bg-blue-100 text-blue-800',
  service: 'bg-purple-100 text-purple-800',
  consumable: 'bg-orange-100 text-orange-800',
}

type ViewMode = 'list' | 'grid'

export function ProductListPage() {
  const { t } = useTranslation(['common', 'inventory'])

  // Get translated type label
  const getTypeLabel = (type: ProductType) => {
    return t(`inventory:products.types.${type}`, type)
  }

  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())
  const [viewMode, setViewMode] = useState<ViewMode>('list')
  const [filterPanelOpen, setFilterPanelOpen] = useState(false)

  const tableState = useTableState({
    defaultSort: { column: 'name', direction: 'asc' },
    defaultPerPage: 25,
    syncToURL: true,
  })

  const { data, isLoading, error } = useQuery({
    queryKey: ['products', tableState.getQueryParams()],
    queryFn: async () => {
      const params = new URLSearchParams(tableState.getQueryParams() as Record<string, string>)
      const queryString = params.toString()
      const response = await api.get<ProductsResponse>(`/products${queryString ? `?${queryString}` : ''}`)
      return response.data
    },
  })

  // Reset to page 1 when filters or sort change
  useEffect(() => {
    tableState.resetPage()
  }, [tableState.sortColumn, tableState.sortDirection, tableState.filters])

  const products = data?.data ?? []

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

  // Product type options for filter
  const productTypeOptions = useMemo(() => [
    { value: 'part', label: getTypeLabel('part') },
    { value: 'service', label: getTypeLabel('service') },
    { value: 'consumable', label: getTypeLabel('consumable') },
  ], [t])

  // Filter config for ActiveFilters component
  const filterConfig = useMemo(() => ({
    type: { label: t('inventory:products.filters.type'), type: 'enum' as const },
    is_active: { label: t('inventory:products.filters.active'), type: 'boolean' as const },
    price_min: { label: t('inventory:products.filters.priceMin'), type: 'range' as const },
    price_max: { label: t('inventory:products.filters.priceMax'), type: 'range' as const },
    search: { label: t('common:search'), type: 'text' as const },
    has_stock: { label: t('inventory:products.filters.hasStock'), type: 'boolean' as const },
  }), [t])

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">{t('inventory:products.title')}</h1>
          <p className="text-gray-500">
            {data?.meta.total ?? 0} {data?.meta.total === 1 ? t('inventory:products.singular') : t('inventory:products.plural')} {t('common:total')}
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

      {/* Aggregate Stats */}
      {data?.aggregates && (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <StatCard
            label={t('inventory:products.aggregates.totalProducts')}
            value={data.aggregates.total_products.toString()}
          />
          <StatCard
            label={t('inventory:products.aggregates.totalActive')}
            value={data.aggregates.total_active.toString()}
          />
          <StatCard
            label={t('inventory:products.aggregates.averagePrice')}
            value={data.aggregates.average_price ? formatAmount(data.aggregates.average_price) : '-'}
          />
        </div>
      )}

      {/* Filter Panel and View Controls */}
      <div className="flex flex-col gap-4">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <FilterPanel
            isOpen={filterPanelOpen}
            onToggle={() => setFilterPanelOpen(!filterPanelOpen)}
            onClear={tableState.clearFilters}
            hasActiveFilters={tableState.hasActiveFilters}
          >
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <SearchFilter
                label={t('common:search')}
                value={tableState.filters.search as string | undefined}
                onChange={(v) => tableState.setFilter('search', v)}
                placeholder={t('inventory:products.searchPlaceholder')}
              />
              <EnumFilter
                label={t('inventory:products.filters.type')}
                value={tableState.filters.type as string | undefined}
                onChange={(v) => tableState.setFilter('type', v)}
                options={productTypeOptions}
              />
              <BooleanFilter
                label={t('inventory:products.filters.active')}
                value={tableState.filters.is_active as boolean | undefined}
                onChange={(v) => tableState.setFilter('is_active', v)}
              />
              <RangeFilter
                label={t('inventory:products.filters.priceRange')}
                min={tableState.filters.price_min as string | undefined}
                max={tableState.filters.price_max as string | undefined}
                onMinChange={(v) => tableState.setFilter('price_min', v)}
                onMaxChange={(v) => tableState.setFilter('price_max', v)}
                placeholder={companyCurrency}
              />
              <BooleanFilter
                label={t('inventory:products.filters.hasStock')}
                value={tableState.filters.has_stock as boolean | undefined}
                onChange={(v) => tableState.setFilter('has_stock', v)}
              />
            </div>
          </FilterPanel>

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

        {/* Active Filters */}
        {tableState.hasActiveFilters && (
          <ActiveFilters
            filters={tableState.filters}
            onRemove={tableState.removeFilter}
            filterConfig={filterConfig}
          />
        )}
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
            {tableState.hasActiveFilters ? t('status.noResults') : t('inventory:products.empty.title')}
          </h3>
          <p className="mt-1 text-sm text-gray-500">
            {tableState.hasActiveFilters
              ? t('status.tryDifferentSearch')
              : t('inventory:products.empty.description')}
          </p>
          {!tableState.hasActiveFilters && (
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
                <SortableTableHeader
                  column="name"
                  label={t('fields.name', 'Name')}
                  currentSort={tableState.sortColumn}
                  currentDirection={tableState.sortDirection}
                  onSort={tableState.setSorting}
                  align="left"
                />
                <SortableTableHeader
                  column="sku"
                  label={t('inventory:products.sku')}
                  currentSort={tableState.sortColumn}
                  currentDirection={tableState.sortDirection}
                  onSort={tableState.setSorting}
                  align="left"
                />
                <SortableTableHeader
                  column="type"
                  label={t('fields.type', 'Type')}
                  currentSort={tableState.sortColumn}
                  currentDirection={tableState.sortDirection}
                  onSort={tableState.setSorting}
                  align="left"
                />
                <SortableTableHeader
                  column="sale_price"
                  label={t('inventory:products.salePrice')}
                  currentSort={tableState.sortColumn}
                  currentDirection={tableState.sortDirection}
                  onSort={tableState.setSorting}
                  align="right"
                />
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
                <div className="text-sm font-medium text-gray-900">
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
      {!isLoading && !error && products.length > 0 && data?.meta && (
        <OffsetPagination
          currentPage={data.meta.current_page}
          lastPage={data.meta.last_page}
          total={data.meta.total}
          perPage={data.meta.per_page}
          from={data.meta.from ?? undefined}
          to={data.meta.to ?? undefined}
          onPageChange={tableState.setPage}
          onPerPageChange={tableState.setPerPage}
        />
      )}
    </div>
  )
}
