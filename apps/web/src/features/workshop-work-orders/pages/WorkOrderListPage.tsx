import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ClipboardList, Plus } from 'lucide-react'
import { tokens, textColors } from '@/lib/designTokens'
import { ListPageLayout } from '@/components/molecules/ListPageLayout'
import { EmptyState } from '@/components/molecules/EmptyState'
import { Select } from '@/components/atoms/Select/Select'
import { FormField } from '@/components/atoms/FormField/FormField'
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
    <div className="p-6">
      <ListPageLayout
        title={t('list.title')}
        subtitle={t('list.subtitle')}
        actions={
          <Link
            to="/workshop/work-orders/new"
            className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.md} gap-1`}
          >
            <Plus className="h-4 w-4" aria-hidden />
            {t('actions.newWorkOrder')}
          </Link>
        }
        filters={
          <FormField label={t('filters.status')} htmlFor="work-order-status-filter">
            <Select
              id="work-order-status-filter"
              value={status}
              onChange={(e) => {
                const value = e.target.value
                setStatus(value === '' || isWorkOrderStatus(value) ? value : '')
              }}
            >
              <option value="">{t('filters.anyStatus')}</option>
              {STATUS_FILTERS.map((s) => (
                <option key={s} value={s}>
                  {t(`status.${s}`)}
                </option>
              ))}
            </Select>
          </FormField>
        }
      >
        {isLoading ? (
          <div className={`${tokens.card.base} text-sm ${textColors.tertiary}`}>
            {t('list.loading')}
          </div>
        ) : isError ? (
          <div role="alert" className={`${tokens.alert.base} ${tokens.alert.error}`}>
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
          <EmptyState
            icon={<ClipboardList className={`mx-auto mb-4 h-10 w-10 ${textColors.disabled}`} aria-hidden />}
            title={t('list.empty.title')}
            description={t('list.empty.body')}
          />
        )}
      </ListPageLayout>
    </div>
  )
}
