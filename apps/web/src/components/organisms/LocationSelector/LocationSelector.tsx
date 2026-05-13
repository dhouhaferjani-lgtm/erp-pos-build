import { useState, useRef, useEffect } from 'react'
import { MapPin, ChevronDown, Check, Plus, Warehouse, Store, Building2, Truck, type LucideIcon } from 'lucide-react'
import { useLocation } from '../../../hooks/useLocation'
import { useQueryClient } from '@tanstack/react-query'
import { AddLocationModal } from '../AddLocationModal'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import type { LocationType } from '../../../stores/locationStore'

/**
 * Icon mapping for location types - defined at module level to avoid recreation
 */
const LOCATION_ICONS: Record<LocationType, LucideIcon> = {
  shop: Store,
  warehouse: Warehouse,
  office: Building2,
  mobile: Truck,
}

/**
 * Get label for location type
 */
function getLocationTypeLabel(type: LocationType): string {
  switch (type) {
    case 'shop':
      return 'Shop'
    case 'warehouse':
      return 'Warehouse'
    case 'office':
      return 'Office'
    case 'mobile':
      return 'Mobile'
    default:
      return type
  }
}

function scopedNamespacePredicate(
  namespace: string,
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      k.length >= 3 &&
      k[0] === namespace &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

interface LocationSelectorProps {
  className?: string
}

/**
 * LocationSelector allows users to switch between locations for inventory operations.
 *
 * Shows current location with type icon, dropdown to switch, and option to add new locations.
 */
export function LocationSelector({ className = '' }: LocationSelectorProps) {
  const { currentLocation, locations, hasMultipleLocations, switchLocation, isLoading } = useLocation()
  const [isOpen, setIsOpen] = useState(false)
  const [isModalOpen, setIsModalOpen] = useState(false)
  const menuRef = useRef<HTMLDivElement>(null)
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

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
      // Invalidate stock-related queries to refetch data for new location
      void queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('stock-levels', tenantId, companyId),
      })
      void queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('stock-movements', tenantId, companyId),
      })
    }
    setIsOpen(false)
  }

  const handleAddLocation = () => {
    setIsOpen(false)
    setIsModalOpen(true)
  }

  // Get the icon component from the static mapping
  const CurrentIcon = currentLocation ? LOCATION_ICONS[currentLocation.type] : MapPin

  // Show loading state
  if (isLoading) {
    return (
      <>
        <div className={`relative ${className}`}>
          <div className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-400">
            <MapPin className="h-4 w-4 animate-pulse" />
            <span>Loading...</span>
          </div>
        </div>
        <AddLocationModal isOpen={isModalOpen} onClose={() => { setIsModalOpen(false) }} />
      </>
    )
  }

  // Determine button label
  const buttonLabel = currentLocation?.name ?? (locations.length === 0 ? 'Add Location' : 'Select location')

  return (
    <>
      <div className={`relative ${className}`} ref={menuRef}>
        <button
          type="button"
          onClick={() => { if (locations.length === 0) { setIsModalOpen(true) } else { setIsOpen(!isOpen) } }}
          className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
          aria-label="Select location"
          aria-expanded={isOpen}
          aria-haspopup="true"
        >
          <CurrentIcon className="h-4 w-4 text-gray-500" />
          <span className="max-w-32 truncate">{buttonLabel}</span>
          {locations.length > 0 && (
            <ChevronDown className={`h-4 w-4 text-gray-400 transition-transform ${isOpen ? 'rotate-180' : ''}`} />
          )}
        </button>

        {/* Location dropdown */}
        {isOpen && (
          <div className="absolute start-0 top-full z-50 mt-1 min-w-56 max-w-72 rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
            {hasMultipleLocations && (
              <>
                <div className="px-3 py-2 text-xs font-semibold uppercase tracking-wider text-gray-500">
                  Switch Location
                </div>
                <div className="max-h-64 overflow-y-auto">
                  {locations.map((location) => {
                    const Icon = LOCATION_ICONS[location.type]
                    return (
                      <button
                        key={location.id}
                        type="button"
                        onClick={() => { handleLocationChange(location.id) }}
                        className={`flex w-full items-center justify-between px-3 py-2 text-sm hover:bg-gray-50 ${
                          location.id === currentLocation?.id ? 'bg-blue-50' : ''
                        }`}
                      >
                        <div className="flex items-center gap-2">
                          <Icon className={`h-4 w-4 ${location.id === currentLocation?.id ? 'text-blue-600' : 'text-gray-400'}`} />
                          <div className="flex flex-col items-start">
                            <span className={`font-medium ${location.id === currentLocation?.id ? 'text-blue-700' : 'text-gray-900'}`}>
                              {location.name}
                            </span>
                            <span className="text-xs text-gray-500">
                              {getLocationTypeLabel(location.type)}
                              {location.isDefault && ' (Default)'}
                            </span>
                          </div>
                        </div>
                        {location.id === currentLocation?.id && (
                          <Check className="h-4 w-4 text-blue-600" />
                        )}
                      </button>
                    )
                  })}
                </div>
                <div className="my-1 border-t border-gray-100" />
              </>
            )}
            {!hasMultipleLocations && locations.length === 1 && (
              <>
                <div className="px-3 py-2 text-xs text-gray-500">
                  Current: {currentLocation?.name}
                </div>
                <div className="my-1 border-t border-gray-100" />
              </>
            )}
            <button
              type="button"
              onClick={handleAddLocation}
              className="flex w-full items-center gap-2 px-3 py-2 text-sm font-medium text-blue-600 hover:bg-blue-50"
            >
              <Plus className="h-4 w-4" />
              Add Location
            </button>
          </div>
        )}
      </div>

      {/* Add Location Modal */}
      <AddLocationModal isOpen={isModalOpen} onClose={() => { setIsModalOpen(false) }} />
    </>
  )
}
