import { apiGet } from '@/lib/api'
import type { Location } from '../types'

/**
 * Get all locations for the current company
 */
export async function getLocations(): Promise<Location[]> {
  return apiGet<Location[]>('/locations')
}

/**
 * Get a single location by ID
 */
export async function getLocation(id: string): Promise<Location> {
  const response = await api.get<{ data: Location }>(`/locations/${id}`)
  return response.data.data
}
