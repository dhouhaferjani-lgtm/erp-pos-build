import { useState, useMemo } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, ArrowDownCircle, ArrowUpCircle, RefreshCw, ArrowRightLeft, Package } from 'lucide-react'
import { api } from '../../lib/api'
import { cn } from '../../lib/utils'
import { tokens, textColors, borderColors } from '../../lib/designTokens'
import { formatQuantity } from '../../lib/format'
import { bccomp } from '../../lib/decimal'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { SearchInput } from '../../components/ui/SearchInput'
import { FilterTabs } from '../../components/ui/FilterTabs'
import { LocationSelector } from '../location/LocationSelector'
import { useLocation } from '../../hooks/useLocation'
import { StatusBadge, type StatusTone } from '../../components/atoms'
import { PageHeader } from '../../components/molecules/PageHeader'
import {
  DataTable,
  type DataTableColumn,
  EmptyState,
} from '../../components/molecules'

interface StockMovement {
  id: string
  product_id: string
  product_name: string
  location_id: string
  location_name: string
  movement_type: string
  quantity: string
  quantity_before: string
  quantity_after: string
  reference: string
  notes: string | null
  user_id: string
  user_name: string | null
  created_at: string
}

interface StockMovementsResponse {
  data: StockMovement[]
}

type MovementFilter = 'all' | 'receipt' | 'issue' | 'adjustment' | 'transfer'

/**
 * Per-movement-type presentation: semantic tone for the {@link StatusBadge} pill
 * and the leading lucide icon. Tones come from the sanctioned {@link StatusTone}
 * set so no off-theme color literals are introduced.
 */
const movementTypeConfig: Record<string, { tone: StatusTone; icon: typeof ArrowDownCircle }> = {
  receipt: { tone: 'success', icon: ArrowDownCircle },
  issue: { tone: 'danger', icon: ArrowUpCircle },
  adjustment: { tone: 'info', icon: RefreshCw },
  transfer_in: { tone: 'success', icon: ArrowRightLeft },
  transfer_out: { tone: 'warning', icon: ArrowRightLeft },
}

