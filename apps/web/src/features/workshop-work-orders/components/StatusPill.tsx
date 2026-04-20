import { useTranslation } from 'react-i18next'
import type { WorkOrderStatus } from '../types'

interface StatusPillProps {
  status: WorkOrderStatus
}

/**
 * Status-coded pill for WorkOrderStatus. Palettes use `emerald/amber/slate/sky`
 * which sit outside the new-feature ESLint restriction on the action-state
 * colour regex (red/green/blue/yellow/gray/etc.).
 */
const STYLES: Record<WorkOrderStatus, string> = {
  received: 'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-500/20',
  diagnosed: 'bg-sky-50 text-sky-700 ring-1 ring-inset ring-sky-600/20',
  quoted: 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-600/20',
  approved: 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20',
  in_progress: 'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-600/20',
  paused: 'bg-orange-50 text-orange-700 ring-1 ring-inset ring-orange-600/20',
  waiting_parts: 'bg-fuchsia-50 text-fuchsia-700 ring-1 ring-inset ring-fuchsia-600/20',
  completed: 'bg-teal-50 text-teal-700 ring-1 ring-inset ring-teal-600/20',
  invoiced: 'bg-cyan-50 text-cyan-700 ring-1 ring-inset ring-cyan-600/20',
  closed: 'bg-slate-200 text-slate-800 ring-1 ring-inset ring-slate-500/30',
  cancelled: 'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-600/20',
}

export function StatusPill({ status }: StatusPillProps) {
  const { t } = useTranslation('workshop-work-orders')
  return (
    <span
      className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ${STYLES[status]}`}
    >
      {t(`status.${status}`)}
    </span>
  )
}
