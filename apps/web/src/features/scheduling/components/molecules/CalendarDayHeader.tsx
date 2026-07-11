import { useTranslation } from 'react-i18next'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { Button } from '@/components/atoms'

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
        <Button
          type="button"
          variant="secondary"
          size="sm"
          onClick={onToday}
        >
          {t('scheduler.today')}
        </Button>
        <Button
          type="button"
          variant="secondary"
          size="sm"
          onClick={onPrevious}
          aria-label={t('scheduler.previous')}
        >
          {'<'}
        </Button>
        <Button
          type="button"
          variant="secondary"
          size="sm"
          onClick={onNext}
          aria-label={t('scheduler.next')}
        >
          {'>'}
        </Button>
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

        <Button
          type="button"
          variant="primary"
          size="sm"
          onClick={onNewAppointment}
        >
          {t('scheduler.newAppointment')}
        </Button>
      </div>
    </div>
  )
}
