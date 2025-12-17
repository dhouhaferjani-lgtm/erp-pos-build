import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import {
  Combobox,
  ComboboxInput,
  ComboboxOptions,
  ComboboxOption,
} from '@headlessui/react'
import { Search, X, MapPin } from 'lucide-react'
import { useLocations } from '../hooks/useLocations'
import { cn } from '@/lib/utils'

interface LocationSelectorMultiProps {
  /**
   * Currently selected location IDs
   */
  value: string[]

  /**
   * Callback when location selection changes
   */
  onChange: (locationIds: string[]) => void

  /**
   * Label for the selector
   */
  label?: string

  /**
   * Helper text to display below the selector
   */
  helperText?: string

  /**
   * Whether the selector is disabled
   */
  disabled?: boolean

  /**
   * Maximum number of locations that can be selected
   */
  maxSelection?: number
}

/**
 * LocationSelectorMulti Component
 *
 * Multi-select component for choosing locations.
 *
 * Features:
 * - Real-time search filtering
 * - Multi-select with visual list
 * - Keyboard navigation
 * - Loading state
 * - Active locations only
 *
 * @example
 * ```tsx
 * <LocationSelectorMulti
 *   value={selectedLocationIds}
 *   onChange={setSelectedLocationIds}
 *   label="Select Locations to Count"
 * />
 * ```
 */
