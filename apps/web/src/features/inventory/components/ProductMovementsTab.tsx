import { useState, useMemo } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import {
  ArrowDownCircle,
  ArrowUpCircle,
  RefreshCw,
  ArrowRightLeft,
  Package,
  Filter,
} from 'lucide-react'
import { api } from '../../../lib/api'
import { formatQuantity } from '../../../lib/format'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import { LocationSelectorMulti } from '../../locations/components/LocationSelectorMulti'

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

interface ProductMovementsTabProps {
  productId: string
}

const movementTypeConfig: Record<
  string,
  { label: string; color: string; icon: typeof ArrowDownCircle }
> = {
  receipt: {
    label: 'Receipt',
    color: 'bg-green-100 text-green-800',
    icon: ArrowDownCircle,
  },
  issue: {
    label: 'Issue',
    color: 'bg-red-100 text-red-800',
    icon: ArrowUpCircle,
  },
  adjustment: {
    label: 'Adjustment',
    color: 'bg-blue-100 text-blue-800',
    icon: RefreshCw,
  },
  transfer_in: {
    label: 'Transfer In',
    color: 'bg-purple-100 text-purple-800',
    icon: ArrowRightLeft,
  },
  transfer_out: {
    label: 'Transfer Out',
    color: 'bg-orange-100 text-orange-800',
    icon: ArrowRightLeft,
  },
  opening: {
    label: 'Opening',
    color: 'bg-gray-100 text-gray-800',
    icon: Package,
  },
}

/**
 * Parses a movement reference to construct a link to the source document.
 * Returns null if the reference doesn't match a known document pattern.
 */
function getDocumentLink(reference: string): string | null {
  // Common document prefixes and their route mappings
  const documentPatterns: Array<{ prefix: string; basePath: string }> = [
    { prefix: 'PO-', basePath: '/purchases/orders' },
    { prefix: 'INV-', basePath: '/sales/invoices' },
    { prefix: 'SO-', basePath: '/sales/orders' },
    { prefix: 'QT-', basePath: '/sales/quotes' },
    { prefix: 'CN-', basePath: '/sales/credit-notes' },
    { prefix: 'DN-', basePath: '/sales/delivery-notes' },
  ]

  for (const { prefix, basePath } of documentPatterns) {
    if (reference.startsWith(prefix)) {
      // Return the path with document number for lookup
      // Note: The actual document detail pages typically use document ID, not number
      // For now, we'll link to the documents list with a search filter
      return `${basePath}?search=${encodeURIComponent(reference)}`
    }
  }

  return null
}

