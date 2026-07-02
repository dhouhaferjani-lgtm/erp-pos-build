import { useState, useMemo, useEffect } from 'react'
import { usePageTitle } from '../../hooks/usePageTitle'
import { Link, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Plus, Package, Grid, List, Upload, Tags, X } from 'lucide-react'
import { api } from '../../lib/api'
import { usePermissions } from '../../hooks/usePermissions'
import { getVariantsForProduct } from '../catalog/api/variantApi'
import {
  VariantLabelDialog,
  type VariantLabelDialogVariant,
} from '../catalog/components/VariantLabelDialog'
import { cn } from '../../lib/utils'
import { colors, tokens, textColors, borderColors } from '../../lib/designTokens'
import { useCompanyStore } from '../../stores/companyStore'
import { useAuthStore } from '../../stores/authStore'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { formatCurrency } from '../../lib/format'
import { ChevronUp, ChevronDown, ChevronsUpDown } from 'lucide-react'
import { useTableState } from '../../hooks/useTableState'
import { FilterPanel } from '../../components/ui/FilterPanel'
import { ActiveFilters } from '../../components/ui/ActiveFilters'
import { OffsetPagination } from '../../components/ui/OffsetPagination'
import { StatCard } from '../../components/ui/StatCard'
import { BooleanFilter } from '../../components/ui/filters/BooleanFilter'
import { RangeFilter } from '../../components/ui/filters/RangeFilter'
import { SearchFilter } from '../../components/ui/filters/SearchFilter'
import { Button, StatusBadge, statusTone } from '../../components/atoms'
import { PageHeader } from '../../components/molecules/PageHeader'
import {
  DataTable,
  type DataTableColumn,
  EmptyState,
} from '../../components/molecules'

interface Product {
  id: string
  name: string
  sku: string
  is_physical: boolean
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

type ViewMode = 'list' | 'grid'

/**
 * Clickable, sort-aware column header rendered INSIDE a DataTable header cell.
 *
 * DataTable owns the `<th>`, so we can't drop a `<th>`-based SortableTableHeader
 * in here; instead we render the same label + direction-arrow affordance and
 * keep the existing `tableState` sort wiring intact.
 */
function SortHeader({
  column,
  label,
  currentSort,
  currentDirection,
  onSort,
  align = 'left',
}: {
  column: string
  label: string
  currentSort: string | null
  currentDirection: 'asc' | 'desc'
  onSort: (column: string) => void
  align?: 'left' | 'right'
}) {
  const isActive = currentSort === column
  return (
    <button
      type="button"
      onClick={() => { onSort(column) }}
      className={cn(
        'inline-flex w-full items-center gap-1.5 uppercase tracking-wide',
        align === 'right' ? 'justify-end' : 'justify-start',
        textColors.tertiary,
        textColors.hoverPrimary,
      )}
      aria-label={`Sort by ${label}`}
    >
      <span>{label}</span>
      {isActive ? (
        currentDirection === 'asc' ? (
          <ChevronUp className="h-4 w-4" data-testid="chevron-up" />
        ) : (
          <ChevronDown className="h-4 w-4" data-testid="chevron-down" />
        )
      ) : (
        <ChevronsUpDown className="h-4 w-4 opacity-30" />
      )}
    </button>
  )
}

export function ProductListPage() {
  const { t } = useTranslation(['common', 'inventory'])
  usePageTitle('products.title', 'inventory')

  const navigate = useNavigate()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const currentCompany = useCompanyStore((state) =>
    state.companies.find((company) => company.id === state.currentCompanyId) ?? null
  )
  const [viewMode, setViewMode] = useState<ViewMode>('list')
  const [filterPanelOpen, setFilterPanelOpen] = useState(false)
  const { hasPermission } = usePermissions()

  // --- Bulk selection + label printing ---
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())
  const [isPreparingLabels, setIsPreparingLabels] = useState(false)
  const [labelDialogOpen, setLabelDialogOpen] = useState(false)
  const [labelVariants, setLabelVariants] = useState<VariantLabelDialogVariant[]>([])
  const [labelProductName, setLabelProductName] = useState<string | undefined>(
    undefined,
  )