export function LocationSelectorMulti({
  value,
  onChange,
  label,
  helperText,
  disabled = false,
  maxSelection,
}: LocationSelectorMultiProps) {
  const { t } = useTranslation(['common', 'locations'])
  const [query, setQuery] = useState('')

  // Fetch locations
  const { data: locationsResponse, isLoading } = useLocations()
  const locations = locationsResponse?.data ?? []

  // Filter active locations and by search query
  const filteredLocations = useMemo(() => {
    let filtered = locations.filter((loc) => loc.isActive)

    if (query.length > 0) {
      const lowerQuery = query.toLowerCase()
      filtered = filtered.filter(
        (loc) =>
          loc.name.toLowerCase().includes(lowerQuery) ||
          loc.code.toLowerCase().includes(lowerQuery)
      )
    }

    // Exclude already selected
    return filtered.filter((loc) => !value.includes(loc.id))
  }, [locations, query, value])

  // Get selected locations for display
  const selectedLocations = useMemo(
    () => locations.filter((loc) => value.includes(loc.id)),
    [locations, value]
  )

  const handleToggleLocation = (locationId: string) => {
    if (value.includes(locationId)) {
      onChange(value.filter((id) => id !== locationId))
    } else {
      if (maxSelection && value.length >= maxSelection) {
        return
      }
      onChange([...value, locationId])
    }
  }

  const handleRemoveLocation = (locationId: string) => {
    onChange(value.filter((id) => id !== locationId))
  }

  const handleClearAll = () => {
    onChange([])
  }

  const getTypeLabel = (type: string) => {
    return t(`common:locations.types.${type}`)
  }

  return (
    <div className="w-full space-y-4">
      {label && (
        <div className="flex items-center justify-between">
          <h3 className="text-sm font-medium text-gray-700">{label}</h3>
          {value.length > 0 && (
            <button
              type="button"
              onClick={handleClearAll}
              className="text-sm text-red-600 hover:text-red-700"
            >
              {t('common:clear')} ({value.length})
            </button>
          )}
        </div>
      )}

      {/* Search Combobox */}
      <Combobox
        value={null}
        onChange={(locationId: string | null) => {
          if (locationId) {
            handleToggleLocation(locationId)
            setQuery('')
          }
        }}
        disabled={disabled}
      >
        <div className="relative">
          <div className="relative">
            <Search className="absolute start-3 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400" />
            <ComboboxInput
              className={cn(
                'w-full ps-10 pe-10 py-3 border border-gray-300 rounded-md',
                'focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500',
                'disabled:bg-gray-100 disabled:cursor-not-allowed',
                'transition-colors'
              )}
              onChange={(e: React.ChangeEvent<HTMLInputElement>) => {
                setQuery(e.target.value)
              }}
              placeholder={t('locations:searchPlaceholder')}
              value={query}
            />
            {isLoading && (
              <div className="absolute end-3 top-1/2 -translate-y-1/2">
                <div className="animate-spin h-4 w-4 border-2 border-gray-300 border-t-blue-600 rounded-full" />
              </div>
            )}
          </div>

          <ComboboxOptions
            className={cn(
              'absolute z-10 mt-1 w-full',
              'max-h-60 overflow-auto',
              'rounded-md bg-white shadow-lg',
              'border border-gray-200',
              'py-1',
              'focus:outline-none'
            )}
          >
            {filteredLocations.length === 0 ? (
              <div className="px-4 py-3 text-sm text-gray-500">
                {query
                  ? t('locations:noLocationsFound')
                  : t('locations:noAvailableLocations')}
              </div>
            ) : (
              filteredLocations.map((location) => (
                <ComboboxOption
                  key={location.id}
                  value={location.id}
                  className={({ active }: { active: boolean }) =>
                    cn(
                      'cursor-pointer select-none px-4 py-2',
                      active ? 'bg-blue-50 text-blue-900' : 'text-gray-900'
                    )
                  }
                >
                  <div className="flex items-center gap-3">
                    {/* Location Icon */}
                    <div className="flex-shrink-0 h-10 w-10 rounded bg-gray-100 flex items-center justify-center">
                      <MapPin className="h-5 w-5 text-gray-500" />
                    </div>

                    {/* Location Info */}
                    <div className="flex-1 min-w-0">
                      <div className="font-medium truncate">{location.name}</div>
                      <div className="flex items-center gap-2 text-sm text-gray-500">
                        <span>{location.code}</span>
                        {location.isDefault && (
                          <>
                            <span>•</span>
                            <span className="text-blue-600">
                              {t('common:locations.default')}
                            </span>
                          </>
                        )}
                      </div>
                    </div>

                    {/* Type Badge */}
                    <span className="text-xs px-2 py-1 bg-gray-100 rounded">
                      {getTypeLabel(location.type)}
                    </span>
                  </div>
                </ComboboxOption>
              ))
            )}
          </ComboboxOptions>
        </div>
      </Combobox>

      {/* Selected Locations List */}
      {value.length > 0 && (
        <div className="border border-gray-200 rounded-lg p-4 bg-gray-50">
          <div className="flex items-center justify-between mb-3">
            <span className="text-sm font-medium text-gray-700">
              {t('locations:selectedLocations', { count: value.length })}
              {maxSelection && ` / ${maxSelection}`}
            </span>
          </div>

          <div className="space-y-2 max-h-60 overflow-y-auto">
            {selectedLocations.map((location) => (
              <div
                key={location.id}
                className="flex items-center gap-3 p-2 bg-white rounded border border-gray-200 hover:border-gray-300 transition-colors"
              >
                {/* Location Icon */}
                <div className="flex-shrink-0 h-8 w-8 rounded bg-gray-100 flex items-center justify-center">
                  <MapPin className="h-4 w-4 text-gray-500" />
                </div>

                {/* Location Info */}
                <div className="flex-1 min-w-0">
                  <div className="text-sm font-medium truncate">{location.name}</div>
                  <div className="text-xs text-gray-500 truncate">
                    {location.code} • {getTypeLabel(location.type)}
                  </div>
                </div>

                {/* Remove Button */}
                <button
                  type="button"
                  onClick={() => {
                    handleRemoveLocation(location.id)
                  }}
                  className="flex-shrink-0 p-1 text-gray-400 hover:text-red-600 transition-colors"
                  title={t('common:actions.delete')}
                >
                  <X className="h-4 w-4" />
                </button>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Helper text */}
      {helperText && <p className="text-sm text-gray-500">{helperText}</p>}
    </div>
  )
}
