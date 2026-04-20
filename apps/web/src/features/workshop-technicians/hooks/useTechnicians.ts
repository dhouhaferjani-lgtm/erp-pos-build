import { useQuery } from '@tanstack/react-query'
import { technicianApi } from '../api/technicianApi'
import type {
  AvailabilityQuery,
  AvailabilityResult,
  TechnicianListFilters,
  TechnicianProfile,
} from '../api/types'

/**
 * Query key factory for Workshop/Technician data.
 *
 * Keeping the factory at the top of the file makes invalidation patterns in the
 * eventual authoring hooks (Plan C follow-up) trivial.
 */
export const technicianKeys = {
  all: ['workshop-technicians'] as const,
  lists: () => [...technicianKeys.all, 'list'] as const,
  list: (filters: TechnicianListFilters) => [...technicianKeys.lists(), filters] as const,
  details: () => [...technicianKeys.all, 'detail'] as const,
  detail: (id: string) => [...technicianKeys.details(), id] as const,
  availability: (query: AvailabilityQuery) =>
    [...technicianKeys.all, 'availability', query] as const,
}

export function useTechnicians(filters: TechnicianListFilters = {}) {
  return useQuery<TechnicianProfile[]>({
    queryKey: technicianKeys.list(filters),
    queryFn: () => technicianApi.list(filters),
    staleTime: 60 * 1000,
  })
}

export function useTechnician(id: string | undefined) {
  return useQuery<TechnicianProfile>({
    queryKey: technicianKeys.detail(id ?? ''),
    queryFn: () => {
      if (typeof id !== 'string' || id.length === 0) {
        // Should be unreachable thanks to `enabled` below, but narrows
        // the type for `technicianApi.get(id)` without a cast.
        return Promise.reject(new Error('Technician id is required'))
      }
      return technicianApi.get(id)
    },
    enabled: typeof id === 'string' && id.length > 0,
    staleTime: 60 * 1000,
  })
}

export function useTechnicianAvailability(query: AvailabilityQuery | null) {
  return useQuery<AvailabilityResult>({
    queryKey: query
      ? technicianKeys.availability(query)
      : [...technicianKeys.all, 'availability', 'idle'],
    queryFn: () => {
      if (query === null) {
        // Unreachable when `enabled: query !== null` gates the query, but
        // satisfies strict type checking without a type assertion.
        return Promise.reject(new Error('Availability query is required'))
      }
      return technicianApi.available(query)
    },
    enabled: query !== null,
    staleTime: 30 * 1000,
  })
}
