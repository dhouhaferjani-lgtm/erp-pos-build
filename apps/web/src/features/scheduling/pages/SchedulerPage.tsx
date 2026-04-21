import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { textColors, tokens } from '@/lib/designTokens'
import { useLocations } from '@/features/locations/hooks/useLocations'
import { CalendarDayHeader } from '../components/molecules/CalendarDayHeader'
import { DayViewBoard } from '../components/organisms/DayViewBoard'
import { WeekViewBoard } from '../components/organisms/WeekViewBoard'
import { AppointmentFormDrawer } from '../components/organisms/AppointmentFormDrawer'
import { UpcomingAppointmentsList } from '../components/organisms/UpcomingAppointmentsList'

function formatDate(d: Date): string {
  const pad = (n: number): string => (n < 10 ? `0${String(n)}` : String(n))
  return `${String(d.getFullYear())}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

function addDays(d: Date, days: number): Date {
  const copy = new Date(d.getTime())
  copy.setDate(copy.getDate() + days)
  return copy
}

function mondayOf(d: Date): Date {
  const copy = new Date(d.getTime())
  const day = copy.getDay() // 0=Sun,1=Mon,…
  const offset = day === 0 ? -6 : 1 - day
  copy.setDate(copy.getDate() + offset)
  copy.setHours(0, 0, 0, 0)
  return copy
}

/**
 * Main Scheduler page — orchestrates organisms only.
 *
 * Pages are thin: they wire routing, toggle state, and compose organisms.
 * Data fetching lives in the organisms + `useScheduling` hooks.
 */
export function SchedulerPage() {
  const { t } = useTranslation('scheduling')
  const navigate = useNavigate()
  const [view, setView] = useState<'day' | 'week'>('day')
  const [currentDate, setCurrentDate] = useState<Date>(new Date())
  const [drawerOpen, setDrawerOpen] = useState<boolean>(false)

  const locations = useLocations()
  const defaultLocation =
    locations.data?.find((loc) => loc.isDefault) ?? locations.data?.[0]

  const handleSelectAppointment = (id: string): void => {
    void navigate(`/scheduling/appointments/${id}`)
  }

  const handleToday = (): void => { setCurrentDate(new Date()) }

  const handlePrevious = (): void => {
    setCurrentDate((prev) => addDays(prev, view === 'day' ? -1 : -7))
  }

  const handleNext = (): void => {
    setCurrentDate((prev) => addDays(prev, view === 'day' ? 1 : 7))
  }

  const weekStart = mondayOf(currentDate)

  return (
    <div className="flex flex-col gap-6 p-4 sm:p-6">
      <header className="flex flex-col gap-1">
        <h1 className={`text-2xl font-bold ${textColors.primary}`}>
          {t('scheduler.title')}
        </h1>
        <p className={`text-sm ${textColors.tertiary}`}>{t('scheduler.subtitle')}</p>
      </header>

      <CalendarDayHeader
        date={view === 'day' ? formatDate(currentDate) : `${formatDate(weekStart)} → ${formatDate(addDays(weekStart, 6))}`}
        view={view}
        onToday={handleToday}
        onPrevious={handlePrevious}
        onNext={handleNext}
        onViewChange={setView}
        onNewAppointment={() => { setDrawerOpen(true) }}
      />

      {view === 'day' ? (
        <DayViewBoard date={formatDate(currentDate)} onSelectAppointment={handleSelectAppointment} />
      ) : (
        <WeekViewBoard weekStart={formatDate(weekStart)} onSelectAppointment={handleSelectAppointment} />
      )}

      <UpcomingAppointmentsList onSelectAppointment={handleSelectAppointment} />

      {defaultLocation !== undefined ? (
        <AppointmentFormDrawer
          isOpen={drawerOpen}
          locationId={defaultLocation.id}
          onClose={() => { setDrawerOpen(false) }}
          onBooked={handleSelectAppointment}
        />
      ) : null}

      {defaultLocation === undefined && locations.isSuccess ? (
        <div className={`${tokens.alert.base} ${tokens.alert.warning}`} role="alert">
          {t('scheduler.noBays')}
        </div>
      ) : null}
    </div>
  )
}
