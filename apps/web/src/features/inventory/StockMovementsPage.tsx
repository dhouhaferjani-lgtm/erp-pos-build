import { useState, useMemo } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, ArrowDownCircle, ArrowUpCircle, RefreshCw, ArrowRightLeft, Package } from 'lucide-react'
import { api } from '../../lib/api'
import { formatQuantity } from '../../lib/format'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { SearchInput } from '../../components/ui/SearchInput'
import { FilterTabs } from '../../components/ui/FilterTabs'
import { LocationSelector } from '../location/LocationSelector'
import { useLocation } from '../../hooks/useLocation'

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

const movementTypeConfig: Record<string, { label: string; color: string; icon: typeof ArrowDownCircle }> = {
  receipt: { label: 'Receipt', color: 'bg-green-100 text-green-800', icon: ArrowDownCircle },
  issue: { label: 'Issue', color: 'bg-red-100 text-red-800', icon: ArrowUpCircle },
  adjustment: { label: 'Adjustment', color: 'bg-blue-100 text-blue-800', icon: RefreshCw },
  transfer_in: { label: 'Transfer In', color: 'bg-purple-100 text-purple-800', icon: ArrowRightLeft },
  transfer_out: { label: 'Transfer Out', color: 'bg-orange-100 text-orange-800', icon: ArrowRightLeft },
}

export function StockMovementsPage() {
  const { t } = useTranslation()
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
      { value: 'all' as MovementFilter, label: t('filters.all'), count: allMovements.length },
      { value: 'receipt' as MovementFilter, label: 'Receipts', count: allMovements.filter(m => m.movement_type === 'receipt').length },
      { value: 'issue' as MovementFilter, label: 'Issues', count: allMovements.filter(m => m.movement_type === 'issue').length },
      { value: 'adjustment' as MovementFilter, label: 'Adjustments', count: allMovements.filter(m => m.movement_type === 'adjustment').length },
      { value: 'transfer' as MovementFilter, label: 'Transfers', count: allMovements.filter(m => m.movement_type.startsWith('transfer')).length },
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

  const getMovementConfig = (type: string) => {
    return movementTypeConfig[type] ?? { label: type, color: 'bg-gray-100 text-gray-800', icon: Package }
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link
            to="/inventory/stock"
            className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
          >
            <ArrowLeft className="h-4 w-4" />
            {t('actions.back')}
          </Link>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Stock Movements</h1>
            <p className="text-gray-500">
              {movements.length} movement{movements.length !== 1 ? 's' : ''} recorded
            </p>
          </div>
        </div>
        <LocationSelector />
      </div>

      {/* Filters */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <FilterTabs tabs={filterTabs} value={movementFilter} onChange={setMovementFilter} />
        <SearchInput
          value={searchQuery}
          onChange={setSearchQuery}
          placeholder="Search movements..."
          className="w-full sm:w-72"
        />
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
      ) : movements.length === 0 ? (
        <div className="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
          <RefreshCw className="mx-auto h-12 w-12 text-gray-400" />
          <h3 className="mt-2 text-sm font-semibold text-gray-900">
            {searchQuery || movementFilter !== 'all' ? t('status.noResults') : 'No movements'}
          </h3>
          <p className="mt-1 text-sm text-gray-500">
            {searchQuery
              ? t('status.tryDifferentSearch', 'Try a different search term.')
              : movementFilter !== 'all'
                ? 'No movements match the selected filter.'
                : 'Stock movements will appear here when inventory changes.'}
          </p>
        </div>
      ) : (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  Date
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  Type
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  Product
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  Location
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  Quantity
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  Before
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  After
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  Reference
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  User
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {movements.map((movement) => {
                const config = getMovementConfig(movement.movement_type)
                const Icon = config.icon
                const qty = parseFloat(movement.quantity)
                const isPositive = qty >= 0

                return (
                  <tr key={movement.id} className="hover:bg-gray-50">
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                      {formatDate(movement.created_at)}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <div className="flex items-center gap-2">
                        <Icon className={`h-4 w-4 ${isPositive ? 'text-green-600' : 'text-red-600'}`} />
                        <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${config.color}`}>
                          {config.label}
                        </span>
                      </div>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <Link
                        to={`/inventory/products/${movement.product_id}`}
                        className="font-medium text-gray-900 hover:text-blue-600"
                      >
                        {movement.product_name}
                      </Link>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                      {movement.location_name}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end">
                      <span className={`text-sm font-semibold ${isPositive ? 'text-green-600' : 'text-red-600'}`}>
                        {isPositive ? '+' : ''}{formatQuantity(movement.quantity)}
                      </span>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-500">
                      {formatQuantity(movement.quantity_before)}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end text-sm font-medium text-gray-900">
                      {formatQuantity(movement.quantity_after)}
                    </td>
                    <td className="px-6 py-4 text-sm text-gray-500 max-w-xs truncate" title={movement.reference}>
                      {movement.reference}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                      {movement.user_name ?? 'System'}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
