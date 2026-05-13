import { useState, useRef, useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Search, X, ChevronDown, MapPin } from 'lucide-react'
import { api } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { getLocations } from '../../features/locations/api/locations'
import type { Location } from '../../features/locations/types'

interface LocationSelectorProps {
  value: string
  onChange: (locationId: string) => void
  placeholder?: string
  error?: string
  disabled?: boolean
  allowNull?: boolean
  nullLabel?: string
}

export function LocationSelector({
  value,
  onChange,
  placeholder,
  error,
  disabled = false,
  allowNull = false,
  nullLabel,
}: LocationSelectorProps) {
  const { t } = useTranslation()
  const [isOpen, setIsOpen] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')
  const containerRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  // Fetch all locations
  const { data: allLocations, isLoading } = useQuery({
    queryKey: tenantScopedKey(['locations']),
    queryFn: getLocations,
    enabled: tenantId !== null && companyId !== null && isOpen,
    staleTime: 30000,
  })

  // Fetch selected location for display
  const { data: selectedLocationData } = useQuery({
    queryKey: tenantScopedKey(['location', value]),
    queryFn: async () => {
      const response = await api.get<{ data: Location }>(`/locations/${value}`)
      return response.data.data
    },
    enabled: tenantId !== null && companyId !== null && Boolean(value) && !isOpen,
    staleTime: 60000,
  })

  // Filter locations by search query
  const locations = (allLocations ?? []).filter((location) => {
    if (!searchQuery) return true
    const query = searchQuery.toLowerCase()
    return (
      location.name.toLowerCase().includes(query) ||
      location.code.toLowerCase().includes(query)
    )
  })

  const selectedLocation = selectedLocationData

  // Close dropdown when clicking outside
  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setIsOpen(false)
        setSearchQuery('')
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => { document.removeEventListener('mousedown', handleClickOutside) }
  }, [])

  // Focus input when dropdown opens
  useEffect(() => {
    if (isOpen && inputRef.current) {
      inputRef.current.focus()
    }
  }, [isOpen])

  const handleSelect = (location: Location | null) => {
    onChange(location?.id ?? '')
    setIsOpen(false)
    setSearchQuery('')
  }

  const handleClear = () => {
    onChange('')
    setSearchQuery('')
  }

  return (
    <div ref={containerRef} className="relative">
      {/* Selected value display / trigger */}
      <div
        role="button"
        tabIndex={disabled ? -1 : 0}
        onClick={() => !disabled && setIsOpen(!isOpen)}
        onKeyDown={(e) => {
          if ((e.key === 'Enter' || e.key === ' ') && !disabled) {
            e.preventDefault()
            setIsOpen(!isOpen)
          }
        }}
        aria-disabled={disabled}
        aria-expanded={isOpen}
        aria-haspopup="listbox"
        className={`flex w-full items-center justify-between rounded-lg border px-3 py-2 text-start shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 ${
          error
            ? 'border-red-300'
            : 'border-gray-300'
        } ${disabled ? 'bg-gray-100 cursor-not-allowed' : 'bg-white hover:bg-gray-50 cursor-pointer'}`}
      >
        <span className={selectedLocation ? 'text-gray-900' : 'text-gray-500'}>
          {selectedLocation ? (
            <span className="flex items-center gap-2">
              <MapPin className="h-4 w-4 text-gray-400" />
              {selectedLocation.name}
              {selectedLocation.code && (
                <span className="text-xs text-gray-500">({selectedLocation.code})</span>
              )}
            </span>
          ) : (
            placeholder ?? t('common.selectLocation', 'Select location')
          )}
        </span>
        <div className="flex items-center gap-1">
          {value && !disabled && (
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation()
                handleClear()
              }}
              aria-label="Clear selection"
              className="rounded p-0.5 text-gray-400 hover:bg-gray-200 hover:text-gray-600"
            >
              <X className="h-4 w-4" />
            </button>
          )}
          <ChevronDown className={`h-4 w-4 text-gray-400 transition-transform ${isOpen ? 'rotate-180' : ''}`} />
        </div>
      </div>

      {/* Dropdown */}
      {isOpen && (
        <div className="absolute left-0 top-full z-50 mt-1 w-full min-w-[300px] rounded-lg border border-gray-200 bg-white shadow-lg">
          {/* Search input */}
          <div className="p-3 border-b border-gray-200">
            <div className="relative">
              <Search className="absolute inset-y-0 start-0 ms-3 h-full w-4 text-gray-400" />
              <input
                ref={inputRef}
                type="text"
                value={searchQuery}
                onChange={(e) => { setSearchQuery(e.target.value) }}
                placeholder={t('common.searchLocation', 'Search locations...')}
                className="w-full rounded-lg border border-gray-300 py-2 pe-10 ps-10 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
              {searchQuery && (
                <button
                  type="button"
                  onClick={() => { setSearchQuery('') }}
                  className="absolute inset-y-0 end-0 flex items-center pe-3 text-gray-400 hover:text-gray-600"
                >
                  <X className="h-4 w-4" />
                </button>
              )}
            </div>
          </div>

          {/* Results list */}
          <div className="max-h-60 overflow-y-auto">
            {/* Null option (if allowed) */}
            {allowNull && (
              <button
                type="button"
                onClick={() => { handleSelect(null) }}
                className={`flex w-full items-center gap-3 px-4 py-3 text-start hover:bg-gray-50 border-b border-gray-100 ${
                  !value ? 'bg-blue-50' : ''
                }`}
              >
                <div className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-gray-100">
                  <MapPin className="h-4 w-4 text-gray-400" />
                </div>
                <div className="min-w-0 flex-1">
                  <div className="text-sm font-medium text-gray-900">
                    {nullLabel ?? t('common.noLocation', 'No location')}
                  </div>
                  <div className="text-xs text-gray-500">
                    {t('common.centralInvoicing', 'Central invoicing')}
                  </div>
                </div>
                {!value && (
                  <div className="flex-shrink-0">
                    <div className="h-2 w-2 rounded-full bg-blue-600" />
                  </div>
                )}
              </button>
            )}

            {isLoading ? (
              <div className="p-4 text-center text-sm text-gray-500">
                {t('status.loading', 'Loading...')}
              </div>
            ) : locations.length === 0 ? (
              <div className="p-4 text-center text-sm">
                <MapPin className="mx-auto h-8 w-8 text-gray-300" />
                <p className="mt-2 text-gray-500">
                  {searchQuery
                    ? t('common.noLocationResults', 'No locations found')
                    : t('common.noLocations', 'No locations available')}
                </p>
              </div>
            ) : (
              <ul className="divide-y divide-gray-100">
                {locations.map((location) => (
                  <li key={location.id}>
                    <button
                      type="button"
                      onClick={() => { handleSelect(location) }}
                      className={`flex w-full items-center gap-3 px-4 py-3 text-start hover:bg-gray-50 ${
                        location.id === value ? 'bg-blue-50' : ''
                      }`}
                    >
                      <div className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-gray-100">
                        <MapPin className="h-4 w-4 text-gray-500" />
                      </div>
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                          <div className="text-sm font-medium text-gray-900 truncate">
                            {location.name}
                          </div>
                          {location.isDefault && (
                            <span className="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">
                              {t('common.default', 'Default')}
                            </span>
                          )}
                          {!location.isActive && (
                            <span className="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-800">
                              {t('common.inactive', 'Inactive')}
                            </span>
                          )}
                        </div>
                        <div className="text-xs text-gray-500 truncate">
                          {location.code && <span className="font-mono">{location.code}</span>}
                          {location.code && (location.addressStreet || location.addressCity) && ' • '}
                          {[location.addressStreet, location.addressCity].filter(Boolean).join(', ')}
                        </div>
                      </div>
                      {location.id === value && (
                        <div className="flex-shrink-0">
                          <div className="h-2 w-2 rounded-full bg-blue-600" />
                        </div>
                      )}
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}

      {/* Error message */}
      {error && (
        <p className="mt-1 text-sm text-red-600">{error}</p>
      )}
    </div>
  )
}
