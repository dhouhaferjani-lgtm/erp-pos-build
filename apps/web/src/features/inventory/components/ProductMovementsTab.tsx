import { useState } from 'react'
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
import { formatQuantity } from '../../../lib/decimal'
import { getQuantityDecimals } from '../../../lib/quantityScale'
import { locationScopedKey } from '../../../lib/locationScopedKey'
import { bccomp } from '@/lib/decimal'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import { LocationSelectorMulti } from '../../locations/components/LocationSelectorMulti'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { StatusBadge, type StatusTone } from '@/components/atoms/StatusBadge'
import { Button } from '@/components/atoms/Button'
import { EntityLink } from '@/components/molecules/EntityLink'
import { OffsetPagination } from '@/components/ui/OffsetPagination'
import { documentRouteTypeFromSource } from '@/lib/entityRoutes'
import { DataTable } from '@/components/molecules/DataTable/DataTable'
import { useViewScope } from '../../locations/hooks/useViewScope'
import type { OffsetPaginationMeta } from '@/types/pagination'

interface StockMovement {
  id: string
  product_id: string
  product_name: string
  location_id: string
  location_name: string
  movement_type: string
  quantity: string
  quantity_decimals: number
  quantity_before: string
  quantity_after: string
  reference: string
  source_document_id: string | null
  source_document_type: string | null
  notes: string | null
  user_id: string
  user_name: string | null
  created_at: string
}

interface StockMovementsResponse {
  data: StockMovement[]
  meta?: OffsetPaginationMeta
}

interface ProductMovementsTabProps {
  productId: string
}

const movementTypeConfig: Record<
  string,
  { label: string; tone: StatusTone; icon: typeof ArrowDownCircle }
> = {
  receipt: {
    label: 'Receipt',
    tone: 'success',
    icon: ArrowDownCircle,
  },
  issue: {
    label: 'Issue',
    tone: 'danger',
    icon: ArrowUpCircle,
  },
  adjustment: {
    label: 'Adjustment',
    tone: 'info',
    icon: RefreshCw,
  },
  transfer_in: {
    label: 'Transfer In',
    tone: 'info',
    icon: ArrowRightLeft,
  },
  transfer_out: {
    label: 'Transfer Out',
    tone: 'warning',
    icon: ArrowRightLeft,
  },
  opening: {
    label: 'Opening',
    tone: 'neutral',
    icon: Package,
  },
}

