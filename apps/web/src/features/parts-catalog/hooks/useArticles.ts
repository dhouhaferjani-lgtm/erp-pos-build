import { useInfiniteQuery, type UseInfiniteQueryResult } from '@tanstack/react-query'
import { getVehicleArticles, getSearchTreeArticles } from '../api/partsCatalog'
import { partsCatalogKeys } from './usePartsCatalog'
import type { PaginatedArticles, VehicleType } from '../types/catalog'

export function useVehicleArticles(
  vehicleType: VehicleType,
  vehicleId: string,
  productGroupId?: string
): UseInfiniteQueryResult<{ pages: PaginatedArticles[]; pageParams: (string | undefined)[] }> {
  return useInfiniteQuery({
    queryKey: partsCatalogKeys.articles({ vehicleType, vehicleId, productGroupId }),
    queryFn: ({ pageParam }) => {
      const params: { product_group_id?: string; cursor?: string; per_page: number } = {
        per_page: 20,
      }
      if (productGroupId) params.product_group_id = productGroupId
      if (pageParam) params.cursor = pageParam
      return getVehicleArticles(vehicleType, vehicleId, params)
    },
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (lastPage) =>
      lastPage.meta.has_more ? (lastPage.meta.cursor ?? undefined) : undefined,
    enabled: Boolean(vehicleId),
  })
}

export function useCategoryArticles(
  nodeId: string
): UseInfiniteQueryResult<{ pages: PaginatedArticles[]; pageParams: (string | undefined)[] }> {
  return useInfiniteQuery({
    queryKey: partsCatalogKeys.searchTreeArticles(nodeId),
    queryFn: ({ pageParam }) => {
      const params: { cursor?: string; per_page: number } = { per_page: 20 }
      if (pageParam) params.cursor = pageParam
      return getSearchTreeArticles(nodeId, params)
    },
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (lastPage) =>
      lastPage.meta.has_more ? (lastPage.meta.cursor ?? undefined) : undefined,
    enabled: Boolean(nodeId),
  })
}
