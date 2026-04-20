import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ClipboardList, Plus } from 'lucide-react'
import { borderColors, textColors } from '@/lib/designTokens'
import { useWorkOrders } from '../hooks/useWorkOrders'
import { WorkOrderRow } from '../components/WorkOrderRow'
import type { WorkOrderListFilters, WorkOrderStatus } from '../types'

const STATUS_FILTERS: WorkOrderStatus[] = [
  'received',
  'diagnosed',
  'quoted',
  'approved',
  'in_progress',
  'paused',
  'waiting_parts',
  'completed',
  'invoiced',
  'closed',
  'cancelled',
]

/** Type guard matching a WorkOrderStatus without a type assertion. */
function isWorkOrderStatus(value: string): value is WorkOrderStatus {
  return (STATUS_FILTERS as string[]).includes(value)
}

export function WorkOrderListPage() {
  const { t } = useTranslation('workshop-work-orders')
  const [status, setStatus] = useState<WorkOrderStatus | ''>('')

  const filters = useMemo<WorkOrderListFilters>(() => {
    const f: WorkOrderListFilters = { per_page: 25 }
    if (status !== '') f.status = status
    return f
  }, [status])

  const { data, isLoading, isError, error } = useWorkOrders(filters)

  return (
    <div className="space-y-6 p-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className={`flex items-center gap-2 text-2xl font-semibold ${textColors.primary}`}>
            <ClipboardList className={`h-6 w-6 ${textColors.tertiary}`} aria-hidden />
            {t('list.title')}
          </h1>
          <p className={`mt-1 text-sm ${textColors.tertiary}`}>{t('list.subtitle')}</p>
        </div>
        <Link
          to="/workshop/work-orders/new"
          className="inline-flex items-center gap-1 rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800"
        >
          <Plus className="h-4 w-4" aria-hidden />
          {t('actions.newWorkOrder')}
        </Link>
      </div>

      <div className={`flex flex-wrap items-center gap-4 rounded-lg border bg-white p-4 ${borderColors.light}`}>
        <label className={`inline-flex items-center gap-2 text-sm ${textColors.secondary}`}>
          <span>{t('filters.status')}</span>
          <select
            value={status}
            onChange={(e) => {
              const value = e.target.value
              setStatus(value === '' || isWorkOrderStatus(value) ? value : '')
            }}
            className={`rounded-md border px-2 py-1 text-sm focus:outline-none focus:ring-1 ${borderColors.default}`}
          >
            <option value="">{t('filters.anyStatus')}</option>
            {STATUS_FILTERS.map((s) => (
              <option key={s} value={s}>
                {t(`status.${s}`)}
              </option>
            ))}
          </select>
        </label>
      </div>

      {isLoading ? (
        <div className={`rounded-lg border bg-white p-6 text-sm ${borderColors.light} ${textColors.tertiary}`}>
          {t('list.loading')}
        </div>
      ) : isError ? (
        <div
          role="alert"
          className="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700"
        >
          {t('list.errorLoading')}: {error instanceof Error ? error.message : String(error)}
        </div>
      ) : data && data.data.length > 0 ? (
        <div className="space-y-2">
          {data.data.map((wo) => (
            <WorkOrderRow key={wo.id} workOrder={wo} />
          ))}
          <div className={`text-xs ${textColors.tertiary}`}>
            {t('list.paginationSummary', {
              shown: data.data.length,
              total: data.meta.total,
            })}
          </div>
        </div>
      ) : (
        <div className={`rounded-lg border border-dashed bg-white p-12 text-center ${borderColors.default}`}>
          <ClipboardList className={`mx-auto h-10 w-10 ${textColors.disabled}`} aria-hidden />
          <h3 className={`mt-3 text-sm font-semibold ${textColors.primary}`}>
            {t('list.empty.title')}
          </h3>
          <p className={`mt-1 text-sm ${textColors.tertiary}`}>{t('list.empty.body')}</p>
        </div>
      )}
    </div>
  )
}
