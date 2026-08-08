import { useState, useMemo } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../lib/api'
import { usePageTitle } from '../../hooks/usePageTitle'
import { useTranslation } from 'react-i18next'
import { Package, AlertTriangle, MapPin, Plus, RefreshCw, ArrowRightLeft } from 'lucide-react'
import { cn } from '../../lib/utils'
import { entityRoutes } from '../../lib/entityRoutes'
import { locationScopedKey } from '../../lib/locationScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { bccomp, formatQuantity } from '../../lib/decimal'
import { getQuantityDecimals } from '../../lib/quantityScale'
import { tokens, textColors, borderColors, colors, semanticColorTokens } from '../../lib/designTokens'
import { SearchInput } from '../../components/molecules/SearchInput'
import { FilterTabs } from '../../components/molecules/FilterTabs'
import { Button } from '../../components/atoms/Button/Button'
import { StatusBadge } from '../../components/atoms/StatusBadge/StatusBadge'
import { statusTone } from '../../components/atoms/StatusBadge/statusTone'
import { PageHeader } from '../../components/molecules/PageHeader'
import { DataTable, type DataTableColumn } from '../../components/molecules/DataTable/DataTable'
import { EmptyState } from '../../components/molecules/EmptyState/EmptyState'
import type { StockLevel, StockLevelsResponse } from './types'
import { useViewScope } from '../locations/hooks/useViewScope'
import { RequirePermission } from '../auth/components/RequirePermission'
import { QuickStockAdjustmentModal } from '../stock-adjustments/components/QuickStockAdjustmentModal'

type StockFilter = 'all' | 'low' | 'out'
type StockStatusKey = 'out' | 'low' | 'in_stock'

