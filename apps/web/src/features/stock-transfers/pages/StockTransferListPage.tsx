import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Plus, Truck } from 'lucide-react'
import { Button } from '@/components/atoms/Button'
import { EmptyState } from '@/components/molecules/EmptyState'
import { textColors, borderColors, tokens } from '@/lib/designTokens'
import { useStockTransferList } from '../api/queries'
import { StockTransferStatusBadge } from '../components/StockTransferStatusBadge'
import type { StockTransferListFilters, StockTransferStatus } from '../types'

const STATUS_OPTIONS: readonly (StockTransferStatus | 'all')[] = [
  'all',
  'draft',
  'in_transit',
  'completed',
  'cancelled',
]

function isStockTransferStatusOrAll(value: string): value is StockTransferStatus | 'all' {
  return (STATUS_OPTIONS as readonly string[]).includes(value)
}

export function StockTransferListPage() {
  const { t } = useTranslation('stock-transfers')
  const [filters, setFilters] = useState<StockTransferListFilters>({
    status: 'all',
    page: 1,
    per_page: 25,
  })

  const { data, isLoading } = useStockTransferList(filters)
  const transfers = data?.data ?? []
  const total = data?.meta.total ?? 0

  const handleStatusChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    const raw = e.target.value
    if (isStockTransferStatusOrAll(raw)) {
      setFilters({ ...filters, status: raw, page: 1 })
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className={`text-2xl font-semibold ${textColors.primary}`}>{t('title')}</h1>
          <p className={textColors.tertiary}>{t('subtitle')}</p>
        </div>
        <Link to="/inventory/stock-transfers/new">
          <Button variant="primary">
            <Plus className="me-2 h-4 w-4" />
            {t('new')}
          </Button>
        </Link>
      </div>

      <div className={tokens.card.base}>
        <div className={`flex items-center justify-between border-b ${borderColors.light} px-6 py-4`}>
          <label htmlFor="status-filter" className={`text-sm font-medium ${textColors.tertiary}`}>
            {t('filters.byStatus')}
          </label>
          <select
            id="status-filter"
            value={filters.status ?? 'all'}
            onChange={handleStatusChange}
            className={`ms-3 ${tokens.select.base}`}
          >
            {STATUS_OPTIONS.map((s) => (
              <option key={s} value={s}>
                {s === 'all' ? t('filters.all') : t(`status.${s}`)}
              </option>
            ))}
          </select>
        </div>

        {isLoading ? (
          <div className={`p-10 text-center ${textColors.tertiary}`}>...</div>
        ) : transfers.length === 0 ? (
          <EmptyState
            title={t('noTransfers')}
            description={t('noTransfersHint')}
            icon={<Truck className={`mb-4 h-16 w-16 ${textColors.disabled}`} />}
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className={tokens.badge.gray}>
                <tr>
                  <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                    {t('list.transferNumber')}
                  </th>
                  <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                    {t('list.source')}
                  </th>
                  <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                    {t('list.destination')}
                  </th>
                  <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                    {t('list.status')}
                  </th>
                  <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                    {t('list.transferCost')}
                  </th>
                  <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                    {t('list.createdAt')}
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {transfers.map((tx) => (
                  <tr key={tx.id} className={tokens.card.hoverPrimary}>
                    <td className="whitespace-nowrap px-6 py-4 text-sm font-medium">
                      <Link
                        to={`/inventory/stock-transfers/${tx.id}`}
                        className={`${textColors.brand} hover:underline`}
                      >
                        {tx.transfer_number}
                      </Link>
                    </td>
                    <td className={`whitespace-nowrap px-6 py-4 text-sm ${textColors.secondary}`}>
                      {tx.source_location_name ?? '—'}
                    </td>
                    <td className={`whitespace-nowrap px-6 py-4 text-sm ${textColors.secondary}`}>
                      {tx.destination_location_name ?? '—'}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm">
                      <StockTransferStatusBadge status={tx.status} />
                    </td>
                    <td className={`whitespace-nowrap px-6 py-4 text-end text-sm ${textColors.secondary}`}>
                      {tx.transfer_cost}
                    </td>
                    <td className={`whitespace-nowrap px-6 py-4 text-sm ${textColors.tertiary}`}>
                      {tx.created_at?.slice(0, 10) ?? '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {total > 0 && (
              <div className={`border-t ${borderColors.light} px-6 py-3 text-xs ${textColors.tertiary}`}>
                {total} / {total}
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  )
}
