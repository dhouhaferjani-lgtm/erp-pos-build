import { textColors, tokens } from '@/lib/designTokens'
import { AppointmentStatusBadge } from '../atoms/AppointmentStatusBadge'
import { BayBadge } from '../atoms/BayBadge'
import { TimeSlotLabel } from '../atoms/TimeSlotLabel'
import type { AppointmentStatus } from '../../types'

export interface AppointmentCardItem {
  id: string
  appointment_number: string | null
  status: AppointmentStatus
  start: string
  end: string
  customer_display: string | null
  bay_name?: string | null
  bay_code?: string | null
}

interface AppointmentCardProps {
  appointment: AppointmentCardItem
  onClick?: (id: string) => void
}

/**
 * Compact card that summarises an appointment — composed from atoms.
 *
 * Molecule: purely presentational + local interaction (click handler). No
 * data fetching. Calendar organisms render many of these for the day/week
 * grid and the upcoming list.
 */
export function AppointmentCard({ appointment, onClick }: AppointmentCardProps) {
  const {
    id,
    appointment_number,
    status,
    start,
    end,
    customer_display,
    bay_name = null,
    bay_code = null,
  } = appointment

  const handleClick = (): void => {
    if (onClick) onClick(id)
  }

  return (
    <button
      type="button"
      onClick={handleClick}
      className={`${tokens.card.base} ${tokens.card.hover} ${tokens.card.hoverPrimary} flex w-full flex-col gap-2 text-left`}
    >
      <div className="flex items-center justify-between gap-2">
        <span className={`text-sm font-semibold ${textColors.primary}`}>
          {customer_display ?? (appointment_number ?? id.slice(0, 8))}
        </span>
        <AppointmentStatusBadge status={status} />
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <TimeSlotLabel start={start} end={end} />
        <BayBadge name={bay_name} code={bay_code} />
      </div>
      {appointment_number !== null && customer_display !== null ? (
        <span className={`text-xs ${textColors.tertiary}`}>{appointment_number}</span>
      ) : null}
    </button>
  )
}