export function StockLevelsPage() {
  const { t } = useTranslation(['common', 'inventory'])
  usePageTitle('stockLevels.title', 'inventory')
  const { scope, effectiveLocationIds } = useViewScope()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const [searchQuery, setSearchQuery] = useState('')
  const [stockFilter, setStockFilter] = useState<StockFilter>('all')
  // The quick-correction target. The four modal states this replaces are gone
  // with the four raw mutations; `searchQuery` and `stockFilter` above SURVIVE —
  // the filter/search UI depends on them.
  const [adjustTarget, setAdjustTarget] = useState<StockLevel | null>(null)

  const { data, isLoading, error } = useQuery({
    queryKey: locationScopedKey(['stock-levels', searchQuery], scope),
    queryFn: async () => {
      const params = new URLSearchParams()
      if (searchQuery) params.append('search', searchQuery)
      effectiveLocationIds.forEach((id) => { params.append('location_ids[]', id) })
      const queryString = params.toString()
      const response = await api.get<StockLevelsResponse>(`/stock-levels${queryString ? `?${queryString}` : ''}`)
      return response.data
    },
    enabled: !!tenantId && !!companyId,
  })

  const stockLevels = useMemo(() => data?.data ?? [], [data?.data])
  const total = data?.meta?.total ?? stockLevels.length

  const filteredStockLevels = useMemo(() => {
    return stockLevels.filter((stock) => {
      if (stockFilter === 'low') {
        return (
          stock.min_quantity != null &&
          bccomp(stock.available, stock.min_quantity) <= 0 &&
          bccomp(stock.available, '0') > 0
        )
      }
      if (stockFilter === 'out') {
        return bccomp(stock.available, '0') <= 0
      }
      return true
    })
  }, [stockLevels, stockFilter])

  const filterTabs = useMemo(() => {
    const lowStockCount = stockLevels.filter(
      (s) =>
        s.min_quantity != null &&
        bccomp(s.available, s.min_quantity) <= 0 &&
        bccomp(s.available, '0') > 0
    ).length
    const outOfStockCount = stockLevels.filter((s) => bccomp(s.available, '0') <= 0).length

    return [
      { value: 'all' as StockFilter, label: t('common:filters.all'), count: total },
      { value: 'low' as StockFilter, label: t('inventory:stock.filters.lowStock'), count: lowStockCount },
      { value: 'out' as StockFilter, label: t('inventory:stock.filters.outOfStock'), count: outOfStockCount },
    ]
  }, [t, total, stockLevels])

  const getStockStatus = (stock: StockLevel): { label: string; key: StockStatusKey } => {
    if (bccomp(stock.available, '0') <= 0) {
      return { label: t('inventory:stock.status.outOfStock'), key: 'out' }
    }
    if (stock.min_quantity != null && bccomp(stock.available, stock.min_quantity) <= 0) {
      return { label: t('inventory:stock.status.lowStock'), key: 'low' }
    }
    return { label: t('inventory:stock.status.inStock'), key: 'in_stock' }
  }

  const columns: DataTableColumn<StockLevel>[] = [
    {
      key: 'product',
      header: t('inventory:stock.product'),
      render: (stock) => (
        <Link
          to={entityRoutes.product(stock.product_id)}
          className={cn('font-medium', textColors.primary, textColors.hoverPrimary)}
        >
          {stock.product_name ?? t('inventory:stock.unknownProduct')}
        </Link>
      ),
    },
    {
      key: 'location',
      header: t('inventory:stock.location'),
      cellClassName: cn('text-sm', textColors.tertiary),
      render: (stock) => (
        <div className="flex items-center gap-1">
          <MapPin className={cn('h-3.5 w-3.5', textColors.disabled)} />
          {stock.location_name ?? t('inventory:stock.defaultLocation')}
        </div>
      ),
    },
    {
      key: 'quantity',
      numeric: true,
      header: t('inventory:stock.quantity'),
      cellClassName: textColors.primary,
      render: (stock) => formatQuantity(stock.quantity, getQuantityDecimals(stock)),
    },
    {
      key: 'reserved',
      numeric: true,
      header: t('inventory:stock.reserved'),
      cellClassName: textColors.tertiary,
      render: (stock) => formatQuantity(stock.reserved, getQuantityDecimals(stock)),
    },
    {
      key: 'available',
      numeric: true,
      header: t('inventory:stock.available'),
      render: (stock) => {
        const isLowOrOut =
          bccomp(stock.available, '0') <= 0 ||
          (stock.min_quantity != null && bccomp(stock.available, stock.min_quantity) <= 0)
        return (
          <>
            <span className={cn('text-sm font-semibold', isLowOrOut ? textColors.error : textColors.primary)}>
              {formatQuantity(stock.available, getQuantityDecimals(stock))}
            </span>
            {stock.min_quantity != null && (
              <span className={cn('ml-1 text-xs', textColors.disabled)}>
                {t('inventory:stock.minQuantityLabel', { quantity: stock.min_quantity })}
              </span>
            )}
          </>
        )
      },
    },
    {
      key: 'status',
      header: t('common:fields.status'),
      render: (stock) => {
        const status = getStockStatus(stock)
        const isLowOrOut = status.key === 'out' || status.key === 'low'
        return (
          <div className="flex items-center gap-2">
            {isLowOrOut && <AlertTriangle className={cn('h-4 w-4', textColors.warningDark)} />}
            <StatusBadge tone={statusTone(status.key, { out: 'danger', low: 'warning', in_stock: 'success' })}>
              {status.label}
            </StatusBadge>
          </div>
        )
      },
    },
    {
      key: 'actions',
      align: 'right',
      header: <span className="sr-only">{t('common:actions.actions')}</span>,
      render: (stock) => (
        <div className="flex items-center justify-end gap-1">
          {/* The modal posts `post_immediately: true`, which the controller
              403s without `.post` — so BOTH permissions gate it, or a create-only
              operator fills the whole form and is refused at the end. */}
          <RequirePermission permission="inventory.adjustments.create" fallback={null}>
            <RequirePermission permission="inventory.adjustments.post" fallback={null}>
              <Button
                variant="ghost"
                size="sm"
                className="gap-1"
                onClick={() => { setAdjustTarget(stock) }}
                title={t('inventory:stock.adjust')}
              >
                <RefreshCw className="h-3.5 w-3.5" />
                {t('inventory:stock.adjust')}
              </Button>
            </RequirePermission>
          </RequirePermission>
          {/* A priced, supplier-sourced entry is a goods receipt, not a
              correction. Permission-gated on its DESTINATION's requirement, so
              this link cannot re-create the hole it exists to close. */}
          <RequirePermission permission="goods-receipt.create-standalone" fallback={null}>
            <Link
              to="/purchases/receipts/new"
              className={cn('inline-flex items-center gap-1 rounded-md px-2 py-1 text-sm', textColors.secondary)}
              title={t('stock-adjustments:quickModal.supplierReceiptHint')}
            >
              <Plus className="h-3.5 w-3.5" />
              {t('inventory:stock.buttons.in')}
            </Link>
          </RequirePermission>
          {/* Transfer is its own shipped document; deep-link into it with the
              source location and product prefilled. */}
          <RequirePermission permission="inventory.transfers.create" fallback={null}>
            <Link
              to={`/inventory/stock-transfers/new?source_location_id=${stock.location_id}&product_id=${stock.product_id}`}
              className={cn('inline-flex items-center gap-1 rounded-md px-2 py-1 text-sm', textColors.secondary)}
              title={t('inventory:stock.transfer')}
            >
              <ArrowRightLeft className="h-3.5 w-3.5" />
              {t('inventory:stock.transfer')}
            </Link>
          </RequirePermission>
        </div>
      ),
    },
  ]

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('inventory:stock.title')}
        subtitle={t('inventory:stock.subtitle', { count: total })}
        actions={
          <>
            <Link
              to="/inventory/movements"
              className={cn(
                'inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition-colors',
                semanticColorTokens.surface.base,
                borderColors.default,
                textColors.secondary,
                colors.hover.gray50,
              )}
            >
              <RefreshCw className="h-4 w-4" />
              {t('inventory:stock.viewMovements')}
            </Link>
          </>
        }
        className="mb-0"
      />

      {/* Filters */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <FilterTabs tabs={filterTabs} value={stockFilter} onChange={setStockFilter} />
        <SearchInput
          value={searchQuery}
          onChange={setSearchQuery}
          placeholder={t('inventory:products.searchPlaceholder')}
          className="w-full sm:w-72"
        />
      </div>

      {/* Content */}
      {error ? (
        <div className={cn(tokens.alert.base, tokens.alert.error)}>
          {t('common:errors.loadingFailed')}
        </div>
      ) : (
        <DataTable
          columns={columns}
          data={filteredStockLevels}
          keyExtractor={(stock) => stock.id}
          isLoading={isLoading}
          className={cn('rounded-lg border', semanticColorTokens.surface.base, borderColors.light)}
          emptyState={
            <div className="py-6">
              <EmptyState
                icon={<Package className={cn('mx-auto h-12 w-12', textColors.disabled)} />}
                title={
                  searchQuery || stockFilter !== 'all'
                    ? t('common:status.noResults')
                    : t('inventory:stock.empty.title')
                }
                description={
                  searchQuery
                    ? t('common:status.tryDifferentSearch')
                    : stockFilter !== 'all'
                      ? t('inventory:stock.empty.noMatchFilter')
                      : t('inventory:stock.empty.description')
                }
              />
            </div>
          }
        />
      )}

      {/* Summary Cards */}
      {stockLevels.length > 0 && (
        <div className="grid gap-4 sm:grid-cols-3">
          <div className={cn('rounded-lg border p-4', semanticColorTokens.surface.base, borderColors.light)}>
            <div className={cn('text-sm font-medium', textColors.tertiary)}>{t('inventory:stock.summary.totalProducts')}</div>
            <div className={cn('mt-1 text-2xl font-bold', textColors.primary)}>{total}</div>
          </div>
          <div className={cn('rounded-lg border p-4', tokens.alert.warning, borderColors.warning)}>
            <div className={cn('text-sm font-medium', textColors.warning)}>{t('inventory:stock.summary.lowStock')}</div>
            <div className={cn('mt-1 text-2xl font-bold', textColors.warning)}>
              {
                stockLevels.filter(
                  (s) =>
                    s.min_quantity != null &&
                    bccomp(s.available, s.min_quantity) <= 0 &&
                    bccomp(s.available, '0') > 0
                ).length
              }
            </div>
          </div>
          <div className={cn('rounded-lg border p-4', tokens.alert.error, borderColors.error)}>
            <div className={cn('text-sm font-medium', textColors.error)}>{t('inventory:stock.summary.outOfStock')}</div>
            <div className={cn('mt-1 text-2xl font-bold', textColors.error)}>
              {stockLevels.filter((s) => bccomp(s.available, '0') <= 0).length}
            </div>
          </div>
        </div>
      )}

      {adjustTarget !== null && (
        <QuickStockAdjustmentModal
          open
          productId={adjustTarget.product_id}
          productName={adjustTarget.product_name ?? t('inventory:stock.unknownProduct')}
          locationId={adjustTarget.location_id}
          onClose={() => { setAdjustTarget(null) }}
        />
      )}
    </div>
  )
}
