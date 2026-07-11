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
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface LocationFieldProps {
  value: string
  onChange: (locationId: string) => void
  placeholder?: string
  error?: string
  disabled?: boolean
  allowNull?: boolean
  nullLabel?: string
}

export function LocationField({
  value,
  onChange,
  placeholder,
  error,
  disabled = false,
  allowNull = false,
  nullLabel,
}: LocationFieldProps) {
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
        className={`flex w-full items-center justify-between rounded-lg border px-3 py-2 text-start shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 ${colorTokens.variants.focusVisibleRingBlue500} ${
          error
            ? `${colorTokens.intent.danger.border}`
            : `${colorTokens.border.default}`
        } ${disabled ? `${colorTokens.surface.muted} cursor-not-allowed` : `${colorTokens.surface.base} ${colorTokens.variants.hoverBgGray50} cursor-pointer`}`}
      >
        <span className={selectedLocation ? `${colorTokens.text.primary}` : `${colorTokens.text.subtle}`}>
          {selectedLocation ? (
            <span className="flex items-center gap-2">
              <MapPin className={`h-4 w-4 ${colorTokens.text.disabled}`} />
              {selectedLocation.name}
              {selectedLocation.code && (
                <span className={`text-xs ${colorTokens.text.subtle}`}>({selectedLocation.code})</span>
              )}
            </span>
          ) : (
            placeholder ?? t('common:locations.selectLocation')
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
              aria-label={t('common:clearSearch')}
              className={`rounded p-0.5 ${colorTokens.text.disabled} ${colorTokens.variants.hoverBgGray200} ${colorTokens.variants.hoverTextGray600}`}
            >
              <X className="h-4 w-4" />
            </button>
          )}
          <ChevronDown className={`h-4 w-4 ${colorTokens.text.disabled} transition-transform ${isOpen ? 'rotate-180' : ''}`} />
        </div>
      </div>

      {/* Dropdown */}
      {isOpen && (
        <div className={`absolute left-0 top-full z-50 mt-1 w-full min-w-[300px] rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} shadow-lg`}>
          {/* Search input */}
          <div className={`p-3 border-b ${colorTokens.border.subtle}`}>
            <div className="relative">
              <Search className={`absolute inset-y-0 start-0 ms-3 h-full w-4 ${colorTokens.text.disabled}`} />
              <input
                ref={inputRef}
                type="text"
                value={searchQuery}
                onChange={(e) => { setSearchQuery(e.target.value) }}
                placeholder={t('common:searchLocation')}
                className={`w-full rounded-lg border ${colorTokens.border.default} py-2 pe-10 ps-10 text-sm ${colorTokens.focus.primaryBorder} focus:outline-none focus:ring-1 ${colorTokens.focus.primaryRing}`}
              />
              {searchQuery && (
                <button
                  type="button"
                  onClick={() => { setSearchQuery('') }}
                  className={`absolute inset-y-0 end-0 flex items-center pe-3 ${colorTokens.text.disabled} ${colorTokens.variants.hoverTextGray600}`}
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
                className={`flex w-full items-center gap-3 px-4 py-3 text-start ${colorTokens.variants.hoverBgGray50} border-b ${colorTokens.border.hairline} ${
                  !value ? `${colorTokens.intent.primary.bgSubtle}` : ''
                }`}
              >
                <div className={`flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full ${colorTokens.surface.muted}`}>
                  <MapPin className={`h-4 w-4 ${colorTokens.text.disabled}`} />
                </div>
                <div className="min-w-0 flex-1">
                  <div className={`text-sm font-medium ${colorTokens.text.primary}`}>
                    {nullLabel ?? t('common:noLocation')}
                  </div>
                  <div className={`text-xs ${colorTokens.text.subtle}`}>
                    {t('common:centralInvoicing')}
                  </div>
                </div>
                {!value && (
                  <div className="flex-shrink-0">
                    <div className={`h-2 w-2 rounded-full ${colorTokens.intent.primary.bgStrong}`} />
                  </div>
                )}
              </button>
            )}

            {isLoading ? (
              <div className={`p-4 text-center text-sm ${colorTokens.text.subtle}`}>
                {t('common:status.loading')}
              </div>
            ) : locations.length === 0 ? (
              <div className="p-4 text-center text-sm">
                <MapPin className={`mx-auto h-8 w-8 ${colorTokens.text.faint}`} />
                <p className={`mt-2 ${colorTokens.text.subtle}`}>
                  {searchQuery
                    ? t('common:noLocationResults')
                    : t('common:noLocations')}
                </p>
              </div>
            ) : (
              <ul className={`divide-y ${colorTokens.border.dividerSubtle}`}>
                {locations.map((location) => (
                  <li key={location.id}>
                    <button
                      type="button"
                      onClick={() => { handleSelect(location) }}
                      className={`flex w-full items-center gap-3 px-4 py-3 text-start ${colorTokens.variants.hoverBgGray50} ${
                        location.id === value ? `${colorTokens.intent.primary.bgSubtle}` : ''
                      }`}
                    >
                      <div className={`flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full ${colorTokens.surface.muted}`}>
                        <MapPin className={`h-4 w-4 ${colorTokens.text.subtle}`} />
                      </div>
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                          <div className={`text-sm font-medium ${colorTokens.text.primary} truncate`}>
                            {location.name}
                          </div>
                          {location.isDefault && (
                            <span className={`inline-flex items-center rounded-full ${colorTokens.intent.primary.bgSoft} px-2 py-0.5 text-xs font-medium ${colorTokens.intent.primary.textStronger}`}>
                              {t('common:locations.default')}
                            </span>
                          )}
                          {!location.isActive && (
                            <span className={`inline-flex items-center rounded-full ${colorTokens.surface.muted} px-2 py-0.5 text-xs font-medium ${colorTokens.text.strong}`}>
                              {t('common:inactive')}
                            </span>
                          )}
                        </div>
                        <div className={`text-xs ${colorTokens.text.subtle} truncate`}>
                          {location.code && <span className="font-mono">{location.code}</span>}
                          {location.code && (location.addressStreet || location.addressCity) && ' • '}
                          {[location.addressStreet, location.addressCity].filter(Boolean).join(', ')}
                        </div>
                      </div>
                      {location.id === value && (
                        <div className="flex-shrink-0">
                          <div className={`h-2 w-2 rounded-full ${colorTokens.intent.primary.bgStrong}`} />
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
        <p className={`mt-1 text-sm ${colorTokens.intent.danger.text}`}>{error}</p>
      )}
    </div>
  )
}
