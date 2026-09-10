import { useState, useMemo } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Plus, Package, AlertCircle } from 'lucide-react'
import { useBatches } from '../hooks/useBatches'
import { BatchStatusBadge } from '../components/BatchStatusBadge'
import { SearchInput } from '@/components/molecules/SearchInput'
import { FilterTabs } from '@/components/molecules/FilterTabs'
import type { ExpiryStatus } from '../types'
import { usePermissions } from '@/hooks/usePermissions'
import { formatQuantity } from '@/lib/decimal'
import { textColors } from '@/lib/designTokens'
import { getQuantityDecimals } from '@/lib/quantityScale'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

type StatusFilter = 'all' | 'OK' | 'APPROACHING' | 'WARNING' | 'CRITICAL' | 'EXPIRED'
type ActiveFilter = 'all' | 'active' | 'inactive'

export function BatchListPage() {
  const { t } = useTranslation(['batches', 'common'])
  const { hasPermission } = usePermissions()
  const canCreate = hasPermission('batches.create')
  const [searchQuery, setSearchQuery] = useState('')
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all')
  const [activeFilter, setActiveFilter] = useState<ActiveFilter>('active')

  // Build query params
  const queryParams = useMemo(() => {
    const params: {
      search?: string
      expiry_status?: ExpiryStatus
      is_active?: boolean
    } = {}

    if (searchQuery) {
      params.search = searchQuery
    }

    if (statusFilter !== 'all') {
      params.expiry_status = statusFilter as ExpiryStatus
    }

    if (activeFilter !== 'all') {
      params.is_active = activeFilter === 'active'
    }

    return params
  }, [searchQuery, statusFilter, activeFilter])

  const { data, isLoading, error } = useBatches(queryParams)

  const batches = data?.data ?? []
  const total = batches.length

  // Status filter tabs
  const statusTabs = useMemo(() => {
    return [
      { value: 'all' as StatusFilter, label: t('common:filters.all'), count: total },
      { value: 'OK' as StatusFilter, label: t('batches:expiryStatus.ok') },
      { value: 'APPROACHING' as StatusFilter, label: t('batches:expiryStatus.approaching') },
      { value: 'WARNING' as StatusFilter, label: t('batches:expiryStatus.warning') },
      { value: 'CRITICAL' as StatusFilter, label: t('batches:expiryStatus.critical') },
      { value: 'EXPIRED' as StatusFilter, label: t('batches:expiryStatus.expired') },
    ]
  }, [t, total])

  // Active filter tabs
  const activeTabs = useMemo(() => {
    return [
      { value: 'all' as ActiveFilter, label: t('common:filters.all') },
      { value: 'active' as ActiveFilter, label: t('common:filters.active') },
      { value: 'inactive' as ActiveFilter, label: t('common:filters.inactive') },
    ]
  }, [t])

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>
            {t('batches:title', 'Product Batches')}
          </PageHeaderTitle>
          <p className={`${colorTokens.text.subtle}`}>
            {total} {t('batches:batchCount', { count: total })}
          </p>
        </div>
        {canCreate && (
        <Link
          to="/inventory/batches/new"
          className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium ${textColors.inverse} ${colorTokens.intent.primary.bgStrongHover} transition-colors`}
        >
          <Plus className="h-4 w-4" />
          {t('batches:actions.addBatch', 'Add Batch')}
        </Link>
        )}
      </div>

      {/* Filters */}
      <div className="space-y-4">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <FilterTabs tabs={statusTabs} value={statusFilter} onChange={setStatusFilter} />
          <SearchInput
            value={searchQuery}
            onChange={setSearchQuery}
            placeholder={t('batches:search.placeholder', 'Search batch numbers...')}
            className="w-full sm:w-72"
          />
        </div>
        <FilterTabs tabs={activeTabs} value={activeFilter} onChange={setActiveFilter} />
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="flex items-center justify-center py-12">
          <div className={`${colorTokens.text.subtle}`}>{t('common:status.loading')}</div>
        </div>
      ) : error ? (
        <div className={`rounded-lg ${colorTokens.intent.danger.bgSubtle} p-4 ${colorTokens.intent.danger.textStrong}`}>
          <div className="flex items-center gap-2">
            <AlertCircle className="h-5 w-5" />
            {t('common:errors.loadingFailed', 'Error loading data. Please try again.')}
          </div>
        </div>
      ) : batches.length === 0 ? (
        <div className={`rounded-lg border-2 border-dashed ${colorTokens.border.default} p-12 text-center`}>
          <Package className={`mx-auto h-12 w-12 ${colorTokens.text.disabled}`} />
          <h3 className={`mt-2 text-sm font-semibold ${colorTokens.text.primary}`}>
            {searchQuery ? t('common:status.noResults') : t('batches:empty.title', 'No batches found')}
          </h3>
          <p className={`mt-1 text-sm ${colorTokens.text.subtle}`}>
            {searchQuery
              ? t('common:status.tryDifferentSearch')
              : t('batches:empty.description', 'Get started by creating your first batch.')}
          </p>
          {!searchQuery && canCreate && (
            <div className="mt-6">
              <Link
                to="/inventory/batches/new"
                className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium ${textColors.inverse} ${colorTokens.intent.primary.bgStrongHover}`}
              >
                <Plus className="h-4 w-4" />
                {t('batches:actions.addBatch', 'Add Batch')}
              </Link>
            </div>
          )}
        </div>
      ) : (
        <div className={`overflow-hidden rounded-lg border ${colorTokens.border.subtle} bg-white`}>
          <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
            <thead className={`${colorTokens.surface.page}`}>
              <tr>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('batches:fields.batchNumber', 'Batch Number')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('batches:fields.product', 'Product')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('batches:fields.expiryDate', 'Expiry Date')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('batches:fields.status', 'Status')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('batches:fields.quantity', 'Quantity')}
                </th>
                <th className="relative px-6 py-3">
                  <span className="sr-only">{t('common:table.actionsColumn')}</span>
                </th>
              </tr>
            </thead>
            <tbody className={`divide-y ${colorTokens.border.divider} bg-white`}>
              {batches.map((batch) => {
                const totalQuantity = batch.available_quantity

                return (
                  <tr key={batch.id} className={`${colorTokens.variants.hoverBgGray50}`}>
                    <td className="whitespace-nowrap px-6 py-4">
                      <Link
                        to={`/inventory/batches/${batch.uuid}`}
                        className={`font-medium ${colorTokens.text.primary} ${colorTokens.variants.hoverTextBlue600}`}
                      >
                        {batch.batch_number}
                      </Link>
                      {batch.is_recalled && (
                        <span className={`ms-2 inline-flex items-center gap-1 rounded-full ${colorTokens.intent.danger.bgSoft} px-2 py-0.5 text-xs font-medium ${colorTokens.intent.danger.textStronger}`}>
                          <AlertCircle className="h-3 w-3" />
                          {t('batches:status.recalled', 'Recalled')}
                        </span>
                      )}
                    </td>
                    <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorTokens.text.primary}`}>
                      {batch.product ? (
                        <div>
                          <div className="font-medium">{batch.product.name}</div>
                          <div className={`${colorTokens.text.subtle}`}>{batch.product.sku}</div>
                        </div>
                      ) : (
                        '-'
                      )}
                    </td>
                    <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorTokens.text.subtle}`}>
                      {batch.expiry_date === null
                        ? t('batches:fields.noExpiry', 'No expiry')
                        : new Date(batch.expiry_date).toLocaleDateString()}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <BatchStatusBadge
                        status={batch.expiry_status}
                        daysUntilExpiry={batch.days_until_expiry}
                        size="sm"
                      />
                    </td>
                    <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorTokens.text.subtle}`}>
                      {formatQuantity(totalQuantity, getQuantityDecimals(batch.product))}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end text-sm">
                      <Link
                        to={`/inventory/batches/${batch.uuid}`}
                        className={`${colorTokens.intent.primary.text} ${colorTokens.variants.hoverTextBlue900}`}
                      >
                        {t('common:actions.view')}
                      </Link>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </DataTable>
        </div>
      )}
    </div>
  )
}
