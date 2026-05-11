import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { apiGet } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { fetchShiftHistory, type ShiftHistoryFilters } from '../../api/shiftHistoryApi'
import { Clock, Loader2 } from 'lucide-react'
import { cn } from '@/lib/utils'
import { usePosTenantScope } from '../../hooks/usePosTenantScope'

interface Terminal {
  id: string
  code: string
  name: string
}

export function ShiftHistoryPage() {
  const { t } = useTranslation(['pos', 'common'])
  const [filters, setFilters] = useState<ShiftHistoryFilters>({ page: 1, per_page: 20 })
  const { hasTenantScope } = usePosTenantScope()

  const { data: terminals = [] } = useQuery({
    queryKey: tenantScopedKey(['pos', 'terminals']),
    queryFn: () => apiGet<Terminal[]>('/pos/terminals'),
    enabled: hasTenantScope,
  })

  const { data, isLoading } = useQuery({
    queryKey: tenantScopedKey(['pos', 'shift-history', filters]),
    queryFn: () => fetchShiftHistory(filters),
    enabled: hasTenantScope,
  })

  const shifts = data?.data ?? []
  const meta = data?.meta

  const formatDuration = (openedAt: string, closedAt: string | null): string => {
    if (!closedAt) return '—'
    const diff = new Date(closedAt).getTime() - new Date(openedAt).getTime()
    const hours = Math.floor(diff / (1000 * 60 * 60))
    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60))
    return `${String(hours)}h ${String(minutes)}m`
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
          <Clock className="h-6 w-6 text-gray-400" />
          {t('pos:shiftHistory.title')}
        </h1>
        <p className="text-gray-500">{t('pos:shiftHistory.description')}</p>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap gap-4 rounded-lg border border-gray-200 bg-white p-4">
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            {t('pos:shiftHistory.filters.terminal')}
          </label>
          <select
            className="rounded-md border-gray-300 text-sm"
            value={filters.terminal_id ?? ''}
            onChange={(e) =>
              { setFilters((prev) => ({
                ...prev,
                terminal_id: e.target.value || undefined,
                page: 1,
              })); }
            }
          >
            <option value="">{t('pos:shiftHistory.filters.allTerminals')}</option>
            {terminals.map((term) => (
              <option key={term.id} value={term.id}>
                {term.name} ({term.code})
              </option>
            ))}
          </select>
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            {t('pos:shiftHistory.filters.status')}
          </label>
          <select
            className="rounded-md border-gray-300 text-sm"
            value={filters.status ?? ''}
            onChange={(e) =>
              { setFilters((prev) => ({
                ...prev,
                status: (e.target.value || undefined) as ShiftHistoryFilters['status'],
                page: 1,
              })); }
            }
          >
            <option value="">{t('pos:shiftHistory.filters.allStatuses')}</option>
            <option value="OPEN">{t('pos:shiftHistory.open')}</option>
            <option value="CLOSED">{t('pos:shiftHistory.closed')}</option>
          </select>
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            {t('pos:shiftHistory.filters.from')}
          </label>
          <input
            type="date"
            className="rounded-md border-gray-300 text-sm"
            value={filters.from_date ?? ''}
            onChange={(e) =>
              { setFilters((prev) => ({ ...prev, from_date: e.target.value || undefined, page: 1 })); }
            }
          />
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            {t('pos:shiftHistory.filters.to')}
          </label>
          <input
            type="date"
            className="rounded-md border-gray-300 text-sm"
            value={filters.to_date ?? ''}
            onChange={(e) =>
              { setFilters((prev) => ({ ...prev, to_date: e.target.value || undefined, page: 1 })); }
            }
          />
        </div>
      </div>

      {/* Table */}
      <div className="rounded-lg border border-gray-200 bg-white overflow-hidden">
        {isLoading ? (
          <div className="flex items-center justify-center py-12">
            <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
          </div>
        ) : shifts.length === 0 ? (
          <div className="text-center py-12">
            <Clock className="h-12 w-12 text-gray-300 mx-auto mb-3" />
            <p className="text-gray-500 font-medium">{t('pos:shiftHistory.noShifts')}</p>
            <p className="text-gray-400 text-sm mt-1">{t('pos:shiftHistory.noShiftsDescription')}</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase">{t('pos:shiftHistory.shiftNumber')}</th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase">{t('pos:shiftHistory.terminal')}</th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase">{t('pos:shiftHistory.cashier')}</th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase">{t('pos:shiftHistory.openedAt')}</th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase">{t('pos:shiftHistory.closedAt')}</th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase">{t('pos:shiftHistory.duration')}</th>
                  <th className="px-4 py-3 text-end text-xs font-medium text-gray-500 uppercase">{t('pos:shiftHistory.openingCash')}</th>
                  <th className="px-4 py-3 text-end text-xs font-medium text-gray-500 uppercase">{t('pos:shiftHistory.variance')}</th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase">{t('pos:shiftHistory.status')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {shifts.map((shift) => (
                  <tr key={shift.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 text-sm font-mono font-medium text-gray-900">
                      #{shift.shift_number}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">
                      {shift.terminal_name ?? shift.terminal_code}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">
                      {shift.cashier_name}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">
                      {new Date(shift.opened_at).toLocaleString()}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">
                      {shift.closed_at ? new Date(shift.closed_at).toLocaleString() : '—'}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">
                      {formatDuration(shift.opened_at, shift.closed_at)}
                    </td>
                    <td className="px-4 py-3 text-sm text-end font-mono text-gray-900">
                      {shift.opening_cash}
                    </td>
                    <td className="px-4 py-3 text-sm text-end font-mono">
                      {shift.variance != null ? (
                        <span className={cn(
                          parseFloat(shift.variance) === 0
                            ? 'text-green-600'
                            : parseFloat(shift.variance) > 0
                            ? 'text-blue-600'
                            : 'text-red-600'
                        )}>
                          {parseFloat(shift.variance) > 0 ? '+' : ''}{shift.variance}
                        </span>
                      ) : '—'}
                    </td>
                    <td className="px-4 py-3">
                      <span className={cn(
                        'inline-flex items-center rounded-full px-2 py-1 text-xs font-medium',
                        shift.status === 'OPEN'
                          ? 'bg-green-100 text-green-700'
                          : 'bg-gray-100 text-gray-600'
                      )}>
                        {shift.status === 'OPEN' ? t('pos:shiftHistory.open') : t('pos:shiftHistory.closed')}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {/* Pagination */}
        {meta && meta.last_page > 1 && (
          <div className="flex items-center justify-between border-t border-gray-200 px-4 py-3 bg-gray-50">
            <p className="text-sm text-gray-500">
              {t('common:pagination.showing', {
                from: (meta.current_page - 1) * meta.per_page + 1,
                to: Math.min(meta.current_page * meta.per_page, meta.total),
                total: meta.total,
              })}
            </p>
            <div className="flex gap-2">
              <button
                type="button"
                disabled={meta.current_page <= 1}
                onClick={() => { setFilters((prev) => ({ ...prev, page: (prev.page ?? 1) - 1 })); }}
                className="rounded-md border border-gray-300 bg-white px-3 py-1 text-sm disabled:opacity-50"
              >
                {t('common:pagination.previous')}
              </button>
              <button
                type="button"
                disabled={meta.current_page >= meta.last_page}
                onClick={() => { setFilters((prev) => ({ ...prev, page: (prev.page ?? 1) + 1 })); }}
                className="rounded-md border border-gray-300 bg-white px-3 py-1 text-sm disabled:opacity-50"
              >
                {t('common:pagination.next')}
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
