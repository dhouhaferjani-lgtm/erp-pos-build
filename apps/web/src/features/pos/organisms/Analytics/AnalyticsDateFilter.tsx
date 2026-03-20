import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { AnalyticsFilters } from '../../api/analyticsApi'

interface AnalyticsDateFilterProps {
  filters: AnalyticsFilters
  onChange: (filters: AnalyticsFilters) => void
}

function formatDate(date: Date): string {
  return date.toISOString().split('T')[0]
}

function getPresets(): Array<{ key: string; from: string; to: string }> {
  const today = new Date()
  const todayStr = formatDate(today)

  const startOfWeek = new Date(today)
  startOfWeek.setDate(today.getDate() - today.getDay() + 1)

  const startOfMonth = new Date(today.getFullYear(), today.getMonth(), 1)

  const last30 = new Date(today)
  last30.setDate(today.getDate() - 29)

  return [
    { key: 'today', from: todayStr, to: todayStr },
    { key: 'thisWeek', from: formatDate(startOfWeek), to: todayStr },
    { key: 'thisMonth', from: formatDate(startOfMonth), to: todayStr },
    { key: 'last30Days', from: formatDate(last30), to: todayStr },
  ]
}

export function AnalyticsDateFilter({ filters, onChange }: AnalyticsDateFilterProps) {
  const { t } = useTranslation(['pos'])
  const [activePreset, setActivePreset] = useState<string | null>('thisMonth')
  const presets = getPresets()

  const handlePreset = (preset: (typeof presets)[number]) => {
    setActivePreset(preset.key)
    onChange({ from: preset.from, to: preset.to })
  }

  const handleDateChange = (field: 'from' | 'to', value: string) => {
    setActivePreset(null)
    onChange({ ...filters, [field]: value })
  }

  return (
    <div className="flex flex-wrap items-center gap-3">
      <div className="flex gap-2">
        {presets.map((preset) => (
          <button
            key={preset.key}
            type="button"
            onClick={() => { handlePreset(preset); }}
            className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
              activePreset === preset.key
                ? 'bg-primary text-primary-foreground'
                : 'bg-muted text-muted-foreground hover:bg-muted/80'
            }`}
          >
            {t(`pos:analytics.presets.${preset.key}`)}
          </button>
        ))}
      </div>
      <div className="flex items-center gap-2">
        <input
          type="date"
          value={filters.from}
          onChange={(e) => { handleDateChange('from', e.target.value); }}
          className="rounded-md border border-input bg-background px-3 py-1.5 text-sm"
        />
        <span className="text-muted-foreground">—</span>
        <input
          type="date"
          value={filters.to}
          onChange={(e) => { handleDateChange('to', e.target.value); }}
          className="rounded-md border border-input bg-background px-3 py-1.5 text-sm"
        />
      </div>
    </div>
  )
}
