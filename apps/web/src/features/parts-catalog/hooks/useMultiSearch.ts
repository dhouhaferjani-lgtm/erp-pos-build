import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { multiSearch } from '../api/partsCatalog'
import { partsCatalogKeys } from './usePartsCatalog'
import { usePartsCatalogTenantScope } from './usePartsCatalogTenantScope'
import type { MultiSearchResponse } from '../types/catalog'

export function useMultiSearch(query: string): UseQueryResult<MultiSearchResponse> {
  const trimmed = query.trim()
  const hasTenantScope = usePartsCatalogTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...partsCatalogKeys.multiSearch(trimmed)]),
    queryFn: () => multiSearch(trimmed),
    enabled: trimmed.length >= 3 && hasTenantScope,
    staleTime: 1000 * 60 * 30, // 30 minutes
  })
}
