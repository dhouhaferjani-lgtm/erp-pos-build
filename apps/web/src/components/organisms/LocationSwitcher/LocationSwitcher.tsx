import { useState, useRef, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { MapPin, ChevronDown, Check, Plus, Settings, Warehouse, Store, Building2, Truck, type LucideIcon } from 'lucide-react'
import { cn } from '../../../lib/utils'
import { textColors, colors } from '../../../lib/designTokens'
import { useLocation } from '../../../hooks/useLocation'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import { useQueryClient } from '@tanstack/react-query'
import { AddLocationModal } from '../AddLocationModal'
import type { LocationType } from '../../../stores/locationStore'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

/**
 * Icon mapping for location types - defined at module level to avoid recreation
 */
const LOCATION_ICONS: Record<LocationType, LucideIcon> = {
  shop: Store,
  warehouse: Warehouse,
  office: Building2,
  mobile: Truck,
}

interface LocationSwitcherProps {
  className?: string
}

function activeScopePredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return k.length >= 2 && k[k.length - 2] === tenantId && k[k.length - 1] === companyId
  }
}

/**
 * LocationSwitcher allows users to switch between locations for inventory operations.
 *
 * Shows current location with type icon, dropdown to switch, and option to add new locations.
 */
export function LocationSwitcher({ className = '' }: LocationSwitcherProps) {
  const { t } = useTranslation('common')
  const { currentLocation, locations, hasMultipleLocations, switchLocation, isLoading } = useLocation()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId)
  const [isOpen, setIsOpen] = useState(false)
  const [isModalOpen, setIsModalOpen] = useState(false)
  const menuRef = useRef<HTMLDivElement>(null)
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  // Close menu when clicking outside
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(event.target as Node)) {
        setIsOpen(false)
      }
    }

    document.addEventListener('mousedown', handleClickOutside)
    return () => {
      document.removeEventListener('mousedown', handleClickOutside)
    }
  }, [])

  const handleLocationChange = (locationId: string) => {
    if (locationId !== currentLocation?.id) {
      switchLocation(locationId)
      // Location scope changed: refetch all active-company data without
      // invalidating caches that belong to another tenant/company.
      void queryClient.invalidateQueries({ predicate: activeScopePredicate(tenantId, companyId) })
    }
    setIsOpen(false)
  }

  const handleAddLocation = () => {
    setIsOpen(false)
    setIsModalOpen(true)
  }

  const handleManageLocations = () => {
    setIsOpen(false)
    void navigate('/settings/locations')
  }

  // Get the icon component from the static mapping
  const CurrentIcon = currentLocation ? LOCATION_ICONS[currentLocation.type] : MapPin

  // Show loading state
  if (isLoading) {
    return (
      <>
        <div className={`relative ${className}`}>
          <div className={`flex items-center gap-2 rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} px-3 py-2 text-sm font-medium ${colorTokens.text.disabled}`}>
            <MapPin className="h-4 w-4 animate-pulse" />
            <span>{t('common:loading')}</span>
          </div>
        </div>
        <AddLocationModal isOpen={isModalOpen} onClose={() => { setIsModalOpen(false) }} />
      </>
    )
  }

  // Determine button label
  const buttonLabel = currentLocation?.name ?? (locations.length === 0 ? t('common:locations.addLocation') : t('common:locations.selectLocation'))

  return (
    <>
      <div className={`relative ${className}`} ref={menuRef}>
        <button
          type="button"
          onClick={() => { if (locations.length === 0) { setIsModalOpen(true) } else { setIsOpen(!isOpen) } }}
          className={`flex items-center gap-2 rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} px-3 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.variants.hoverBgGray50}`}
          aria-label={t('common:locations.selectLocation')}
          aria-expanded={isOpen}
          aria-haspopup="true"
        >
          <CurrentIcon className={`h-4 w-4 ${colorTokens.text.subtle}`} />
          <span className="max-w-40 truncate font-semibold">{buttonLabel}</span>
          {locations.length > 0 && (
            <ChevronDown className={`h-4 w-4 ${colorTokens.text.disabled} transition-transform ${isOpen ? 'rotate-180' : ''}`} />
          )}
        </button>

        {/* Location dropdown */}
        {isOpen && (
          <div className={`absolute start-0 top-full z-50 mt-1 min-w-56 max-w-72 rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} py-1 shadow-lg`}>
            {hasMultipleLocations && (
              <>
                <div className={`px-3 py-2 text-xs font-semibold uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('common:locations.switchLocation')}
                </div>
                <div className="max-h-64 overflow-y-auto">
                  {locations.map((location) => {
                    const Icon = LOCATION_ICONS[location.type]
                    return (
                      <button
                        key={location.id}
                        type="button"
                        onClick={() => { handleLocationChange(location.id) }}
                        className={`flex w-full items-center justify-between px-3 py-2 text-sm ${colorTokens.variants.hoverBgGray50} ${
                          location.id === currentLocation?.id ? `${colorTokens.intent.primary.bgSubtle}` : ''
                        }`}
                      >
                        <div className="flex items-center gap-2">
                          <Icon className={`h-4 w-4 ${location.id === currentLocation?.id ? `${colorTokens.intent.primary.text}` : `${colorTokens.text.disabled}`}`} />
                          <div className="flex flex-col items-start">
                            <span className={`font-medium ${location.id === currentLocation?.id ? `${colorTokens.intent.primary.textStrong}` : `${colorTokens.text.primary}`}`}>
                              {location.name}
                            </span>
                            <span className={`text-xs ${colorTokens.text.subtle}`}>
                              {t(`common:locations.types.${location.type}`)}
                              {location.isDefault && ` (${t('common:locations.default')})`}
                            </span>
                          </div>
                        </div>
                        {location.id === currentLocation?.id && (
                          <Check className={`h-4 w-4 ${colorTokens.intent.primary.text}`} />
                        )}
                      </button>
                    )
                  })}
                </div>
                <div className={`my-1 border-t ${colorTokens.border.hairline}`} />
              </>
            )}
            {!hasMultipleLocations && locations.length === 1 && (
              <>
                <div className={`px-3 py-2 text-xs ${colorTokens.text.subtle}`}>
                  {t('common:locations.currentLocation', { name: currentLocation?.name })}
                </div>
                <div className={`my-1 border-t ${colorTokens.border.hairline}`} />
              </>
            )}
            <button
              type="button"
              onClick={handleAddLocation}
              className={`flex w-full items-center gap-2 px-3 py-2 text-sm font-medium ${colorTokens.intent.primary.text} ${colorTokens.variants.hoverBgBlue50}`}
            >
              <Plus className="h-4 w-4" />
              {t('common:locations.addLocation')}
            </button>
            <button
              type="button"
              onClick={handleManageLocations}
              className={cn('flex w-full items-center gap-2 px-3 py-2 text-sm font-medium', textColors.secondary, colors.hover.gray50)}
            >
              <Settings className="h-4 w-4" />
              {t('common:locations.manageLocations')}
            </button>
          </div>
        )}
      </div>

      {/* Add Location Modal */}
      <AddLocationModal isOpen={isModalOpen} onClose={() => { setIsModalOpen(false) }} />
    </>
  )
}
