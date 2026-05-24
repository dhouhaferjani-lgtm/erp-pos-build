import { CalendarDays } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { borderColors, colors, textColors } from '@/lib/designTokens'

export interface OwnerDashboardFiltersValue {
  from: string
  to: string
  granularity: 'day' | 'week' | 'month'
}

interface OwnerDashboardFiltersProps {
  value: OwnerDashboardFiltersValue
  onChange: (value: OwnerDashboardFiltersValue) => void
}

export function OwnerDashboardFilters({ value, onChange }: OwnerDashboardFiltersProps) {
  const { t } = useTranslation(['reports'])

  return (
    <div className={`flex flex-wrap items-center gap-3 rounded-lg border ${borderColors.light} ${colors.white} p-3`}>
      <CalendarDays className={`h-4 w-4 ${textColors.tertiary}`} />
      <label className={`text-sm ${textColors.secondary}`}>
        {t('reports:ownerDashboard.filters.from')}
        <input
          type="date"
          value={value.from}
          onChange={(event) => {
            onChange({ ...value, from: event.target.value })
          }}
          className={`ms-2 rounded-md border ${borderColors.default} px-2 py-1`}
        />
      </label>
      <label className={`text-sm ${textColors.secondary}`}>
        {t('reports:ownerDashboard.filters.to')}
        <input
          type="date"
          value={value.to}
          onChange={(event) => {
            onChange({ ...value, to: event.target.value })
          }}
          className={`ms-2 rounded-md border ${borderColors.default} px-2 py-1`}
        />
      </label>
      <label className={`text-sm ${textColors.secondary}`}>
        {t('reports:ownerDashboard.filters.granularity')}
        <select
          value={value.granularity}
          onChange={(event) => {
            onChange({ ...value, granularity: parseGranularity(event.target.value) })
          }}
          className={`ms-2 rounded-md border ${borderColors.default} px-2 py-1`}
        >
          <option value="day">{t('reports:ownerDashboard.filters.day')}</option>
          <option value="week">{t('reports:ownerDashboard.filters.week')}</option>
          <option value="month">{t('reports:ownerDashboard.filters.month')}</option>
        </select>
      </label>
    </div>
  )
}

function parseGranularity(value: string): OwnerDashboardFiltersValue['granularity'] {
  if (value === 'week' || value === 'month') {
    return value
  }

  return 'day'
}
