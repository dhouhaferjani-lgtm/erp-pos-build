import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { textColors, tokens } from '@/lib/designTokens'
import { AppointmentCard } from '../molecules/AppointmentCard'
import { useWeekView } from '../../hooks/useScheduling'
import type { WeekEntryDTO } from '../../types'

interface WeekViewBoardProps {
  /** ISO date (YYYY-MM-DD) of the Monday that starts the week. */
  weekStart: string
  onSelectAppointment?: (id: string) => void
}

interface DayColumn {
  date: string
  entries: WeekEntryDTO[]
}

/**
 * Owns `useWeekView` and renders one column per day (Mon–Sun) with the
 * bookings. Organism: data-aware; pages compose it with date navigation.
 */
export function WeekViewBoard({ weekStart, onSelectAppointment }: WeekViewBoardProps) {
  const { t } = useTranslation('scheduling')
  const query = useWeekView(weekStart)

  const columns = useMemo<DayColumn[]>(() => {
    if (!query.data) return []
    return Object.entries(query.data).map(([date, entries]) => ({
      date,
      entries,
    }))
  }, [query.data])

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

  if (columns.length === 0) {
    return <p className={`text-sm ${textColors.tertiary}`}>{t('scheduler.emptyDay')}</p>
  }

  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-7">
      {columns.map((col) => (
        <section key={col.date} className={tokens.card.base}>
          <header className="mb-2 flex items-center justify-between">
            <h3 className={`text-sm font-semibold ${textColors.primary}`}>{col.date}</h3>
            <span className={`text-xs ${textColors.tertiary}`}>
              {String(col.entries.length)}
            </span>
          </header>
          {col.entries.length === 0 ? (
            <p className={`text-sm ${textColors.tertiary}`}>{t('scheduler.emptyDay')}</p>
          ) : (
            <ul className="flex flex-col gap-2">
              {col.entries.map((entry) => (
                <li key={entry.appointment_id}>
                  <AppointmentCard
                    appointment={{
                      id: entry.appointment_id,
                      appointment_number: entry.appointment_number,
                      status: entry.status,
                      start: entry.start,
                      end: entry.end,
                      customer_display: entry.customer_display,
                      bay_name: null,
                      bay_code: null,
                    }}
                    {...(onSelectAppointment !== undefined ? { onClick: onSelectAppointment } : {})}
                  />
                </li>
              ))}
            </ul>
          )}
        </section>
      ))}
    </div>
  )
}
