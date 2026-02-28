import { create } from 'zustand'
import { persist } from 'zustand/middleware'

/**
 * Location type enumeration matching backend LocationType enum
 */
export type LocationType = 'shop' | 'warehouse' | 'office' | 'mobile'

/**
 * Location type for multi-location support
 */
export interface Location {
  id: string
  companyId: string
  name: string
  code: string
  type: LocationType
  phone: string | null
  email: string | null
  addressStreet: string | null
  addressCity: string | null
  addressPostalCode: string | null
  addressCountry: string | null
  isDefault: boolean
  isActive: boolean
  posEnabled: boolean
  createdAt: string
  updatedAt: string
}

/**
 * Location state interface
 */
interface LocationState {
  currentLocationId: string | null
  locations: Location[]
  isLoading: boolean
}

/**
 * Location actions interface
 */
interface LocationActions {
  setLocations: (locations: Location[]) => void
  setCurrentLocation: (locationId: string) => void
  setLoading: (loading: boolean) => void
  getCurrentLocation: () => Location | null
  getDefaultLocation: () => Location | null
  reset: () => void
  resetForCompanyChange: () => void
}

/**
 * Location store type
 */
type LocationStore = LocationState & LocationActions

/**
 * Initial state
 */
const initialState: LocationState = {
  currentLocationId: null,
  locations: [],
  isLoading: true,
}

/**
 * Location store with persistence
 *
 * Stores the current location selection for inventory operations.
 * Location list is fetched from the server based on current company.
 */
export const useLocationStore = create<LocationStore>()(
  persist(
    (set, get) => ({
      ...initialState,

      setLocations: (locations) => {
        const state = get()
        // If no current location selected or current location not in list,
        // default to the default location or first location
        let currentLocationId = state.currentLocationId
        if (!currentLocationId || !locations.find((l) => l.id === currentLocationId)) {
          const defaultLocation = locations.find((l) => l.isDefault)
          currentLocationId = defaultLocation?.id ?? (locations.length > 0 ? locations[0].id : null)
        }
        set({
          locations,
          currentLocationId,
          isLoading: false,
        })
      },

      setCurrentLocation: (locationId) => {
        const { locations } = get()
        // Validate that the location exists in the list
        if (locations.find((l) => l.id === locationId)) {
          set({ currentLocationId: locationId })
        }
      },

      setLoading: (isLoading) => set({ isLoading }),

      getCurrentLocation: () => {
        const { currentLocationId, locations } = get()
        if (!currentLocationId) return null
        return locations.find((l) => l.id === currentLocationId) ?? null
      },

      getDefaultLocation: () => {
        const { locations } = get()
        return locations.find((l) => l.isDefault) ?? (locations.length > 0 ? locations[0] : null)
      },

      reset: () => {
        set(initialState)
        localStorage.removeItem('autoerp-location')
      },

      resetForCompanyChange: () => {
        // When company changes, reset location selection but keep loading state
        set({
          currentLocationId: null,
          locations: [],
          isLoading: true,
        })
      },
    }),
    {
      name: 'autoerp-location',
      partialize: (state) => ({
        currentLocationId: state.currentLocationId,
      }),
    }
  )
)
