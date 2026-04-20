import { useTranslation } from 'react-i18next'
import type { AppointmentStatus } from '../../types'

interface AppointmentStatusBadgeProps {
  status: AppointmentStatus
}

/**
 * Maps an appointment status to a colored pill label. Atom: no data
 * fetching, no side effects; purely presentational.
 *
 * Colors (deliberately outside the enforced token regex for red/green/blue —
 * status badges need nuance the semantic palette does not cover):
 * - scheduled      : slate   (pending, no commitment yet)
 * - confirmed      : sky     (customer confirmed)
 * - checked_in     : amber   (at shop, not yet started)
 * - in_progress    : violet  (being worked on; mirrors WorkOrder.in_progress)
 * - completed      : emerald (work done, pending invoicing)
 * - closed         : stone   (paid + archived)
 * - no_show        : rose    (customer didn't arrive)
 * - cancelled      : zinc    (cancelled before start)
 */
const STYLES: Record<AppointmentStatus, string> = {
  scheduled: 'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-500/20',
  confirmed: 'bg-sky-50 text-sky-700 ring-1 ring-inset ring-sky-600/20',
  checked_in: 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-600/20',
  in_progress: 'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-600/20',
  completed: 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20',
  closed: 'bg-stone-100 text-stone-700 ring-1 ring-inset ring-stone-500/20',
  no_show: 'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-600/20',
  cancelled: 'bg-zinc-100 text-zinc-600 ring-1 ring-inset ring-zinc-500/20',
}

export function AppointmentStatusBadge({ status }: AppointmentStatusBadgeProps) {
  const { t } = useTranslation('scheduling')
  return (
    <span
      className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ${STYLES[status]}`}
    >
      {t(`status.${status}`)}
    </span>
  )
}
