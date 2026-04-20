import { useTranslation } from 'react-i18next'
import { borderColors, textColors, tokens } from '@/lib/designTokens'

interface CalendarDayHeaderProps {
  date: string
  view: 'day' | 'week'
  onToday: () => void
  onPrevious: () => void
  onNext: () => void
  onViewChange: (view: 'day' | 'week') => void
  onNewAppointment: () => void
}

/**
 * Top strip above the calendar grid: date label, day/week toggle, nav
 * buttons, "Book appointment" CTA. Molecule: local controlled interactions
 * only — no data fetching.
 */
export function CalendarDayHeader({
  date,
  view,
  onToday,
  onPrevious,
  onNext,
  onViewChange,
  onNewAppointment,
}: CalendarDayHeaderProps) {
  const { t } = useTranslation('scheduling')

  return (
    <div className={`flex flex-col gap-3 border-b ${borderColors.light} pb-3 sm:flex-row sm:items-center sm:justify-between`}>
      <div className="flex items-center gap-2">
        <button
          type="button"
          onClick={onToday}
          className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.sm}`}
        >
          {t('scheduler.today')}
        </button>
        <button
          type="button"
          onClick={onPrevious}
          aria-label={t('scheduler.previous')}
          className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.sm}`}
        >
          {'<'}
        </button>
        <button
          type="button"
          onClick={onNext}
          aria-label={t('scheduler.next')}
          className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.sm}`}
        >
          {'>'}
        </button>
        <span className={`ml-2 text-lg font-semibold ${textColors.primary}`}>{date}</span>
      </div>

      <div className="flex items-center gap-2">
        <div className={tokens.toggleButton.group}>
          <button
            type="button"
            onClick={() => { onViewChange('day') }}
            className={`px-3 py-1.5 text-sm font-medium ${
              view === 'day' ? tokens.toggleButton.active : tokens.toggleButton.idle
            }`}
          >
            {t('scheduler.viewDay')}
          </button>
          <button
            type="button"
            onClick={() => { onViewChange('week') }}
            className={`px-3 py-1.5 text-sm font-medium ${
              view === 'week' ? tokens.toggleButton.active : tokens.toggleButton.idle
            }`}
          >
            {t('scheduler.viewWeek')}
          </button>
        </div>

        <button
          type="button"
          onClick={onNewAppointment}
          className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.sm}`}
        >
          {t('scheduler.newAppointment')}
        </button>
      </div>
    </div>
  )
}
