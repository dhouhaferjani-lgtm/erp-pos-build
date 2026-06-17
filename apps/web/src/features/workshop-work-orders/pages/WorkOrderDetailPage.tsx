import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Wrench } from 'lucide-react'
import { usePermissions } from '@/hooks/usePermissions'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { PageHeader } from '@/components/molecules/PageHeader'
import { StatusBadge } from '@/components/atoms/StatusBadge'
import { StatusPill } from '../components/StatusPill'
import { MileageLabel } from '../components/MileageLabel'
import { ApprovalBadge } from '../components/ApprovalBadge'
import { TransitionBar } from '../components/TransitionBar'
import { TotalsPanel } from '../components/TotalsPanel'
import { WorkOrderLineRow } from '../components/WorkOrderLineRow'
import { CompleteWorkOrderDialog } from '../components/CompleteWorkOrderDialog'
import {
  useApproveWorkOrder,
  useCancelWorkOrder,
  useCompleteWorkOrder,
  useTransitionWorkOrder,
  useWorkOrder,
} from '../hooks/useWorkOrders'
import type { WorkOrderStatus } from '../types'
import { createMutationErrorHandler } from './handleMutationError'

export function WorkOrderDetailPage() {
  const { id } = useParams<{ id: string }>()
  const { t } = useTranslation('workshop-work-orders')
  const { data: wo, isLoading, isError, error } = useWorkOrder(id)
  const transitionMutation = useTransitionWorkOrder(id ?? '')
  const approveMutation = useApproveWorkOrder(id ?? '')
  const cancelMutation = useCancelWorkOrder(id ?? '')
  const completeMutation = useCompleteWorkOrder(id ?? '')
  const [isCompleteDialogOpen, setIsCompleteDialogOpen] = useState(false)

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
      <div className={`p-6 text-sm ${textColors.tertiary}`}>
        {t('detail.loading')}
      </div>
    )
  }

  if (isError || !wo) {
    return (
      <div className="p-6">
        <div
          role="alert"
          className={`${tokens.alert.base} ${tokens.alert.error}`}
        >
          {t('detail.notFound')}
          {error !== null && (
            <span className="ml-2 text-xs">
              ({error instanceof Error ? error.message : String(error)})
            </span>
          )}
        </div>
      </div>
    )
  }

  const redactFinancials = wo.estimated_totals === null && wo.actual_totals === null
  const handleMutationError = createMutationErrorHandler(t)
  const handleTransition = (to: WorkOrderStatus) => {
    transitionMutation.mutate({ to_status: to }, { onError: handleMutationError })
  }
  const handleApprove = () => {
    approveMutation.mutate(
      { approval_method: 'in_person' },
      { onError: handleMutationError },
    )
  }
  const handleCancel = () => {
    cancelMutation.mutate(
      { reason_code: 'customer_declined' },
      { onError: handleMutationError },
    )
  }
  const handleComplete = () => {
    setIsCompleteDialogOpen(true)
  }
  const handleCompleteConfirm = (payload: { completion_mileage: number | null }) => {
    completeMutation.mutate(
      {
        completion_mileage: payload.completion_mileage,
        expected_updated_at: wo.updated_at,
      },
      {
        onSuccess: () => {
          setIsCompleteDialogOpen(false)
        },
        onError: handleMutationError,
      },
    )
  }

  return (
    <div className="space-y-6 p-6">
      <Link
        to="/workshop/work-orders"
        className={`inline-flex items-center gap-1 text-sm ${textColors.tertiary} ${textColors.hoverPrimary}`}
      >
        <ArrowLeft className="h-4 w-4" aria-hidden />
        {t('detail.backToList')}
      </Link>

      <div className={`rounded-lg border bg-white p-6 ${borderColors.light}`}>
        <PageHeader
          className="mb-0"
          title={wo.work_order_number}
          actions={
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
          }
          breadcrumb={
            <div className="flex items-center gap-2">
              <Wrench className={`h-5 w-5 ${textColors.tertiary}`} aria-hidden />
              <StatusPill status={wo.status} />
            </div>
          }
        />
        <div className={`mt-2 text-sm ${textColors.secondary}`}>
          {wo.customer_display_name} · {wo.vehicle_display_name}
        </div>
        <div className="mt-1 flex flex-wrap items-center gap-3">
          <MileageLabel mileage={wo.mileage_at_intake} />
          {wo.approval_method !== null && (
            <ApprovalBadge method={wo.approval_method} capturedAt={wo.approval_captured_at} />
          )}
        </div>
        {wo.invoice_document_id !== null && (
          <Link
            to={`/sales/invoices/${wo.invoice_document_id}`}
            className={`mt-2 inline-flex items-center gap-1 text-sm ${textColors.secondary} ${textColors.hoverPrimary}`}
          >
            {t('detail.invoice_link', { number: wo.invoice_document_id.slice(0, 8) })}
          </Link>
        )}
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
          <ul className={`divide-y ${borderColors.divideLight}`}>
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

      <CompleteWorkOrderDialog
        isOpen={isCompleteDialogOpen}
        onClose={() => {
          setIsCompleteDialogOpen(false)
        }}
        onConfirm={handleCompleteConfirm}
        isPending={completeMutation.isPending}
      />
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
                <StatusBadge tone="info" className="ml-2">
                  {t('detail.lead')}
                </StatusBadge>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
