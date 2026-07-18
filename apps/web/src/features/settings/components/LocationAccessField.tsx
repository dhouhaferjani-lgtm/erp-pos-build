import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { MapPin } from 'lucide-react'
import { useManagementLocations } from '@/features/locations/hooks/useManagementLocations'
import { cn } from '@/lib/utils'
import { borderColors, focusRing, semanticColorTokens as colorTokens, textColors } from '@/lib/designTokens'

export interface LocationAccessFieldProps {
  value: 'all' | string[] | null
  onChange: (value: string[] | null) => void
  disabled?: boolean
  readOnly?: boolean
}

export function LocationAccessField({
  value,
  onChange,
  disabled = false,
  readOnly = false,
}: LocationAccessFieldProps) {
  const { t } = useTranslation('locations')
  const { data: locations = [], isLoading } = useManagementLocations()
  const blocked = disabled || readOnly
  const selectedIds = useMemo(() => new Set(Array.isArray(value) ? value : []), [value])
  const isAll = value === null || value === 'all'

  const toggleLocation = (locationId: string) => {
    if (blocked) return
    const next = selectedIds.has(locationId)
      ? [...selectedIds].filter((id) => id !== locationId)
      : [...selectedIds, locationId]
    onChange(next.sort((left, right) => left.localeCompare(right)))
  }

  return (
    <fieldset
      className={cn('mt-5 space-y-3 rounded-lg border p-4', borderColors.light, blocked && 'opacity-70')}
      disabled={disabled}
      aria-readonly={readOnly || undefined}
    >
      <legend className={cn('px-1 text-sm font-semibold', textColors.primary)}>
        {t('locations:staffAccess.label')}
      </legend>

      <label className={cn('flex cursor-pointer items-start gap-3 rounded-md p-2', blocked && 'cursor-not-allowed')}>
        <input
          type="radio"
          name="location-access-mode"
          value="all"
          checked={isAll}
          onChange={() => { if (!blocked) onChange(null) }}
          readOnly={readOnly}
          className={cn('mt-0.5 h-4 w-4', focusRing.default)}
        />
        <span>
          <span className={cn('block text-sm font-medium', textColors.primary)}>{t('locations:staffAccess.allLocations')}</span>
          <span className={cn('block text-xs', textColors.tertiary)}>{t('locations:staffAccess.allLocationsHint')}</span>
        </span>
      </label>

      <label className={cn('flex cursor-pointer items-start gap-3 rounded-md p-2', blocked && 'cursor-not-allowed')}>
        <input
          type="radio"
          name="location-access-mode"
          value="subset"
          checked={!isAll}
          onChange={() => { if (!blocked && isAll) onChange([]) }}
          readOnly={readOnly}
          className={cn('mt-0.5 h-4 w-4', focusRing.default)}
        />
        <span className="min-w-0 flex-1">
          <span className={cn('block text-sm font-medium', textColors.primary)}>{t('locations:staffAccess.subset')}</span>
          <span className={cn('block text-xs', textColors.tertiary)}>{t('locations:staffAccess.subsetHint')}</span>
        </span>
      </label>

      {!isAll && (
        <div className="space-y-2 ps-7" aria-label={t('locations:staffAccess.subset')}>
          {isLoading && <p className={cn('text-sm', textColors.tertiary)}>{t('locations:staffAccess.loading')}</p>}
          {!isLoading && locations.length === 0 && (
            <p className={cn('text-sm', textColors.tertiary)}>{t('locations:staffAccess.noLocations')}</p>
          )}
          {locations.map((location) => (
            <label key={location.id} className={cn('flex cursor-pointer items-center gap-3 rounded-md p-2', blocked && 'cursor-not-allowed')}>
              <input
                type="checkbox"
                checked={selectedIds.has(location.id)}
                onChange={() => { toggleLocation(location.id) }}
                disabled={blocked}
                className={cn('h-4 w-4 rounded', focusRing.default)}
                aria-label={`${location.name} (${location.code})`}
              />
              <MapPin className={cn('h-4 w-4 shrink-0', colorTokens.text.subtle)} aria-hidden="true" />
              <span className={cn('min-w-0 truncate text-sm', textColors.primary)}>{location.name}</span>
              <span className={cn('ms-auto text-xs', textColors.tertiary)}>{location.code}</span>
            </label>
          ))}
        </div>
      )}
    </fieldset>
  )
}
