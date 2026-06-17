import { useTranslation } from 'react-i18next'
import type { WeeklyScheduleData, WeeklyScheduleDay } from '../api/types'
import { borderColors, colors, textColors } from '@/lib/designTokens'

interface WeeklyScheduleViewProps {
  schedule: WeeklyScheduleData
}

const DAYS: WeeklyScheduleDay[] = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']

export function WeeklyScheduleView({ schedule }: WeeklyScheduleViewProps) {
  const { t } = useTranslation('workshop-technicians')
  return (
    <div
      className={`divide-y ${borderColors.divideLight} rounded-md border ${borderColors.light} bg-white`}
    >
      {DAYS.map((day) => {
        const windows = schedule[day]
        return (
          <div key={day} className="flex items-start gap-4 px-3 py-2">
            <div
              className={`w-16 shrink-0 text-xs font-semibold uppercase tracking-wide ${textColors.tertiary}`}
            >
              {t(`days.${day}`)}
            </div>
            <div className="flex flex-1 flex-wrap gap-1.5">
              {windows.length === 0 ? (
                <span className={`text-xs ${textColors.disabled}`}>
                  {t('schedule.off')}
                </span>
              ) : (
                windows.map((w, idx) => (
                  <span
                    key={`${day}-${String(idx)}`}
                    className={`inline-flex rounded-md border ${borderColors.light} ${colors.neutral[50]} px-2 py-0.5 text-xs font-medium ${textColors.secondary}`}
                  >
                    {w.start}–{w.end}
                  </span>
                ))
              )}
            </div>
          </div>
        )
      })}
    </div>
  )
}
