import { api } from '@/lib/api'
import type { LocationsResponse, Location } from '../types'

/**
 * Get all locations for the current company
 */
export async function getLocations(): Promise<LocationsResponse> {
  const response = await api.get<LocationsResponse>('/locations')
  return response.data
}

/**
 * Get a single location by ID
 */
export async function getLocation(id: string): Promise<Location> {
  const response = await api.get<{ data: Location }>(`/locations/${id}`)
  return response.data.data
}
