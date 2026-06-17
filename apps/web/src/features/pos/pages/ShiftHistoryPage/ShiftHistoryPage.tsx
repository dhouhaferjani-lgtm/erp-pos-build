import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { apiGet } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { fetchShiftHistory, type ShiftHistoryFilters } from '../../api/shiftHistoryApi'
import { Clock, Loader2 } from 'lucide-react'
import { cn } from '@/lib/utils'
import { tokens, textColors, colors, borderColors } from '@/lib/designTokens'
import { StatusBadge, statusTone } from '@/components/atoms/StatusBadge'
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
        <h1 className={cn('text-2xl font-bold flex items-center gap-2', textColors.primary)}>
          <Clock className={cn('h-6 w-6', textColors.disabled)} />
          {t('pos:shiftHistory.title')}
        </h1>
        <p className={textColors.disabled}>{t('pos:shiftHistory.description')}</p>
      </div>

      {/* Filters */}
      <div className={cn('flex flex-wrap gap-4 rounded-lg border p-4', borderColors.light, colors.white)}>
        <div>
          <label className={cn('block text-sm font-medium mb-1', textColors.secondary)}>
            {t('pos:shiftHistory.filters.terminal')}
          </label>
          <select
            className={cn('rounded-md text-sm', borderColors.default)}
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
          <label className={cn('block text-sm font-medium mb-1', textColors.secondary)}>
            {t('pos:shiftHistory.filters.status')}
          </label>
          <select
            className={cn('rounded-md text-sm', borderColors.default)}
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
          <label className={cn('block text-sm font-medium mb-1', textColors.secondary)}>
            {t('pos:shiftHistory.filters.from')}
          </label>
          <input
            type="date"
            className={cn('rounded-md text-sm', borderColors.default)}
            value={filters.from_date ?? ''}
            onChange={(e) =>
              { setFilters((prev) => ({ ...prev, from_date: e.target.value || undefined, page: 1 })); }
            }
          />
        </div>

        <div>
          <label className={cn('block text-sm font-medium mb-1', textColors.secondary)}>
            {t('pos:shiftHistory.filters.to')}
          </label>
          <input
            type="date"
            className={cn('rounded-md text-sm', borderColors.default)}
            value={filters.to_date ?? ''}
            onChange={(e) =>
              { setFilters((prev) => ({ ...prev, to_date: e.target.value || undefined, page: 1 })); }
            }
          />
        </div>
      </div>

      {/* Table */}
      <div className={cn('rounded-lg border overflow-hidden', borderColors.light, colors.white)}>
        {isLoading ? (
          <div className="flex items-center justify-center py-12">
            <Loader2 className={cn('h-8 w-8 animate-spin', textColors.brand)} />
          </div>
        ) : shifts.length === 0 ? (
          <div className="text-center py-12">
            <Clock className={cn('h-12 w-12 mx-auto mb-3', textColors.disabled)} />
            <p className={cn('font-medium', textColors.disabled)}>{t('pos:shiftHistory.noShifts')}</p>
            <p className={cn('text-sm mt-1', textColors.disabled)}>{t('pos:shiftHistory.noShiftsDescription')}</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className={cn('min-w-full divide-y', borderColors.divideDefault)}>
              <thead className={tokens.table.header}>
                <tr>
                  <th className={cn('px-4 py-3 text-start text-xs font-medium uppercase', textColors.disabled)}>{t('pos:shiftHistory.shiftNumber')}</th>
                  <th className={cn('px-4 py-3 text-start text-xs font-medium uppercase', textColors.disabled)}>{t('pos:shiftHistory.terminal')}</th>
                  <th className={cn('px-4 py-3 text-start text-xs font-medium uppercase', textColors.disabled)}>{t('pos:shiftHistory.cashier')}</th>
                  <th className={cn('px-4 py-3 text-start text-xs font-medium uppercase', textColors.disabled)}>{t('pos:shiftHistory.openedAt')}</th>
                  <th className={cn('px-4 py-3 text-start text-xs font-medium uppercase', textColors.disabled)}>{t('pos:shiftHistory.closedAt')}</th>
                  <th className={cn('px-4 py-3 text-start text-xs font-medium uppercase', textColors.disabled)}>{t('pos:shiftHistory.duration')}</th>
                  <th className={cn('px-4 py-3 text-end text-xs font-medium uppercase', textColors.disabled)}>{t('pos:shiftHistory.openingCash')}</th>
                  <th className={cn('px-4 py-3 text-end text-xs font-medium uppercase', textColors.disabled)}>{t('pos:shiftHistory.variance')}</th>
                  <th className={cn('px-4 py-3 text-start text-xs font-medium uppercase', textColors.disabled)}>{t('pos:shiftHistory.status')}</th>
                </tr>
              </thead>
              <tbody className={cn('divide-y', borderColors.divideDefault)}>
                {shifts.map((shift) => (
                  <tr key={shift.id} className={tokens.table.rowHover}>
                    <td className={cn('px-4 py-3 text-sm font-mono font-medium tabular-nums', textColors.primary)}>
                      #{shift.shift_number}
                    </td>
                    <td className={cn('px-4 py-3 text-sm', textColors.tertiary)}>
                      {shift.terminal_name ?? shift.terminal_code}
                    </td>
                    <td className={cn('px-4 py-3 text-sm', textColors.tertiary)}>
                      {shift.cashier_name}
                    </td>
                    <td className={cn('px-4 py-3 text-sm', textColors.tertiary)}>
                      {new Date(shift.opened_at).toLocaleString()}
                    </td>
                    <td className={cn('px-4 py-3 text-sm', textColors.tertiary)}>
                      {shift.closed_at ? new Date(shift.closed_at).toLocaleString() : '—'}
                    </td>
                    <td className={cn('px-4 py-3 text-sm', textColors.tertiary)}>
                      {formatDuration(shift.opened_at, shift.closed_at)}
                    </td>
                    <td className={cn('px-4 py-3 text-sm text-end font-mono tabular-nums', textColors.primary)}>
                      {shift.opening_cash}
                    </td>
                    <td className="px-4 py-3 text-sm text-end font-mono tabular-nums">
                      {shift.variance != null ? (
                        <span className={cn(
                          parseFloat(shift.variance) === 0
                            ? textColors.success
                            : parseFloat(shift.variance) > 0
                            ? textColors.brand
                            : textColors.error
                        )}>
                          {parseFloat(shift.variance) > 0 ? '+' : ''}{shift.variance}
                        </span>
                      ) : '—'}
                    </td>
                    <td className="px-4 py-3">
                      <StatusBadge
                        tone={statusTone(shift.status, { open: 'success', closed: 'neutral' })}
                      >
                        {shift.status === 'OPEN' ? t('pos:shiftHistory.open') : t('pos:shiftHistory.closed')}
                      </StatusBadge>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {/* Pagination */}
        {meta && meta.last_page > 1 && (
          <div className={cn('flex items-center justify-between border-t px-4 py-3', borderColors.light, colors.neutral[50])}>
            <p className={cn('text-sm', textColors.disabled)}>
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
                className={cn('rounded-md border px-3 py-1 text-sm disabled:opacity-50', borderColors.default, colors.white)}
              >
                {t('common:pagination.previous')}
              </button>
              <button
                type="button"
                disabled={meta.current_page >= meta.last_page}
                onClick={() => { setFilters((prev) => ({ ...prev, page: (prev.page ?? 1) + 1 })); }}
                className={cn('rounded-md border px-3 py-1 text-sm disabled:opacity-50', borderColors.default, colors.white)}
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
