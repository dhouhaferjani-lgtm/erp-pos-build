import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { getSearchTreeRoots, getSearchTreeChildren } from '../api/partsCatalog'
import { partsCatalogKeys } from './usePartsCatalog'
import type { SearchTreeNode, VehicleType } from '../types/catalog'

const TWENTY_FOUR_HOURS = 1000 * 60 * 60 * 24

export function useSearchTreeRoots(treeType: VehicleType = 'pc'): UseQueryResult<SearchTreeNode[]> {
  return useQuery({
    queryKey: partsCatalogKeys.searchTreeRoots(treeType),
    queryFn: () => getSearchTreeRoots(treeType),
    staleTime: TWENTY_FOUR_HOURS,
  })
}

export function useSearchTreeChildren(nodeId: string): UseQueryResult<SearchTreeNode[]> {
  return useQuery({
    queryKey: partsCatalogKeys.searchTreeChildren(nodeId),
    queryFn: () => getSearchTreeChildren(nodeId),
    enabled: Boolean(nodeId),
    staleTime: TWENTY_FOUR_HOURS,
  })
}
