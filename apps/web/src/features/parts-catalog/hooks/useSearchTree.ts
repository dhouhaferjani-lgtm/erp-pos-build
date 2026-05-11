import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { getSearchTreeRoots, getSearchTreeChildren } from '../api/partsCatalog'
import { partsCatalogKeys } from './usePartsCatalog'
import { usePartsCatalogTenantScope } from './usePartsCatalogTenantScope'
import type { SearchTreeNode, VehicleType } from '../types/catalog'

const TWENTY_FOUR_HOURS = 1000 * 60 * 60 * 24

export function useSearchTreeRoots(treeType: VehicleType = 'pc'): UseQueryResult<SearchTreeNode[]> {
  const hasTenantScope = usePartsCatalogTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...partsCatalogKeys.searchTreeRoots(treeType)]),
    queryFn: () => getSearchTreeRoots(treeType),
    enabled: hasTenantScope,
    staleTime: TWENTY_FOUR_HOURS,
  })
}

export function useSearchTreeChildren(nodeId: string): UseQueryResult<SearchTreeNode[]> {
  const hasTenantScope = usePartsCatalogTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...partsCatalogKeys.searchTreeChildren(nodeId)]),
    queryFn: () => getSearchTreeChildren(nodeId),
    enabled: Boolean(nodeId) && hasTenantScope,
    staleTime: TWENTY_FOUR_HOURS,
  })
}
