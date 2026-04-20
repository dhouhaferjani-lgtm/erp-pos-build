import { useTranslation } from 'react-i18next'
import type { EmploymentStatus } from '../api/types'

interface EmploymentStatusBadgeProps {
  status: EmploymentStatus
}

/**
 * Employment-status badge. Active → emerald, on_leave → amber, terminated →
 * slate. These palettes sit outside the enforced token regex in
 * eslint.config.js, which the palette is intentionally reserved for
 * red/green/blue action states.
 */
const STYLES: Record<EmploymentStatus, string> = {
  active: 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20',
  on_leave: 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-600/20',
  terminated: 'bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-500/20',
}

export function EmploymentStatusBadge({ status }: EmploymentStatusBadgeProps) {
  const { t } = useTranslation('workshop-technicians')
  return (
    <span
      className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ${STYLES[status]}`}
    >
      {t(`employmentStatus.${status}`)}
    </span>
  )
}
