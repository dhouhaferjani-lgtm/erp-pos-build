import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { borderColors, textColors } from '@/lib/designTokens'
import { StatusPill } from './StatusPill'
import type { WorkOrderListItem } from '../types'

interface WorkOrderRowProps {
  workOrder: WorkOrderListItem
}

/**
 * Row in the WorkOrder list table. Uses CSS-grid layout for readable columns
 * across widths without an external table dep. Financial column shows `—`
 * when redacted for the current caller (backend returned null).
 */
export function WorkOrderRow({ workOrder }: WorkOrderRowProps) {
  const { t } = useTranslation('workshop-work-orders')

  return (
    <Link
      to={`/workshop/work-orders/${workOrder.id}`}
      className={`grid grid-cols-12 items-center gap-3 rounded-lg border bg-white px-4 py-3 shadow-sm transition-colors hover:bg-slate-50 ${borderColors.light}`}
    >
      <div className="col-span-2 font-mono text-xs font-semibold text-slate-900">
        {workOrder.work_order_number}
      </div>
      <div className="col-span-2">
        <StatusPill status={workOrder.status} />
      </div>
      <div className="col-span-3">
        <div className={`text-sm font-medium ${textColors.primary}`}>
          {workOrder.customer_display_name}
        </div>
        <div className={`text-xs ${textColors.tertiary}`}>{workOrder.vehicle_display_name}</div>
      </div>
      <div className={`col-span-2 text-xs ${textColors.tertiary}`}>
        {workOrder.primary_technician_display_name ?? t('labels.unassigned')}
      </div>
      <div className={`col-span-2 text-xs ${textColors.tertiary}`}>
        {workOrder.scheduled_start_at
          ? new Date(workOrder.scheduled_start_at).toLocaleString()
          : t('labels.notSet')}
      </div>
      <div className="col-span-1 text-right text-sm font-semibold text-slate-900">
        {workOrder.estimated_grand_total === null
          ? t('labels.notSet')
          : `${workOrder.estimated_grand_total} ${workOrder.currency}`}
      </div>
    </Link>
  )
}
