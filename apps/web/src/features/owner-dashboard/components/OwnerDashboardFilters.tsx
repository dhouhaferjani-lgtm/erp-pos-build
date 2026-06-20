import { CalendarDays, MapPin } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { borderColors, colors, textColors } from '@/lib/designTokens'
import { useLocationStore } from '@/stores/locationStore'

export interface OwnerDashboardFiltersValue {
  from: string
  to: string
  granularity: 'day' | 'week' | 'month'
  locationIds: string[]
}

interface OwnerDashboardFiltersProps {
  value: OwnerDashboardFiltersValue
  onChange: (value: OwnerDashboardFiltersValue) => void
}

export function OwnerDashboardFilters({ value, onChange }: OwnerDashboardFiltersProps) {
  const { t } = useTranslation(['reports'])
  const locations = useLocationStore((s) => s.locations)

  function handleLocationToggle(id: string) {
    const next = value.locationIds.includes(id)
      ? value.locationIds.filter((l) => l !== id)
      : [...value.locationIds, id]
    onChange({ ...value, locationIds: next })
  }

  function handleAllLocations() {
    onChange({ ...value, locationIds: [] })
  }

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
      {locations.length > 0 && (
        <div className={`flex items-center gap-2 border-s ${borderColors.light} ps-3`}>
          <MapPin className={`h-4 w-4 ${textColors.tertiary}`} />
          <button
            type="button"
            onClick={handleAllLocations}
            className={`text-sm ${value.locationIds.length === 0 ? textColors.primary : textColors.tertiary} hover:${textColors.secondary}`}
          >
            {t('reports:ownerDashboard.filters.allLocations')}
          </button>
          {locations.map((loc) => (
            <label key={loc.id} className={`flex cursor-pointer items-center gap-1 text-sm ${textColors.secondary}`}>
              <input
                type="checkbox"
                aria-label={loc.name}
                checked={value.locationIds.includes(loc.id)}
                onChange={() => {
                  handleLocationToggle(loc.id)
                }}
                className={`rounded border ${borderColors.default}`}
              />
              {loc.name}
            </label>
          ))}
        </div>
      )}
    </div>
  )
}

function parseGranularity(value: string): OwnerDashboardFiltersValue['granularity'] {
  if (value === 'week' || value === 'month') {
    return value
  }

  return 'day'
}
