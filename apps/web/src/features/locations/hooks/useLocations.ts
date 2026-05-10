import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { getLocations, getLocation } from '../api/locations'
import type { Location } from '../types'

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
export function useLocations(): UseQueryResult<Location[]> {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey([...locationKeys.list()]),
    queryFn: () => getLocations(),
    enabled: !!tenantId && !!companyId,
    staleTime: 300000, // Consider data fresh for 5 minutes (locations rarely change)
  })
}

/**
 * Hook to fetch a single location by ID
 */
export function useLocation(id: string): UseQueryResult<Location> {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey([...locationKeys.detail(id)]),
    queryFn: () => getLocation(id),
    enabled: Boolean(id) && !!tenantId && !!companyId,
  })
}
