import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { textColors, tokens } from '@/lib/designTokens'
import { AppointmentCard } from '../molecules/AppointmentCard'
import { useAppointments } from '../../hooks/useScheduling'

interface UpcomingAppointmentsListProps {
  onSelectAppointment?: (id: string) => void
  /** How many days out to include — defaults to 7. */
  daysAhead?: number
}

/**
 * Shows the list of upcoming appointments (status in scheduled/confirmed)
 * for the next N days.
 *
 * Organism: owns `useAppointments` query. Composes `AppointmentCard` molecule.
 */
export function UpcomingAppointmentsList({
  onSelectAppointment,
  daysAhead = 7,
}: UpcomingAppointmentsListProps) {
  const { t } = useTranslation('scheduling')

  const range = useMemo(() => {
    const now = new Date()
    const until = new Date(now.getTime() + daysAhead * 24 * 60 * 60 * 1000)
    return {
      date_from: now.toISOString(),
      date_to: until.toISOString(),
    }
  }, [daysAhead])

  const query = useAppointments({
    date_from: range.date_from,
    date_to: range.date_to,
    per_page: 20,
  })

  if (query.isLoading) {
    return <p className={`text-sm ${textColors.tertiary}`}>{t('scheduler.loading')}</p>
  }

  if (query.isError) {
    return (
      <div className={`${tokens.alert.base} ${tokens.alert.error}`} role="alert">
        {t('scheduler.loadError')}
      </div>
    )
  }

  const items = query.data?.data ?? []

  if (items.length === 0) {
    return <p className={`text-sm ${textColors.tertiary}`}>{t('upcoming.empty')}</p>
  }

  return (
    <section className="flex flex-col gap-2">
      <h3 className={`text-sm font-semibold ${textColors.primary}`}>{t('upcoming.title')}</h3>
      <ul className="grid grid-cols-1 gap-2 md:grid-cols-2 lg:grid-cols-3">
        {items.map((appt) => (
          <li key={appt.id}>
            <AppointmentCard
              appointment={{
                id: appt.id,
                appointment_number: appt.appointment_number,
                status: appt.status,
                start: appt.scheduled_start,
                end: appt.scheduled_end,
                customer_display: appt.customer_name,
                bay_name: null,
                bay_code: null,
              }}
              {...(onSelectAppointment !== undefined ? { onClick: onSelectAppointment } : {})}
            />
          </li>
        ))}
      </ul>
    </section>
  )
}
