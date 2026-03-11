import { useInfiniteQuery, type UseInfiniteQueryResult } from '@tanstack/react-query'
import { searchByCriteria } from '../api/partsCatalog'
import { partsCatalogKeys } from './usePartsCatalog'
import type { CriteriaFilter, CriteriaSearchRequest, PaginatedArticles } from '../types/catalog'

export function useCriteriaSearch(
  criteriaFilters: CriteriaFilter[],
  productGroupId?: string
): UseInfiniteQueryResult<{ pages: PaginatedArticles[]; pageParams: (string | undefined)[] }> {
  return useInfiniteQuery({
    queryKey: partsCatalogKeys.criteriaSearch({ criteriaFilters, productGroupId }),
    queryFn: ({ pageParam }) => {
      const request: CriteriaSearchRequest = {
        criteria_filters: criteriaFilters,
        per_page: 20,
      }
      if (productGroupId) request.product_group_id = productGroupId
      if (pageParam) request.cursor = pageParam
      return searchByCriteria(request)
    },
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (lastPage) =>
      lastPage.meta.has_more ? (lastPage.meta.cursor ?? undefined) : undefined,
    enabled: criteriaFilters.length > 0,
    staleTime: 1000 * 60 * 30, // 30 minutes
  })
}
