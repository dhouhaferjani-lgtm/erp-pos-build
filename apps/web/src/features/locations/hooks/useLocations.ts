import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { getLocations, getLocation } from '../api/locations'
import type { LocationsResponse, Location } from '../types'

/**
 * Query key factory for locations
 */
export const locationKeys = {
  all: ['locations'] as const,
  lists: () => [...locationKeys.all, 'list'] as const,
  list: () => [...locationKeys.lists()] as const,
  details: () => [...locationKeys.all, 'detail'] as const,
  detail: (id: string) => [...locationKeys.details(), id] as const,
}

/**
 * Hook to fetch list of locations
 */
export function useLocations(): UseQueryResult<LocationsResponse> {
  return useQuery({
    queryKey: locationKeys.list(),
    queryFn: () => getLocations(),
    staleTime: 300000, // Consider data fresh for 5 minutes (locations rarely change)
  })
}

/**
 * Hook to fetch a single location by ID
 */
export function useLocation(id: string): UseQueryResult<Location> {
  return useQuery({
    queryKey: locationKeys.detail(id),
    queryFn: () => getLocation(id),
    enabled: Boolean(id),
  })
}