export function ProductMovementsTab({ productId }: ProductMovementsTabProps) {
  const { t } = useTranslation(['inventory', 'common'])
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const [selectedLocationIds, setSelectedLocationIds] = useState<string[]>([])
  const [showLocationFilter, setShowLocationFilter] = useState(false)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)
  const { scope } = useViewScope()

  const { data, isLoading, error } = useQuery({
    queryKey: locationScopedKey(['product-movements', productId, page, perPage, selectedLocationIds.join(',')], scope),
    queryFn: async () => {
      const params = new URLSearchParams()
      params.append('product_id', productId)
      params.append('page', String(page))
      params.append('per_page', String(perPage))

      selectedLocationIds.forEach((locationId) => params.append('location_ids[]', locationId))

      const response = await api.get<StockMovementsResponse>(
        `/stock-movements?${params.toString()}`
      )
      return response.data
    },
    enabled: !!productId && !!tenantId && !!companyId,
  })

  const movements = data?.data ?? []

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
        tone: 'neutral' as StatusTone,
        icon: Package,
      }
    )
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className={textColors.tertiary}>{t('common:status.loading')}</div>
      </div>
    )
  }

  if (error) {
    return (
      <div className={`${tokens.alert.base} ${tokens.alert.error}`}>
        {t('common:errors.loadingFailed')}
      </div>
    )
  }

  return (
    <div className="space-y-4">
      {/* Header with filter */}
      <div className="flex items-center justify-between">
        <div>
          <h3 className={`text-lg font-semibold ${textColors.primary}`}>
            {t('products.movementsTab.title')}
          </h3>
          <p className={`text-sm ${textColors.tertiary}`}>
            {t('products.movementsTab.subtitle', { count: movements.length })}
          </p>
        </div>
        <Button
          type="button"
          variant="secondary"
          size="sm"
          onClick={() => {
            setShowLocationFilter(!showLocationFilter)
          }}
          className={
            selectedLocationIds.length > 0
              ? `gap-2 ${tokens.alert.info} ${borderColors.primary}`
              : 'gap-2'
          }
        >
          <Filter className="h-4 w-4" />
          {selectedLocationIds.length > 0
            ? t('products.movementsTab.filterByLocation') +
              ` (${String(selectedLocationIds.length)})`
            : t('products.movementsTab.filterByLocation')}
        </Button>
      </div>

      {/* Location filter panel */}
      {showLocationFilter && (
        <div className={`rounded-lg border ${borderColors.light} bg-white p-4`}>
          <LocationSelectorMulti
            value={selectedLocationIds}
            onChange={(ids) => {
              setSelectedLocationIds(ids)
              setPage(1)
            }}
            label={t('products.movementsTab.filterByLocation')}
            {...(selectedLocationIds.length === 0 && {
              helperText: t('products.movementsTab.allLocations'),
            })}
          />
        </div>
      )}

      {/* Content */}
      {movements.length === 0 ? (
        <div className={`rounded-lg border-2 border-dashed ${borderColors.default} p-12 text-center`}>
          <RefreshCw className={`mx-auto h-12 w-12 ${textColors.disabled}`} />
          <h3 className={`mt-2 text-sm font-semibold ${textColors.primary}`}>
            {t('products.movementsTab.empty.title')}
          </h3>
          <p className={`mt-1 text-sm ${textColors.tertiary}`}>
            {t('products.movementsTab.empty.description')}
          </p>
        </div>
      ) : (
        <div className={`overflow-hidden rounded-lg border ${borderColors.light} bg-white`}>
          <DataTable className={`min-w-full divide-y ${borderColors.divideDefault}`}>
            <thead className={tokens.table.header}>
              <tr>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                  {t('products.movementsTab.columns.date')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                  {t('products.movementsTab.columns.type')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                  {t('products.movementsTab.columns.location')}
                </th>
                <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                  {t('products.movementsTab.columns.quantity')}
                </th>
                <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                  {t('products.movementsTab.columns.before')}
                </th>
                <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                  {t('products.movementsTab.columns.after')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                  {t('products.movementsTab.columns.reference')}
                </th>
              </tr>
            </thead>
            <tbody className={`divide-y ${borderColors.divideDefault} bg-white`}>
              {movements.map((movement) => {
                const config = getMovementConfig(movement.movement_type)
                const Icon = config.icon
                const isPositive = bccomp(movement.quantity, '0') >= 0
                const documentType = documentRouteTypeFromSource(movement.source_document_type)

                return (
                  <tr key={movement.id} className={tokens.table.rowHover}>
                    <td className={`whitespace-nowrap px-6 py-4 text-sm ${textColors.tertiary}`}>
                      {formatDate(movement.created_at)}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <div className="flex items-center gap-2">
                        <Icon
                          className={`h-4 w-4 ${isPositive ? textColors.success : textColors.error}`}
                        />
                        <StatusBadge tone={config.tone}>
                          {config.label}
                        </StatusBadge>
                      </div>
                    </td>
                    <td className={`whitespace-nowrap px-6 py-4 text-sm ${textColors.tertiary}`}>
                      {movement.location_name}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end">
                      <span
                        className={`text-sm font-semibold tabular-nums ${isPositive ? textColors.success : textColors.error}`}
                      >
                        {isPositive ? '+' : ''}
                        {formatQuantity(movement.quantity, getQuantityDecimals(movement))}
                      </span>
                    </td>
                    <td className={`whitespace-nowrap px-6 py-4 text-end text-sm tabular-nums ${textColors.tertiary}`}>
                      {formatQuantity(movement.quantity_before, getQuantityDecimals(movement))}
                    </td>
                    <td className={`whitespace-nowrap px-6 py-4 text-end text-sm font-medium tabular-nums ${textColors.primary}`}>
                      {formatQuantity(movement.quantity_after, getQuantityDecimals(movement))}
                    </td>
                    <td
                      className={`max-w-xs truncate px-6 py-4 text-sm ${textColors.tertiary}`}
                      title={movement.reference}
                    >
                      {documentType ? (
                        <EntityLink
                          type="document"
                          id={movement.source_document_id}
                          documentType={documentType}
                          label={movement.reference}
                        />
                      ) : (
                        movement.reference
                      )}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </DataTable>
          {data?.meta && data.meta.last_page > 1 && (
            <OffsetPagination
              currentPage={data.meta.current_page}
              lastPage={data.meta.last_page}
              total={data.meta.total}
              perPage={data.meta.per_page}
              from={data.meta.from ?? null}
              to={data.meta.to ?? null}
              onPageChange={setPage}
              onPerPageChange={(nextPerPage) => {
                setPerPage(nextPerPage)
                setPage(1)
              }}
            />
          )}
        </div>
      )}
    </div>
  )
}
