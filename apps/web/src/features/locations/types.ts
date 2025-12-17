/**
 * Location Types for Inventory Counting
 * Shared types for location selection across the application
 */

export type LocationType = 'shop' | 'warehouse' | 'office' | 'mobile'

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

export interface LocationsResponse {
  data: Location[]
  meta: {
    timestamp: string
    request_id: string
  }
}

/**
 * Minimal location info for selection UI
 */
export interface LocationSelectionItem {
  id: string
  name: string
  code: string
  type: LocationType
  isActive: boolean
}
