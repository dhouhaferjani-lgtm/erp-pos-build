import { CalendarDays } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { borderColors, colors, textColors } from '@/lib/designTokens'
import { Input, Select } from '@/components/atoms'

export interface OwnerDashboardFiltersValue {
  from: string
  to: string
  granularity: 'hour' | 'day' | 'week' | 'month'
}

interface OwnerDashboardFiltersProps {
  value: OwnerDashboardFiltersValue
  onChange: (value: OwnerDashboardFiltersValue) => void
}

export function OwnerDashboardFilters({ value, onChange }: OwnerDashboardFiltersProps) {
  const { t } = useTranslation(['reports'])

  function applyPreset(days: 1 | 7 | 30) {
    const to = new Date()
    const from = new Date(to)
    from.setDate(to.getDate() - (days - 1))

    onChange({
      ...value,
      from: formatDateInput(from),
      to: formatDateInput(to),
      granularity: days === 1 ? 'hour' : 'day',
    })
  }

  function handleDateChange(field: 'from' | 'to', nextDate: string) {
    const nextValue = { ...value, [field]: nextDate }
    onChange({
      ...nextValue,
      granularity: isSingleDay(nextValue.from, nextValue.to) ? 'hour' : value.granularity,
    })
  }

  return (
    <div className={`flex flex-wrap items-center gap-3 rounded-lg border ${borderColors.light} ${colors.white} p-3`}>
      <CalendarDays className={`h-4 w-4 ${textColors.tertiary}`} />
      <div className={`inline-flex overflow-hidden rounded-md border ${borderColors.default}`}>
        <button
          type="button"
          onClick={() => {
            applyPreset(1)
          }}
          className={`px-3 py-1 text-sm ${isToday(value.from, value.to) ? `${colors.primary[600]} ${textColors.inverse}` : `${colors.white} ${textColors.secondary}`}`}
        >
          {t('reports:ownerDashboard.filters.today')}
        </button>
        <button
          type="button"
          onClick={() => {
            applyPreset(7)
          }}
          className={`border-s ${borderColors.default} px-3 py-1 text-sm ${colors.white} ${textColors.secondary} ${textColors.hoverPrimary}`}
        >
          {t('reports:ownerDashboard.filters.last7Days')}
        </button>
        <button
          type="button"
          onClick={() => {
            applyPreset(30)
          }}
          className={`border-s ${borderColors.default} px-3 py-1 text-sm ${colors.white} ${textColors.secondary} ${textColors.hoverPrimary}`}
        >
          {t('reports:ownerDashboard.filters.last30Days')}
        </button>
      </div>
      <label className={`text-sm ${textColors.secondary}`}>
        {t('reports:ownerDashboard.filters.from')}
        <Input
          type="date"
          value={value.from}
          onChange={(event) => {
            handleDateChange('from', event.target.value)
          }}
          className="ms-2 w-auto"
        />
      </label>
      <label className={`text-sm ${textColors.secondary}`}>
        {t('reports:ownerDashboard.filters.to')}
        <Input
          type="date"
          value={value.to}
          onChange={(event) => {
            handleDateChange('to', event.target.value)
          }}
          className="ms-2 w-auto"
        />
      </label>
      <label className={`text-sm ${textColors.secondary}`}>
        {t('reports:ownerDashboard.filters.granularity')}
        <Select
          value={value.granularity}
          onChange={(event) => {
            onChange({ ...value, granularity: parseGranularity(event.target.value) })
          }}
          className="ms-2 w-auto"
        >
          <option value="hour">{t('reports:ownerDashboard.filters.hour')}</option>
          <option value="day">{t('reports:ownerDashboard.filters.day')}</option>
          <option value="week">{t('reports:ownerDashboard.filters.week')}</option>
          <option value="month">{t('reports:ownerDashboard.filters.month')}</option>
        </Select>
      </label>
    </div>
  )
}

function parseGranularity(value: string): OwnerDashboardFiltersValue['granularity'] {
  if (value === 'hour' || value === 'week' || value === 'month') {
    return value
  }

  return 'day'
}

function isSingleDay(from: string, to: string): boolean {
  return from !== '' && from === to
}

function isToday(from: string, to: string): boolean {
  const today = formatDateInput(new Date())
  return from === today && to === today
}

function formatDateInput(date: Date): string {
  const year = String(date.getFullYear())
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')

  return `${year}-${month}-${day}`
}