export function StockMovementsPage() {
  const { t } = useTranslation(['inventory', 'common'])
  const { currentLocationId } = useLocation()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const [searchQuery, setSearchQuery] = useState('')
  const [movementFilter, setMovementFilter] = useState<MovementFilter>('all')

  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['stock-movements', searchQuery, movementFilter, currentLocationId]),
    queryFn: async () => {
      const params = new URLSearchParams()
      if (searchQuery) params.append('search', searchQuery)
      if (currentLocationId) params.append('location_id', currentLocationId)
      if (movementFilter !== 'all') {
        if (movementFilter === 'transfer') {
          // Backend doesn't have a combined transfer filter, we'll filter client-side
        } else {
          params.append('movement_type', movementFilter)
        }
      }
      const queryString = params.toString()
      const response = await api.get<StockMovementsResponse>(`/stock-movements${queryString ? `?${queryString}` : ''}`)
      return response.data
    },
    enabled: !!tenantId && !!companyId,
  })

  const movements = useMemo(() => {
    let items = data?.data ?? []
    if (movementFilter === 'transfer') {
      items = items.filter(m => m.movement_type === 'transfer_in' || m.movement_type === 'transfer_out')
    }
    return items
  }, [data?.data, movementFilter])

  const filterTabs = useMemo(() => {
    const allMovements = data?.data ?? []
    return [
      { value: 'all' as MovementFilter, label: t('common:filters.all'), count: allMovements.length },
      { value: 'receipt' as MovementFilter, label: t('movements.filters.receipts'), count: allMovements.filter(m => m.movement_type === 'receipt').length },
      { value: 'issue' as MovementFilter, label: t('movements.filters.issues'), count: allMovements.filter(m => m.movement_type === 'issue').length },
      { value: 'adjustment' as MovementFilter, label: t('movements.filters.adjustments'), count: allMovements.filter(m => m.movement_type === 'adjustment').length },
      { value: 'transfer' as MovementFilter, label: t('movements.filters.transfers'), count: allMovements.filter(m => m.movement_type.startsWith('transfer')).length },
    ]
  }, [t, data?.data])

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    })
  }

  const movementTypeLabels: Record<string, string> = {
    receipt: t('movements.typeLabels.receipt'),
    issue: t('movements.typeLabels.issue'),
    adjustment: t('movements.typeLabels.adjustment'),
    transfer_in: t('movements.typeLabels.transfer_in'),
    transfer_out: t('movements.typeLabels.transfer_out'),
  }

  const getMovementConfig = (type: string) => {
    const base = movementTypeConfig[type] ?? { tone: 'neutral' as StatusTone, icon: Package }
    return { ...base, label: movementTypeLabels[type] ?? type }
  }

  const columns: DataTableColumn<StockMovement>[] = [
    {
      key: 'date',
      header: t('products.movementsTab.columns.date'),
      cellClassName: cn('whitespace-nowrap text-sm', textColors.tertiary),
      render: (movement) => formatDate(movement.created_at),
    },
    {
      key: 'type',
      header: t('products.movementsTab.columns.type'),
      render: (movement) => {
        const config = getMovementConfig(movement.movement_type)
        const Icon = config.icon
        const isPositive = bccomp(movement.quantity, '0') >= 0
        return (
          <div className="flex items-center gap-2">
            <Icon className={cn('h-4 w-4', isPositive ? textColors.success : textColors.error)} />
            <StatusBadge tone={config.tone}>{config.label}</StatusBadge>
          </div>
        )
      },
    },
    {
      key: 'product',
      header: t('movements.columns.product'),
      render: (movement) => (
        <Link
          to={`/inventory/products/${movement.product_id}`}
          className={cn('font-medium', textColors.primary, textColors.hoverPrimary)}
        >
          {movement.product_name}
        </Link>
      ),
    },
    {
      key: 'location',
      header: t('products.movementsTab.columns.location'),
      cellClassName: cn('whitespace-nowrap text-sm', textColors.tertiary),
      render: (movement) => movement.location_name,
    },
    {
      key: 'quantity',
      numeric: true,
      header: t('products.movementsTab.columns.quantity'),
      render: (movement) => {
        const isPositive = bccomp(movement.quantity, '0') >= 0
        return (
          <span className={cn('text-sm font-semibold', isPositive ? textColors.success : textColors.error)}>
            {isPositive ? '+' : ''}{formatQuantity(movement.quantity)}
          </span>
        )
      },
    },
    {
      key: 'before',
      numeric: true,
      header: t('products.movementsTab.columns.before'),
      cellClassName: cn('text-sm', textColors.tertiary),
      render: (movement) => formatQuantity(movement.quantity_before),
    },
    {
      key: 'after',
      numeric: true,
      header: t('products.movementsTab.columns.after'),
      cellClassName: cn('text-sm font-medium', textColors.primary),
      render: (movement) => formatQuantity(movement.quantity_after),
    },
    {
      key: 'reference',
      header: t('products.movementsTab.columns.reference'),
      cellClassName: cn('text-sm max-w-xs truncate', textColors.tertiary),
      render: (movement) => (
        <span title={movement.reference}>{movement.reference}</span>
      ),
    },
    {
      key: 'user',
      header: t('movements.columns.user'),
      cellClassName: cn('whitespace-nowrap text-sm', textColors.tertiary),
      render: (movement) => movement.user_name ?? t('movements.system'),
    },
  ]

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('movements.title')}
        subtitle={t('movements.subtitle', { count: movements.length })}
        breadcrumb={
          <Link
            to="/inventory/stock"
            className={cn('inline-flex items-center gap-2 text-sm', textColors.tertiary, textColors.hoverPrimary)}
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:actions.back')}
          </Link>
        }
        actions={<LocationSelector />}
        className="mb-0"
      />

      {/* Filters */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <FilterTabs tabs={filterTabs} value={movementFilter} onChange={setMovementFilter} />
        <SearchInput
          value={searchQuery}
          onChange={setSearchQuery}
          placeholder={t('movements.searchPlaceholder')}
          className="w-full sm:w-72"
        />
      </div>

      {/* Content */}
      {error ? (
        <div className={cn(tokens.alert.base, tokens.alert.error)}>
          {t('common:errors.operationFailed')}
        </div>
      ) : (
        <DataTable
          columns={columns}
          data={movements}
          keyExtractor={(movement) => movement.id}
          isLoading={isLoading}
          className={cn('rounded-lg border bg-white', borderColors.light)}
          emptyState={
            <div className="py-6">
              <EmptyState
                icon={<RefreshCw className={cn('mx-auto h-12 w-12', textColors.disabled)} />}
                title={
                  searchQuery || movementFilter !== 'all'
                    ? t('common:status.noResults')
                    : t('movements.noMovements')
                }
                description={
                  searchQuery
                    ? t('common:status.tryDifferentSearch')
                    : movementFilter !== 'all'
                      ? t('movements.noMatchFilter')
                      : t('movements.emptyDescription')
                }
              />
            </div>
          }
        />
      )}
    </div>
  )
}