export function ProductMovementsTab({ productId }: ProductMovementsTabProps) {
  const { t } = useTranslation(['inventory', 'common'])
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const [selectedLocationIds, setSelectedLocationIds] = useState<string[]>([])
  const [showLocationFilter, setShowLocationFilter] = useState(false)

  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['product-movements', productId, selectedLocationIds]),
    queryFn: async () => {
      const params = new URLSearchParams()
      params.append('product_id', productId)

      // If locations are selected, we need to fetch for each and merge
      // OR the API supports multiple location_id params
      // For simplicity, if multiple locations selected, we won't filter by location
      // (show all and filter client-side)
      if (selectedLocationIds.length === 1) {
        params.append('location_id', selectedLocationIds[0])
      }

      const response = await api.get<StockMovementsResponse>(
        `/stock-movements?${params.toString()}`
      )
      return response.data
    },
    enabled: !!productId && !!tenantId && !!companyId,
  })

  // Client-side filtering for multiple location selection
  const movements = useMemo(() => {
    const items = data?.data ?? []
    if (selectedLocationIds.length > 1) {
      return items.filter((m) => selectedLocationIds.includes(m.location_id))
    }
    return items
  }, [data?.data, selectedLocationIds])

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleString(undefined, {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    })
  }

  const getMovementConfig = (type: string) => {
    return (
      movementTypeConfig[type] ?? {
        label: type,
        color: 'bg-gray-100 text-gray-800',
        icon: Package,
      }
    )
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className="text-gray-500">{t('common:status.loading')}</div>
      </div>
    )
  }

  if (error) {
    return (
      <div className="rounded-lg bg-red-50 p-4 text-red-700">
        {t('common:errors.loadingFailed')}
      </div>
    )
  }

  return (
    <div className="space-y-4">
      {/* Header with filter */}
      <div className="flex items-center justify-between">
        <div>
          <h3 className="text-lg font-semibold text-gray-900">
            {t('products.movementsTab.title')}
          </h3>
          <p className="text-sm text-gray-500">
            {t('products.movementsTab.subtitle', { count: movements.length })}
          </p>
        </div>
        <button
          type="button"
          onClick={() => {
            setShowLocationFilter(!showLocationFilter)
          }}
          className={`inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition-colors ${
            selectedLocationIds.length > 0
              ? 'border-blue-300 bg-blue-50 text-blue-700'
              : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
          }`}
        >
          <Filter className="h-4 w-4" />
          {selectedLocationIds.length > 0
            ? t('products.movementsTab.filterByLocation') +
              ` (${String(selectedLocationIds.length)})`
            : t('products.movementsTab.filterByLocation')}
        </button>
      </div>

      {/* Location filter panel */}
      {showLocationFilter && (
        <div className="rounded-lg border border-gray-200 bg-white p-4">
          <LocationSelectorMulti
            value={selectedLocationIds}
            onChange={setSelectedLocationIds}
            label={t('products.movementsTab.filterByLocation')}
            {...(selectedLocationIds.length === 0 && {
              helperText: t('products.movementsTab.allLocations'),
            })}
          />
        </div>
      )}

      {/* Content */}
      {movements.length === 0 ? (
        <div className="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
          <RefreshCw className="mx-auto h-12 w-12 text-gray-400" />
          <h3 className="mt-2 text-sm font-semibold text-gray-900">
            {t('products.movementsTab.empty.title')}
          </h3>
          <p className="mt-1 text-sm text-gray-500">
            {t('products.movementsTab.empty.description')}
          </p>
        </div>
      ) : (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('products.movementsTab.columns.date')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('products.movementsTab.columns.type')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('products.movementsTab.columns.location')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('products.movementsTab.columns.quantity')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('products.movementsTab.columns.before')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('products.movementsTab.columns.after')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('products.movementsTab.columns.reference')}
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {movements.map((movement) => {
                const config = getMovementConfig(movement.movement_type)
                const Icon = config.icon
                const qty = parseFloat(movement.quantity)
                const isPositive = qty >= 0
                const documentLink = getDocumentLink(movement.reference)

                return (
                  <tr key={movement.id} className="hover:bg-gray-50">
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                      {formatDate(movement.created_at)}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <div className="flex items-center gap-2">
                        <Icon
                          className={`h-4 w-4 ${isPositive ? 'text-green-600' : 'text-red-600'}`}
                        />
                        <span
                          className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${config.color}`}
                        >
                          {config.label}
                        </span>
                      </div>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                      {movement.location_name}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end">
                      <span
                        className={`text-sm font-semibold ${isPositive ? 'text-green-600' : 'text-red-600'}`}
                      >
                        {isPositive ? '+' : ''}
                        {formatQuantity(movement.quantity)}
                      </span>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-500">
                      {formatQuantity(movement.quantity_before)}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end text-sm font-medium text-gray-900">
                      {formatQuantity(movement.quantity_after)}
                    </td>
                    <td
                      className="max-w-xs truncate px-6 py-4 text-sm text-gray-500"
                      title={movement.reference}
                    >
                      {documentLink ? (
                        <Link
                          to={documentLink}
                          className="text-blue-600 hover:text-blue-800 hover:underline"
                        >
                          {movement.reference}
                        </Link>
                      ) : (
                        movement.reference
                      )}
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
