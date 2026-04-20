import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Wrench } from 'lucide-react'
import { usePermissions } from '@/hooks/usePermissions'
import { borderColors, textColors } from '@/lib/designTokens'
import { StatusPill } from '../components/StatusPill'
import { MileageLabel } from '../components/MileageLabel'
import { ApprovalBadge } from '../components/ApprovalBadge'
import { TransitionBar } from '../components/TransitionBar'
import { TotalsPanel } from '../components/TotalsPanel'
import { WorkOrderLineRow } from '../components/WorkOrderLineRow'
import {
  useApproveWorkOrder,
  useCancelWorkOrder,
  useCompleteWorkOrder,
  useTransitionWorkOrder,
  useWorkOrder,
} from '../hooks/useWorkOrders'
import type { WorkOrderStatus } from '../types'

export function WorkOrderDetailPage() {
  const { id } = useParams<{ id: string }>()
  const { t } = useTranslation('workshop-work-orders')
  const { data: wo, isLoading, isError, error } = useWorkOrder(id)
  const transitionMutation = useTransitionWorkOrder(id ?? '')
  const approveMutation = useApproveWorkOrder(id ?? '')
  const cancelMutation = useCancelWorkOrder(id ?? '')
  const completeMutation = useCompleteWorkOrder(id ?? '')

  const { hasPermission } = usePermissions()
  const canTransition = hasPermission('work-orders.transition')
  const canApprove = hasPermission('work-orders.approve')
  const canCancel = hasPermission('work-orders.cancel')
  const canComplete = hasPermission('work-orders.complete')

  const isPending =
    transitionMutation.isPending ||
    approveMutation.isPending ||
    cancelMutation.isPending ||
    completeMutation.isPending

  if (isLoading) {
    return (
      <div className="p-6 text-sm text-slate-500">
        {t('detail.loading')}
      </div>
    )
  }

  if (isError || !wo) {
    return (
      <div className="p-6">
        <div
          role="alert"
          className="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700"
        >
          {t('detail.notFound')}
          {error !== null && error !== undefined && (
            <span className="ml-2 text-xs">
              ({error instanceof Error ? error.message : String(error)})
            </span>
          )}
        </div>
      </div>
    )
  }

  const redactFinancials = wo.estimated_totals === null && wo.actual_totals === null
  const handleTransition = (to: WorkOrderStatus) => {
    transitionMutation.mutate({ to_status: to })
  }
  const handleApprove = () => {
    approveMutation.mutate({ approval_method: 'in_person' })
  }
  const handleCancel = () => {
    cancelMutation.mutate({ reason_code: 'customer_declined' })
  }
  const handleComplete = () => {
    completeMutation.mutate({})
  }

  return (
    <div className="space-y-6 p-6">
      <Link
        to="/workshop/work-orders"
        className={`inline-flex items-center gap-1 text-sm ${textColors.tertiary} hover:text-slate-900`}
      >
        <ArrowLeft className="h-4 w-4" aria-hidden />
        {t('detail.backToList')}
      </Link>

      <div className={`rounded-lg border bg-white p-6 ${borderColors.light}`}>
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <div className="flex items-center gap-2">
              <Wrench className={`h-5 w-5 ${textColors.tertiary}`} aria-hidden />
              <h1 className={`font-mono text-2xl font-semibold ${textColors.primary}`}>
                {wo.work_order_number}
              </h1>
              <StatusPill status={wo.status} />
            </div>
            <div className={`mt-2 text-sm ${textColors.secondary}`}>
              {wo.customer_display_name} · {wo.vehicle_display_name}
            </div>
            <div className="mt-1 flex flex-wrap items-center gap-3">
              <MileageLabel mileage={wo.mileage_at_intake} />
              {wo.approval_method !== null && (
                <ApprovalBadge method={wo.approval_method} capturedAt={wo.approval_captured_at} />
              )}
            </div>
          </div>
          <TransitionBar
            current={wo.status}
            canTransition={canTransition}
            canApprove={canApprove}
            canCancel={canCancel}
            canComplete={canComplete}
            onTransition={handleTransition}
            onApprove={handleApprove}
            onCancel={handleCancel}
            onComplete={handleComplete}
            isPending={isPending}
          />
        </div>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div className={`lg:col-span-2 rounded-lg border bg-white ${borderColors.light}`}>
          <div className={`border-b px-4 py-2 text-sm font-semibold ${borderColors.light} ${textColors.primary}`}>
            {t('detail.lines')}
          </div>
          {wo.lines.length === 0 ? (
            <div className={`p-4 text-sm ${textColors.tertiary}`}>{t('detail.noLines')}</div>
          ) : (
            wo.lines.map((line) => (
              <WorkOrderLineRow
                key={line.id}
                line={line}
                currency={wo.currency}
                redactFinancials={redactFinancials}
              />
            ))
          )}
        </div>
        <div className="space-y-4">
          <TotalsPanel
            estimated={wo.estimated_totals}
            actual={wo.actual_totals}
            currency={wo.currency}
          />
          <AssignmentsPanel assignments={wo.assignments} />
        </div>
      </div>

      {wo.status_history.length > 0 && (
        <div className={`rounded-lg border bg-white ${borderColors.light}`}>
          <div className={`border-b px-4 py-2 text-sm font-semibold ${borderColors.light} ${textColors.primary}`}>
            {t('detail.history')}
          </div>
          <ul className="divide-y divide-slate-100">
            {wo.status_history.map((entry) => (
              <li key={entry.id} className={`px-4 py-2 text-xs ${textColors.secondary}`}>
                <span className="font-medium">
                  {entry.from_status ?? '—'} → {entry.to_status}
                </span>
                <span className={`ml-2 ${textColors.tertiary}`}>
                  {new Date(entry.triggered_at).toLocaleString()}
                </span>
                {entry.reason_code !== null && (
                  <span className={`ml-2 ${textColors.tertiary}`}>({entry.reason_code})</span>
                )}
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}

function AssignmentsPanel({ assignments }: { assignments: import('../types').WorkOrderAssignment[] }) {
  const { t } = useTranslation('workshop-work-orders')
  const active = assignments.filter((a) => a.unassigned_at === null)

  return (
    <div className={`rounded-lg border bg-white p-4 ${borderColors.light}`}>
      <div className={`mb-2 text-sm font-semibold ${textColors.primary}`}>
        {t('detail.assignments')}
      </div>
      {active.length === 0 ? (
        <div className={`text-sm ${textColors.tertiary}`}>{t('detail.noAssignments')}</div>
      ) : (
        <ul className="space-y-1">
          {active.map((assignment) => (
            <li key={assignment.id} className={`text-sm ${textColors.secondary}`}>
              {assignment.technician_display_name ?? assignment.technician_profile_id}
              {assignment.is_lead && (
                <span className="ml-2 inline-flex items-center rounded-md bg-sky-50 px-1.5 py-0.5 text-[10px] font-medium text-sky-700 ring-1 ring-inset ring-sky-600/20">
                  {t('detail.lead')}
                </span>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