  const tableState = useTableState({
    defaultSort: { column: 'name', direction: 'asc' },
    defaultPerPage: 25,
    syncToURL: true,
  })

  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['products', tableState.getQueryParams()]),
    queryFn: async () => {
      const params = new URLSearchParams(tableState.getQueryParams())
      const queryString = params.toString()
      const response = await api.get<ProductsResponse>(`/products${queryString ? `?${queryString}` : ''}`)
      return response.data
    },
    enabled: !!tenantId && !!companyId,
  })

  // Reset to page 1 when filters or sort change
  useEffect(() => {
    tableState.resetPage()
  }, [tableState.sortColumn, tableState.sortDirection, tableState.filters])

  const products = data?.data ?? []
  const canPrintLabels = hasPermission('catalog.labels.print')

  const toggleSelected = (id: string) => {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      if (next.has(id)) {
        next.delete(id)
      } else {
        next.add(id)
      }
      return next
    })
  }

  const toggleAllSelected = () => {
    setSelectedIds((prev) =>
      prev.size === products.length && products.length > 0
        ? new Set()
        : new Set(products.map((product) => product.id)),
    )
  }

  const clearSelection = () => {
    setSelectedIds(new Set())
  }

  const handlePrintLabels = async () => {
    const ids = [...selectedIds]
    if (ids.length === 0) return

    setIsPreparingLabels(true)
    try {
      const lists = await Promise.all(ids.map((id) => getVariantsForProduct(id)))
      const variants: VariantLabelDialogVariant[] = lists
        .flat()
        .filter((variant) => variant.is_active)
        .map((variant) => ({ id: variant.id, name_suffix: variant.name_suffix }))

      if (variants.length === 0) {
        toast.error(t('inventory:bulk.noVariants'))
        return
      }

      // When exactly one product is selected we can title the dialog with it.
      const onlyProductName =
        ids.length === 1
          ? products.find((product) => product.id === ids[0])?.name
          : undefined

      setLabelVariants(variants)
      setLabelProductName(onlyProductName)
      setLabelDialogOpen(true)
    } catch {
      toast.error(t('inventory:bulk.noVariants'))
    } finally {
      setIsPreparingLabels(false)
    }
  }

  // Get company currency with fallback
  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale?.replace('_', '-') ?? 'en-US'

  // Format currency using company settings
  const formatAmount = (amount: string | null) => {
    if (!amount) return '-'
    return formatCurrency(amount, {
      currency: companyCurrency,
      locale: companyLocale,
    })
  }

  // Filter config for ActiveFilters component
  const filterConfig = useMemo(() => ({
    is_physical: { label: t('inventory:products.isPhysical'), type: 'boolean' as const },
    is_active: { label: t('inventory:products.filters.active'), type: 'boolean' as const },
    price_min: { label: t('inventory:products.filters.priceMin'), type: 'range' as const },
    price_max: { label: t('inventory:products.filters.priceMax'), type: 'range' as const },
    search: { label: t('common:actions.search'), type: 'text' as const },
    has_stock: { label: t('inventory:products.filters.hasStock'), type: 'boolean' as const },
  }), [t])

  // Column descriptors for the canonical DataTable. The clickable sort headers
  // are rendered inside each column's `header` node — DataTable itself is
  // sort-agnostic, so we keep the existing tableState sort wiring intact and
  // hand it already-sorted data (the server returns it pre-sorted).
  const columns: DataTableColumn<Product>[] = [
    {
      key: 'name',
      header: (
        <SortHeader
          column="name"
          label={t('fields.name', 'Name')}
          currentSort={tableState.sortColumn}
          currentDirection={tableState.sortDirection}
          onSort={tableState.setSorting}
        />
      ),
      render: (product) => (
        <div>
          <Link
            to={`/inventory/products/${product.id}`}
            className={cn('font-medium', textColors.primary, textColors.hoverPrimary)}
          >
            {product.name}
          </Link>
          {product.description && (
            <p className={cn('truncate max-w-xs text-sm', textColors.tertiary)}>
              {product.description}
            </p>
          )}
        </div>
      ),
    },
    {
      key: 'sku',
      header: (
        <SortHeader
          column="sku"
          label={t('inventory:products.sku')}
          currentSort={tableState.sortColumn}
          currentDirection={tableState.sortDirection}
          onSort={tableState.setSorting}
        />
      ),
      cellClassName: cn('text-sm', textColors.tertiary),
      render: (product) => product.sku,
    },
    {
      key: 'sale_price',
      numeric: true,
      cellClassName: cn('font-medium', textColors.primary),
      header: (
        <SortHeader
          column="sale_price"
          label={t('inventory:products.salePrice')}
          currentSort={tableState.sortColumn}
          currentDirection={tableState.sortDirection}
          onSort={tableState.setSorting}
          align="right"
        />
      ),
      render: (product) => formatAmount(product.sale_price),
    },
    {
      key: 'status',
      header: t('fields.status', 'Status'),
      render: (product) => (
        <StatusBadge
          tone={statusTone(product.is_active ? 'active' : 'inactive', { inactive: 'neutral' })}
        >
          {product.is_active ? t('status.active') : t('status.inactive')}
        </StatusBadge>
      ),
    },
    {
      key: 'actions',
      align: 'right',
      header: <span className="sr-only">{t('actions.actions')}</span>,
      render: (product) => (
        <Link
          to={`/inventory/products/${product.id}`}
          className={textColors.brand}
        >
          {t('actions.view')}
        </Link>
      ),
    },
  ]

  return (
    <div className="space-y-6">
      {/* Header */}
      <PageHeader
        title={t('inventory:products.title')}
        subtitle={`${data?.meta.total ?? 0} ${data?.meta.total === 1 ? t('inventory:products.singular') : t('inventory:products.plural')} ${t('common:total')}`}
        actions={
          <>
            <Button
              variant="secondary"
              className="gap-2"
              onClick={() => { void navigate('/settings/import?entity=products') }}
            >
              <Upload className="h-4 w-4" />
              {t('actions.import')}
            </Button>
            <Button
              className="gap-2"
              onClick={() => { void navigate('/inventory/products/new') }}
            >
              <Plus className="h-4 w-4" />
              {t('inventory:products.new')}
            </Button>
          </>
        }
        className="mb-0"
      />

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
            onToggle={() => { setFilterPanelOpen(!filterPanelOpen); }}
            onClear={tableState.clearFilters}
            hasActiveFilters={tableState.hasActiveFilters}
          >
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <SearchFilter
                label={t('common:search')}
                value={tableState.filters['search'] as string | undefined}
                onChange={(v) => { tableState.setFilter('search', v); }}
                placeholder={t('inventory:products.searchPlaceholder')}
              />
              <BooleanFilter
                label={t('inventory:products.isPhysical')}
                value={tableState.filters['is_physical'] as boolean | undefined}
                onChange={(v) => { tableState.setFilter('is_physical', v); }}
              />
              <BooleanFilter
                label={t('inventory:products.filters.active')}
                value={tableState.filters['is_active'] as boolean | undefined}
                onChange={(v) => { tableState.setFilter('is_active', v); }}
              />
              <RangeFilter
                label={t('inventory:products.filters.priceRange')}
                min={tableState.filters['price_min'] as string | undefined}
                max={tableState.filters['price_max'] as string | undefined}
                onMinChange={(v) => { tableState.setFilter('price_min', v); }}
                onMaxChange={(v) => { tableState.setFilter('price_max', v); }}
                placeholder={companyCurrency}
              />
              <BooleanFilter
                label={t('inventory:products.filters.hasStock')}
                value={tableState.filters['has_stock'] as boolean | undefined}
                onChange={(v) => { tableState.setFilter('has_stock', v); }}
              />
            </div>
          </FilterPanel>

          <div className={cn('flex items-center gap-1 rounded-lg border p-1', borderColors.light)}>
            <button
              onClick={() => { setViewMode('list') }}
              className={cn(
                'rounded p-1.5',
                viewMode === 'list'
                  ? cn(tokens.table.header, textColors.primary)
                  : cn(textColors.disabled, textColors.hoverSecondary),
              )}
              title={t('common:views.list')}
            >
              <List className="h-4 w-4" />
            </button>
            <button
              onClick={() => { setViewMode('grid') }}
              className={cn(
                'rounded p-1.5',
                viewMode === 'grid'
                  ? cn(tokens.table.header, textColors.primary)
                  : cn(textColors.disabled, textColors.hoverSecondary),
              )}
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

      {/* Bulk selection action bar (list view only) */}
      {viewMode === 'list' && selectedIds.size > 0 && (
        <div
          className={cn(
            'flex items-center justify-between gap-3 rounded-lg border px-4 py-2',
            borderColors.light,
            tokens.table.header,
          )}
        >
          <span className={cn('text-sm font-medium', textColors.primary)}>
            {t('inventory:bulk.selected', { count: selectedIds.size })}
          </span>
          <div className="flex items-center gap-2">
            {canPrintLabels && (
              <Button
                variant="secondary"
                className="gap-2"
                disabled={isPreparingLabels}
                onClick={() => {
                  void handlePrintLabels()
                }}
              >
                <Tags className="h-4 w-4" />
                {isPreparingLabels
                  ? t('inventory:bulk.preparing')
                  : t('inventory:bulk.printLabels')}
              </Button>
            )}
            <Button variant="ghost" className="gap-2" onClick={clearSelection}>
              <X className="h-4 w-4" />
              {t('inventory:bulk.clear')}
            </Button>
          </div>
        </div>
      )}

      {/* Content */}
      {error ? (
        <div className={cn(tokens.alert.base, tokens.alert.error)}>
          {t('errors.loadingFailed', 'Error loading data. Please try again.')}
        </div>
      ) : viewMode === 'list' ? (
        <DataTable
          columns={columns}
          data={products}
          keyExtractor={(product) => product.id}
          isLoading={isLoading}
          selection={{
            selectedIds,
            onToggle: toggleSelected,
            onToggleAll: toggleAllSelected,
            getRowLabel: (product) =>
              t('inventory:bulk.selectRow', { name: product.name }),
            selectAllLabel: t('inventory:bulk.selectAll'),
          }}
          className={cn('rounded-lg border', colors.white, borderColors.light)}
          emptyState={
            <div className="py-6">
              <EmptyState
                icon={<Package className={cn('mx-auto h-12 w-12', textColors.disabled)} />}
                title={
                  tableState.hasActiveFilters
                    ? t('status.noResults')
                    : t('inventory:products.empty.title')
                }
                description={
                  tableState.hasActiveFilters
                    ? t('status.tryDifferentSearch')
                    : t('inventory:products.empty.description')
                }
              />
              {!tableState.hasActiveFilters && (
                <div className="mt-6 flex justify-center">
                  <Button
                    className="gap-2"
                    onClick={() => { void navigate('/inventory/products/new') }}
                  >
                    <Plus className="h-4 w-4" />
                    {t('inventory:products.new')}
                  </Button>
                </div>
              )}
            </div>
          }
        />
      ) : isLoading ? (
        <div className="flex items-center justify-center py-12">
          <div className={textColors.tertiary}>{t('status.loading')}</div>
        </div>
      ) : products.length === 0 ? (
        <div className={cn('rounded-lg border-2 border-dashed p-12 text-center', borderColors.default)}>
          <Package className={cn('mx-auto h-12 w-12', textColors.disabled)} />
          <h3 className={cn('mt-2 text-sm font-semibold', textColors.primary)}>
            {tableState.hasActiveFilters ? t('status.noResults') : t('inventory:products.empty.title')}
          </h3>
          <p className={cn('mt-1 text-sm', textColors.tertiary)}>
            {tableState.hasActiveFilters
              ? t('status.tryDifferentSearch')
              : t('inventory:products.empty.description')}
          </p>
          {!tableState.hasActiveFilters && (
            <div className="mt-6 flex justify-center">
              <Button
                className="gap-2"
                onClick={() => { void navigate('/inventory/products/new') }}
              >
                <Plus className="h-4 w-4" />
                {t('inventory:products.new')}
              </Button>
            </div>
          )}
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {products.map((product) => (
            <Link
              key={product.id}
              to={`/inventory/products/${product.id}`}
              className={cn('block p-4', tokens.card.base, tokens.card.hover)}
            >
              <div className="flex items-start justify-between">
                <div className="flex-1 min-w-0">
                  <h3 className={cn('font-medium truncate', textColors.primary)}>{product.name}</h3>
                  <p className={cn('text-sm', textColors.tertiary)}>{product.sku}</p>
                </div>
              </div>
              {product.description && (
                <p className={cn('mt-2 text-sm line-clamp-2', textColors.tertiary)}>{product.description}</p>
              )}
              <div className="mt-4 flex items-center justify-between">
                <div className={cn('text-sm font-medium', textColors.primary)}>
                  {formatAmount(product.sale_price)}
                </div>
                <StatusBadge
                  tone={statusTone(product.is_active ? 'active' : 'inactive', { inactive: 'neutral' })}
                >
                  {product.is_active ? t('status.active') : t('status.inactive')}
                </StatusBadge>
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
          from={data.meta.from ?? null}
          to={data.meta.to ?? null}
          onPageChange={tableState.setPage}
          onPerPageChange={tableState.setPerPage}
        />
      )}

      <VariantLabelDialog
        open={labelDialogOpen}
        onClose={() => {
          setLabelDialogOpen(false)
        }}
        variants={labelVariants}
        {...(labelProductName !== undefined
          ? { productName: labelProductName }
          : {})}
      />
    </div>
  )
}
