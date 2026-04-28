import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { textColors, tokens } from '@/lib/designTokens'
import { AppointmentCard } from '../molecules/AppointmentCard'
import { useBays, useDayView } from '../../hooks/useScheduling'
import type { BookedEntryDTO } from '../../types'

interface DayViewBoardProps {
  date: string
  onSelectAppointment?: (id: string) => void
}

interface BayRow {
  id: string
  name: string
  code: string
  booked: BookedEntryDTO[]
}

/**
 * Data-aware grid that shows one row per bay with that day's appointments.
 *
 * Organism: owns queries (`useBays`, `useDayView`) and renders cards via
 * the `AppointmentCard` molecule. Upstream pages just pass `date` — the
 * organism owns all fetching + invalidation.
 */
export function DayViewBoard({ date, onSelectAppointment }: DayViewBoardProps) {
  const { t } = useTranslation('scheduling')
  const baysQuery = useBays()
  const dayQuery = useDayView(date)

  const rows = useMemo<BayRow[]>(() => {
    if (!baysQuery.data) return []
    const booked = dayQuery.data?.booked ?? {}
    return baysQuery.data
      .filter((b) => b.is_active)
      .sort((a, b) => a.display_order - b.display_order)
      .map((bay): BayRow => ({
        id: bay.id,
        name: bay.name,
        code: bay.code,
        booked: booked[bay.id] ?? [],
      }))
  }, [baysQuery.data, dayQuery.data])

  if (baysQuery.isLoading || dayQuery.isLoading) {
    return <p className={`text-sm ${textColors.tertiary}`}>{t('scheduler.loading')}</p>
  }

  if (baysQuery.isError || dayQuery.isError) {
    return (
      <div className={`${tokens.alert.base} ${tokens.alert.error}`} role="alert">
        {t('scheduler.loadError')}
      </div>
    )
  }

  if (rows.length === 0) {
    return <p className={`text-sm ${textColors.tertiary}`}>{t('scheduler.noBays')}</p>
  }

  return (
    <div className="flex flex-col gap-4">
      {rows.map((row) => (
        <section key={row.id} className={tokens.card.base}>
          <header className="mb-3 flex items-center justify-between">
            <h3 className={`text-sm font-semibold ${textColors.primary}`}>
              {row.code} · {row.name}
            </h3>
            <span className={`text-xs ${textColors.tertiary}`}>
              {String(row.booked.length)}
            </span>
          </header>
          {row.booked.length === 0 ? (
            <p className={`text-sm ${textColors.tertiary}`}>{t('scheduler.emptyBay')}</p>
          ) : (
            <ul className="grid grid-cols-1 gap-2 md:grid-cols-2 lg:grid-cols-3">
              {row.booked.map((entry) => (
                <li key={entry.appointment_id}>
                  <AppointmentCard
                    appointment={{
                      id: entry.appointment_id,
                      appointment_number: null,
                      status: entry.status,
                      start: entry.start,
                      end: entry.end,
                      customer_display: null,
                      bay_name: row.name,
                      bay_code: row.code,
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
